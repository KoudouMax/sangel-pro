<?php

declare(strict_types=1);

namespace Drupal\sangel_core_base\Plugin\views\display;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Drupal\views_data_export\Plugin\views\display\DataExport as BaseDataExport;

/**
 * Extends the Views Data Export display to log exports.
 */
class DataExport extends BaseDataExport {

  /**
   * {@inheritdoc}
   */
  public static function processBatch($view_id, $display_id, array $args, array $exposed_input, $total_rows, array $query_parameters, $redirect_url, int $uid, &$context = []) {
    \Drupal::logger('sangel_core_base')->debug('processBatch override triggered for @view/@display.', ['@view' => $view_id, '@display' => $display_id]);

    parent::processBatch($view_id, $display_id, $args, $exposed_input, $total_rows, $query_parameters, $redirect_url, $uid, $context);

    if (!isset($context['results'])) {
      $context['results'] = [];
    }
    if (!isset($context['results']['sangel_export'])) {
      $metadata = static::buildMetadata($view_id, $display_id);
      $context['results']['sangel_export'] = [
        'view_id' => $view_id,
        'display_id' => $display_id,
        'view_label' => $metadata['view_label'] ?? $view_id,
        'display_label' => $metadata['display_label'] ?? $display_id,
        'format' => $metadata['format'] ?? '',
        'filename' => $metadata['filename'] ?? '',
        'uid' => $uid,
        'args' => $args,
        'exposed_input' => $exposed_input,
        'query' => $query_parameters,
        'redirect_url' => $redirect_url,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function finishBatch($success, array $results, array $operations) {
    \Drupal::logger('sangel_core_base')->debug('finishBatch override triggered. Success: @success', ['@success' => $success ? 'true' : 'false']);
    \Drupal::logger('sangel_core_base')->debug('finishBatch override triggered. Success: @success, results keys: @keys', ['@success' => $success ? 'true' : 'false', '@keys' => implode(', ', array_keys($results))]);

    $response = parent::finishBatch($success, $results, $operations);

    $metadata = $results['sangel_export'] ?? NULL;

    if ($success && $metadata && !empty($results['vde_file']) && file_exists($results['vde_file'])) {
      try {
        $file = File::loadByUri($results['vde_file']);
        if (!$file instanceof FileInterface) {
          $file = NULL;
        }

        if ($file instanceof FileInterface) {
          $metadata['file_uri'] = $file->getFileUri();
          $metadata['file_url'] = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
        }

        \Drupal::service('sangel_core.export_log_manager')->logViewExport($metadata, $file);
      }
      catch (\Throwable $throwable) {
        \Drupal::logger('sangel_core_base')->error('Failed to log export: @message', ['@message' => $throwable->getMessage()]);
      }
    }

    if ($metadata && static::shouldResetCatalog($metadata['view_id'] ?? '', $metadata['display_id'] ?? '', (int) ($metadata['uid'] ?? 0))) {
      static::clearCatalogProductData((int) ($metadata['uid'] ?? 0));
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  protected static function buildStandard(ViewExecutable $view) {
    \Drupal::logger('sangel_core_base')->debug('buildStandard override triggered for @view/@display.', ['@view' => $view->id(), '@display' => $view->current_display]);

    $response = parent::buildStandard($view);

    $view_id = $view->id();
    $display_id = $view->current_display;
    $metadata = [
      'view_id' => $view_id,
      'display_id' => $display_id,
    ];
    $uid = (int) \Drupal::currentUser()->id();

    try {
      $metadata = static::buildMetadata($view_id, $display_id) + $metadata;
      $metadata['view_label'] = $metadata['view_label'] ?? $view->storage->label();
      $metadata['display_label'] = $metadata['display_label'] ?? ($view->display_handler->display['display_title'] ?? $view->current_display);
      $metadata['uid'] = $uid;
      $metadata['args'] = $view->args;
      $metadata['exposed_input'] = $view->getExposedInput();
      $metadata['query'] = $view->getExposedInput();
      $metadata['redirect_url'] = $view->getUrl()->toString();

      $content = (string) $response->getContent();
      $extension = static::guessExtension($metadata['format'] ?? $view->display_handler->getOption('content_type'));
      $baseFilename = $metadata['filename'] ?? ($view->storage->label() ?? 'export');

      $file = \Drupal::service('sangel_core.export_log_manager')->persistInlineFile($content, $baseFilename, $extension);
      if ($file instanceof FileInterface) {
        $metadata['file_uri'] = $file->getFileUri();
        $metadata['file_url'] = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
        \Drupal::service('sangel_core.export_log_manager')->logViewExport($metadata, $file);
      }
    }
    catch (\Throwable $throwable) {
      \Drupal::logger('sangel_core_base')->error('Failed to log standard export: @message', ['@message' => $throwable->getMessage()]);
    }

    if (static::shouldResetCatalog($metadata['view_id'] ?? $view_id, $metadata['display_id'] ?? $display_id, $uid)) {
      static::clearCatalogProductData($uid);
    }

    return $response;
  }

  /**
   * Collects metadata about the export display.
   */
  protected static function buildMetadata(string $view_id, string $display_id): array {
    $metadata = [];
    $view = Views::getView($view_id);
    if (!$view) {
      return $metadata;
    }

    try {
      $view->setDisplay($display_id);
      $metadata['view_label'] = $view->storage->label();
      $metadata['display_label'] = $view->display_handler->display['display_title'] ?? $display_id;
      $metadata['format'] = $view->display_handler->getOption('content_type');
      $metadata['filename'] = $view->display_handler->getOption('filename');
    }
    catch (\Throwable $throwable) {
      \Drupal::logger('sangel_core_base')->warning('Unable to determine export metadata: @message', ['@message' => $throwable->getMessage()]);
    }
    finally {
      $view->destroy();
    }

    return $metadata;
  }

  /**
   * Determines whether catalog data should be reset for this export.
   */
  protected static function shouldResetCatalog(?string $view_id, ?string $display_id, int $uid): bool {

    $allowed_views = [
      'product_catalog' => [
        'selections_export',
      ],
    ];

    if(!$view_id || !isset($allowed_views[$view_id])) {
      return FALSE;
    }

    if ($view_id !== 'product_catalog' || $display_id !== 'selections_export') {
      return FALSE;
    }

    return static::userIsCommercial($uid);
  }

  /**
   * Checks if the provided user has the commercial role.
   */
  protected static function userIsCommercial(int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }

    drupal_static_reset(__METHOD__); // For debugging purposes only
    $cache = &drupal_static(__METHOD__, []);
    if (array_key_exists($uid, $cache)) {
      return $cache[$uid];
    }

    try {
      // $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      $account = \Drupal::currentUser();
      return $cache[$uid] = in_array('commercial', $account->getRoles(), TRUE);
    }
    catch (\Throwable $throwable) {
      \Drupal::logger('sangel_core_base')->warning('Unable to determine commercial status for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $throwable->getMessage(),
      ]);
    }

    return $cache[$uid] = FALSE;
  }

  /**
   * Clears stored product data on catalogs owned by the given user.
   */
  protected static function clearCatalogProductData(int $uid): void {
    if ($uid <= 0) {
      return;
    }

    try {
      /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager */
      $field_manager = \Drupal::service('entity_field.manager');
      /** @var \Drupal\node\NodeStorageInterface $storage */
      $storage = \Drupal::entityTypeManager()->getStorage('node');

      $catalog_query = $storage->getQuery()
        ->condition('type', 'catalog')
        ->accessCheck(FALSE);

      $or_group = $catalog_query->orConditionGroup()->condition('uid', $uid);

      $catalog_definitions = $field_manager->getFieldDefinitions('node', 'catalog');
      if (isset($catalog_definitions['field_catalog_clients'])) {
        $or_group->condition('field_catalog_clients', $uid);
      }

      $client_ids = [];
      if (isset($catalog_definitions['field_client'])) {
        $client_definitions = $field_manager->getFieldDefinitions('node', 'client');
        if (isset($client_definitions['field_user'])) {
          $client_ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
            ->condition('type', 'client')
            ->condition('field_user', $uid)
            ->accessCheck(FALSE)
            ->execute();
          $client_ids = array_values(array_unique(array_map('intval', $client_ids)));
          if ($client_ids) {
            $or_group->condition('field_client', $client_ids, 'IN');
          }
        }
      }

      $catalog_query->condition($or_group);
      $catalog_ids = $catalog_query->execute();

      if (!$catalog_ids) {
        return;
      }

      /** @var \Drupal\sangel_core\Repository\CatalogProductRepositoryInterface $repository */
      $repository = \Drupal::service('sangel_core.catalog_product_repository');
      $catalogs = $storage->loadMultiple($catalog_ids);
      foreach ($catalogs as $catalog) {
        if (!$catalog instanceof NodeInterface || !$catalog->hasField('field_product_data')) {
          continue;
        }

        $current = (string) $catalog->get('field_product_data')->value;
        if ($current === '') {
          continue;
        }

        $repository->setProductIds($catalog, []);
        $catalog->save();
      }
    }
    catch (\Throwable $throwable) {
      \Drupal::logger('sangel_core_base')->warning('Unable to clear catalog product data for user @uid: @message', [
        '@uid' => $uid,
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  /**
   * Deduces a reasonable file extension from a MIME type.
   */
  protected static function guessExtension(?string $mime): string {
    $mapping = [
      'text/csv' => 'csv',
      'text/plain' => 'txt',
      'application/json' => 'json',
      'application/xml' => 'xml',
      'text/xml' => 'xml',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    $mime = strtolower($mime ?? '');
    return $mapping[$mime] ?? 'csv';
  }

}
