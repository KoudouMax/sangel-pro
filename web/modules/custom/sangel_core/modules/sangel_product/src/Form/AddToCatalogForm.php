<?php

namespace Drupal\sangel_product\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\sangel_product\Service\CatalogSelectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AJAX form to add a product to the user's catalog selection.
 */
class AddToCatalogForm extends FormBase {

  /**
   * Wrapper ID for AJAX messages.
   */
  protected string $wrapperId;

  /**
   * Selection manager service.
   */
  protected CatalogSelectionManager $selectionManager;

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
  public function __construct(CatalogSelectionManager $selection_manager, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, BlockManagerInterface $block_manager) {
    $this->selectionManager = $selection_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->blockManager = $block_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sangel_product.catalog_selection_manager'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('plugin.manager.block'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sangel_product_add_to_catalog_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $product_id = NULL): array {
    $form['product_id'] = [
      '#type' => 'value',
      '#value' => (int) $product_id,
    ];

    $this->wrapperId = 'sangel-product-add-to-catalog-message-' . ((int) $product_id ?: 'default');

    $form['#attributes']['class'][] = 'product-action-form product-action-form--catalog';

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['product-action-form__actions']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ajouter à mon catalogue'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => $this->wrapperId,
      ],
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['product-action-form__submit product-action-form__submit--catalog'],
      ],
    ];

    $form['message'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $this->wrapperId,
        'class' => ['product-action-form__message'],
      ],
    ];

    if ($form_state->has('sangel_product_response')) {
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->isAuthenticated()) {
      $this->messenger()->addWarning($this->t('Veuillez vous connecter pour gérer votre catalogue personnalisé.'));
      return;
    }

    $product_id = (int) $form_state->getValue('product_id');
    if (!$product_id) {
      $this->messenger()->addError($this->t('Identifiant de produit manquant.'));
      return;
    }

    $product = $this->entityTypeManager->getStorage('node')->load($product_id);
    if (!$product || $product->bundle() !== 'product') {
      $this->messenger()->addError($this->t('Produit introuvable.'));
      return;
    }

    $already = in_array($product_id, $this->selectionManager->getItems(), TRUE);
    $added = $this->selectionManager->addProduct($product);

    if ($added) {
      $this->messenger()->addStatus($this->t('@title a été ajouté à votre catalogue.', [
        '@title' => $product->label(),
      ]));
    }
    elseif ($already) {
      $this->messenger()->addWarning($this->t('@title est déjà présent dans votre catalogue.', [
        '@title' => $product->label(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Impossible d’ajouter @title à votre catalogue pour le moment.', [
        '@title' => $product->label(),
      ]));
    }

    $form_state->set('sangel_product_response', TRUE);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback returning the replacement commands.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    $message_rendered = $this->renderer->renderRoot($form['message']);
    $response->addCommand(new ReplaceCommand('#' . $this->wrapperId, $message_rendered));

    $counter_rendered = $this->renderer->renderRoot($this->buildCounter());
    $response->addCommand(new ReplaceCommand('#sangel-product-catalog-count', $counter_rendered));

    return $response;
  }

  /**
   * Builds the counter render array.
   */
  protected function buildCounter(): array {
    $block = $this->blockManager->createInstance('sangel_product_catalog_counter');
    return $block->build();
  }

}
