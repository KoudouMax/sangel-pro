<?php

declare(strict_types=1);

namespace Drupal\sangel_catalog\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\sangel_core\Repository\CatalogProductRepositoryInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\file\Entity\File;

/**
 * Handles catalog synchronization for CSV imports.
 */
class CatalogImportManager {

  use StringTranslationTrait;

  /**
   * Constructs the manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly CatalogProductRepositoryInterface $productRepository,
    protected readonly LoggerChannelInterface $logger,
    protected readonly FileSystemInterface $fileSystem,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Parses a CSV file and returns normalized SKU references.
   *
   * @return string[]
   *   Uppercase SKU values.
   */
  public function parseCsvForSkus(File $file): array {
    $real_path = $this->fileSystem->realpath($file->getFileUri());

    if (!$real_path || !is_readable($real_path)) {
      return [];
    }

    $handle = fopen($real_path, 'r');
    if (!$handle) {
      return [];
    }

    $skus = [];
    $header = null;
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
      $row = array_map('trim', $row);
      if ($header === null) {
        $header = array_map(static function ($value) {
          $value = trim((string) $value);
          $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
          return strtoupper($value);
        }, $row);
        continue;
      }
      if (!$row || $row === ['']) {
        continue;
      }
      $record = [];
      foreach ($row as $index => $value) {
        $key = $header[$index] ?? $index;
        $record[$key] = $value;
      }
      $reference = (string) ($record['REFERENCE'] ?? $record['SKU'] ?? '');
      if ($reference === '') {
        continue;
      }
      $skus[] = strtoupper($reference);
    }
    fclose($handle);

