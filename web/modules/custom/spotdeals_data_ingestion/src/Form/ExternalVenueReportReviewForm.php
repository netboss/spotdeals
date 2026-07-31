<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\spotdeals_data_ingestion\Service\ExternalVenueReportStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExternalVenueReportReviewForm extends FormBase {
  private array $report = [];

  public function __construct(private readonly ExternalVenueReportStorage $storage, private readonly AccountProxyInterface $account) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('spotdeals_data_ingestion.external_venue_report_storage'), $container->get('current_user'));
  }

  public function getFormId(): string { return 'spotdeals_external_venue_report_review_form'; }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $report_id = NULL): array {
    $this->report = $this->storage->load((int) $report_id) ?? [];
    if (!$this->report) { throw new NotFoundHttpException(); }
    $form['summary'] = ['#type' => 'details', '#title' => $this->t('Report details'), '#open' => TRUE];
    foreach (['venue_name' => 'Venue', 'venue_address' => 'Address', 'external_source' => 'Source', 'external_id' => 'External ID', 'reason' => 'Reason', 'details' => 'Details', 'email' => 'Reporter email', 'status' => 'Current status'] as $key => $label) {
      $form['summary'][$key] = ['#type' => 'item', '#title' => $this->t($label), '#markup' => nl2br(htmlspecialchars((string) $this->report[$key]))];
    }
    $form['report_id'] = ['#type' => 'hidden', '#value' => $this->report['id']];
    $form['action'] = ['#type' => 'select', '#title' => $this->t('Administrative action'), '#required' => TRUE, '#options' => [
      'closed' => $this->t('Confirm closed and exclude'),
      'invalid' => $this->t('Mark invalid and exclude'),
      'excluded' => $this->t('Permanently exclude'),
      'restored' => $this->t('Restore venue'),
      'dismissed' => $this->t('Dismiss report'),
    ]];
    $form['admin_notes'] = ['#type' => 'textarea', '#title' => $this->t('Administrative notes'), '#default_value' => $this->report['admin_notes'], '#maxlength' => 2000];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save decision'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $report = $this->storage->load((int) $form_state->getValue('report_id'));
    if (!$report) { throw new NotFoundHttpException(); }
    $action = (string) $form_state->getValue('action');
    $notes = trim((string) $form_state->getValue('admin_notes'));
    if (in_array($action, ['closed', 'invalid', 'excluded'], TRUE)) {
      $this->storage->exclude($report, $action, (int) $this->account->id());
    }
    elseif ($action === 'restored') {
      $this->storage->restore($report['external_source'], $report['external_id']);
    }
    $this->storage->review((int) $report['id'], $action, $notes, (int) $this->account->id());
    $this->messenger()->addStatus($this->t('The report decision was saved.'));
    $form_state->setRedirect('spotdeals_data_ingestion.external_venue_reports');
  }
}
