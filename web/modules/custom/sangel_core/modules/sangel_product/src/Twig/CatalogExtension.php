<?php

namespace Drupal\sangel_product\Twig;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;
use Drupal\node\NodeInterface;
use Drupal\sangel_product\Service\CatalogSelectionManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension exposing catalog helper functions.
 */
class CatalogExtension extends AbstractExtension {

  /**
   * Catalog selection manager.
   */
  protected CatalogSelectionManager $selectionManager;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Current user proxy.
   */
  protected AccountProxyInterface $currentUser;

  use CatalogProductDataTrait;

  /**
   * Constructs the extension.
   */
  public function __construct(CatalogSelectionManager $selection_manager, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user, CatalogProductRepositoryInterface $catalog_product_repository) {
    $this->selectionManager = $selection_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->setCatalogProductRepository($catalog_product_repository);
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('is_in_catalog', [$this, 'isInCatalog']),
      new TwigFunction('product_count_by_client_type', [$this, 'getProductCountByClientType']),
      new TwigFunction('catalog_export_url', [$this, 'getCatalogExportUrl']),
      new TwigFunction('user_catalog_export_url', [$this, 'getUserCatalogExportUrl']),
      new TwigFunction('export_products_url', [$this, 'getExportProductsUrl']),
      new TwigFunction('selection_has_items', [$this, 'selectionHasItems']),
    ];
  }

  /**
   * Checks if the given product is already in the user's catalog.
   */
  public function isInCatalog(int $product_id): bool {
    $items = $this->selectionManager->getItems();
    return in_array($product_id, $items, TRUE);
  }

  /**
   * Counts published products for a given client type term ID.
   */
  public function getProductCountByClientType(int $client_type_tid): int {
    if ($client_type_tid <= 0) {
      return 0;
    }

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'product')
      ->condition('status', 1)
      ->condition('field_client_type', $client_type_tid)
      ->accessCheck(FALSE)
      ->count();

    return (int) $query->execute();
  }

  /**
   * Returns the export URL for the first catalog referencing the product.
   */
  public function getCatalogExportUrl(int $product_id): string {
    if ($product_id <= 0) {
      return '';
    }

    $connection = Database::getConnection();
    $schema = $connection->schema();

    if ($schema->tableExists('sangel_catalog_product')) {
      $catalog_id = $connection->select('sangel_catalog_product', 'scp')
        ->fields('scp', ['catalog_nid'])
        ->condition('product_nid', $product_id)
        ->orderBy('weight', 'ASC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($catalog_id) {
        return Url::fromRoute('sangel_catalog.export', ['node' => (int) $catalog_id])->toString();
      }

      return '';
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'catalog')
      ->accessCheck(FALSE);

    $ids = $query->execute();
    if (!$ids) {
      return '';
    }

    $catalogs = $storage->loadMultiple($ids);
    foreach ($catalogs as $catalog_id => $catalog) {
      if (!$catalog instanceof NodeInterface) {
        continue;
      }
      $product_ids = $this->getCatalogProductIds($catalog);
      if (in_array($product_id, $product_ids, TRUE)) {
        return Url::fromRoute('sangel_catalog.export', ['node' => $catalog_id])->toString();
      }
    }

    return '';
  }


  /**
   * Returns the export URL for the current user catalog.
   */
  public function getUserCatalogExportUrl(): string {
    if (!$this->currentUser->isAuthenticated()) {
      return '';
    }

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'catalog')
      ->condition('uid', (int) $this->currentUser->id())
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->sort('changed', 'DESC');

    $ids = $query->execute();
    if (!$ids) {
      return '';
    }

    $catalog_id = reset($ids);
    return Url::fromRoute('sangel_catalog.export', ['node' => $catalog_id])->toString();
  }

  /**
   * Returns the export URL for a given export node.
   */
  public function getExportProductsUrl(int $export_id): string {
    if ($export_id <= 0) {
      return '';
    }

    try {
      return Url::fromRoute('sangel_catalog.export_export_products', ['export' => $export_id])->toString();
    }
    catch (\Throwable $throwable) {
      return '';
    }
  }

  /**
   * Indicates whether the current selection contains items.
   */
  public function selectionHasItems(): bool {
    return !empty($this->selectionManager->getItems());
  }
}
