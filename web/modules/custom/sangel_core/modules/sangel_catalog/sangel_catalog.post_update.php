<?php

use Drupal\Component\Serialization\Json;
use Drupal\feeds\Entity\FeedType;
use Drupal\Core\Database\Database;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;

/**
 * Installs the client-specific catalog import feed type if missing.
 */
function sangel_catalog_post_update_install_client_feed(&$sandbox) {
  if (FeedType::load('catalog_client_products_csv')) {
    return t('Client catalog feed already installed.');
  }

  $default_storage = \Drupal::service('config.storage');
  $config_name = 'feeds.feed_type.catalog_client_products_csv';
  if (!$default_storage->exists($config_name)) {
    return t('No configuration found for client catalog feed.');
  }

  $data = $default_storage->read($config_name);
  if (!$data) {
    return t('Failed to read configuration for client catalog feed.');
  }

  FeedType::create($data)->save();
  return t('Installed client-specific catalog feed type.');
}

/**
 * Ensures the JSON-backed catalog product field exists.
 */
function sangel_catalog_post_update_install_product_data_field() {
  $storage = FieldStorageConfig::loadByName('node', 'field_product_data');
  if (!$storage) {
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_product_data',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
      'translatable' => FALSE,
    ]);
    $storage->save();
  }

  $field = FieldConfig::loadByName('node', 'catalog', 'field_product_data');
  if (!$field) {
    $field = FieldConfig::create([
      'field_name' => 'field_product_data',
      'entity_type' => 'node',
      'bundle' => 'catalog',
      'label' => 'Catalog product data',
      'field_type' => 'text_long',
      'settings' => [
        'display_summary' => FALSE,
      ],
    ]);
    $field->save();
  }

  return t('Ensured field_product_data exists on catalog nodes.');
}

/**
 * Migrates catalog product references to the JSON storage field.
 */
