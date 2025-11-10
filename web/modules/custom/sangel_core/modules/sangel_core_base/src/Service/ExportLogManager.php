<?php

declare(strict_types=1);

namespace Drupal\sangel_core_base\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\UserInterface;

/**
 * Persists export logs as "export" nodes.
 */
class ExportLogManager {

  use StringTranslationTrait;

  protected bool $prepared = FALSE;

  protected LoggerChannelInterface $logger;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileRepositoryInterface $fileRepository,
    protected readonly FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('sangel_export_log');
  }

  /**
   * Logs a view export operation.
   */
  public function logViewExport(array $metadata, ?FileInterface $file = NULL): void {
    $this->ensureContentType();

    try {
      $user = NULL;
      $uid = isset($metadata['uid']) ? (int) $metadata['uid'] : 0;
      if ($uid > 0) {
        $user = $this->entityTypeManager->getStorage('user')->load($uid);
      }

      if ($file instanceof FileInterface) {
        if ($file->isTemporary()) {
          $file->setPermanent();
          $file->save();
        }
      }

      $view_label = $metadata['view_label'] ?? $metadata['view_id'] ?? 'unknown';
      $display_label = $metadata['display_label'] ?? $metadata['display_id'] ?? 'default';
      $timestamp = \Drupal::time()->getCurrentTime();
      $title = $this->t('Export « @view » du @date', [
        '@view' => $view_label,
        '@date' => date('d/m/Y H:i', $timestamp),
      ]);

      $node_values = [
        'type' => 'export',
        'title' => $title,
        'status' => Node::NOT_PUBLISHED,
      ];
      if ($user instanceof UserInterface) {
        $node_values['uid'] = $user->id();
      }

      $node = Node::create($node_values);

      if ($node->hasField('field_export_file') && $file instanceof FileInterface) {
        $node->set('field_export_file', ['target_id' => $file->id()]);
      }

      if ($node->hasField('field_export_author') && $user instanceof UserInterface) {
        $node->set('field_export_author', ['target_id' => $user->id()]);
      }

      if ($node->hasField('field_export_view')) {
        $node->set('field_export_view', $view_label);
      }

      if ($node->hasField('field_export_display')) {
        $node->set('field_export_display', $display_label);
      }

      if ($node->hasField('field_export_context')) {
        $context = [
          'view_id' => $metadata['view_id'] ?? '',
          'display_id' => $metadata['display_id'] ?? '',
          'args' => $metadata['args'] ?? [],
          'exposed_input' => $metadata['exposed_input'] ?? [],
          'query_parameters' => $metadata['query'] ?? [],
          'format' => $metadata['format'] ?? '',
          'file_uri' => $file instanceof FileInterface ? $file->getFileUri() : ($metadata['file_uri'] ?? ''),
          'file_url' => $metadata['file_url'] ?? '',
          'redirect_url' => $metadata['redirect_url'] ?? '',
        ];
        $node->set('field_export_context', Json::encode($context));
      }

      $node->save();

      $this->logger->notice('Export enregistré pour @view / @display (node: @nid).', [
        '@view' => $view_label,
        '@display' => $display_label,
        '@nid' => $node->id(),
      ]);
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Unable to log export: @message', ['@message' => $throwable->getMessage()]);
    }
  }

  /**
   * Writes inline export contents to a managed file and returns it.
   */
  public function persistInlineFile(string $contents, string $baseFilename, string $extension = 'csv'): ?FileInterface {
    try {
      $directory = 'private://exports';
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $sanitized = preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseFilename);
      $sanitized = trim($sanitized ?: 'export', '-');
      $timestamp = date('Ymd-His', \Drupal::time()->getCurrentTime());
      $destination = $directory . '/' . $sanitized . '-' . $timestamp . '.' . $extension;

      $file = $this->fileRepository->writeData($contents, $destination, FileSystemInterface::EXISTS_REPLACE);
      $file->setPermanent();
      $file->save();
      return $file;
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Unable to persist export file: @message', ['@message' => $throwable->getMessage()]);
      return NULL;
    }
  }

  /**
   * Ensures the export content type and fields exist.
   */
  protected function ensureContentType(): void {
    if ($this->prepared) {
      return;
    }

    $type_storage = $this->entityTypeManager->getStorage('node_type');
    $type = $type_storage->load('export');
    if (!$type) {
      $type = NodeType::create([
        'type' => 'export',
        'name' => $this->t('Export'),
      ]);
      $type->setNewRevision(TRUE);
      $type->save();
    }

    $this->ensureField('field_export_file', 'file', [
      'uri_scheme' => 'private',
      'cardinality' => 1,
    ], [
      'label' => $this->t('Export file'),
      'description' => $this->t('Fichier généré par l’export.'),
      'settings' => [
        'display_field' => FALSE,
        'description_field' => FALSE,
      ],
    ]);

    $this->ensureField('field_export_author', 'entity_reference', [
      'target_type' => 'user',
      'cardinality' => 1,
    ], [
      'label' => $this->t('Auteur de l’export'),
      'description' => $this->t('Utilisateur qui a déclenché l’export.'),
      'settings' => [
        'handler' => 'default',
      ],
    ]);

    $this->ensureField('field_export_view', 'string', [
      'max_length' => 255,
    ], [
      'label' => $this->t('Vue'),
      'description' => $this->t('Nom de la vue ayant généré l’export.'),
    ]);

    $this->ensureField('field_export_display', 'string', [
      'max_length' => 255,
    ], [
      'label' => $this->t('Display'),
      'description' => $this->t('Sous-affichage utilisé pour l’export.'),
    ]);

    $this->ensureField('field_export_context', 'string_long', [
      'cardinality' => 1,
    ], [
      'label' => $this->t('Contexte'),
      'description' => $this->t('Paramètres techniques de l’export (arguments, filtres, etc.).'),
    ]);

    $this->ensureField('field_export_note', 'text_long', [
      'cardinality' => 1,
    ], [
      'label' => $this->t('Note commerciale'),
      'description' => $this->t('Commentaire interne ajouté par le commercial.'),
      'settings' => [
        'text_processing' => 0,
      ],
    ]);

    $this->ensureFormDisplay();
    $this->ensureViewDisplay();

    $this->prepared = TRUE;
  }

  /**
   * Ensures a specific field exists on the export content type.
   */
  protected function ensureField(string $field_name, string $type, array $storage_settings = [], array $field_settings = []): void {
    $storage = FieldStorageConfig::loadByName('node', $field_name);
    if (!$storage) {
      $storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $storage_settings,
        'cardinality' => $storage_settings['cardinality'] ?? 1,
        'translatable' => FALSE,
      ]);
      $storage->save();
    }

    if (!FieldConfig::loadByName('node', 'export', $field_name)) {
      $field_config = FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'export',
        'label' => $field_settings['label'] ?? ucfirst(str_replace('_', ' ', $field_name)),
        'description' => $field_settings['description'] ?? '',
        'settings' => $field_settings['settings'] ?? [],
      ]);
      $field_config->save();
    }
  }

  /**
   * Ensures the export form display exposes the note field.
   */
  protected function ensureFormDisplay(): void {
    $form_display = EntityFormDisplay::load('node.export.default');
    if (!$form_display) {
      $form_display = EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'export',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    $component = $form_display->getComponent('field_export_note');
    if (!$component) {
      $form_display->setComponent('field_export_note', [
        'type' => 'text_textarea',
        'weight' => 20,
        'settings' => [
          'rows' => 5,
          'placeholder' => '',
        ],
      ]);
      $form_display->save();
    }
  }

  /**
   * Ensures the export view display can render the note field.
   */
  protected function ensureViewDisplay(): void {
    $view_display = EntityViewDisplay::load('node.export.default');
    if (!$view_display) {
      $view_display = EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'export',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    $component = $view_display->getComponent('field_export_note');
    if (!$component) {
      $view_display->setComponent('field_export_note', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 25,
        'settings' => [],
      ]);
      $view_display->save();
    }
  }

}
