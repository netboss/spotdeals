<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\spotdeals_data_ingestion\Service\ExternalVenueReportStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class ExternalVenueReportForm extends FormBase {

  public function __construct(
    private readonly ExternalVenueReportStorage $storage,
    private readonly FloodInterface $flood,
    private readonly RequestStack $externalVenueRequestStack,
    private readonly AccountProxyInterface $account,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.external_venue_report_storage'),
      $container->get('flood'),
      $container->get('request_stack'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_external_venue_report_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->externalVenueRequestStack->getCurrentRequest();
    $source = trim((string) $request?->query->get('external_source', ''));
    $externalId = trim((string) $request?->query->get('external_id', ''));
    $name = trim((string) $request?->query->get('venue_name', ''));
    $address = trim((string) $request?->query->get('venue_address', ''));

    if ($source === '' || $externalId === '' || $name === '') {
      $form['error'] = ['#markup' => '<p>' . $this->t('The external venue information is incomplete. Please return to the search results and try again.') . '</p>'];
      return $form;
    }

    $form['intro'] = ['#markup' => '<p>' . $this->t('Report incorrect or outdated information for <strong>@venue</strong>. Reports are reviewed before a venue is hidden.', ['@venue' => $name]) . '</p>'];
    $form['external_source'] = ['#type' => 'hidden', '#value' => $source];
    $form['external_id'] = ['#type' => 'hidden', '#value' => $externalId];
    $form['venue_name'] = ['#type' => 'hidden', '#value' => $name];
    $form['venue_address'] = ['#type' => 'hidden', '#value' => $address];
    $form['venue'] = ['#type' => 'item', '#title' => $this->t('Venue'), '#markup' => $name];
    $form['address'] = ['#type' => 'item', '#title' => $this->t('Address'), '#markup' => $address ?: $this->t('Not available')];
    $form['reason'] = [
      '#type' => 'select', '#title' => $this->t('What is wrong?'), '#required' => TRUE,
      '#options' => [
        'closed' => $this->t('Permanently closed'),
        'wrong_address' => $this->t('Wrong address'),
        'duplicate' => $this->t('Duplicate venue'),
        'incorrect_information' => $this->t('Incorrect business information'),
        'not_a_venue' => $this->t('Not a real venue'),
        'other' => $this->t('Other'),
      ],
      '#empty_option' => $this->t('- Select a reason -'),
    ];
    $form['details'] = ['#type' => 'textarea', '#title' => $this->t('Additional details'), '#maxlength' => 1000, '#rows' => 5, '#description' => $this->t('Optional. Include any details that may help an administrator verify the report.')];
    $form['email'] = ['#type' => 'email', '#title' => $this->t('Email'), '#required' => FALSE, '#maxlength' => 254, '#description' => $this->t('Optional. Used only if we need clarification about this report.')];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Submit report'), '#button_type' => 'primary'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $request = $this->externalVenueRequestStack->getCurrentRequest();
    $identifier = hash('sha256', (string) $request?->getClientIp());
    if (!$this->flood->isAllowed('spotdeals_external_venue_report', 5, 3600, $identifier)) {
      $form_state->setErrorByName('reason', $this->t('Too many reports were submitted from this connection. Please try again later.'));
    }

    $source = trim((string) $form_state->getValue('external_source'));
    $externalId = trim((string) $form_state->getValue('external_id'));
    if (!preg_match('/^[a-z0-9_\-]+$/i', $source) || $externalId === '' || strlen($externalId) > 255) {
      $form_state->setErrorByName('reason', $this->t('The external venue identifier is invalid.'));
    }

    if ($this->storage->hasRecentDuplicate($source, $externalId, (int) $this->account->id(), $identifier)) {
      $form_state->setErrorByName('reason', $this->t('A report for this venue was already submitted recently.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $request = $this->externalVenueRequestStack->getCurrentRequest();
    $identifier = hash('sha256', (string) $request?->getClientIp());
    $this->storage->create([
      'external_source' => trim((string) $form_state->getValue('external_source')),
      'external_id' => trim((string) $form_state->getValue('external_id')),
      'venue_name' => trim((string) $form_state->getValue('venue_name')),
      'venue_address' => trim((string) $form_state->getValue('venue_address')),
      'reason' => (string) $form_state->getValue('reason'),
      'details' => trim((string) $form_state->getValue('details')),
      'email' => trim((string) $form_state->getValue('email')),
      'uid' => (int) $this->account->id(),
      'ip_hash' => $identifier,
    ]);
    $this->flood->register('spotdeals_external_venue_report', 3600, $identifier);
    $this->messenger()->addStatus($this->t('Thank you. Your report was submitted for review.'));
    $form_state->setRedirect('<front>');
  }
}
