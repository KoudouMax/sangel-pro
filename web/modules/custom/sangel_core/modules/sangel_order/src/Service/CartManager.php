<?php

namespace Drupal\sangel_order\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Manages user carts (node-backed for authenticated users, session fallback otherwise).
 */
class CartManager {

  /**
   * Session key used to store cart items.
   */
  public const SESSION_KEY = 'sangel_order.cart';

  /**
   * Injected request stack.
   */
  protected RequestStack $requestStack;

  /**
   * Entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Current user proxy.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Cached cart node.
   */
  protected ?NodeInterface $cartNode = NULL;

  /**
   * Constructs the cart manager.
   */
  public function __construct(RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user, LoggerInterface $logger, TimeInterface $time) {
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger;
    $this->time = $time;
  }

  /**
   * Returns the current cart contents keyed by product node ID.
   *
   * @return array<int,int>
   *   Item quantities indexed by product node ID.
   */
  public function getItems(): array {
    if ($cart = $this->loadCartNode(FALSE)) {
      return $this->readCartData($cart);
    }
    return $this->getSession()->get(self::SESSION_KEY, []);
  }

  /**
   * Persists the given items to the session.
   *
   * @param array<int,int> $items
   *   Item quantities indexed by product node ID.
   */
  protected function setItems(array $items): void {
    $normalized = [];
    foreach ($items as $id => $quantity) {
      $id = (int) $id;
      $quantity = (int) $quantity;
      if ($id > 0 && $quantity > 0) {
        $normalized[$id] = $quantity;
      }
    }

    if ($cart = $this->loadCartNode(!empty($normalized))) {
      $this->writeCartData($cart, $normalized);
      return;
    }

    $this->getSession()->set(self::SESSION_KEY, $normalized);
  }

  /**
   * Adds a product to the cart, increasing quantity if present.
   *
   * @param \Drupal\node\NodeInterface $product
   *   The product node to add.
   *
   * @return int
   *   The updated quantity for the product.
   */
  public function addProduct(NodeInterface $product, int $quantity = 1): int {
    if ($product->bundle() !== 'product') {
      $this->logger->warning('Attempted to add non-product node @nid to cart.', ['@nid' => $product->id()]);
      return 0;
    }

    $items = $this->getItems();
    $nid = (int) $product->id();
    $quantity = max(1, $quantity);
    $items[$nid] = ($items[$nid] ?? 0) + $quantity;
    $this->setItems($items);
    return $items[$nid];
  }

  /**
   * Sets the quantity for the given product.
   */
  public function setQuantity(int $product_id, int $quantity): int {
    $product_id = (int) $product_id;
    $quantity = (int) $quantity;
    $items = $this->getItems();
    if ($quantity <= 0) {
      unset($items[$product_id]);
      $this->setItems($items);
      return 0;
    }

    $items[$product_id] = $quantity;
    $this->setItems($items);
    return $items[$product_id];
  }

  /**
   * Removes a product line from the cart.
   */
  public function removeProduct(int $product_id): void {
    $items = $this->getItems();
    if (isset($items[$product_id])) {
      unset($items[$product_id]);
      $this->setItems($items);
    }
  }

  /**
   * Clears the cart.
   */
  public function clear(): void {
    if ($cart = $this->loadCartNode(FALSE)) {
      $this->writeCartData($cart, []);
    }
    $this->getSession()->remove(self::SESSION_KEY);
  }

  /**
   * Returns the number of lines kept in the cart.
   */
  public function getItemCount(): int {
    return array_sum($this->getItems());
  }

