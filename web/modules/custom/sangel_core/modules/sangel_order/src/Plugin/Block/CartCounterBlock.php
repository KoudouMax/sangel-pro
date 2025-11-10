<?php

namespace Drupal\sangel_order\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\sangel_order\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block showing the number of items in the cart.
 *
 * @Block(
 *   id = "sangel_order_cart_counter",
 *   admin_label = @Translation("Cart counter"),
 *   category = @Translation("Sangel")
 * )
 */
class CartCounterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Cart manager service.
   */
  protected CartManager $cartManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->cartManager = $container->get('sangel_order.cart_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'cart_label' => $this->t('Mon panier'),
      'cart_label_commercial' => $this->t('Ma sélection'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $form['cart_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->configuration['cart_label'] ?? $this->t('Mon panier'),
      '#description' => $this->t('Text displayed under the cart icon.'),
      '#maxlength' => 255,
    ];

    $form['cart_label_commercial'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label for commercial users'),
      '#default_value' => $this->configuration['cart_label_commercial'] ?? $this->t('Ma sélection'),
      '#description' => $this->t('Text displayed for users with the "commercial" role.'),
      '#maxlength' => 255,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['cart_label'] = $form_state->getValue('cart_label') ?: $this->t('Mon panier');
    $this->configuration['cart_label_commercial'] = $form_state->getValue('cart_label_commercial') ?: $this->t('Ma sélection');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $count = $this->cartManager->getItemCount();
    $account = \Drupal::currentUser();
    $label = $this->configuration['cart_label'] ?? $this->t('Mon panier');
    $roles = $account->getRoles();

    if (in_array('commercial', $roles, TRUE)) {
      $label = $this->configuration['cart_label_commercial'] ?? $this->t('Ma sélection');
    }

    return [
      '#theme' => 'sangel_header_counter',
      '#variant' => 'cart',
      '#counter' => $count,
      '#label' => $label,
      '#icon' => 'cart',
      '#url' => Url::fromRoute('sangel_order.cart')->toString(),
      '#attached' => [
        'library' => [
          'sangelpro_theme/product-actions',
        ],
      ],
      '#attributes' => [
        'id' => 'sangel-order-cart-count',
        'class' => ['sangel-header-counter'],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user'],
      ],
    ];
  }

}