function sangel_catalog_post_update_migrate_catalog_products(array &$sandbox) {
  $entity_type_manager = \Drupal::entityTypeManager();
  $storage = $entity_type_manager->getStorage('node');

  if (!isset($sandbox['progress'])) {
    $sandbox['progress'] = 0;
    $sandbox['total'] = 0;
    $sandbox['nids'] = [];

    $query = $storage->getQuery()
      ->condition('type', 'catalog')
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if ($nids) {
      $sandbox['nids'] = array_values($nids);
      $sandbox['total'] = count($sandbox['nids']);
    }
    else {
      $sandbox['#finished'] = 1;
      return t('No catalog nodes found for migration.');
    }
  }

  $batch = array_splice($sandbox['nids'], 0, 25);
  foreach ($batch as $nid) {
    $catalog = $storage->load($nid);
    if (!$catalog instanceof NodeInterface) {
      continue;
    }

    $legacy_items = [];
    if ($catalog->hasField('field_catalog_products')) {
      foreach ($catalog->get('field_catalog_products') as $item) {
        $target_id = (int) $item->target_id;
        if ($target_id > 0) {
          $legacy_items[] = $target_id;
        }
      }
    }

    $existing_json = '';
    $existing_items = [];
    if ($catalog->hasField('field_product_data')) {
      $existing_json = (string) $catalog->get('field_product_data')->value;
      if ($existing_json !== '') {
        try {
          $decoded = Json::decode($existing_json);
          if (is_array($decoded)) {
            foreach ($decoded as $value) {
              $id = (int) $value;
              if ($id > 0) {
                $existing_items[] = $id;
              }
            }
          }
        }
        catch (\InvalidArgumentException $exception) {
          $existing_items = [];
        }
      }
    }

    if (!$catalog->hasField('field_product_data')) {
      continue;
    }

    $final_items = [];
    $seen = [];

    foreach ($existing_items as $id) {
      if (!isset($seen[$id]) && $id > 0) {
        $seen[$id] = TRUE;
        $final_items[] = $id;
      }
    }

    foreach ($legacy_items as $id) {
      if (!isset($seen[$id]) && $id > 0) {
        $seen[$id] = TRUE;
        $final_items[] = $id;
      }
    }

    $new_json = $final_items ? Json::encode($final_items) : '';
    if ($new_json !== $existing_json) {
      $catalog->set('field_product_data', $new_json);
      try {
        $catalog->save();
      }
      catch (\Exception $exception) {
        \Drupal::logger('sangel_catalog')->error('Failed to migrate catalog @nid: @message', [
          '@nid' => $catalog->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }
  }

  $sandbox['progress'] += count($batch);
  if ($sandbox['progress'] >= $sandbox['total']) {
    $sandbox['#finished'] = 1;
    return t('Migrated catalog product references to field_product_data.');
  }

  $sandbox['#finished'] = $sandbox['total'] > 0 ? $sandbox['progress'] / $sandbox['total'] : 1;
}

/**
 * Removes the legacy field_catalog_products configuration from catalogs.
 */
function sangel_catalog_post_update_remove_catalog_products_field() {
  $field = FieldConfig::loadByName('node', 'catalog', 'field_catalog_products');
  if ($field) {
    $field->delete();
  }

  $storage = FieldStorageConfig::loadByName('node', 'field_catalog_products');
  if ($storage) {
    $storage->delete();
  }

  return t('Removed legacy field_catalog_products from catalog nodes.');
}

/**
 * Creates the catalog/product mapping table.
 */
function sangel_catalog_post_update_create_catalog_product_table() {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  if ($schema->tableExists('sangel_catalog_product')) {
    return t('Catalog/product mapping table already exists.');
  }

  $schema->createTable('sangel_catalog_product', [
    'description' => 'Maps catalog nodes to their product selections.',
    'fields' => [
      'id' => [
        'type' => 'serial',
        'not null' => TRUE,
      ],
      'catalog_nid' => [
        'type' => 'int',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ],
      'product_nid' => [
        'type' => 'int',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ],
      'weight' => [
        'type' => 'int',
        'not null' => TRUE,
        'default' => 0,
      ],
    ],
    'primary key' => ['id'],
    'unique keys' => [
      'catalog_product' => ['catalog_nid', 'product_nid'],
    ],
    'indexes' => [
      'catalog_nid' => ['catalog_nid'],
      'product_nid' => ['product_nid'],
    ],
  ]);

  return t('Created catalog/product mapping table.');
}

/**
 * Populates the catalog/product mapping table from existing catalog data.
 */
function sangel_catalog_post_update_populate_catalog_product_table(array &$sandbox) {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  if (!$schema->tableExists('sangel_catalog_product')) {
    $schema->createTable('sangel_catalog_product', [
      'description' => 'Maps catalog nodes to their product selections.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'catalog_nid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'product_nid' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'weight' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'catalog_product' => ['catalog_nid', 'product_nid'],
      ],
      'indexes' => [
        'catalog_nid' => ['catalog_nid'],
        'product_nid' => ['product_nid'],
      ],
    ]);
  }

  if (!isset($sandbox['nids'])) {
    $sandbox['nids'] = [];
    $sandbox['total'] = 0;
    $sandbox['progress'] = 0;

    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'catalog')
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if ($nids) {
      $sandbox['nids'] = array_values($nids);
      $sandbox['total'] = count($sandbox['nids']);
    }
    else {
      $sandbox['#finished'] = 1;
      return t('No catalog nodes found.');
    }
  }

  $batch = array_splice($sandbox['nids'], 0, 25);
  if (!$batch) {
    $sandbox['#finished'] = 1;
    return t('Catalog/product mapping populated.');
  }

  /** @var \Drupal\node\NodeStorageInterface $storage */
  $storage = \Drupal::entityTypeManager()->getStorage('node');

  foreach ($batch as $nid) {
    $catalog = $storage->load($nid);
    if (!$catalog instanceof NodeInterface) {
      continue;
    }

    $connection->delete('sangel_catalog_product')
      ->condition('catalog_nid', $catalog->id())
      ->execute();

    $raw = '';
    if ($catalog->hasField('field_product_data')) {
      $raw = (string) $catalog->get('field_product_data')->value;
    }

    $ids = [];
    if ($raw !== '') {
      try {
        $decoded = Json::decode($raw);
        if (is_array($decoded)) {
          foreach ($decoded as $value) {
            $id = (int) $value;
            if ($id > 0 && !isset($ids[$id])) {
              $ids[$id] = $id;
            }
          }
        }
      }
      catch (\InvalidArgumentException $exception) {
        $ids = [];
      }
    }

    if (!$ids) {
      continue;
    }

    $insert = $connection->insert('sangel_catalog_product')
      ->fields(['catalog_nid', 'product_nid', 'weight']);

    $weight = 0;
    foreach ($ids as $id) {
      $insert->values([
        'catalog_nid' => $catalog->id(),
        'product_nid' => $id,
        'weight' => $weight++,
      ]);
    }

    try {
      $insert->execute();
    }
    catch (\Exception $exception) {
      \Drupal::logger('sangel_catalog')->error('Failed to migrate catalog/product mapping for catalog @nid: @message', [
        '@nid' => $catalog->id(),
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  $sandbox['progress'] += count($batch);
  if ($sandbox['progress'] >= $sandbox['total']) {
    $sandbox['#finished'] = 1;
    return t('Catalog/product mapping populated.');
  }

  $sandbox['#finished'] = $sandbox['total'] > 0 ? $sandbox['progress'] / $sandbox['total'] : 1;
}
