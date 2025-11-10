<?php

namespace Drupal\sangel_order\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\sangel_order\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a checkout form to create an order.
 */
class CheckoutForm extends FormBase {

  /**
   * Cart manager service.
   */
  protected CartManager $cartManager;

  /**
   * Entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the form.
   */
  public function __construct(CartManager $cart_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->cartManager = $cart_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sangel_order.cart_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sangel_order_checkout_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $items = $this->cartManager->getItems();

    if (!$items) {
      $form['empty'] = [
        '#markup' => $this->t('Your cart is empty.'),
      ];
      $form['back_link'] = [
        '#type' => 'markup',
        '#markup' => Link::fromTextAndUrl($this->t('Return to cart'), Url::fromRoute('sangel_order.cart'))->toString(),
      ];
      return $form;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirmer ma commande'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Modifier mon panier'),
      '#url' => Url::fromRoute('sangel_order.cart'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      $this->messenger()->addError($this->t('Please log in before placing an order.'));
      $form_state->setRedirect('user.login', [], ['query' => ['destination' => '/sangel-order/cart/checkout']]);
      return;
    }

    $owner = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$owner) {
      $this->messenger()->addError($this->t('Unable to load the current account.'));
      return;
    }

    try {
      $order = $this->cartManager->createOrder($owner);
    }
    catch (\Exception $exception) {
      $this->messenger()->addError($this->t('An error occurred while creating the order: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return;
    }

    if (!$order) {
      $this->messenger()->addWarning($this->t('Your cart is empty.'));
      $form_state->setRedirect('sangel_order.cart');
      return;
    }

    $this->messenger()->addMessage($this->t('Order created successfully.'), MessengerInterface::TYPE_STATUS);
    $form_state->setRedirect('entity.node.canonical', ['node' => $order->id()]);
  }

  /**
   * Formats numeric amounts.
   */
  protected function formatCurrency(float $amount): string {
    return number_format($amount, 2, '.', ' ');
  }

}
