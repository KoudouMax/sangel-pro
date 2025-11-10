<?php

declare(strict_types=1);

namespace Drupal\sangel_catalog\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Generates CSV exports for export nodes.
 */
class ExportProductsController extends ControllerBase {

  protected TimeInterface $time;

  public function __construct(TimeInterface $time) {
    $this->time = $time;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('datetime.time')
    );
  }

  public function download(NodeInterface $export): Response {
    if ($export->bundle() !== 'export') {
      throw new NotFoundHttpException();
    }

    $items = $export->get('field_export_products');
    if ($items->isEmpty()) {
      throw new NotFoundHttpException();
    }

    $products = [];
    foreach ($items as $item) {
      if ($item->entity instanceof NodeInterface && $item->entity->bundle() === 'product') {
        $products[] = $item->entity;
      }
    }

    if (!$products) {
      throw new NotFoundHttpException();
    }

    $response = new StreamedResponse();
    $filename = sprintf('export-products-%d-%s.csv', (int) $export->id(), date('YmdHis', $this->time->getCurrentTime()));

    $response->setCallback(function () use ($export, $products) {
      $handle = fopen('php://output', 'w');
      if (!$handle) {
        return;
      }

      fputcsv($handle, [
        'Nom',
        'Référence',
        'Famille',
        'Sous-famille',
        'Type de client',
        'Poids',
        'Prix',
        'Image',
      ], ';');

      foreach ($products as $product) {
        $family = $product->hasField('field_family') && !$product->get('field_family')->isEmpty()
          ? $product->get('field_family')->entity->label()
          : '';
        $subFamily = $product->hasField('field_sub_family') && !$product->get('field_sub_family')->isEmpty()
          ? $product->get('field_sub_family')->entity->label()
          : '';
        $clientType = $product->hasField('field_client_type') && !$product->get('field_client_type')->isEmpty()
          ? $product->get('field_client_type')->entity->label()
          : '';
        $weight = $product->hasField('field_weight') && !$product->get('field_weight')->isEmpty()
          ? $product->get('field_weight')->value
          : '';
        $unit = $product->hasField('field_unit_weight') && !$product->get('field_unit_weight')->isEmpty()
          ? $product->get('field_unit_weight')->value
          : '';
        $image = $product->hasField('field_image_url') && !$product->get('field_image_url')->isEmpty()
          ? $product->get('field_image_url')->value
          : '';

        fputcsv($handle, [
          $product->label(),
          $product->get('field_sku')->value ?? '',
          $family,
          $subFamily,
          $clientType,
          trim($weight . ' ' . $unit),
          $product->get('field_price')->value ?? '',
          $image,
        ], ';');
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

    return $response;
  }

  /**
   * Access check allowing commercial users to export.
   */
  public function access(NodeInterface $export, AccountInterface $account): AccessResult {
    $view_access = $export->access('view', $account, TRUE);

    return $view_access->andIf(AccessResult::allowedIf(
      $account->hasPermission('export sangel catalogs') || in_array('commercial', $account->getRoles(), TRUE)
    ))
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($export);
  }

}
