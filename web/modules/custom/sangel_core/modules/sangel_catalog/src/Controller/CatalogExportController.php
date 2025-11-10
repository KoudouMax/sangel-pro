<?php

namespace Drupal\sangel_catalog\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports catalog content as CSV.
 */
class CatalogExportController extends ControllerBase {

  use CatalogProductDataTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $controller */
    $controller = parent::create($container);
    /** @var \Drupal\sangel_core\Repository\CatalogProductRepositoryInterface $repository */
    $repository = $container->get('sangel_core.catalog_product_repository');
    $controller->setCatalogProductRepository($repository);
    return $controller;
  }

  /**
   * Exports the given catalog node as CSV.
   */
  public function export(NodeInterface $node): StreamedResponse {
    $account = $this->currentUser();

    // $access = $this->checkCatalogAccess($node, $account);
    // if (!$access->isAllowed()) {
    //   throw $this->createAccessDeniedException();
    // }

    $products = $this->loadCatalogProducts($node);

    $rows = [[
      (string) $this->t('Nom'),
      (string) $this->t('Référence'),
      (string) $this->t('Famille'),
      (string) $this->t('Sous-famille'),
      (string) $this->t('Type de client'),
      (string) $this->t('Poids'),
      (string) $this->t('Prix'),
      (string) $this->t('Image'),
    ]];

    $file = NULL;
    if ($products) {
      foreach ($products as $product) {
        $price = $product->get('field_price')->value ?? '';
        $family = $product->hasField('field_family') && !$product->get('field_family')->isEmpty()
          ? $product->get('field_family')->entity->label()
          : '';
        $subFamily = $product->hasField('field_sub_family') && !$product->get('field_sub_family')->isEmpty()
          ? $product->get('field_sub_family')->entity->label()
          : '';
        $clientType = $this->getClientTypeLabel($product);
        $weight = $product->hasField('field_weight') && !$product->get('field_weight')->isEmpty()
          ? $product->get('field_weight')->value
          : '';
        $unit = $product->hasField('field_unit_weight') && !$product->get('field_unit_weight')->isEmpty()
          ? $product->get('field_unit_weight')->value
          : '';
        $image = $product->hasField('field_image_url') && !$product->get('field_image_url')->isEmpty()
          ? $product->get('field_image_url')->value
          : '';

        $rows[] = [
          $product->label(),
          $product->get('field_sku')->value ?? '',
          $family,
          $subFamily,
          $clientType,
          trim($weight . ' ' . $unit),
          $price,
          $image,
        ];
      }

      $file = $this->createExportFile($rows, $node);
      $this->logExport($node, $products, $account, $file);
      $this->clearCatalogProducts($node);
    }

    $response = new StreamedResponse();
    $filename = sprintf('selection-%d.csv', $node->id());
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    $response->setCallback(function () use ($rows) {
      $handle = fopen('php://output', 'w');
      fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
      foreach ($rows as $row) {
        fputcsv($handle, $row, ';');
      }
      fclose($handle);
    });

    return $response;
  }

  /**
   * Checks if the account can export the catalog.
   */
  protected function checkCatalogAccess(NodeInterface $node, AccountInterface $account): AccessResult {
    if ($node->bundle() !== 'catalog') {
      return AccessResult::forbidden();
    }

    if ($account->hasPermission('view all sangel catalogs')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$node->isPublished() && $node->getOwnerId() !== $account->id()) {
      return AccessResult::forbidden()->cachePerPermissions()->cachePerUser();
    }

    if ($account->hasPermission('export sangel catalogs') && \sangel_catalog_is_assigned($node, $account)) {
      return AccessResult::allowed()->cachePerUser()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerUser()->cachePerPermissions();
  }

  /**
   * Returns the client type label for a product node.
   */
  protected function getClientTypeLabel(NodeInterface $product): string {
    if (!$product->hasField('field_client_type')) {
      return '';
    }

    $term = $product->get('field_client_type')->entity;
    return $term ? $term->label() : '';
  }

  /**
   * Ensures the export content type and fields exist.
   */
  protected function ensureExportContentType(): void {
    $type_storage = $this->entityTypeManager()->getStorage('node_type');
    if (!$type_storage->load('export')) {
      $type = NodeType::create([
        'type' => 'export',
        'name' => $this->t('Export'),
      ]);
      $type->setNewRevision(TRUE);
      $type->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_export_products')) {
      FieldStorageConfig::create([
        'field_name' => 'field_export_products',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'node',
        ],
        'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
        'translatable' => FALSE,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', 'export', 'field_export_products')) {
      FieldConfig::create([
        'field_name' => 'field_export_products',
        'entity_type' => 'node',
        'bundle' => 'export',
        'label' => $this->t('Produits exportés'),
        'settings' => [
          'handler' => 'default',
          'handler_settings' => [
            'target_bundles' => ['product' => 'product'],
          ],
        ],
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_export_catalog')) {
      FieldStorageConfig::create([
        'field_name' => 'field_export_catalog',
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'node',
        ],
        'cardinality' => 1,
        'translatable' => FALSE,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', 'export', 'field_export_catalog')) {
      FieldConfig::create([
        'field_name' => 'field_export_catalog',
        'entity_type' => 'node',
        'bundle' => 'export',
        'label' => $this->t('Catalogue associé'),
        'settings' => [
          'handler' => 'default',
          'handler_settings' => [
            'target_bundles' => ['catalog' => 'catalog'],
          ],
        ],
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_export_file')) {
      FieldStorageConfig::create([
        'field_name' => 'field_export_file',
        'entity_type' => 'node',
        'type' => 'file',
        'settings' => [
          'uri_scheme' => 'private',
        ],
        'cardinality' => 1,
        'translatable' => FALSE,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', 'export', 'field_export_file')) {
      FieldConfig::create([
        'field_name' => 'field_export_file',
        'entity_type' => 'node',
        'bundle' => 'export',
        'label' => $this->t('Fichier exporté'),
        'settings' => [
          'display_field' => FALSE,
          'description_field' => FALSE,
        ],
      ])->save();
    }
  }

  /**
   * Logs an export operation as an "export" node.
   *
   * @param \Drupal\node\NodeInterface[] $products
   *   The exported products.
   */
  protected function logExport(NodeInterface $catalog, array $products, AccountInterface $account, ?File $file = NULL): void {
    try {
      $this->ensureExportContentType();

      $timestamp = \Drupal::time()->getCurrentTime();
      $title = $this->t('Export du @date - @count produits', [
        '@date' => \Drupal::service('date.formatter')->format($timestamp, 'short'),
        '@count' => count($products),
      ]);

      $export_node = Node::create([
        'type' => 'export',
        'title' => $title,
        'uid' => $account->id(),
        'status' => Node::NOT_PUBLISHED,
      ]);

      if ($export_node->hasField('field_export_products') && $products) {
        $export_node->set('field_export_products', array_map(static function (NodeInterface $product): array {
          return ['target_id' => $product->id()];
        }, $products));
      }

      if ($export_node->hasField('field_export_catalog')) {
        $export_node->set('field_export_catalog', ['target_id' => $catalog->id()]);
      }

      if ($file instanceof File && $export_node->hasField('field_export_file')) {
        $export_node->set('field_export_file', ['target_id' => $file->id()]);
      }

      $export_node->save();
    }
    catch (\Throwable $throwable) {
      $this->getLogger('sangel_catalog')->error('Impossible d’enregistrer le journal d’export : @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  /**
   * Creates a CSV file entity from generated rows.
   */
  protected function createExportFile(array $rows, NodeInterface $catalog): ?File {
    try {
      $handle = fopen('php://temp', 'r+');
      fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
      foreach ($rows as $row) {
        fputcsv($handle, $row, ';');
      }
      rewind($handle);
      $data = stream_get_contents($handle);
      fclose($handle);

      $directory = 'private://exports';
      \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $filename = sprintf('catalog-export-%d-%s.csv', $catalog->id(), date('Ymd-His', \Drupal::time()->getCurrentTime()));
      $uri = $directory . '/' . $filename;

      $file = file_save_data($data, $uri, FileSystemInterface::EXISTS_RENAME);
      if ($file instanceof File) {
        $file->setPermanent();
        $file->save();
        return $file;
      }
    }
    catch (\Throwable $throwable) {
      $this->getLogger('sangel_catalog')->error('Impossible de sauvegarder le fichier d’export : @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Clears products from the catalog after export.
   */
  protected function clearCatalogProducts(NodeInterface $catalog): void {
    $this->setCatalogProductIds($catalog, []);

    try {
      $catalog->save();
    }
    catch (\Throwable $throwable) {
      $this->getLogger('sangel_catalog')->error('Impossible de vider le catalogue après export : @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

}
