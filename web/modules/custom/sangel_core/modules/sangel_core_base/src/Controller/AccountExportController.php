<?php

namespace Drupal\sangel_core_base\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;
use Drupal\sangel_core\Service\CatalogExportManager;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Exports catalog and order data for the logged-in client in CSV format.
 */
class AccountExportController extends ControllerBase {

  use CatalogProductDataTrait;

  public function __construct(
    protected readonly CatalogExportManager $catalogExportManager,
    CatalogProductRepositoryInterface $catalogProductRepository,
  ) {
    $this->setCatalogProductRepository($catalogProductRepository);
  }

  public static function create(ContainerInterface $container): static {
    /** @var static $controller */
    /** @var \Drupal\sangel_core\Service\CatalogExportManager $export_manager */
    $export_manager = $container->get('sangel_core.catalog_export_manager');
    /** @var \Drupal\sangel_core\Repository\CatalogProductRepositoryInterface $repository */
    $repository = $container->get('sangel_core.catalog_product_repository');
    $controller = new static($export_manager, $repository);
    $controller->setStringTranslation($container->get('string_translation'));
    $controller->setMessenger($container->get('messenger'));
    $controller->setLoggerFactory($container->get('logger.factory'));
    return $controller;
  }

  /**
   * Exports the current client's catalog as CSV.
   */
  public function exportCatalog(): StreamedResponse {
    return $this->exportCatalogView('product_catalog', 'for_current_user');
  }

  /**
   * Exports the provided view display as CSV.
   */
  public function exportCatalogView(string $view_id, string $display_id): StreamedResponse {
    $this->ensureAuthenticated();

    $filename_prefix = $this->buildFilenamePrefix($view_id, $display_id);
    return $this->exportViewAsCsv($view_id, $display_id, $filename_prefix, [$this, 'buildCatalogRows']);
  }

  /**
   * Exports the current client's order history as CSV.
   */
  public function exportOrders(): StreamedResponse {
    $this->ensureAuthenticated();
    return $this->exportViewAsCsv('orders', 'user_order_history', 'historique-commandes', [$this, 'buildOrderRows']);
  }

