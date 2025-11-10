<?php

declare(strict_types=1);

namespace Drupal\sangel_core\Repository;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

/**
 * Default catalog product repository implementation.
 */
class CatalogProductRepository implements CatalogProductRepositoryInterface {

  /**
   * Constructs the repository.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getProductIds(NodeInterface $catalog): array {
    if (!$catalog->hasField('field_product_data')) {
      return [];
    }

    $raw = (string) $catalog->get('field_product_data')->value;
    if ($raw === '') {
      return [];
    }

    try {
      $decoded = Json::decode($raw);
    }
    catch (\InvalidArgumentException $exception) {
      return [];
    }

    if (!is_array($decoded)) {
      return [];
    }

    $ids = [];
    foreach ($decoded as $value) {
      $product_id = (int) $value;
      if ($product_id > 0) {
        $ids[$product_id] = $product_id;
      }
    }

    return array_values($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function setProductIds(NodeInterface $catalog, array $product_ids): void {
    if (!$catalog->hasField('field_product_data')) {
      return;
    }

    $normalized = [];
    foreach ($product_ids as $value) {
      $product_id = (int) $value;
      if ($product_id > 0) {
        $normalized[$product_id] = $product_id;
      }
    }

    $values = array_values($normalized);
    $catalog->set('field_product_data', $values ? Json::encode($values) : '');
    $this->syncMappingTable($catalog, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function loadProducts(NodeInterface $catalog): array {
    $ids = $this->getProductIds($catalog);
    if (!$ids) {
      return [];
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple($ids);

    $products = [];
    foreach ($ids as $product_id) {
      $product = $loaded[$product_id] ?? NULL;
      if ($product instanceof NodeInterface && $product->bundle() === 'product') {
        $products[$product_id] = $product;
      }
    }

    return $products;
  }

  /**
   * {@inheritdoc}
   */
  public function loadProductsSubset(NodeInterface $catalog, array $product_ids): array {
    $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
    if (!$product_ids) {
      return [];
    }

    $ordered_ids = $product_ids;
    if ($this->database->schema()->tableExists('sangel_catalog_product')) {
      $result = $this->database->select('sangel_catalog_product', 'scp')
        ->fields('scp', ['product_nid'])
        ->condition('catalog_nid', (int) $catalog->id())
        ->condition('product_nid', $product_ids, 'IN')
        ->orderBy('weight')
        ->execute()
        ->fetchCol();

      if ($result) {
        $ordered_ids = array_map('intval', $result);
      }
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple($ordered_ids);

    $products = [];
    foreach ($ordered_ids as $id) {
      $product = $loaded[$id] ?? NULL;
      if ($product instanceof NodeInterface && $product->bundle() === 'product') {
        $products[$id] = $product;
      }
    }

    return $products;
  }

  /**
   * Synchronises the mapping table with the provided IDs.
   */
  protected function syncMappingTable(NodeInterface $catalog, array $product_ids): void {
    if (!$this->database->schema()->tableExists('sangel_catalog_product')) {
      return;
    }

    $catalog_id = (int) $catalog->id();

    $this->database->delete('sangel_catalog_product')
      ->condition('catalog_nid', $catalog_id)
      ->execute();

    if (!$product_ids) {
      return;
    }

    $insert = $this->database->insert('sangel_catalog_product')
      ->fields(['catalog_nid', 'product_nid', 'weight']);

    $weight = 0;
    foreach ($product_ids as $product_id) {
      $product_id = (int) $product_id;
      if ($product_id <= 0) {
        continue;
      }
      $insert->values([
        'catalog_nid' => $catalog_id,
        'product_nid' => $product_id,
        'weight' => $weight++,
      ]);
    }

    try {
      $insert->execute();
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to persist catalog/product mapping for @catalog: @message', [
        '@catalog' => $catalog_id,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}

