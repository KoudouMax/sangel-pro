<?php

namespace Drupal\sangel_catalog\Form;

use Drupal\Core\Form\FormStateInterface;

class CommercialClientFeedImportForm extends CommercialFeedImportForm {

  protected const FEED_TYPE_ID = 'catalog_client_products_csv';

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    if (isset($form['import_scope'])) {
      $form['import_scope'] = [
        '#type' => 'value',
        '#value' => 'client',
      ];
      $form_state->setValue('import_scope', 'client');
    }

    if (isset($form['client_type'])) {
      $form['client_type']['#access'] = FALSE;
    }

    return $form;
  }

}
