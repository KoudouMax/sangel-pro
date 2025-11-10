<?php

namespace Drupal\sangel_catalog\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AJAX form allowing editors to remove products from a catalog.
 */
class CatalogProductsForm extends FormBase {

  /**
   * Wrapper ID suffix used for AJAX refresh.
   */
  protected const WRAPPER_PREFIX = 'sangel-catalog-products';

  /**
   * Entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Renderer service.
   */
  protected RendererInterface $renderer;

  /**
   * The catalog being edited.
   */
  protected ?NodeInterface $catalog = NULL;

  use CatalogProductDataTrait;

  /**
   * Constructs the form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, CatalogProductRepositoryInterface $catalog_product_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->setCatalogProductRepository($catalog_product_repository);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('sangel_core.catalog_product_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sangel_catalog_products_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $catalog = NULL): array {
    $this->catalog = $catalog;

    if (!$catalog instanceof NodeInterface || $catalog->bundle() !== 'catalog' || !$catalog->access('update')) {
      $form['#access'] = FALSE;
      return $form;
    }

    $form_state->set('catalog_id', $catalog->id());

    $wrapper_id = self::WRAPPER_PREFIX . '-' . $catalog->id();
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';
    $form['#attributes']['class'][] = 'sangel-catalog-products-form';
    $form['#cache']['max-age'] = 0;

    $products = $this->loadCatalogProducts($catalog);
    $form['items'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sangel-catalog-products']],
      '#theme' => 'sangel_catalog_products',
      '#empty_message' => [
        '#markup' => $this->t('This catalog does not contain any products yet.'),
      ],
    ];

    $has_items = FALSE;

    foreach ($products as $product) {
      $nid = (int) $product->id();
      $client_type = $product->hasField('field_client_type') ? $product->get('field_client_type')->entity : NULL;
      $price = (float) ($product->get('field_price')->value ?? 0);

      $has_items = TRUE;

      $form['items'][$nid] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sangel-catalog-product']],
        '#theme' => 'sangel_catalog_product',
      ];

      $form['items'][$nid]['title'] = [
        '#markup' => $product->toLink()->toString(),
      ];
      $form['items'][$nid]['client_type'] = [
        '#markup' => $client_type ? $client_type->label() : $this->t('—'),
      ];
      $form['items'][$nid]['price'] = [
        '#markup' => number_format($price, 2, ',', ' ') . ' F CFA',
      ];
      $form['items'][$nid]['actions'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_' . $nid,
        '#limit_validation_errors' => [],
        '#sangel_product_id' => $nid,
        '#ajax' => [
          'callback' => '::ajaxRefresh',
        ],
        '#submit' => ['::removeItem'],
        '#attributes' => ['class' => ['sangel-catalog-product__remove']],
      ];
    }

    return $form;
  }

  /**
   * Submit handler for removal buttons.
   */
  public function removeItem(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $product_id = (int) ($trigger['#sangel_product_id'] ?? 0);
    if ($product_id <= 0) {
      return;
    }

    $catalog = $this->loadCatalogFromState($form_state);
    if (!$catalog || !$catalog->access('update')) {
      $this->messenger()->addError($this->t('You are not allowed to modify this catalog.'));
      return;
    }

    if ($this->removeProductFromCatalog($catalog, $product_id)) {
      $this->messenger()->addStatus($this->t('Product removed from the catalog.'));
      $form_state->setRebuild(TRUE);
    }
    else {
      $this->messenger()->addWarning($this->t('The selected product could not be removed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No bulk submission; handled via dedicated handlers.
  }

  /**
   * AJAX callback refreshing the form wrapper.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): AjaxResponse {
    $catalog_id = $form_state->get('catalog_id');
    $wrapper = self::WRAPPER_PREFIX . '-' . $catalog_id;

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . $wrapper, $this->renderer->renderRoot($form)));
    return $response;
  }

  /**
   * Removes the given product from the catalog.
   */
  protected function removeProductFromCatalog(NodeInterface $catalog, int $product_id): bool {
    $product_id = (int) $product_id;
    if ($product_id <= 0) {
      return FALSE;
    }

    $existing = $this->getCatalogProductIds($catalog);
    $filtered = array_values(array_filter($existing, static fn(int $id): bool => $id !== $product_id));

    if ($existing === $filtered) {
      return FALSE;
    }

    $this->setCatalogProductIds($catalog, $filtered);
    $catalog->setNewRevision(TRUE);
    $catalog->setRevisionUserId($this->currentUser()->id());
    $catalog->setRevisionLogMessage($this->t('Removed product @id from catalog.', ['@id' => $product_id]));
    $catalog->save();

    // Refresh internal reference for subsequent rebuilds.
    $this->catalog = $catalog;
    return TRUE;
  }

  /**
   * Loads the catalog entity from form state if needed.
   */
  protected function loadCatalogFromState(FormStateInterface $form_state): ?NodeInterface {
    if ($this->catalog instanceof NodeInterface) {
      return $this->catalog;
    }

    $catalog_id = $form_state->get('catalog_id');
    if (!$catalog_id) {
      return NULL;
    }

    $catalog = $this->entityTypeManager->getStorage('node')->load($catalog_id);
    if ($catalog instanceof NodeInterface && $catalog->bundle() === 'catalog') {
      $this->catalog = $catalog;
      return $catalog;
    }
    return NULL;
  }

}
