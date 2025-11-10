<?php

namespace Drupal\sangel_product\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;

/**
 * Provides utilities to manage the custom catalog for the current user.
 */
class CatalogSelectionManager {

  use StringTranslationTrait;
  use CatalogProductDataTrait;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Current user proxy.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Cached catalog entity.
   */
  protected ?NodeInterface $catalog = NULL;

  /**
   * Flag indicating the catalog has been looked up.
   */
  protected bool $catalogLoaded = FALSE;

  /**
   * Constructs the selection manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, AccountProxyInterface $current_user, LoggerInterface $logger, TranslationInterface $string_translation, CatalogProductRepositoryInterface $catalog_product_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger;
    $this->stringTranslation = $string_translation;
    $this->setCatalogProductRepository($catalog_product_repository);
  }

  /**
   * Returns selected product IDs.
   *
   * @return int[]
   *   Selected product node IDs.
   */
  public function getItems(): array {
    $catalog = $this->loadCatalog(FALSE);
    if (!$catalog) {
      return [];
    }

    $ids = $this->getCatalogProductIds($catalog);
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
  }

  /**
   * Returns the number of selected products.
   */
  public function getItemCount(): int {
    return count($this->getItems());
  }

  /**
   * Adds a product to the user's catalog, creating it if needed.
   *
   * @return bool
   *   TRUE if the product was newly added, FALSE otherwise.
   */
  public function addProduct(NodeInterface $product): bool {
    if ($product->bundle() !== 'product') {
      $this->logger->warning('Attempt to add non-product node @nid to catalog.', ['@nid' => $product->id()]);
      return FALSE;
    }

    $catalog = $this->loadCatalog(TRUE);
    if (!$catalog) {
      $this->logger->error('Unable to create or load catalog for user @uid.', ['@uid' => $this->currentUser->id()]);
      return FALSE;
    }
    $existing = $this->getCatalogProductIds($catalog);
    $nid = (int) $product->id();
    if (in_array($nid, $existing, TRUE)) {
      return FALSE;
    }

    $existing[] = $nid;
    $this->setCatalogProductIds($catalog, $existing);
    $this->saveCatalog($catalog, $this->t('Added product @nid to custom catalog.', ['@nid' => $product->id()]));
    return TRUE;
  }

  /**
   * Removes a product from the catalog.
   */
  public function removeProduct(int $product_id): void {
    $catalog = $this->loadCatalog(FALSE);
    if (!$catalog) {
      return;
    }
    $product_id = (int) $product_id;
    $ids = $this->getCatalogProductIds($catalog);
    $filtered = array_values(array_filter($ids, static fn(int $id): bool => $id !== $product_id));

    if ($ids !== $filtered) {
      $this->setCatalogProductIds($catalog, $filtered);
      $this->saveCatalog($catalog, $this->t('Removed product @nid from custom catalog.', ['@nid' => $product_id]));
    }
  }

  /**
   * Clears the catalog selection.
   */
  public function clear(): void {
    $catalog = $this->loadCatalog(FALSE);
    if (!$catalog) {
      return;
    }
    $this->setCatalogProductIds($catalog, []);
    $this->saveCatalog($catalog, $this->t('Cleared custom catalog.'));
  }

