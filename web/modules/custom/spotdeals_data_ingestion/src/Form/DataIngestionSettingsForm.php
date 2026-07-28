<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
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
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
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

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $this->configFactory
      ->getEditable('spotdeals_data_ingestion.settings')
      ->set('page_size', (int) $form_state->getValue('page_size'))
      ->set('max_pages', (int) $form_state->getValue('max_pages'))
      ->set(
        'request_timeout',
        (int) $form_state->getValue('request_timeout'),
      )
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
