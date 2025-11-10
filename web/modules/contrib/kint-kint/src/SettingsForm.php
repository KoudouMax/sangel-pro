<?php

declare(strict_types=1);

namespace Drupal\kint;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Kint\Kint;

/**
 * Settings for kint module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return 'kint_admin_settings';
  }

  /**
   * {@inheritDoc}
   *
   * @return string[]
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames(): array {
    return ['kint.settings'];
  }

  /**
   * {@inheritDoc}
   *
   * @param mixed[] $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return mixed[]
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('kint.settings');

    $old_return = Kint::$return;
    Kint::$return = TRUE;
    $demo_dump = Kint::dump($config);
    Kint::$return = $old_return;

    $form['demo'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Demo dump'),
      'dump' => [
        '#markup' => Markup::create($demo_dump),
      ],
      '#description' => $this->t("This will demonstrate the current Kint settings by dumping its config object. If you don't see anything here, check your permissions. If you clicked the folder icon and are wondering where it went check the bottom of your window."),
    ];

    $form['early_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable early dump'),
      '#description' => $this->t('Whether to enable dumping during load before authentication gives us access to permissions.'),
      '#default_value' => $config->get('early_enable'),
    ];

    $theme_options = [
      'original.css' => $this->t('Default'),
      'aante-light.css' => $this->t('Aante light'),
      'aante-dark.css' => $this->t('Aante dark'),
      'solarized.css' => $this->t('Solarized'),
      'solarized-dark.css' => $this->t('Solarized dark'),
      'custom' => $this->t('Custom'),
    ];
    $selected_theme = $config->get('rich_theme');
    $theme_is_custom = !isset($theme_options[$selected_theme]);

    $form['rich_theme'] = [
      '#tree' => TRUE,
      'select' => [
        '#type' => 'select',
        '#options' => $theme_options,
        '#title' => $this->t('Rich renderer theme'),
        '#description' => $this->t('Kint theme to use for dumps.'),
        '#default_value' => $theme_is_custom ? 'custom' : $selected_theme,
      ],
      'text' => [
        '#type' => 'textfield',
        '#title' => $this->t('Custom theme path'),
        '#description' => $this->t('Full path to the custom CSS file. If the dump looks messed up after this you got the path wrong.'),
        '#default_value' => $theme_is_custom ? $config->get('rich_theme') : '',
        '#states' => [
          'visible' => [
            ':input[name="rich_theme[select]"]' => ['value' => 'custom'],
          ],
        ],
      ],
    ];

    $date_format = $config->get('date_format');
    $form['date_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Date format'),
      '#description' => $this->t(
        'Format for date in dump footer. See <a target="_blank" href=":url">the PHP documentation</a>.',
        [':url' => 'https://www.php.net/datetime.format#refsect1-datetime.format-parameters'],
      ),
      '#default_value' => is_string($date_format) ? $date_format : '',
    ];

    $devel_enabled = 'kint' === $this->config('devel.settings')->get('devel_dumper');

    $form['devel'] = [
      '#type' => 'details',
      '#open' => $devel_enabled,
      '#title' => $this->t('Devel integration'),
      'contents' => [
        'demo' => NULL,
        'use_kint_trace_in_devel' => [
          '#type' => 'checkbox',
          '#title' => $this->t("Override Devel's trace"),
          '#description' => $this->t("Whether to use Kint's trace instead of Devel's in ddebug_backtrace."),
          '#default_value' => $config->get('use_kint_trace_in_devel'),
        ],
      ],
    ];

    if ($devel_enabled) {
      ob_start();
      // @codingStandardsIgnoreStart
      devel_dump($config, '$config');
      ddebug_backtrace();
      // @codingStandardsIgnoreEnd
      $demo_dump = ob_get_clean();

      $form['devel']['contents']['demo'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Demo devel dumps'),
        'dump' => [
          '#markup' => Markup::create($demo_dump),
        ],
      ];
    }
    else {
      $form['devel']['contents']['demo'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => 'messages messages--warning'],
        'value' => [
          'header' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => ['class' => 'messages__header'],
            'value' => [
              '#type' => 'html_tag',
              '#tag' => 'h2',
              '#attributes' => ['class' => 'messages__title'],
              '#value' => $this->t('Warning'),
            ],
          ],
          'content' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => ['class' => 'messages__content'],
            '#value' => $this->t('Devel is not installed and/or Kint is not the selected Devel dumper.'),
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   *
   * @param mixed[] $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('kint.settings');
    $config->set('early_enable', $form_state->getValue('early_enable'));

    $theme = $form_state->getValue('rich_theme');
    if (!is_array($theme)) {
      throw new \UnexpectedValueException();
    }

    $theme['select'] ??= 'original.css';
    if ('custom' === $theme['select']) {
      if (!isset($theme['text'])) {
        throw new \UnexpectedValueException();
      }
      $theme = $theme['text'];
    }
    else {
      $theme = $theme['select'];
    }
    $config->set('rich_theme', $theme);

    $date_format = $form_state->getValue('date_format');
    $config->set('date_format', is_string($date_format) && strlen($date_format) ? $date_format : NULL);

    $config->set('use_kint_trace_in_devel', $form_state->getValue('use_kint_trace_in_devel'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
