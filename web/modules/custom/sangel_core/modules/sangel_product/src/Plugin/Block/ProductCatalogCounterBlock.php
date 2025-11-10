<?php

namespace Drupal\sangel_product\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\sangel_product\Service\CatalogSelectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the number of products selected for the user's catalog.
 *
 * @Block(
 *   id = "sangel_product_catalog_counter",
 *   admin_label = @Translation("Catalog selection counter"),
 *   category = @Translation("Sangel")
 * )
 */
class ProductCatalogCounterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Selection manager.
   */
  protected CatalogSelectionManager $selectionManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->selectionManager = $container->get('sangel_product.catalog_selection_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $account = \Drupal::currentUser();
    if ($account->isAnonymous()) {
      return [
        '#cache' => [
          'max-age' => 0,
          'contexts' => ['user'],
        ],
      ];
    }

    $count = $this->selectionManager->getItemCount();

    return [
      '#theme' => 'sangel_header_counter',
      '#variant' => 'catalog',
      '#counter' => $count,
      '#label' => $this->t('Ma sélection'),
      '#icon' => 'heart',
      '#url' => Url::fromRoute('sangel_catalog.user_catalogs', ['user' => $account->id()])->toString(),
      '#attached' => [
        'library' => [
          'sangelpro_theme/product-actions',
        ],
      ],
      '#attributes' => [
        'id' => 'sangel-product-catalog-count',
        'class' => ['sangel-header-counter'],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user'],
      ],
    ];
  }

}
