<?php

namespace Drupal\sangel_core_base\Plugin\views\field;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\sangel_order\Service\CartManager;
use Drupal\user\UserInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the decoded cart items stored on a cart node.
 *
 * @ViewsField("sangel_cart_items")
 */
class CartItems extends FieldPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Cart manager service.
   */
  protected CartManager $cartManager;

  /**
   * Current user proxy.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->cartManager = $container->get('sangel_order.cart_manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do not add a column to the SQL query. Rendering is handled manually.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity ?? NULL;
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'cart') {
      return [];
    }

    $owner = $entity->getOwner();
    $owner_id = $owner instanceof UserInterface && $owner->id() ? (int) $owner->id() : NULL;
    $owner_name = $owner instanceof UserInterface ? $owner->getDisplayName() : '';
    $is_read_only = $owner_id !== NULL && $owner_id !== (int) $this->currentUser->id();

    if (!$entity->hasField('field_cart_data')) {
      return $this->buildRenderable($entity, [], $owner_id, $owner_name, $is_read_only, $this->t('Panier non disponible.'));
    }

    $raw = (string) $entity->get('field_cart_data')->value;
    $decoded = $raw !== '' ? json_decode($raw, TRUE) : [];

    if ((!is_array($decoded) || $decoded === []) && $owner_id !== NULL && $owner_id === (int) $this->currentUser->id()) {
      $decoded = $this->cartManager->getItems();
    }

    $normalized = [];
    if (is_array($decoded)) {
      foreach ($decoded as $product_id => $quantity) {
        $product_id = (int) $product_id;
        $quantity = (int) $quantity;
        if ($product_id > 0 && $quantity > 0) {
          $normalized[$product_id] = $quantity;
        }
      }
    }

    $is_empty = $normalized === [];

    return $this->buildRenderable(
      $entity,
      $normalized,
      $owner_id,
      $owner_name,
      $is_read_only,
      $is_empty ? $this->t('Panier vide') : NULL
    );
  }

  /**
   * Builds the render array passed to the twig template.
   */
  protected function buildRenderable(NodeInterface $entity, array $raw_items, ?int $owner_id, string $owner_name, bool $is_read_only, $empty_text = NULL): array {
    return [
      '#theme' => 'sangel_core_cart_items',
      '#items' => [],
      '#cart_raw_items' => $raw_items,
      '#cart_owner_id' => $owner_id,
      '#cart_owner_name' => $owner_name,
      '#cart_is_read_only' => $is_read_only,
      '#empty_text' => $empty_text ?? $this->t('Panier vide'),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $entity->getCacheTags(),
      ],
      '#attached' => [
        'library' => ['core/drupal.ajax'],
      ],
    ];
  }

}
