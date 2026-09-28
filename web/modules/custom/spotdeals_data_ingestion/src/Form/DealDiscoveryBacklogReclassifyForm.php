<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPendingReclassifier;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrator-only safe pending-backlog reclassification action.
 */
final class DealDiscoveryBacklogReclassifyForm extends ConfirmFormBase {

  public function __construct(
    private readonly DealDiscoveryPendingReclassifier $reclassifier,
    private readonly DealDiscoveryStorage $storage,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(DealDiscoveryPendingReclassifier::class),
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_backlog_reclassify_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Safely reclassify the pending Deal Discovery backlog?');
  }

  public function getDescription(): string {
    return (string) $this->t('Only unreviewed pending candidates that the current classifier explicitly identifies as terminal/non-actionable will be rejected. Ambiguous candidates remain pending and reviewed candidates are protected. Current pending count: @count.', [
      '@count' => $this->storage->count('pending'),
    ]);
  }

  public function getConfirmText(): string {
    return (string) $this->t('Reclassify safe candidates');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('spotdeals_data_ingestion.deal_discovery_candidates');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->reclassifier->reclassify(1000, TRUE);
      $this->messenger()->addStatus($this->t(
        'Backlog reclassification completed. Evaluated @loaded pending candidates; @eligible were classifier-confirmed for rejection and @updated were moved to Rejected. @remaining candidates remain Pending.',
        [
          '@loaded' => $result['loaded'],
          '@eligible' => $result['eligible'],
          '@updated' => $result['updated'],
          '@remaining' => $this->storage->count('pending'),
        ],
      ));
      if ($result['errors'] > 0) {
        $this->messenger()->addWarning($this->t('@count candidate(s) could not be evaluated and were left unchanged.', [
          '@count' => $result['errors'],
        ]));
      }
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Backlog reclassification failed safely. No uncertain candidate was changed. Error: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }

    $form_state->setRedirect('spotdeals_data_ingestion.deal_discovery_candidates');
  }

}
