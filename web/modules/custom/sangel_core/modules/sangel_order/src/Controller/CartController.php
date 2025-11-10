<?php

namespace Drupal\sangel_order\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sangel_order\Form\CartForm;
use Drupal\sangel_order\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides cart-related pages.
 */
class CartController extends ControllerBase {

  /**
   * Cart manager service.
   */
  protected CartManager $cartManager;

  /**
   * Constructs the controller.
   */
  public function __construct(CartManager $cart_manager) {
    $this->cartManager = $cart_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sangel_order.cart_manager')
    );
  }

  /**
   * Displays the current cart contents.
   */
  public function view(): array {
    $items = $this->cartManager->getItems();
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sangel-order-cart-page']],
      '#cache' => ['max-age' => 0],
    ];

    if (!$items) {
      $build['form'] = $this->formBuilder()->getForm(CartForm::class);
      return $build;
    }

    $build['form'] = $this->formBuilder()->getForm(CartForm::class);

    return $build;
  }

}
