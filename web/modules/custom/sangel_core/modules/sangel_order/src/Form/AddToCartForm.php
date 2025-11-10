<?php

namespace Drupal\sangel_order\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Component\Utility\Html;
use Drupal\sangel_order\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AJAX form to add a product to the cart.
 */
class AddToCartForm extends FormBase {

  /**
   * Wrapper ID for AJAX messages.
   */
  protected string $wrapperId;

  /**
   * Cart manager service.
   */
  protected CartManager $cartManager;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Renderer service.
   */
  protected RendererInterface $renderer;

  /**
   * Block manager service.
   */
  protected BlockManagerInterface $blockManager;

  /**
   * Constructs the form.
   */
  public function __construct(CartManager $cart_manager, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, BlockManagerInterface $block_manager) {
    $this->cartManager = $cart_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->blockManager = $block_manager;
    $this->wrapperId = Html::getUniqueId('sangel-order-add-to-cart-message');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sangel_order.cart_manager'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('plugin.manager.block'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sangel_order_add_to_cart_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $product_id = NULL): array {
    $form['product_id'] = [
      '#type' => 'value',
      '#value' => (int) $product_id,
    ];

    $form['#attributes']['class'][] = 'product-action-form product-action-form--cart';

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantité'),
      '#title_display' => 'invisible',
      '#default_value' => 1,
      '#min' => 1,
      '#step' => 1,
      '#attributes' => [
        'class' => ['product-action-form__quantity'],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['product-action-form__actions']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ajouter au panier'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => $this->wrapperId,
      ],
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['product-action-form__submit']],
    ];

    $form['message'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $this->wrapperId, 'class' => ['product-action-form__message']],
    ];

    if ($form_state->has('sangel_order_response')) {
      $form['message']['status'] = [
        '#type' => 'status_messages',
        '#weight' => 0,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $product_id = (int) $form_state->getValue('product_id');
    $quantity = (int) $form_state->getValue('quantity', 1);
    if ($quantity < 1) {
      $form_state->setErrorByName('quantity', $this->t('Veuillez sélectionner une quantité valide.'));
    }
    if (!$product_id) {
      $form_state->setErrorByName('product_id', $this->t('Missing product identifier.'));
      return;
    }

    $product = $this->entityTypeManager->getStorage('node')->load($product_id);
    if (!$product || $product->bundle() !== 'product') {
      $form_state->setErrorByName('product_id', $this->t('The selected product is not available.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $product_id = (int) $form_state->getValue('product_id');
    $quantity = max(1, (int) $form_state->getValue('quantity', 1));
    $product = $this->entityTypeManager->getStorage('node')->load($product_id);
    if (!$product) {
      $this->messenger()->addError($this->t('Unable to load the product.'));
      return;
    }

    $new_quantity = $this->cartManager->addProduct($product, $quantity);
    $total_items = $this->cartManager->getItemCount();

    $this->messenger()->addMessage($this->t('@title added to cart. Quantity now: @quantity. Items in cart: @count.', [
      '@title' => $product->label(),
      '@quantity' => $new_quantity,
      '@count' => $total_items,
    ]), MessengerInterface::TYPE_STATUS);

    $form_state->set('sangel_order_response', TRUE);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback returning the message container.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $message_rendered = $this->renderer->renderRoot($form['message']);
    $response->addCommand(new ReplaceCommand('#' . $this->wrapperId, $message_rendered));

    $counter_rendered = $this->renderer->renderRoot($this->buildCartCounter());
    $response->addCommand(new ReplaceCommand('#sangel-order-cart-count', $counter_rendered));

    return $response;
  }

  /**
   * Builds the cart counter render array.
   */
  protected function buildCartCounter(): array {
    $block = $this->blockManager->createInstance('sangel_order_cart_counter');
    return $block->build();
  }

}
