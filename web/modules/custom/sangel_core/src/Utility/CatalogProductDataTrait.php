<?php

declare(strict_types=1);

namespace Drupal\sangel_core\Utility;

use Drupal\node\NodeInterface;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;

/**
 * Provides helpers to manage catalog product data stored as JSON.
 */
trait CatalogProductDataTrait {

  /**
   * Catalog product repository instance.
   */
  protected ?CatalogProductRepositoryInterface $catalogProductRepository = NULL;

  /**
   * Returns the product IDs stored on the catalog.
   *
   * @return int[]
   *   Unique product node IDs in their stored order.
   */
  protected function getCatalogProductIds(NodeInterface $catalog): array {
    return $this->catalogProductRepository()->getProductIds($catalog);
  }

  /**
   * Persists product IDs on the catalog node.
   *
   * @param int[] $product_ids
   *   Product node IDs to store.
   */
  protected function setCatalogProductIds(NodeInterface $catalog, array $product_ids): void {
    $this->catalogProductRepository()->setProductIds($catalog, $product_ids);
  }

  /**
   * Loads product nodes referenced by the catalog.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded product nodes keyed by their ID.
   */
  protected function loadCatalogProducts(NodeInterface $catalog): array {
    return $this->catalogProductRepository()->loadProducts($catalog);
  }

  /**
   * Loads a subset of catalog products preserving catalog order.
   *
   * @param \Drupal\node\NodeInterface $catalog
   *   Catalog node.
   * @param int[] $product_ids
   *   Product identifiers to load.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded product nodes keyed by their ID.
   */
  protected function loadCatalogProductsSubset(NodeInterface $catalog, array $product_ids): array {
    return $this->catalogProductRepository()->loadProductsSubset($catalog, $product_ids);
  }

  /**
   * Allows inheriting classes to inject the catalog repository.
   */
  protected function setCatalogProductRepository(CatalogProductRepositoryInterface $repository): void {
    $this->catalogProductRepository = $repository;
  }

  /**
   * Returns the catalog product repository service.
   */
  protected function catalogProductRepository(): CatalogProductRepositoryInterface {
    if (!$this->catalogProductRepository instanceof CatalogProductRepositoryInterface) {
      /** @var \Drupal\sangel_core\Repository\CatalogProductRepositoryInterface $repository */
      $repository = \Drupal::service('sangel_core.catalog_product_repository');
      $this->catalogProductRepository = $repository;
    }
    return $this->catalogProductRepository;
  }

}
