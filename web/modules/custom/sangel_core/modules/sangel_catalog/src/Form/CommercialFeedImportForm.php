<?php

declare(strict_types=1);

namespace Drupal\sangel_catalog\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\sangel_catalog\Service\CatalogImportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a simplified upload form for commercial catalog imports.
 */
class CommercialFeedImportForm extends FormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Catalog import manager service.
   */
  protected CatalogImportManager $catalogImportManager;

  /**
   * Prefilled client context when embedding the form.
   */
  protected ?NodeInterface $prefillClient = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $form */
    $form = parent::create($container);
    $form->setStringTranslation($container->get('string_translation'));
    $form->setMessenger($container->get('messenger'));
    $form->setLoggerFactory($container->get('logger.factory'));
    $form->entityTypeManager = $container->get('entity_type.manager');
    /** @var \Drupal\sangel_catalog\Service\CatalogImportManager $import_manager */
    $import_manager = $container->get('sangel_catalog.import_manager');
    $form->catalogImportManager = $import_manager;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sangel_catalog_commercial_feed_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->prefillClient = $this->prefillClient ?: $this->resolvePrefillClientFromArgs($form_state);
    if ($this->prefillClient instanceof NodeInterface) {
      if (!$form_state->hasValue('import_scope')) {
        $form_state->setValue('import_scope', 'client');
      }
      if (!$form_state->hasValue('client')) {
        $form_state->setValue('client', ['target_id' => $this->prefillClient->id()]);
      }
    }

    $form['#attached']['library'][] = 'sangel_catalog/import-feedback';
    $form['#attached']['library'][] = 'sangel_catalog/import_form';
    $form['#attributes']['class'][] = 'sangel-catalog-import-form';

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sangel-catalog-import__intro']],
      'content' => [
        '#markup' => $this->t('<p>Téléchargez le fichier CSV fourni par le client. Le catalogue associé sera mis à jour automatiquement après import.</p>'),
      ],
    ];

    $scope = $form_state->getValue('import_scope');
    if (!in_array($scope, ['client', 'type'], TRUE)) {
      $scope = 'client';
    }
    if ($this->prefillClient instanceof NodeInterface) {
      $scope = 'client';
      $form_state->setValue('import_scope', $scope);
    }

    $form['import_scope'] = [
      '#type' => 'radios',
      '#title' => $this->t('Importer pour'),
      '#options' => [
        'client' => $this->t('Un client spécifique'),
        'type' => $this->t('Un type de client'),
      ],
      '#default_value' => $scope,
      '#attributes' => ['class' => ['flex', 'flex-col', 'gap-2', 'sm:flex-row', 'sm:items-center', 'sm:gap-4']],
      '#ajax' => [
        'callback' => '::refreshClientSummary',
        'wrapper' => 'sangel-catalog-client-summary',
        'event' => 'change',
      ],
    ];

    if ($this->prefillClient instanceof NodeInterface) {
      $form['import_scope']['#type'] = 'hidden';
      $form['import_scope']['#value'] = 'client';
      $form['import_scope']['#default_value'] = 'client';
      unset($form['import_scope']['#options'], $form['import_scope']['#ajax']);
      $form['import_scope']['#attributes'] = [];
    }

    $form['client'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Client'),
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => ['client'],
      ],
      '#required' => FALSE,
      '#description' => $this->t('Sélectionnez le client pour lequel vous souhaitez mettre à jour le catalogue.'),
      '#ajax' => [
        'callback' => '::refreshClientSummary',
        'wrapper' => 'sangel-catalog-client-summary',
        'event' => 'autocompleteclose',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
      '#states' => [
        'visible' => [
          ':input[name="import_scope"]' => ['value' => 'client'],
        ],
        'required' => [
          ':input[name="import_scope"]' => ['value' => 'client'],
        ],
      ],
    ];

    if ($this->prefillClient instanceof NodeInterface) {
      $form['client']['#default_value'] = $this->prefillClient;
      $form['client']['#states'] = [];
      $form['client']['#required'] = TRUE;
    }

    $form['client_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type de client'),
      '#options' => $this->getClientTypeOptions(),
      '#empty_option' => $this->t('- Sélectionnez -'),
      '#required' => FALSE,
      '#description' => $this->t('Choisissez un type pour importer automatiquement dans les catalogues de tous les clients associés.'),
      '#ajax' => [
        'callback' => '::refreshClientSummary',
        'wrapper' => 'sangel-catalog-client-summary',
        'event' => 'change',
      ],
      '#states' => [
        'visible' => [
          ':input[name="import_scope"]' => ['value' => 'type'],
        ],
        'required' => [
          ':input[name="import_scope"]' => ['value' => 'type'],
        ],
      ],
    ];

    if ($this->prefillClient instanceof NodeInterface) {
      $form['client_type']['#access'] = FALSE;
    }

    $selected_client = $this->resolveSelectedClient($form_state);
    $selected_type = $this->resolveSelectedClientType($form_state);

    $form['client_summary'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'sangel-catalog-client-summary',
        'class' => ['space-y-3', 'rounded-2xl', 'border', 'border-sangel-ice', 'bg-white', 'p-5', 'shadow-sm'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Aperçu'),
        '#attributes' => [
          'class' => ['text-xs', 'font-semibold', 'uppercase', 'tracking-[0.3em]', 'text-sangel-text/50'],
        ],
      ],
      'body' => [
        '#markup' => $this->buildSelectionSummaryMarkup($scope, $selected_client, $selected_type),
        '#allowed_tags' => ['p', 'span', 'strong', 'ul', 'li'],
      ],
    ];

    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Fichier CSV'),
      '#required' => TRUE,
      '#upload_location' => 'private://sangel_catalog/imports',
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'csv',
        ],
      ],
      '#description' => $this->t('Le fichier doit respecter le modèle fourni (séparateur ;).'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Importer le catalogue'),
    ];

    $form['status_indicator'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sangel-catalog-import__status'],
        'data-sangel-import-status' => 'pending',
        'aria-live' => 'polite',
        'aria-hidden' => 'true',
      ],
      'content' => [
        '#markup' => Markup::create('<div class="sangel-catalog-import__status-inner"><span class="sangel-catalog-import__spinner" aria-hidden="true"></span><span class="sangel-catalog-import__message">' . $this->t('Import en cours... Merci de patienter.') . '</span></div>'),
      ],
      '#weight' => 100,
    ];

    return $form;
  }

  /**
   * Determines if the form is prefilled with a specific client.
   */
  protected function resolvePrefillClientFromArgs(FormStateInterface $form_state): ?NodeInterface {
    $build_info = $form_state->getBuildInfo();
    $args = $build_info['args'] ?? [];
    if (!$args) {
      return NULL;
    }

    $candidate = $args[0];
    if (is_array($candidate) && array_key_exists('prefill_client', $candidate)) {
      $candidate = $candidate['prefill_client'];
    }

    if ($candidate instanceof NodeInterface) {
      return $candidate;
    }

    if (is_numeric($candidate)) {
      return $this->entityTypeManager->getStorage('node')->load((int) $candidate) ?: NULL;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $scope = $form_state->getValue('import_scope') ?? 'client';

    if ($scope === 'type') {
      $client_type = $this->resolveSelectedClientType($form_state);
      if (!$client_type) {
        $form_state->setErrorByName('client_type', $this->t('Sélectionnez un type de client valide.'));
      }
      else {
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', 'client')
          ->condition('field_client_type', $client_type->id())
          ->accessCheck(TRUE)
          ->range(0, 1);
        if (!$query->execute()) {
          $form_state->setErrorByName('client_type', $this->t('Aucun client n’est associé à ce type.'));
        }
      }
    }
    else {
      $client = $this->resolveSelectedClient($form_state);
      if (!$client instanceof NodeInterface || $client->bundle() !== 'client') {
        $form_state->setErrorByName('client', $this->t('Sélectionnez un client valide.'));
        return;
      }

      if (!$client->access('view')) {
        $form_state->setErrorByName('client', $this->t("Vous n'avez pas l'autorisation de gérer ce client."));
      }
    }

    $csv_value = $form_state->getValue('csv_file');
    $csv_fid = is_array($csv_value) ? reset($csv_value) : NULL;
    if (!$csv_fid || !File::load($csv_fid)) {
      $form_state->setErrorByName('csv_file', $this->t('Le fichier CSV est obligatoire.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $scope = $form_state->getValue('import_scope') ?? 'client';

    $csv_value = $form_state->getValue('csv_file');
    $csv_fid = is_array($csv_value) ? reset($csv_value) : NULL;
    /** @var \Drupal\file\Entity\File|null $csv_file */
    $csv_file = $csv_fid ? File::load($csv_fid) : NULL;
    if (!$csv_file) {
      $this->messenger()->addError($this->t('Le fichier fourni est invalide.'));
      return;
    }
    $csv_file->setPermanent();
    $csv_file->save();
    $this->ensureUtf8Encoding($csv_file);

    $skus = $this->catalogImportManager->parseCsvForSkus($csv_file);
    if (!$skus) {
      $this->messenger()->addError($this->t('Aucune référence produit valide n’a été trouvée dans le fichier CSV.'));
      return;
    }

    $sku_map = $this->catalogImportManager->mapSkusToProductIds($skus);
    if (!$sku_map) {
      $this->messenger()->addError($this->t('Les références du fichier ne correspondent à aucun produit existant.'));
      return;
    }

    $missing_skus = array_values(array_diff($skus, array_keys($sku_map)));
    $product_ids = array_values($sku_map);

    if (!$product_ids) {
      $this->messenger()->addError($this->t('Aucun produit valide n’a été trouvé pour mettre à jour le catalogue.'));
      return;
    }

    $revision_user_id = $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : NULL;

    if ($scope === 'type') {
      $client_type = $this->resolveSelectedClientType($form_state);
      if (!$client_type) {
        $this->messenger()->addError($this->t('Impossible de charger le type de client sélectionné.'));
        return;
      }

      $client_storage = $this->entityTypeManager->getStorage('node');
      $client_ids = $client_storage->getQuery()
        ->condition('type', 'client')
        ->condition('field_client_type', $client_type->id())
        ->accessCheck(TRUE)
        ->execute();

      if (!$client_ids) {
        $this->messenger()->addError($this->t('Aucun client n’est associé au type « @type ».', ['@type' => $client_type->label()]));
        return;
      }

      $clients = array_filter($client_storage->loadMultiple($client_ids), static fn($item) => $item instanceof NodeInterface);
      $result = $this->catalogImportManager->synchronizeClients($clients, $product_ids, $client_type, $revision_user_id);
      $this->dispatchResults($result, $missing_skus);
    }
    else {
      $client = $this->resolveSelectedClient($form_state);
      if (!$client instanceof NodeInterface) {
        $this->messenger()->addError($this->t('Impossible de charger le client sélectionné.'));
        return;
      }
      $result = $this->catalogImportManager->synchronizeClients([$client], $product_ids, NULL, $revision_user_id);
      $this->dispatchResults($result, $missing_skus);
    }

    $form_state->setRedirect('<current>');
  }

  /**
   * Ensures the uploaded CSV file is UTF-8 encoded.
   */
  protected function ensureUtf8Encoding(File $file): void {
    $file_system = \Drupal::service('file_system');
    $path = $file_system->realpath($file->getFileUri());
    if (!$path || !is_file($path) || !is_readable($path)) {
      return;
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE || $contents === '') {
      return;
    }

    // If the file is already valid UTF-8, nothing to do.
    if (mb_detect_encoding($contents, 'UTF-8', TRUE)) {
      return;
    }

    $encoding = mb_detect_encoding($contents, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252'], TRUE) ?: 'ISO-8859-1';
    $converted = mb_convert_encoding($contents, 'UTF-8', $encoding);
    if ($converted === FALSE) {
      $converted = iconv($encoding, 'UTF-8//TRANSLIT', $contents);
    }

    if ($converted !== FALSE && $converted !== $contents) {
      file_put_contents($path, $converted);
    }
  }

  /**
   * Emits messages summarising the import result.
   */
  protected function dispatchResults(array $result, array $missing_skus): void {
    if (!empty($result['processed'])) {
      $this->messenger()->addStatus($this->t('Catalogue(s) mis à jour pour @count client(s).', ['@count' => $result['processed']]));
    }

    if ($result['created'] > 0) {
      $this->messenger()->addStatus($this->t('@count catalogue(s) personnalisé(s) ont été créé(s).', ['@count' => $result['created']]));
    }

    if (!empty($result['updated'])) {
      $this->messenger()->addStatus($this->t('@count catalogue(s) existant(s) ont été mis à jour.', ['@count' => $result['updated']]));
    }

    if ($missing_skus) {
      $display = array_slice($missing_skus, 0, 10);
      $message = $this->t('Certaines références n’ont pas été trouvées et ont été ignorées : @list', ['@list' => implode(', ', $display)]);
      if (count($missing_skus) > 10) {
        $message = $this->t('Certaines références n’ont pas été trouvées et ont été ignorées (affichage limité) : @list', ['@list' => implode(', ', $display)]);
      }
      $this->messenger()->addWarning($message);
    }

    foreach ($result['errors'] as $error_message) {
      $this->messenger()->addError($error_message);
    }
  }

  /**
   * Ajax callback updating the client summary block.
   */
  public function refreshClientSummary(array &$form, FormStateInterface $form_state): array {
    return $form['client_summary'];
  }

  /**
   * Builds the summary markup depending on the selected scope.
   */
  protected function buildSelectionSummaryMarkup(string $scope, ?NodeInterface $client, ?TermInterface $client_type): string {
    if ($scope === 'type') {
      if (!$client_type instanceof TermInterface) {
        return '<p class="text-sm text-sangel-text/60">' . Html::escape($this->t('Sélectionnez un type de client pour afficher le détail.')) . '</p>';
      }

      $storage = $this->entityTypeManager->getStorage('node');
      $client_ids = $storage->getQuery()
        ->condition('type', 'client')
        ->condition('field_client_type', $client_type->id())
        ->accessCheck(TRUE)
        ->execute();

      $count = count($client_ids);
      if (!$count) {
        return '<p class="text-sm text-sangel-text/60">' . Html::escape($this->t('Aucun client n’est associé à « @type ».', ['@type' => $client_type->label()])) . '</p>';
      }

      $clients = $storage->loadMultiple(array_slice(array_values($client_ids), 0, 5));
      $items = [];
      foreach ($clients as $item) {
        if ($item instanceof NodeInterface) {
          $items[] = '<li>' . Html::escape($item->label()) . '</li>';
        }
      }

      $summary = '<p class="text-sm font-semibold text-sangel-text">' . Html::escape($client_type->label()) . '</p>';
      $summary .= '<p class="text-xs text-sangel-text/60">' . Html::escape($this->t('@count client(s) associé(s).', ['@count' => $count])) . '</p>';
      if ($items) {
        $summary .= '<ul class="space-y-1 list-disc pl-5 text-xs text-sangel-text/70">' . implode('', $items) . '</ul>';
      }

      return $summary;
    }

    if (!$client instanceof NodeInterface) {
      return '<p class="text-sm text-sangel-text/60">' . Html::escape($this->t('Sélectionnez un client pour afficher ses informations.')) . '</p>';
    }

    $rows = [];
    if ($client->hasField('field_client_type') && !$client->get('field_client_type')->isEmpty()) {
      $type_entity = $client->get('field_client_type')->entity;
      if ($type_entity) {
        $rows[] = '<li><strong>' . Html::escape($this->t('Type')) . '</strong> : ' . Html::escape($type_entity->label()) . '</li>';
      }
    }

    if ($client->hasField('field_reason_social') && !$client->get('field_reason_social')->isEmpty()) {
      $rows[] = '<li><strong>' . Html::escape($this->t('Société')) . '</strong> : ' . Html::escape($client->get('field_reason_social')->value) . '</li>';
    }

    if ($client->hasField('field_mail') && !$client->get('field_mail')->isEmpty()) {
      $rows[] = '<li><strong>' . Html::escape($this->t('Email')) . '</strong> : ' . Html::escape($client->get('field_mail')->value) . '</li>';
    }

    if (!$rows) {
      $rows[] = '<li>' . Html::escape($this->t('Aucune information complémentaire.')) . '</li>';
    }

    return '<p class="text-sm font-semibold text-sangel-text">' . Html::escape($client->label()) . '</p>'
      . '<ul class="space-y-1 list-disc pl-5 text-xs text-sangel-text/70">' . implode('', $rows) . '</ul>';
  }

  /**
   * Resolves the selected client from the form state.
   */
  protected function resolveSelectedClient(FormStateInterface $form_state): ?NodeInterface {
    $value = $form_state->getValue('client');
    $target_id = NULL;

    if (is_array($value) && isset($value['target_id'])) {
      $target_id = (int) $value['target_id'];
    }
    elseif (is_numeric($value)) {
      $target_id = (int) $value;
    }
    elseif (is_string($value) && $value !== '') {
      $target_id = (int) EntityAutocomplete::extractEntityIdFromAutocompleteInput($value);
    }

    return $target_id ? $this->entityTypeManager->getStorage('node')->load($target_id) : NULL;
  }

  /**
   * Resolves the selected client type from the form state.
   */
  protected function resolveSelectedClientType(FormStateInterface $form_state): ?TermInterface {
    $value = $form_state->getValue('client_type');
    $target_id = is_numeric($value) ? (int) $value : NULL;

    return $target_id ? $this->entityTypeManager->getStorage('taxonomy_term')->load($target_id) : NULL;
  }

  /**
   * Builds the options list for the client type select element.
   */
  protected function getClientTypeOptions(): array {
    $options = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('client_type');
    foreach ($terms as $term) {
      $options[$term->tid] = $term->name;
    }
    return $options;
  }

}