    return array_values(array_unique(array_filter($skus)));
  }

  /**
   * Maps SKU values to existing product node IDs.
   *
   * @param string[] $skus
   *   SKU values.
   *
   * @return array<string,int>
   *   Map of SKU => product ID.
   */
  public function mapSkusToProductIds(array $skus): array {
    if (!$skus) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'product')
      ->condition('field_sku', $skus, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    if (!$nids) {
      return [];
    }

    /** @var \Drupal\node\NodeInterface[] $products */
    $products = $storage->loadMultiple($nids);
    $map = [];
    foreach ($products as $product) {
      if (!$product instanceof NodeInterface || !$product->hasField('field_sku')) {
        continue;
      }
      $sku_value = strtoupper(trim((string) $product->get('field_sku')->value));
      if ($sku_value === '') {
        continue;
      }
      $map[$sku_value] = (int) $product->id();
    }
    return $map;
  }

  /**
   * Ensures a custom catalog exists for the provided client.
   *
   * @return array{0: ?\Drupal\node\NodeInterface, 1: bool}
   *   Catalog instance (or NULL) and flag indicating creation.
   */
  public function ensureCatalogForClient(NodeInterface $client, ?TermInterface $forced_type = NULL): array {
    $catalog = $this->loadClientCatalog($client);
    $created = FALSE;

    if ($catalog instanceof NodeInterface) {
      if ($forced_type instanceof TermInterface && $catalog->hasField('field_client_type')) {
        $catalog->set('field_client_type', ['target_id' => $forced_type->id()]);
      }
      return [$catalog, $created];
    }

    $owner_id = $this->resolveClientOwnerId($client);
    $title = $this->t('Custom catalog of @name', ['@name' => $client->label()])->__toString();

    /** @var \Drupal\node\NodeInterface $catalog */
    $catalog = Node::create([
      'type' => 'catalog',
      'title' => $title,
      'status' => Node::PUBLISHED,
    ]);

    if ($owner_id) {
      $catalog->setOwnerId($owner_id);
    }

    if ($catalog->hasField('field_custom')) {
      $catalog->set('field_custom', 1);
    }

    if ($catalog->hasField('field_client')) {
      $catalog->set('field_client', $client->id());
    }

    if ($catalog->hasField('field_client_type')) {
      if ($forced_type instanceof TermInterface) {
        $catalog->set('field_client_type', ['target_id' => $forced_type->id()]);
      }
      elseif ($client->hasField('field_client_type') && !$client->get('field_client_type')->isEmpty()) {
        $catalog->set('field_client_type', $client->get('field_client_type')->target_id ?? NULL);
      }
    }

    if ($catalog->hasField('field_catalog_clients') && $owner_id) {
      $catalog->set('field_catalog_clients', [['target_id' => $owner_id]]);
    }

    try {
      $catalog->save();
      $created = TRUE;
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Impossible de créer le catalogue personnalisé pour « @client » : @message', [
        '@client' => $client->label(),
        '@message' => $throwable->getMessage(),
      ]);
      return [NULL, FALSE];
    }

    return [$catalog, $created];
  }

  /**
   * Replaces catalog contents with the provided product IDs.
   */
  public function synchronizeCatalog(NodeInterface $catalog, array $product_ids, ?int $revision_user_id = NULL, ?string $revision_message = NULL): void {
    $normalized = array_values(array_unique(array_map('intval', $product_ids)));

    $this->productRepository->setProductIds($catalog, $normalized);
    $catalog->setNewRevision(TRUE);
    $catalog->setRevisionLogMessage($revision_message ?? $this->t('Catalogue mis à jour via import commercial.')->__toString());
    if ($revision_user_id) {
      $catalog->setRevisionUserId($revision_user_id);
    }

    try {
      $catalog->save();
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Impossible d’enregistrer le catalogue « @catalog » : @message', [
        '@catalog' => $catalog->label(),
        '@message' => $throwable->getMessage(),
      ]);
      throw $throwable;
    }
  }

  /**
   * Synchronises the provided clients with the supplied product IDs.
   *
   * @param \Drupal\node\NodeInterface[] $clients
   *   Client nodes to update.
   * @param int[] $product_ids
   *   Product identifiers to apply.
   * @param \Drupal\taxonomy\TermInterface|null $forced_type
   *   Optional forced client type.
   * @param int|null $revision_user_id
   *   Optional revision user identifier.
   * @param string|null $revision_message
   *   Optional revision message.
   *
   * @return array{processed:int, updated:int, created:int, errors:string[]}
   *   Summary statistics.
   */
  public function synchronizeClients(array $clients, array $product_ids, ?TermInterface $forced_type = NULL, ?int $revision_user_id = NULL, ?string $revision_message = NULL): array {
    $summary = [
      'processed' => 0,
      'updated' => 0,
      'created' => 0,
      'errors' => [],
    ];

    foreach ($clients as $client) {
      if (!$client instanceof NodeInterface || $client->bundle() !== 'client') {
        continue;
      }

      $summary['processed']++;

      try {
        [$catalog, $created] = $this->ensureCatalogForClient($client, $forced_type);
        if (!$catalog instanceof NodeInterface) {
          $summary['errors'][] = $this->t('Impossible de créer ou charger le catalogue pour « @client ».', ['@client' => $client->label()])->__toString();
          continue;
        }

        $this->synchronizeCatalog($catalog, $product_ids, $revision_user_id, $revision_message);

        if ($created) {
          $summary['created']++;
        }
        else {
          $summary['updated']++;
        }
      }
      catch (\Throwable $throwable) {
        $this->logger->error('Échec lors de la mise à jour du catalogue pour « @client » : @message', [
          '@client' => $client->label(),
          '@message' => $throwable->getMessage(),
        ]);
        $summary['errors'][] = $this->t('La mise à jour du catalogue pour « @client » a échoué.', ['@client' => $client->label()])->__toString();
      }
    }

    return $summary;
  }

  /**
   * Loads the catalog associated with a client, if any.
   */
  protected function loadClientCatalog(NodeInterface $client): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'catalog')
      ->condition('status', 1)
      ->condition('field_client', $client->id())
      ->range(0, 1)
      ->accessCheck(FALSE);

    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'catalog');
    if (isset($definitions['field_custom'])) {
      $query->condition('field_custom', 1);
    }

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }

    $catalog = $storage->load(reset($ids));
    return $catalog instanceof NodeInterface ? $catalog : NULL;
  }

  /**
   * Determines a suitable owner for the catalog.
   */
  protected function resolveClientOwnerId(NodeInterface $client): ?int {
    if ($client->getOwnerId()) {
      return (int) $client->getOwnerId();
    }

    if ($client->hasField('field_user') && !$client->get('field_user')->isEmpty()) {
      return (int) $client->get('field_user')->target_id;
    }

    return NULL;
  }

}
