<?php

declare(strict_types=1);

namespace Drupal\sangel_catalog\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\feeds\Event\EntityEvent;
use Drupal\feeds\Event\FeedsEvents;
use Drupal\feeds\Event\ImportFinishedEvent;
use Drupal\feeds\Event\InitEvent;
use Drupal\feeds\FeedInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;
use Drupal\sangel_core\Utility\CatalogProductDataTrait;

/**
 * Keeps catalog product imports in sync with the parent feed settings.
 */
final class CatalogFeedSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;
  use CatalogProductDataTrait;

  /**
   * Node storage service.
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * State storage.
   */
  protected StateInterface $state;

  /**
   * Catalog updates accumulated during a feed import.
   *
   * @var array<string, array<int, array<string, mixed>>>
   */
  protected array $catalogUpdates = [];

  /**
   * State key prefix for pending catalog updates.
   */
  protected const STATE_PENDING_UPDATES = 'sangel_catalog.pending_updates.';

  /**
   * Constructs the event subscriber.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, StateInterface $state, CatalogProductRepositoryInterface $catalog_product_repository) {
    $storage = $entity_type_manager->getStorage('node');
    if (!$storage instanceof NodeStorageInterface) {
      throw new \LogicException('Unable to obtain the node storage handler.');
    }

    $this->nodeStorage = $storage;
    $this->logger = $logger;
    $this->state = $state;
    $this->setCatalogProductRepository($catalog_product_repository);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FeedsEvents::INIT_IMPORT => ['onInitImport'],
      FeedsEvents::PROCESS_ENTITY_PRESAVE => ['onProcessEntityPresave'],
      FeedsEvents::PROCESS_ENTITY_POSTSAVE => ['onProcessEntityPostsave'],
      FeedsEvents::IMPORT_FINISHED => ['onImportFinished'],
    ];
  }

  /**
   * Clears any pending catalog updates before an import starts.
   */
  public function onInitImport(InitEvent $event): void {
    $feed = $event->getFeed();
    if (!in_array($feed->bundle(), ['catalog_products_csv', 'catalog_client_products_csv'], TRUE)) {
      return;
    }

    $feed_key = $this->getFeedKey($feed);
    unset($this->catalogUpdates[$feed_key]);
    $this->state->delete($this->getPendingUpdatesStateKey($feed_key));
    $this->catalogUpdates[$feed_key] = [];
  }

  /**
   * Forces imported products to inherit the feed client type.
   */
  public function onProcessEntityPresave(EntityEvent $event): void {
    $feed = $event->getFeed();
    if (!in_array($feed->bundle(), ['catalog_products_csv', 'catalog_client_products_csv'], TRUE)) {
      return;
    }

    $entity = $event->getEntity();
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'product') {
      return;
    }

    if (!$entity->hasField('field_client_type')) {
      return;
    }

    $target_id = \sangel_catalog_get_feed_client_type_tid($feed);
    if (!$target_id) {
      $this->logger->warning($this->t('Impossible d\'appliquer le type client, aucun terme trouvé pour le flux @feed.', [
        '@feed' => $feed->label() ?: $feed->id(),
      ]));
      return;
    }

    $field = $entity->get('field_client_type');
    $current = [];
    foreach ($field as $item) {
      if (!empty($item->target_id)) {
        $current[(int) $item->target_id] = (int) $item->target_id;
      }
    }

    if (!isset($current[$target_id])) {
      $current[$target_id] = (int) $target_id;
      $field->setValue(array_map(static fn(int $tid): array => ['target_id' => $tid], array_values($current)));
      $this->logger->debug($this->t('Type client @tid ajouté au produit @title pendant l\'import.', [
        '@tid' => $target_id,
        '@title' => $entity->label(),
      ]));
    }
  }

  /**
   * Collects imported product IDs for the target catalog.
   */
  public function onProcessEntityPostsave(EntityEvent $event): void {
    $feed = $event->getFeed();
    if (!in_array($feed->bundle(), ['catalog_products_csv', 'catalog_client_products_csv'], TRUE)) {
      return;
    }

    $entity = $event->getEntity();
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'product') {
      return;
    }

    $client = \sangel_catalog_get_feed_client($feed);
    if (!$client instanceof NodeInterface) {
      // Nothing to do if the feed is not tied to a specific client.
      return;
    }

    $feed_key = $this->getFeedKey($feed);

    $catalog = \sangel_catalog_load_client_catalog($client);
    if (!$catalog instanceof NodeInterface) {
      $catalog = $this->loadCatalogFromState($feed);
    }

    if (!$catalog instanceof NodeInterface) {
      // Ensure a catalog exists by synchronising metadata, then retry.
      \sangel_catalog_sync_catalog_from_feed($feed);
      $catalog = \sangel_catalog_load_client_catalog($client) ?? $this->loadCatalogFromState($feed);
    }

    if (!$catalog instanceof NodeInterface) {
      $this->logger->warning($this->t('Aucun catalogue n\'a été trouvé pour le client @client pendant l\'import du flux @feed.', [
        '@client' => $client->label(),
        '@feed' => $feed->label() ?: $feed->id(),
      ]));
      return;
    }

    $this->ensureCatalogUpdatesLoaded($feed_key);
    $updates =& $this->catalogUpdates[$feed_key];

    $catalog_id = (int) $catalog->id();
    if (!isset($updates[$catalog_id])) {
      $updates[$catalog_id] = [
        'product_ids' => [],
        'client_items' => [],
      ];
      $updates[$catalog_id]['existing_skus'] = [];
      $updates[$catalog_id]['existing_skus_loaded'] = FALSE;
    }

    if (!$updates[$catalog_id]['existing_skus_loaded']) {
      $existing_ids = $this->getCatalogProductIds($catalog);
      $loaded_existing = $existing_ids ? $this->nodeStorage->loadMultiple($existing_ids) : [];
      foreach ($existing_ids as $existing_id) {
        $existing_product = $loaded_existing[$existing_id] ?? NULL;
        if ($existing_product instanceof NodeInterface && $existing_product->hasField('field_sku')) {
          $existing_sku = trim((string) $existing_product->get('field_sku')->value);
          if ($existing_sku !== '') {
            $updates[$catalog_id]['existing_skus'][$existing_sku] = TRUE;
          }
        }
      }
      $updates[$catalog_id]['existing_skus_loaded'] = TRUE;
    }

    $sku_value = $entity->hasField('field_sku') ? trim((string) $entity->get('field_sku')->value) : '';
    if ($sku_value !== '' && !empty($updates[$catalog_id]['existing_skus'][$sku_value])) {
      // SKU already present in the catalog, skip linking again.
      $this->persistCatalogUpdates($feed_key);
      return;
    }

    if ($sku_value !== '') {
      $updates[$catalog_id]['existing_skus'][$sku_value] = TRUE;
    }

    $updates[$catalog_id]['product_ids'][(int) $entity->id()] = (int) $entity->id();

    if ($entity->hasField('field_catalog_clients')) {
      foreach ($entity->get('field_catalog_clients') as $client_item) {
        if ($client_item->target_id) {
          $updates[$catalog_id]['client_items'][(int) $client_item->target_id] = (int) $client_item->target_id;
        }
      }
    }

    $this->persistCatalogUpdates($feed_key);
  }

  /**
   * Applies pending catalog updates once the import is finished.
   */
  public function onImportFinished(ImportFinishedEvent $event): void {
    $feed = $event->getFeed();
    if (!in_array($feed->bundle(), ['catalog_products_csv', 'catalog_client_products_csv'], TRUE)) {
      return;
    }

    $feed_key = $this->getFeedKey($feed);
    $this->ensureCatalogUpdatesLoaded($feed_key);

    if (empty($this->catalogUpdates[$feed_key])) {
      $this->state->delete($this->getPendingUpdatesStateKey($feed_key));
      unset($this->catalogUpdates[$feed_key]);
      return;
    }

    $client = \sangel_catalog_get_feed_client($feed);
    $client_id = $client instanceof NodeInterface ? (int) $client->id() : NULL;
    $client_owner_id = $client instanceof NodeInterface ? (int) $client->getOwnerId() : 0;
    $client_type_tid = \sangel_catalog_get_feed_client_type_tid($feed);

    $cover_values = $this->extractCoverValues($feed);

    foreach ($this->catalogUpdates[$feed_key] as $catalog_id => $data) {
      $catalog = $this->nodeStorage->load($catalog_id);
      if (!$catalog instanceof NodeInterface) {
        continue;
      }

      $needs_save = FALSE;

      if (!empty($data['product_ids'])) {
        $existing_ids = $this->getCatalogProductIds($catalog);
        $existing_map = [];
        foreach ($existing_ids as $existing_id) {
          $existing_map[$existing_id] = TRUE;
        }

        $updated = FALSE;
        foreach ($data['product_ids'] as $product_id) {
          $product_id = (int) $product_id;
          if ($product_id > 0 && !isset($existing_map[$product_id])) {
            $existing_ids[] = $product_id;
            $existing_map[$product_id] = TRUE;
            $updated = TRUE;
          }
        }

        if ($updated) {
          sort($existing_ids, SORT_NUMERIC);
          $this->setCatalogProductIds($catalog, $existing_ids);
          $needs_save = TRUE;
          $this->logger->debug('Catalogue @catalog reçoit @count produits (total: @total).', [
            '@catalog' => $catalog->label(),
            '@count' => count($data['product_ids']),
            '@total' => count($existing_ids),
          ]);
        }
      }

      if ($client_id && $catalog->hasField('field_client')) {
        $current_client = (int) ($catalog->get('field_client')->target_id ?? 0);
        if ($current_client !== $client_id) {
          $catalog->set('field_client', $client_id);
          $needs_save = TRUE;
        }
      }

      if ($client_type_tid && $catalog->hasField('field_client_type')) {
        $current_type = (int) ($catalog->get('field_client_type')->target_id ?? 0);
        if ($current_type !== $client_type_tid) {
          $catalog->set('field_client_type', ['target_id' => $client_type_tid]);
          $needs_save = TRUE;
        }
      }

      if ($cover_values !== NULL && $catalog->hasField('field_cover')) {
        $current_cover = $catalog->get('field_cover')->getValue();
        if ($current_cover !== $cover_values) {
          $catalog->set('field_cover', $cover_values);
          $needs_save = TRUE;
        }
      }

      if ($catalog->hasField('field_catalog_clients')) {
        $existing_client_ids = [];
        foreach ($catalog->get('field_catalog_clients') as $item) {
          if ($item->target_id) {
            $existing_client_ids[(int) $item->target_id] = (int) $item->target_id;
          }
        }

        $client_ids = $existing_client_ids;

        if ($client_owner_id > 0) {
          $client_ids[$client_owner_id] = $client_owner_id;
        }

        foreach ($data['client_items'] ?? [] as $target_id) {
          $client_ids[(int) $target_id] = (int) $target_id;
        }

        $final_ids = array_values($client_ids);
        sort($final_ids, SORT_NUMERIC);

        $current_ids = array_values($existing_client_ids);
        sort($current_ids, SORT_NUMERIC);

        if ($current_ids !== $final_ids) {
          $catalog->set('field_catalog_clients', array_map(static fn(int $id): array => ['target_id' => $id], $final_ids));
          $needs_save = TRUE;
        }
      }

      if ($needs_save) {
        try {
          $catalog->save();
        }
        catch (\Exception $exception) {
          $this->logger->error($this->t('Échec de la mise à jour du catalogue @catalog pour le flux @feed : @message', [
            '@catalog' => $catalog->label(),
            '@feed' => $feed->label() ?: $feed->id(),
            '@message' => $exception->getMessage(),
          ]));
        }
      }
    }

    unset($this->catalogUpdates[$feed_key]);
    $this->state->delete($this->getPendingUpdatesStateKey($feed_key));
  }

  /**
   * Returns a stable key for the feed.
   */
  protected function getFeedKey(FeedInterface $feed): string {
    return $feed->uuid();
  }

  /**
   * Attempts to load the catalog currently mapped to the feed.
   */
  protected function loadCatalogFromState(FeedInterface $feed): ?NodeInterface {
    $catalog_id = (int) $this->state->get('sangel_catalog.catalog_for_feed.' . $feed->uuid());
    if ($catalog_id <= 0) {
      return NULL;
    }

    $catalog = $this->nodeStorage->load($catalog_id);
    return $catalog instanceof NodeInterface ? $catalog : NULL;
  }

  /**
   * Extracts cover values defined on the feed entity.
   */
  protected function extractCoverValues(FeedInterface $feed): ?array {
    if (!$feed->hasField('field_cover')) {
      return NULL;
    }

    $list = $feed->get('field_cover');
    if (!$list instanceof FieldItemListInterface || $list->isEmpty()) {
      return NULL;
    }

    $values = $list->getValue();
    return $values === [] ? NULL : $values;
  }

  /**
   * Ensures catalog updates for a feed are initialised from state.
   */
  protected function ensureCatalogUpdatesLoaded(string $feed_key): void {
    if (!array_key_exists($feed_key, $this->catalogUpdates)) {
      $stored = $this->state->get($this->getPendingUpdatesStateKey($feed_key), []);
      $this->catalogUpdates[$feed_key] = is_array($stored) ? $stored : [];
    }
  }

  /**
   * Persists catalog updates for a feed to state storage.
   */
  protected function persistCatalogUpdates(string $feed_key): void {
    if (empty($this->catalogUpdates[$feed_key])) {
      $this->state->delete($this->getPendingUpdatesStateKey($feed_key));
      return;
    }

    $this->state->set($this->getPendingUpdatesStateKey($feed_key), $this->catalogUpdates[$feed_key]);
  }

  /**
   * Builds the state key used for pending updates per feed.
   */
  protected function getPendingUpdatesStateKey(string $feed_key): string {
    return self::STATE_PENDING_UPDATES . $feed_key;
  }

}
