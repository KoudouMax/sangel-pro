<?php

declare(strict_types=1);

namespace Drupal\sangel_core\Repository;

use Drupal\node\NodeInterface;

/**
 * Provides read/write helpers for catalog product data storage.
 */
interface CatalogProductRepositoryInterface {

  /**
   * Returns the product IDs stored on the given catalog.
   *
   * @return int[]
   *   Product node IDs in their stored order.
   */
  public function getProductIds(NodeInterface $catalog): array;

  /**
   * Persists the provided product IDs on the catalog.
   *
   * @param int[] $product_ids
   *   Product node identifiers.
   */
  public function setProductIds(NodeInterface $catalog, array $product_ids): void;

  /**
   * Loads products referenced by the catalog.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded product nodes keyed by ID.
   */
  public function loadProducts(NodeInterface $catalog): array;

  /**
   * Loads a subset of products referenced by the catalog.
   *
   * @param int[] $product_ids
   *   Identifiers to load.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded product nodes keyed by ID.
   */
  public function loadProductsSubset(NodeInterface $catalog, array $product_ids): array;

}