  /**
   * Loads the product entities currently selected.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded product entities.
   */
  public function loadProducts(): array {
    $ids = $this->getItems();
    if (!$ids) {
      return [];
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple($ids);
    $products = [];
    foreach ($ids as $id) {
      $product = $loaded[$id] ?? NULL;
      if ($product instanceof NodeInterface && $product->bundle() === 'product') {
        $products[$id] = $product;
      }
    }
    return $products;
  }

  /**
   * Returns the user's custom catalog, creating it if required.
   */
  protected function loadCatalog(bool $create_if_missing = FALSE): ?NodeInterface {
    if ($this->catalogLoaded) {
      if (!$this->catalog && $create_if_missing) {
        $catalog = $this->createCatalog();
        if ($catalog) {
          $this->catalog = $catalog;
        }
      }
      return $this->catalog;
    }

    $this->catalogLoaded = TRUE;
    $catalog = $this->findExistingCatalog();

    if (!$catalog && $create_if_missing) {
      $catalog = $this->createCatalog();
    }

    $this->catalog = $catalog;
    return $this->catalog;
  }

  /**
   * Saves the catalog with a revision message.
   */
  protected function saveCatalog(NodeInterface $catalog, string $log): void {
    try {
      $catalog->setNewRevision(TRUE);
      $catalog->setRevisionLogMessage($log);
      if ($this->currentUser->isAuthenticated()) {
        $catalog->setRevisionUserId($this->currentUser->id());
      }
      $catalog->save();
      $this->catalog = $catalog;
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to save catalog @nid: @message', [
        '@nid' => $catalog->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Attempts to load an existing catalog for the current user.
   */
  protected function findExistingCatalog(): ?NodeInterface {
    $user = $this->getCurrentUserEntity();
    if (!$user) {
      return NULL;
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'catalog')
      ->condition('uid', $user->id())
      ->accessCheck(FALSE);

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }

    $catalogs = $storage->loadMultiple($ids);
    foreach ($catalogs as $catalog) {
      if (!$catalog instanceof NodeInterface) {
        continue;
      }
      if ($catalog->hasField('field_custom')) {
        $custom = $catalog->get('field_custom')->value;
        if ((string) $custom !== '' && (int) $custom === 1) {
          return $catalog;
        }
      }
      else {
        return $catalog;
      }
    }

    $first = reset($catalogs);
    return $first instanceof NodeInterface ? $first : NULL;
  }

  /**
   * Creates a new custom catalog for the current user.
   */
  protected function createCatalog(): ?NodeInterface {
    $user = $this->getCurrentUserEntity();
    if (!$user) {
      return NULL;
    }

    $client = $this->loadClientForUser($user);

    $title = $this->t('Custom catalog of @name', ['@name' => $user->getDisplayName()]);

    /** @var \Drupal\node\NodeInterface $catalog */
    $catalog = Node::create([
      'type' => 'catalog',
      'title' => $title,
      'uid' => $user->id(),
      'status' => Node::PUBLISHED,
    ]);

    if ($catalog->hasField('field_custom')) {
      $catalog->set('field_custom', 1);
    }

    if ($catalog->hasField('field_catalog_clients')) {
      $catalog->set('field_catalog_clients', [$user->id()]);
    }

    if ($client && $catalog->hasField('field_client')) {
      $catalog->set('field_client', $client->id());
    }

    if ($catalog->hasField('field_client_type') && $client && $client->hasField('field_client_type')) {
      $client_type = $client->get('field_client_type');
      if ($client_type && !$client_type->isEmpty()) {
        $catalog->set('field_client_type', $client_type->target_id ?? NULL);
      }
    }

    try {
      $catalog->save();
      return $catalog;
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to create custom catalog for user @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Loads the authenticated user as an entity.
   */
  protected function getCurrentUserEntity(): ?UserInterface {
    if (!$this->currentUser->isAuthenticated()) {
      return NULL;
    }
    /** @var \Drupal\user\UserStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('user');
    $user = $storage->load($this->currentUser->id());
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Loads the client node referencing the given user.
   */
  protected function loadClientForUser(UserInterface $user): ?NodeInterface {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'client');
    if (!isset($definitions['field_user'])) {
      return NULL;
    }

    /** @var \Drupal\node\NodeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'client')
      ->condition('status', 1)
      ->condition('field_user', $user->id())
      ->range(0, 1)
      ->accessCheck(FALSE);

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }

    $client = $storage->load(reset($ids));
    return $client instanceof NodeInterface ? $client : NULL;
  }

}