  /**
   * Ensures the user is authenticated.
   */
  protected function ensureAuthenticated(): void {
    if ($this->currentUser()->isAnonymous()) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Generic exporter for a view display.
   *
   * @param string $view_id
   *   The view ID.
   * @param string $display_id
   *   The display ID.
   * @param string $filename_prefix
   *   The CSV filename prefix.
   * @param callable $row_builder
   *   Callback generating rows based on the executed view.
   */
  protected function exportViewAsCsv(string $view_id, string $display_id, string $filename_prefix, callable $row_builder): StreamedResponse {
    $view = $this->catalogExportManager->execute($view_id, $display_id);

    $rows = call_user_func($row_builder, $view);

    if (!$rows) {
      throw new BadRequestHttpException('No data found for export.');
    }

    return $this->buildCsvResponse($rows, $filename_prefix);
  }

  /**
   * Generates a safe filename prefix from the view and display identifiers.
   */
  protected function buildFilenamePrefix(string $view_id, string $display_id): string {
    $sanitize = static function (string $value): string {
      $value = strtolower($value);
      $value = preg_replace('/[^a-z0-9_]+/', '-', $value);
      $value = trim($value, '-_');
      return $value !== '' ? $value : 'export';
    };

    $view_slug = $sanitize($view_id);
    $display_slug = $sanitize($display_id);

    return sprintf('%s-%s', $view_slug, $display_slug);
  }

  /**
   * Builds CSV rows for the catalog view results.
   */
  protected function buildCatalogRows($view): array {
    $rows = [
      $this->getProductExportHeader(),
    ];

    foreach ($view->result as $row) {
      $entity = $row->_entity ?? NULL;
      if (!$entity instanceof NodeInterface || $entity->bundle() !== 'product') {
        continue;
      }

      $rows[] = $this->buildProductRow($entity);
    }

    return $rows;
  }

  /**
   * Builds CSV rows for the order history view results.
   */
  protected function buildOrderRows($view): array {
    $rows = [
      [
        $this->t('Commande')->__toString(),
        $this->t('Date')->__toString(),
        $this->t('Statut')->__toString(),
        $this->t('Total (F CFA)')->__toString(),
      ],
    ];

    $state_labels = [
      'draft' => $this->t('Brouillon')->__toString(),
      'submitted' => $this->t('Soumise')->__toString(),
      'processed' => $this->t('Traitée')->__toString(),
    ];

    foreach ($view->result as $row) {
      $entity = $row->_entity ?? NULL;
      if (!$entity instanceof NodeInterface || $entity->bundle() !== 'order') {
        continue;
      }

      $state_value = $entity->hasField('field_state') ? $entity->get('field_state')->value : '';
      $state = $state_labels[$state_value] ?? ucfirst($state_value);

      $total_value = $entity->hasField('field_total') ? $entity->get('field_total')->value : '';
      $total = $total_value !== '' ? number_format((float) $total_value, 0, ',', ' ') : '';

      $created = $entity->getCreatedTime();
      $date_formatted = $created ? $this->dateFormatter()->format($created, 'custom', 'd/m/Y H\hi') : '';

      $rows[] = [
        $entity->label(),
        $date_formatted,
        $state,
        $total,
      ];
    }

    return $rows;
  }

  /**
   * Exports products related to a referenced node (cart, catalog, order, ...).
   */
  public function exportProductsFromNode(NodeInterface $node): StreamedResponse {
    $this->ensureAuthenticated();

    if (!$node->access('view')) {
      throw new AccessDeniedHttpException();
    }

    $products = $this->resolveProductsForNode($node);
    if (!$products) {
      throw new BadRequestHttpException('No products could be determined for the requested node.');
    }

    $rows = [
      $this->getProductExportHeader(),
    ];

    foreach ($products as $product) {
      $rows[] = $this->buildProductRow($product);
    }

    $prefix = sprintf('produits-%s-%d', $node->bundle(), $node->id());
    return $this->buildCsvResponse($rows, $prefix);
  }

  /**
   * Builds the CSV header used for product exports.
   */
  protected function getProductExportHeader(): array {
    return [
      $this->t('Produit')->__toString(),
      $this->t('Référence')->__toString(),
      $this->t('Type de client')->__toString(),
      $this->t('Famille')->__toString(),
      $this->t('Sous-famille')->__toString(),
      $this->t('Prix (F CFA)')->__toString(),
    ];
  }

  /**
   * Normalizes the product data into a CSV row.
   */
  protected function buildProductRow(NodeInterface $product): array {
    $price_value = $product->hasField('field_price') ? $product->get('field_price')->value : '';
    $price = $price_value !== '' ? number_format((float) $price_value, 0, ',', ' ') : '';

    $client_type = '';
    if ($product->hasField('field_client_type') && !$product->get('field_client_type')->isEmpty()) {
      $client_entity = $product->get('field_client_type')->entity;
      $client_type = $client_entity ? $client_entity->label() : '';
    }

    $family = '';
    if ($product->hasField('field_family') && !$product->get('field_family')->isEmpty()) {
      $family_entity = $product->get('field_family')->entity;
      $family = $family_entity ? $family_entity->label() : '';
    }

    $sub_family = '';
    if ($product->hasField('field_sub_family') && !$product->get('field_sub_family')->isEmpty()) {
      $sub_entity = $product->get('field_sub_family')->entity;
      $sub_family = $sub_entity ? $sub_entity->label() : '';
    }

    $sku = '';
    if ($product->hasField('field_sku')) {
      $sku = (string) $product->get('field_sku')->value;
    }

    return [
      $product->label(),
      $sku,
      $client_type,
      $family,
      $sub_family,
      $price,
    ];
  }

  /**
   * Builds the streamed CSV response.
   */
  protected function buildCsvResponse(array $rows, string $filename_prefix): StreamedResponse {
    $timestamp = \Drupal::time()->getCurrentTime();
    $filename = sprintf('%s-%s.csv', $filename_prefix, date('Ymd-His', $timestamp));

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    $response->setCallback(function () use ($rows) {
      $handle = fopen('php://output', 'w');
      // Add BOM for Excel compatibility.
      fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
      foreach ($rows as $row) {
        fputcsv($handle, $row, ';');
      }
      fclose($handle);
    });

    return $response;
  }

  /**
   * Collects product nodes associated with the provided node.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Product nodes keyed by their ID (order preserved).
   */
  protected function resolveProductsForNode(NodeInterface $node): array {
    switch ($node->bundle()) {
      case 'cart':
        return $this->loadProductsFromCart($node);

      case 'catalog':
        return $this->loadProductsFromCatalog($node);

      case 'export':
        return $this->loadReferencedProducts($node, 'field_export_products');

      case 'order':
        return $this->loadProductsFromOrder($node);

      case 'order_item':
        return $this->loadProductsFromOrderItem($node);

      default:
        return [];
    }
  }

  /**
   * Loads product nodes referenced by an entity reference field.
   */
  protected function loadReferencedProducts(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name)) {
      return [];
    }

