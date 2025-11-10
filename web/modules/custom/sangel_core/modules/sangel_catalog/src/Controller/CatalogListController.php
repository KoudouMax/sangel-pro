<?php

namespace Drupal\sangel_catalog\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user\UserInterface;

/**
 * Builds catalog listings for users.
 */
class CatalogListController extends ControllerBase {

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
   * Displays the catalogs assigned to a user.
   */
  public function userCatalogs(UserInterface $user): array {
    $account = $this->currentUser();
    $is_self = (int) $account->id() === (int) $user->id();

    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'catalog')
      ->condition('status', 1)
      ->sort('changed', 'DESC');

    if (!$account->hasPermission('view all sangel catalogs') || !$is_self) {
      $query->condition('field_catalog_clients', $user->id());
    }

    $nids = $query->accessCheck(FALSE)->execute();
    if (!$nids) {
      return [
        '#markup' => $this->t('No catalogs available.'),
        '#cache' => [
          'tags' => ['node_list:catalog'],
          'contexts' => ['user'],
        ],
      ];
    }

    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);
    $date_formatter = \Drupal::service('date.formatter');
    $rows = [];
    foreach ($nodes as $node) {
      $products = $this->loadCatalogProducts($node);
      $count = count($products);
      $client_type = '';
      if ($term = $node->get('field_client_type')->entity) {
        $client_type = $term->label();
      }

      $rows[] = [
        Link::fromTextAndUrl($node->label(), $node->toUrl())->toString(),
        $client_type,
        $this->formatPlural($count, '1 product', '@count products'),
        $date_formatter->format($node->getChangedTime(), 'short'),
        $this->buildExportLink($node),
      ];
    }

    $header = [
      $this->t('Catalog'),
      $this->t('Client type'),
      $this->t('Products'),
      $this->t('Updated'),
      $this->t('Export'),
    ];

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No catalogs available.'),
      '#cache' => [
        'tags' => ['node_list:catalog'],
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Access callback for catalog listing.
   */
  public function accessUserCatalogs(UserInterface $user, AccountInterface $account): AccessResult {
    if ((int) $account->id() === (int) $user->id()) {
      return AccessResult::allowed()->cachePerUser();
    }

    if ($account->hasPermission('view all sangel catalogs')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerPermissions()->cachePerUser();
  }

  /**
   * Builds an export link if the viewer can export the catalog.
   */
  protected function buildExportLink(NodeInterface $node): string {
    $account = $this->currentUser();
    if (!$account->hasPermission('export sangel catalogs')) {
      return '';
    }

    if (!$account->hasPermission('view all sangel catalogs') && !\sangel_catalog_is_assigned($node, $account)) {
      return '';
    }

    return Link::fromTextAndUrl(
      $this->t('Export'),
      Url::fromRoute('sangel_catalog.export', ['node' => $node->id()])
    )->toString();
  }

}
