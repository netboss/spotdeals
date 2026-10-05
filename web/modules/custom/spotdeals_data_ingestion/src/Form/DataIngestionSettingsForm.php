<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures SpotDeals external data ingestion.
 */
final class DataIngestionSettingsForm extends ConfigFormBase {

  private const API_KEY_STATE_NAME =
    'spotdeals_data_ingestion.geoapify_api_key';

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly StateInterface $state,
    private readonly VenueTypeResolver $venueTypeResolver,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
      $container->get('Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_data_ingestion_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return [
      'spotdeals_data_ingestion.settings',
    ];
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $config = $this->config('spotdeals_data_ingestion.settings');

    $existingApiKey = trim((string) $this->state->get(
      self::API_KEY_STATE_NAME,
      '',
    ));

    $form['geoapify'] = [
      '#type' => 'details',
      '#title' => $this->t('Geoapify'),
      '#open' => TRUE,
    ];

    $form['geoapify']['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t('API key status'),
      '#markup' => $existingApiKey !== ''
        ? $this->t('A Geoapify API key is currently saved.')
        : $this->t('No Geoapify API key is currently saved.'),
    ];

    $form['geoapify']['geoapify_api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Geoapify API key'),
      '#description' => $this->t(
        'Enter a new key to save or replace the existing key. Leave blank to keep the current key.',
      ),
      '#attributes' => [
        'autocomplete' => 'new-password',
      ],
    ];

    $form['geoapify']['clear_geoapify_api_key'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove the currently saved API key'),
      '#default_value' => FALSE,
    ];

    $form['requests'] = [
      '#type' => 'details',
      '#title' => $this->t('Request defaults'),
      '#open' => TRUE,
    ];

    $form['requests']['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size'),
      '#default_value' => $config->get('page_size') ?? 100,
      '#min' => 1,
      '#max' => 500,
      '#required' => TRUE,
    ];

    $form['requests']['max_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum pages'),
      '#default_value' => $config->get('max_pages') ?? 50,
      '#min' => 1,
      '#max' => 1000,
      '#required' => TRUE,
    ];

    $form['requests']['request_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout'),
      '#description' => $this->t('Timeout in seconds for each API request.'),
      '#default_value' => $config->get('request_timeout') ?? 30,
      '#min' => 1,
      '#max' => 120,
      '#required' => TRUE,
    ];

    $form['deal_discovery'] = [
      '#type' => 'details',
      '#title' => $this->t('Deal discovery automation'),
      '#open' => FALSE,
    ];

    $form['deal_discovery']['deal_discovery_auto_approve_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Automatic-approval minimum score'),
      '#default_value' => $config->get('deal_discovery_auto_approve_score'),
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];

    $form['deal_discovery']['deal_discovery_auto_approve_location_confidence'] = [
      '#type' => 'number',
      '#title' => $this->t('Automatic-approval minimum location confidence'),
      '#default_value' => $config->get('deal_discovery_auto_approve_location_confidence'),
      '#min' => 0,
      '#max' => 10,
      '#required' => TRUE,
    ];

    $form['deal_discovery']['deal_discovery_auto_approve_require_schedule'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require schedule or validity context for automatic approval'),
      '#default_value' => $config->get('deal_discovery_auto_approve_require_schedule'),
    ];

    $form['deal_discovery']['deal_discovery_auto_publish_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically publish ready auto-approved candidates via cron'),
      '#description' => $this->t('Enabled by default. High-confidence candidates are queued only after the exact publishing preview is ready. Drupal cron then publishes them in bounded batches through the controlled publisher. Candidates that become blocked are routed to manual review; write-time failures remain queued for retry.'),
      '#default_value' => (bool) ($config->get('deal_discovery_auto_publish_enabled') ?? TRUE),
    ];

    $form['deal_discovery']['deal_discovery_auto_publish_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Automatic publishing batch size per cron run'),
      '#description' => $this->t('Maximum number of queued auto-approved candidates cron will process in one run. Oldest queued candidates are processed first.'),
      '#default_value' => (int) ($config->get('deal_discovery_auto_publish_batch_size') ?? 25),
      '#min' => 1,
      '#max' => 200,
      '#required' => TRUE,
    ];

    $venueTypeOptions = [];
    foreach ($this->venueTypeResolver->mappedVenueTypes() as $definition) {
      $venueTypeOptions[(string) $definition['tid']] = (string) $definition['name'];
    }
    natcasesort($venueTypeOptions);

    $form['scheduled_discovery'] = [
      '#type' => 'details',
      '#title' => $this->t('Scheduled deal discovery'),
      '#open' => FALSE,
      '#description' => $this->t('Runs the same discovery pipeline as the manual Deal Discovery form, one category/location pair at a time, with cooldown and Pending-backlog protection.'),
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable scheduled deal discovery via cron'),
      '#default_value' => (bool) ($config->get('deal_discovery_scheduler_enabled') ?? FALSE),
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_venue_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Automated venue categories'),
      '#options' => $venueTypeOptions,
      '#default_value' => array_map('strval', (array) ($config->get('deal_discovery_scheduler_venue_types') ?? [])),
      '#description' => $this->t('Choose only the dynamic/high-value categories that should be rediscovered automatically. The scheduler uses their existing Geoapify mappings.'),
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_refresh_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Rediscovery interval (days)'),
      '#default_value' => (int) ($config->get('deal_discovery_scheduler_refresh_days') ?? 7),
      '#min' => 1,
      '#max' => 30,
      '#required' => TRUE,
      '#description' => $this->t('A category/location pair becomes eligible again after this many days. Start with 7 days.'),
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_minimum_interval_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum time between automated discovery runs (minutes)'),
      '#default_value' => (int) ($config->get('deal_discovery_scheduler_minimum_interval_minutes') ?? 60),
      '#min' => 5,
      '#max' => 1440,
      '#required' => TRUE,
      '#description' => $this->t('Even when many category/location pairs are due, cron will start at most one discovery after this cooldown.'),
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_candidate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Automated candidate limit'),
      '#default_value' => (int) ($config->get('deal_discovery_scheduler_candidate_limit') ?? 25),
      '#min' => 1,
      '#max' => 50,
      '#required' => TRUE,
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_site_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Website pages per automated candidate'),
      '#default_value' => (int) ($config->get('deal_discovery_scheduler_site_pages') ?? 5),
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_pause_pending'] = [
      '#type' => 'number',
      '#title' => $this->t('Pause automation at Pending count'),
      '#default_value' => (int) ($config->get('deal_discovery_scheduler_pause_pending') ?? 300),
      '#min' => 1,
      '#max' => 10000,
      '#required' => TRUE,
    ];

    $form['scheduled_discovery']['deal_discovery_scheduler_resume_pending'] = [
      '#type' => 'number',
      '#title' => $this->t('Resume automation at or below Pending count'),
      '#default_value' => (int) ($config->get('deal_discovery_scheduler_resume_pending') ?? 250),
      '#min' => 0,
      '#max' => 9999,
      '#required' => TRUE,
      '#description' => $this->t('Keep this below the pause threshold to prevent rapid pause/resume toggling.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $pauseAt = (int) $form_state->getValue('deal_discovery_scheduler_pause_pending');
    $resumeAt = (int) $form_state->getValue('deal_discovery_scheduler_resume_pending');
    if ($resumeAt >= $pauseAt) {
      $form_state->setErrorByName(
        'deal_discovery_scheduler_resume_pending',
        $this->t('The resume threshold must be lower than the pause threshold.'),
      );
    }
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $selectedVenueTypes = array_values(array_map('intval', array_filter(
      (array) $form_state->getValue('deal_discovery_scheduler_venue_types'),
      static fn (mixed $value): bool => (string) $value !== '0' && (string) $value !== '',
    )));

    $this->configFactory
      ->getEditable('spotdeals_data_ingestion.settings')
      ->set('page_size', (int) $form_state->getValue('page_size'))
      ->set('max_pages', (int) $form_state->getValue('max_pages'))
      ->set(
        'request_timeout',
        (int) $form_state->getValue('request_timeout'),
      )
      ->set('deal_discovery_auto_approve_score', (int) $form_state->getValue('deal_discovery_auto_approve_score'))
      ->set('deal_discovery_auto_approve_location_confidence', (int) $form_state->getValue('deal_discovery_auto_approve_location_confidence'))
      ->set('deal_discovery_auto_approve_require_schedule', (bool) $form_state->getValue('deal_discovery_auto_approve_require_schedule'))
      ->set('deal_discovery_auto_publish_enabled', (bool) $form_state->getValue('deal_discovery_auto_publish_enabled'))
      ->set('deal_discovery_auto_publish_batch_size', max(1, min(200, (int) $form_state->getValue('deal_discovery_auto_publish_batch_size'))))
      ->set('deal_discovery_scheduler_enabled', (bool) $form_state->getValue('deal_discovery_scheduler_enabled'))
      ->set('deal_discovery_scheduler_venue_types', $selectedVenueTypes)
      ->set('deal_discovery_scheduler_refresh_days', max(1, min(30, (int) $form_state->getValue('deal_discovery_scheduler_refresh_days'))))
      ->set('deal_discovery_scheduler_minimum_interval_minutes', max(5, min(1440, (int) $form_state->getValue('deal_discovery_scheduler_minimum_interval_minutes'))))
      ->set('deal_discovery_scheduler_candidate_limit', max(1, min(50, (int) $form_state->getValue('deal_discovery_scheduler_candidate_limit'))))
      ->set('deal_discovery_scheduler_site_pages', max(1, min(10, (int) $form_state->getValue('deal_discovery_scheduler_site_pages'))))
      ->set('deal_discovery_scheduler_pause_pending', max(1, (int) $form_state->getValue('deal_discovery_scheduler_pause_pending')))
      ->set('deal_discovery_scheduler_resume_pending', max(0, (int) $form_state->getValue('deal_discovery_scheduler_resume_pending')))
      ->save();

    $clearKey = (bool) $form_state->getValue(
      'clear_geoapify_api_key',
    );

    $newApiKey = trim((string) $form_state->getValue(
      'geoapify_api_key',
    ));

    if ($clearKey) {
      $this->state->delete(self::API_KEY_STATE_NAME);
      $this->messenger()->addStatus(
        $this->t('The Geoapify API key was removed.'),
      );
    }
    elseif ($newApiKey !== '') {
      $this->state->set(self::API_KEY_STATE_NAME, $newApiKey);
      $this->messenger()->addStatus(
        $this->t('The Geoapify API key was saved.'),
      );
    }

    parent::submitForm($form, $form_state);
  }

}