    $products = [];
    foreach ($node->get($field_name)->referencedEntities() as $entity) {
      if ($entity instanceof NodeInterface && $entity->bundle() === 'product') {
        $products[$entity->id()] = $entity;
      }
    }
    return $products;
  }

  /**
   * Loads product nodes referenced in a catalog JSON field.
   */
  protected function loadProductsFromCatalog(NodeInterface $catalog): array {
    $ids = $this->getCatalogProductIds($catalog);
    if (!$ids) {
      return [];
    }

    $map = [];
    foreach ($ids as $id) {
      $map[$id] = $id;
    }

    return $this->loadProductsByIds($map);
  }

  /**
   * Loads product nodes from a cart node (JSON payload).
   */
  protected function loadProductsFromCart(NodeInterface $cart): array {
    if (!$cart->hasField('field_cart_data')) {
      return [];
    }

    $raw = (string) $cart->get('field_cart_data')->value;
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

    $product_ids = [];
    foreach ($decoded as $product_id => $quantity) {
      $product_id = (int) $product_id;
      $quantity = (int) $quantity;
      if ($product_id > 0 && $quantity > 0) {
        $product_ids[$product_id] = $product_id;
      }
    }

    return $this->loadProductsByIds($product_ids);
  }

  /**
   * Loads product nodes attached to an order through its order items.
   */
  protected function loadProductsFromOrder(NodeInterface $order): array {
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage('node');

    $item_ids = $storage->getQuery()
      ->condition('type', 'order_item')
      ->condition('field_order.target_id', $order->id())
      ->accessCheck(FALSE)
      ->execute();

    if (!$item_ids) {
      return [];
    }

    /** @var \Drupal\node\NodeInterface[] $order_items */
    $order_items = $storage->loadMultiple($item_ids);

    $product_ids = [];
    foreach ($order_items as $order_item) {
      if (!$order_item->hasField('field_product') || $order_item->get('field_product')->isEmpty()) {
        continue;
      }
      $product = $order_item->get('field_product')->entity;
      if ($product instanceof NodeInterface && $product->bundle() === 'product') {
        $product_ids[$product->id()] = $product->id();
      }
    }

    return $this->loadProductsByIds($product_ids);
  }

  /**
   * Loads the product attached directly to an order item node.
   */
  protected function loadProductsFromOrderItem(NodeInterface $order_item): array {
    if (!$order_item->hasField('field_product') || $order_item->get('field_product')->isEmpty()) {
      return [];
    }

    $product = $order_item->get('field_product')->entity;
    if ($product instanceof NodeInterface && $product->bundle() === 'product') {
      return [$product->id() => $product];
    }

    return [];
  }

  /**
   * Loads product nodes for the given identifiers.
   *
   * @param array<int,int> $product_ids
   *   Product IDs keyed by themselves to preserve order.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded products keyed by ID.
   */
  protected function loadProductsByIds(array $product_ids): array {
    if (!$product_ids) {
      return [];
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager()->getStorage('node');
    $loaded = $storage->loadMultiple($product_ids);

    $products = [];
    foreach ($product_ids as $product_id) {
      $product = $loaded[$product_id] ?? NULL;
      if ($product instanceof NodeInterface && $product->bundle() === 'product') {
        $products[$product_id] = $product;
      }
    }

    return $products;
  }

}
