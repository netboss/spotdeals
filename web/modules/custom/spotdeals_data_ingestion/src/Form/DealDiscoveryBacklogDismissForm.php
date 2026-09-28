<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets administrators explicitly dismiss a backlog alert.
 */
final class DealDiscoveryBacklogDismissForm extends ConfirmFormBase {

  public function __construct(private readonly StateInterface $state) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('state'));
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_backlog_dismiss_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Dismiss the Deal Discovery backlog alert?');
  }

  public function getDescription(): string {
    return (string) $this->t('This only clears the administrator alert. It does not change any deal candidates.');
  }

  public function getConfirmText(): string {
    return (string) $this->t('Dismiss alert');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('spotdeals_data_ingestion.deal_discovery_candidates');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->state->delete('spotdeals_data_ingestion.deal_discovery_backlog_alert');
    $this->messenger()->addStatus($this->t('The Deal Discovery backlog alert was dismissed.'));
    $form_state->setRedirect('spotdeals_data_ingestion.deal_discovery_candidates');
  }

}