  /**
   * Loads product entities currently listed in the cart.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded product nodes.
   */
  public function loadProducts(): array {
    $ids = array_keys($this->getItems());
    if (!$ids) {
      return [];
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $products = $storage->loadMultiple($ids);

    // Ensure we only return product bundle nodes.
    return array_filter($products, static function ($entity) {
      return $entity instanceof NodeInterface && $entity->bundle() === 'product';
    });
  }

  /**
   * Calculates the total for the current cart contents.
   *
   * @return string
   *   Total formatted as a numeric string with 2 decimals.
   */
  public function getTotal(): string {
    $items = $this->getItems();
    if (!$items) {
      return '0.00';
    }

    $total = 0.0;
    $products = $this->loadProducts();
    foreach ($products as $product) {
      $nid = (int) $product->id();
      $quantity = $items[$nid] ?? 0;
      if ($quantity <= 0) {
        continue;
      }

      $price_value = (float) ($product->get('field_price')->value ?? 0);
      $total += $price_value * $quantity;
    }

    return number_format($total, 2, '.', '');
  }

  /**
   * Creates an order node with the current cart contents.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The user who owns the order.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The created order, or NULL if no items were in the cart.
   */
  public function createOrder(UserInterface $owner): ?NodeInterface {
    $items = $this->getItems();
    if (!$items) {
      return NULL;
    }

    $total = $this->getTotal();
    $products = $this->loadProducts();

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $initial_title = $this->generateOrderTitle();
    $order = $storage->create([
      'type' => 'order',
      'title' => $initial_title,
      'field_state' => 'submitted',
      'field_total' => $total,
      'field_owner' => $owner->id(),
      'uid' => $owner->id(),
      'langcode' => $owner->getPreferredLangcode(),
    ]);

    try {
      $order->save();
      $prefixed_title = $this->generateOrderTitle($order);
      if ($prefixed_title !== $order->label()) {
        $order->setTitle($prefixed_title);
        $order->setNewRevision(FALSE);
        $order->save();
      }
      $this->persistOrderItems($order, $items, $products);
      $this->clear();
      if ($cart = $this->loadCartNode(FALSE)) {
        $this->writeCartData($cart, []);
      }
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to save order: @message', ['@message' => $exception->getMessage()]);
      throw $exception;
    }

    return $order;
  }

  /**
   * Generates an order title, optionally prefixed with the order node ID.
   *
   * @param \Drupal\node\NodeInterface|null $order
   *   (optional) The order node, used to prefix the title with its nid.
   */
  protected function generateOrderTitle(?NodeInterface $order = NULL): string {
    $timestamp = $this->time->getCurrentTime();
    $suffix = date('Ymd-His', $timestamp);
    if ($order instanceof NodeInterface && $order->id()) {
      return sprintf('%s-%s', $order->id(), $suffix);
    }
    return $suffix;
  }

  /**
   * Persists each cart line as an order item record.
   *
   * @param \Drupal\node\NodeInterface $order
   *   The saved order node.
   * @param array<int,int> $items
   *   Quantities keyed by product node ID.
   * @param \Drupal\node\NodeInterface[] $products
   *   Loaded product entities keyed by node ID.
   */
  protected function persistOrderItems(NodeInterface $order, array $items, array $products): void {
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');

    foreach ($items as $product_id => $quantity) {
      if ($quantity <= 0) {
        continue;
      }
      $product = $products[$product_id] ?? NULL;
      if (!$product) {
        $product = $this->entityTypeManager->getStorage('node')->load($product_id);
        if (!$product || $product->bundle() !== 'product') {
          continue;
        }
      }
      $unit_price = (float) ($product->get('field_price')->value ?? 0);
      $total_price = $unit_price * $quantity;

      /** @var \Drupal\node\NodeInterface $order_item */
      $order_item = $storage->create([
        'type' => 'order_item',
        'title' => $product->label(),
        'status' => NodeInterface::NOT_PUBLISHED,
        'uid' => $order->getOwnerId(),
        'field_order' => ['target_id' => $order->id()],
        'field_product' => ['target_id' => $product->id()],
        'field_product_title' => $product->label(),
        'field_product_sku' => $product->hasField('field_sku') ? (string) $product->get('field_sku')->value : '',
        'field_quantity' => (int) $quantity,
        'field_unit_price' => number_format($unit_price, 2, '.', ''),
        'field_total_price' => number_format($total_price, 2, '.', ''),
      ]);
      $order_item->save();
    }
  }

  /**
   * Loads order items for the provided order.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The order items, keyed by ID.
   */
  public function loadOrderItems(NodeInterface $order): array {
    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'order_item')
      ->condition('field_order.target_id', $order->id())
      ->sort('created')
      ->accessCheck(FALSE)
      ->execute();

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Helper to grab the current session.
   */
  protected function getSession(): SessionInterface {
    return $this->requestStack->getCurrentRequest()->getSession();
  }

  /**
   * Loads or creates the cart node for the current user.
   */
  protected function loadCartNode(bool $create_if_missing = FALSE): ?NodeInterface {
    if (!$this->currentUser->isAuthenticated()) {
      return NULL;
    }

    if ($this->cartNode instanceof NodeInterface) {
      return $this->cartNode;
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'cart')
      ->condition('uid', $this->currentUser->id())
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $cart = $storage->load(reset($ids));
      if ($cart instanceof NodeInterface) {
        $this->cartNode = $cart;
        return $this->cartNode;
      }
    }

    if (!$create_if_missing) {
      return NULL;
    }

    $title = sprintf('Cart of %s', $this->currentUser->getAccountName());
    /** @var \Drupal\node\NodeInterface $cart */
    $cart = $storage->create([
      'type' => 'cart',
      'title' => $title,
      'uid' => $this->currentUser->id(),
      'status' => NodeInterface::NOT_PUBLISHED,
      'langcode' => $this->currentUser->getPreferredLangcode(),
      'field_cart_data' => '',
    ]);

    try {
      $cart->save();
      $this->cartNode = $cart;
      return $this->cartNode;
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to create cart node: @message', ['@message' => $exception->getMessage()]);
      return NULL;
    }
  }

  /**
   * Reads cart data stored on a cart node.
   *
   * @return array<int,int>
   *   Quantities keyed by product node ID.
   */
  protected function readCartData(NodeInterface $cart): array {
    if (!$cart->hasField('field_cart_data')) {
      return [];
    }
    $raw = (string) $cart->get('field_cart_data')->value;
    if ($raw === '') {
      return [];
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      return [];
    }
    $normalized = [];
    foreach ($data as $id => $quantity) {
      $id = (int) $id;
      $quantity = (int) $quantity;
      if ($id > 0 && $quantity > 0) {
        $normalized[$id] = $quantity;
      }
    }
    return $normalized;
  }

  /**
   * Persists cart data on the given cart node.
   *
   * @param \Drupal\node\NodeInterface $cart
   *   The cart node to update.
   * @param array<int,int> $items
   *   Quantities keyed by product node ID.
   */
  protected function writeCartData(NodeInterface $cart, array $items): void {
    if (!$cart->hasField('field_cart_data')) {
      return;
    }

    if ($items) {
      ksort($items);
    }
    $cart->set('field_cart_data', $items ? json_encode($items) : '');

    try {
      $cart->save();
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to persist cart node: @message', ['@message' => $exception->getMessage()]);
      // Fallback to session to avoid data loss.
      $this->getSession()->set(self::SESSION_KEY, $items);
    }
  }

}
