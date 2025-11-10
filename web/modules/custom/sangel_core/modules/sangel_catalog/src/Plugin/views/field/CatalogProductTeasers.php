<?php

declare(strict_types=1);

namespace Drupal\sangel_catalog\Plugin\views\field;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;
use Drupal\views\Annotation\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the products stored in a catalogue node.
 *
 * @ViewsField("catalog_product_teasers")
 */
final class CatalogProductTeasers extends FieldPluginBase implements ContainerFactoryPluginInterface {

  use CatalogProductDataTrait;

  /**
   * Entity type manager service.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Entity display repository.
   */
  protected EntityDisplayRepositoryInterface $displayRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->displayRepository = $container->get('entity_display.repository');
    /** @var \Drupal\sangel_core\Repository\CatalogProductRepositoryInterface $repository */
    $repository = $container->get('sangel_core.catalog_product_repository');
    $instance->setCatalogProductRepository($repository);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['catalog_view_mode'] = ['default' => 'teaser'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);

    $view_modes = $this->displayRepository->getViewModeOptions('node');
    $form['catalog_view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Product view mode'),
      '#description' => $this->t('Select the view mode used to render catalogue products.'),
      '#options' => $view_modes,
      '#default_value' => $this->options['catalog_view_mode'] ?? 'teaser',
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // No query alterations needed; rendering happens post-query.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity ?? NULL;
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'catalog') {
      return [];
    }

    $filtered_ids = $this->extractFilteredProductIds($values);

    $products = $filtered_ids
      ? $this->loadCatalogProductsSubset($entity, $filtered_ids)
      : $this->loadCatalogProducts($entity);
    if (!$products) {
      return [];
    }

    $view_mode = $this->options['catalog_view_mode'] ?? 'teaser';
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->viewMultiple($products, $view_mode);

    // Ensure catalogue changes bust caches of rendered products.
    $build['#cache']['tags'] = array_merge(
      $build['#cache']['tags'] ?? [],
      $entity->getCacheTags()
    );

    return $build;
  }

  /**
   * Extracts filtered product identifiers from the current result row.
   */
  protected function extractFilteredProductIds(ResultRow $values): array {
    $aliases = [
      'node_field_data_sangel_catalog_product_nid',
      'sangel_catalog_product_product_nid',
    ];

    $collected = [];
    foreach ($aliases as $alias) {
      if (!isset($values->{$alias})) {
        continue;
      }
      $collected = array_merge($collected, $this->normalizeAggregatedValue($values->{$alias}));
    }

    if (!$collected) {
      foreach (get_object_vars($values) as $key => $value) {
        if (str_contains($key, 'product_nid')) {
          $collected = array_merge($collected, $this->normalizeAggregatedValue($value));
        }
      }
    }

    return array_values(array_unique(array_filter(array_map('intval', $collected))));
  }

  /**
   * Normalises a Views aggregated value to an array of product IDs.
   *
   * @param mixed $value
   *   Aggregated value from Views.
   *
   * @return array
   *   Normalised list of product IDs.
   */
  protected function normalizeAggregatedValue($value): array {
    if (is_array($value)) {
      return $value;
    }

    if ($value === NULL || is_object($value)) {
      return [];
    }

    $value_string = trim((string) $value);
    if ($value_string === '') {
      return [];
    }

    $maybe_unserialized = @unserialize($value_string, ['allowed_classes' => FALSE]);
    if ($maybe_unserialized !== FALSE || $value_string === 'b:0;') {
      if (is_array($maybe_unserialized)) {
        return $maybe_unserialized;
      }
    }

    if (str_contains($value_string, ',')) {
      return array_map('trim', explode(',', $value_string));
    }

    return [$value_string];
  }

}
