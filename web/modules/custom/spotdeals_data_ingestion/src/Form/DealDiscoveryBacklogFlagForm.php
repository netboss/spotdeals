<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets editors flag an oversized deal-discovery backlog for administrators.
 */
final class DealDiscoveryBacklogFlagForm extends ConfirmFormBase {

  public function __construct(
    private readonly StateInterface $state,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly DealDiscoveryStorage $storage,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_backlog_flag_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Flag the Deal Discovery backlog for administrator review?');
  }

  public function getDescription(): string {
    return (string) $this->t('This does not change or reject any candidates. It creates a persistent administrator alert that the pending queue needs attention.');
  }

  public function getConfirmText(): string {
    return (string) $this->t('Flag backlog for admin review');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('spotdeals_data_ingestion.deal_discovery_candidates');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->state->set('spotdeals_data_ingestion.deal_discovery_backlog_alert', [
      'flagged_at' => $this->time->getRequestTime(),
      'flagged_by_uid' => (int) $this->account->id(),
      'flagged_by_name' => (string) $this->account->getDisplayName(),
      'pending_count' => $this->storage->count('pending'),
    ]);

    $this->messenger()->addStatus($this->t('The Deal Discovery backlog was flagged for administrator review.'));
    $form_state->setRedirect('spotdeals_data_ingestion.deal_discovery_candidates');
  }

}
