<?php

namespace Drupal\sangelpro_theme\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Inline form to update an order status from the order node page.
 */
class OrderStatusForm extends FormBase {

  /**
   * Order being managed.
   */
  protected ?NodeInterface $order = NULL;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Current user.
   */
  protected AccountInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sangelpro_theme_order_status_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $order = NULL): array {
    $this->order = $order;

    if (!$order instanceof NodeInterface || $order->bundle() !== 'order') {
      $form['#access'] = FALSE;
      return $form;
    }

    if (!\sangelpro_theme_can_manage_order_status($this->currentUser)) {
      $form['#access'] = FALSE;
      return $form;
    }

    $current_state = $order->hasField('field_state') ? (string) $order->get('field_state')->value : 'draft';

    $options = [
      'draft' => $this->t('Brouillon'),
      'submitted' => $this->t('Soumise'),
      'processed' => $this->t('Traitée'),
    ];

    if (!isset($options[$current_state])) {
      $current_state = 'draft';
    }

    $form['#attached']['library'][] = 'sangelpro_theme/global-styling';
    $form['#attributes']['class'][] = 'order-status-form';
    $form['#attributes']['class'][] = 'space-y-4';

    $form['order_id'] = [
      '#type' => 'value',
      '#value' => (int) $order->id(),
    ];

    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('Statut de la commande'),
      '#options' => $options,
      '#default_value' => $current_state,
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['w-full', 'rounded-2xl', 'border', 'border-sangel-ice-strong', 'bg-white', 'px-4', 'py-3', 'text-sm', 'text-sangel-text'],
      ],
      '#title_display' => 'before',
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['pt-2']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Mettre à jour'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => [
          'inline-flex',
          'items-center',
          'justify-center',
          'gap-2',
          'rounded-full',
          'bg-sangel-primary',
          'px-5',
          'py-2.5',
          'text-sm',
          'font-semibold',
          'text-white',
          'transition',
          'hover:bg-sangel-primary-dark',
          'focus:outline-none',
          'focus:ring-2',
          'focus:ring-sangel-primary/30',
          'focus:ring-offset-2',
          'focus:ring-offset-white',
        ],
      ],
    ];

    $form['#cache'] = [
      'contexts' => ['user.roles', 'route'],
      'tags' => $order->getCacheTags(),
      'max-age' => 0,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!\sangelpro_theme_can_manage_order_status($this->currentUser)) {
      return;
    }

    $order_id = (int) $form_state->getValue('order_id');
    $new_state = $form_state->getValue('state');

    if (!$order_id || !$new_state) {
      $this->messenger()->addError($this->t('Impossible de mettre à jour le statut de la commande.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $order */
    $order = $this->entityTypeManager->getStorage('node')->load($order_id);
    if (!$order instanceof NodeInterface || $order->bundle() !== 'order' || !$order->hasField('field_state')) {
      $this->messenger()->addError($this->t('Impossible de trouver la commande demandée.'));
      return;
    }

    $order->set('field_state', $new_state);

    try {
      $order->save();
      $this->messenger()->addStatus($this->t('Le statut de la commande a été mis à jour.'));
    }
    catch (\Exception $exception) {
      $this->messenger()->addError($this->t('Une erreur est survenue lors de la mise à jour du statut.'));
      $this->logger('sangelpro_theme')->error('Order status update failed for order @id: @message', [
        '@id' => $order_id,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
