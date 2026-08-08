<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for SpotDeals Yelp.
 */
final class YelpSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['spotdeals_yelp.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'spotdeals_yelp_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('spotdeals_yelp.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable SpotDeals Yelp integration'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['sync_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic sync queueing'),
      '#default_value' => (bool) $config->get('sync_enabled'),
    ];

    $form['venue_page_display_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable venue page display output'),
      '#default_value' => (bool) $config->get('venue_page_display_enabled'),
    ];

    $form['card_display_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable compact card display output'),
      '#default_value' => (bool) $config->get('card_display_enabled'),
    ];

    $form['refresh_interval_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh interval (hours)'),
      '#default_value' => (int) $config->get('refresh_interval_hours'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['sync_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Cron queue batch size'),
      '#default_value' => (int) $config->get('sync_batch_size'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['min_match_confidence'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum auto-match confidence'),
      '#default_value' => (float) $config->get('min_match_confidence'),
      '#min' => 0.1,
      '#max' => 1,
      '#step' => 0.01,
    ];

    $form['cache_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => (int) $config->get('cache_ttl_seconds'),
      '#min' => 60,
      '#step' => 60,
    ];

    $form['request_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout (seconds)'),
      '#default_value' => (int) $config->get('request_timeout'),
      '#min' => 1,
      '#step' => 1,
    ];

    $form['default_locale'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Yelp locale'),
      '#default_value' => (string) $config->get('default_locale'),
      '#maxlength' => 16,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Yelp API key'),
      '#default_value' => (string) $config->get('api_key'),
      '#description' => $this->t('This can be left blank here if you override it in settings.php.'),
    ];

    $form['log_verbose'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verbose logging'),
      '#default_value' => (bool) $config->get('log_verbose'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('spotdeals_yelp.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('sync_enabled', (bool) $form_state->getValue('sync_enabled'))
      ->set('venue_page_display_enabled', (bool) $form_state->getValue('venue_page_display_enabled'))
      ->set('card_display_enabled', (bool) $form_state->getValue('card_display_enabled'))
      ->set('refresh_interval_hours', max(1, (int) $form_state->getValue('refresh_interval_hours')))
      ->set('sync_batch_size', max(1, (int) $form_state->getValue('sync_batch_size')))
      ->set('min_match_confidence', (float) $form_state->getValue('min_match_confidence'))
      ->set('cache_ttl_seconds', max(60, (int) $form_state->getValue('cache_ttl_seconds')))
      ->set('request_timeout', max(1, (int) $form_state->getValue('request_timeout')))
      ->set('default_locale', trim((string) $form_state->getValue('default_locale')))
      ->set('api_key', trim((string) $form_state->getValue('api_key')))
      ->set('log_verbose', (bool) $form_state->getValue('log_verbose'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
