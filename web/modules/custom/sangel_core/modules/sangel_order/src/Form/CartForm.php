<?php

namespace Drupal\sangel_order\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\sangel_order\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to manage cart contents (update quantities / remove lines).
 */
class CartForm extends FormBase {

  protected const WRAPPER_ID = 'sangel-order-cart-wrapper';

  protected CartManager $cartManager;

  protected RendererInterface $renderer;

  public function __construct(CartManager $cart_manager, RendererInterface $renderer) {
    $this->cartManager = $cart_manager;
    $this->renderer = $renderer;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sangel_order.cart_manager'),
      $container->get('renderer')
    );
  }

  public function getFormId(): string {
    return 'sangel_order_cart_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $items = $this->cartManager->getItems();
    $products = $this->cartManager->loadProducts();
    $wrapper_id = self::WRAPPER_ID;

    $form['#attributes']['id'] = $wrapper_id;
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    $form['items'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sangel-order-cart-items']],
      '#tree' => TRUE,
      '#theme' => 'sangel_order_cart_items',
      '#empty_message' => [
        '#markup' => $this->t('Your cart is empty.'),
      ],
    ];

    $has_items = FALSE;

    foreach ($products as $product) {
      $nid = (int) $product->id();
      $quantity = $items[$nid] ?? 0;
      if ($quantity <= 0) {
        continue;
      }

      $unit_price = (float) ($product->get('field_price')->value ?? 0);
      $subtotal = $unit_price * $quantity;

      $has_items = TRUE;

      $form['items'][$nid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sangel-order-cart-item']],
        '#theme' => 'sangel_order_cart_item',
      ];

      $form['items'][$nid]['title'] = [
        '#markup' => $product->toLink()->toString(),
      ];

      $form['items'][$nid]['unit_price'] = [
      '#markup' => number_format($unit_price, 0, ',', ' ') . ' F CFA',
      ];

      $form['items'][$nid]['quantity'] = [
        '#type' => 'number',
        '#title' => $this->t('Quantity'),
        '#title_display' => 'invisible',
        '#min' => 0,
        '#step' => 1,
        '#default_value' => $quantity,
        '#size' => 4,
        '#attributes' => ['class' => ['sangel-order-cart-item__quantity-input']],
      ];

      $form['items'][$nid]['subtotal'] = [
      '#markup' => number_format($subtotal, 0, ',', ' ') . ' F CFA',
      ];

      $form['items'][$nid]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_' . $nid,
        '#limit_validation_errors' => [],
        '#sangel_product_id' => $nid,
        '#submit' => ['::removeItem'],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
        ],
        '#attributes' => ['class' => ['sangel-order-cart-item__remove']],
      ];
    }
    
    if (!$has_items) {
      return $form;
    }

    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sangel-order-cart-summary']],
      'total' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Total : @total F CFA', ['@total' => number_format((float) $this->cartManager->getTotal(), 0, ',', ' ')]),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['update'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update cart'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxRefresh',
      ],
    ];

    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Empty cart'),
      '#submit' => ['::clearCart'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxRefresh',
      ],
    ];

    if ($this->currentUser()->isAuthenticated()) {
      $form['footer'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sangel-order-cart-actions']],
        'checkout' => [
          '#type' => 'link',
          '#title' => $this->t('Proceed to checkout'),
          '#url' => Url::fromRoute('sangel_order.checkout'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'history' => [
          '#type' => 'link',
          '#title' => $this->t('View my orders'),
          '#url' => Url::fromRoute('sangel_order.user_orders', ['user' => $this->currentUser()->id()]),
          '#attributes' => ['class' => ['button', 'button--link']],
        ],
      ];
    }
    else {
      $login_url = Url::fromRoute('user.login', [], ['query' => ['destination' => '/sangel-order/cart']]);
      $form['footer'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sangel-order-cart-actions']],
        'login' => [
          '#markup' => $this->t('Please <a href=":login">log in</a> to checkout.', [':login' => $login_url->toString()]),
        ],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('items', []);
    foreach ($values as $product_id => $row) {
      if (!is_array($row) || !isset($row['quantity'])) {
        continue;
      }
      $this->cartManager->setQuantity((int) $product_id, (int) $row['quantity']);
    }
    $this->messenger()->addStatus($this->t('Cart updated.'));
    $form_state->setRebuild(TRUE);
  }

  public function removeItem(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#sangel_product_id'])) {
      $this->cartManager->removeProduct((int) $trigger['#sangel_product_id']);
      $this->messenger()->addStatus($this->t('Product removed from cart.'));
      $form_state->setRebuild(TRUE);
    }
  }

  public function clearCart(array &$form, FormStateInterface $form_state): void {
    $this->cartManager->clear();
    $this->messenger()->addStatus($this->t('Cart emptied.'));
    $form_state->setRebuild(TRUE);
  }

  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . self::WRAPPER_ID, $this->renderer->renderRoot($form)));

    $counter_render = $this->renderer->renderRoot($this->buildCartCounter());
    $response->addCommand(new ReplaceCommand('#sangel-order-cart-count', $counter_render));

    return $response;
  }

  protected function buildCartCounter(): array {
    $count = $this->cartManager->getItemCount();
    $link_title = $this->formatPlural($count, 'Cart (@count item)', 'Cart (@count items)', ['@count' => $count]);

    $link = Link::fromTextAndUrl(
      $link_title,
      Url::fromRoute('sangel_order.cart')
    )->toRenderable();
    $link['#attributes']['class'][] = 'sangel-order-cart-counter__link';

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'sangel-order-cart-count',
        'class' => ['sangel-order-cart-counter'],
      ],
      'link' => $link,
      '#cache' => ['max-age' => 0],
    ];
  }

}
