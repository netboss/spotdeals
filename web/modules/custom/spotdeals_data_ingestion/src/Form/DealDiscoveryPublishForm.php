<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishPreviewService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublisher;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms controlled publishing of one ready deal candidate.
 */
final class DealDiscoveryPublishForm extends ConfirmFormBase {

  private array $candidate = [];

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryPublishPreviewService $previewService,
    private readonly DealDiscoveryPublisher $publisher,
    private readonly AccountProxyInterface $account,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_storage'),
      $container->get('spotdeals_data_ingestion.deal_discovery_publish_preview'),
      $container->get('spotdeals_data_ingestion.deal_discovery_publisher'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_publish_form';
  }

  public function getQuestion(): string {
    return (string) $this->t(
      'Publish “@offer” to SpotDeals?',
      ['@offer' => (string) ($this->candidate['offer_title'] ?? '')],
    );
  }

  public function getDescription(): string {
    return (string) $this->t(
      'The candidate will be checked again before any write. If still ready, SpotDeals will reuse or create the venue, create the published deal node and Spanish translation, and mark this candidate as published. This action is idempotent: the same candidate cannot intentionally create a second deal.',
    );
  }

  public function getConfirmText(): string {
    return (string) $this->t('Publish to SpotDeals');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute(
      'spotdeals_data_ingestion.deal_discovery_publish_preview',
      ['candidate_id' => (int) ($this->candidate['id'] ?? 0)],
    );
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?int $candidate_id = NULL,
  ): array {
    $this->candidate = $this->storage->load((int) $candidate_id) ?? [];
    if ($this->candidate === []) {
      throw new NotFoundHttpException();
    }

    $publishedDealNid = (int) ($this->candidate['published_deal_nid'] ?? 0);
    if ($publishedDealNid > 0 || (string) ($this->candidate['status'] ?? '') === 'published') {
      $this->messenger()->addStatus(
        $this->t('This candidate has already been published.'),
      );
      $form_state->setRedirect(
        'spotdeals_data_ingestion.deal_discovery_publish_preview',
        ['candidate_id' => (int) $this->candidate['id']],
      );
      return $form;
    }

    $preview = $this->previewService->preview($this->candidate);
    if (empty($preview['ready'])) {
      $this->messenger()->addError(
        $this->t('Publishing is currently blocked. Resolve the Preview publish issues first.'),
      );
      $form_state->setRedirect(
        'spotdeals_data_ingestion.deal_discovery_publish_preview',
        ['candidate_id' => (int) $this->candidate['id']],
      );
      return $form;
    }

    $form['candidate_id'] = [
      '#type' => 'hidden',
      '#value' => (int) $this->candidate['id'],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $candidateId = (int) $form_state->getValue('candidate_id');

    try {
      $result = $this->publisher->publish(
        $candidateId,
        (int) $this->account->id(),
      );
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError(
        $this->t(
          'Publishing failed safely: @message',
          ['@message' => $exception->getMessage()],
        ),
      );

      $form_state->setRedirect(
        'spotdeals_data_ingestion.deal_discovery_publish_preview',
        ['candidate_id' => $candidateId],
      );
      return;
    }

    if (!empty($result['already_published'])) {
      $this->messenger()->addStatus(
        $this->t('This candidate was already published; no duplicate deal was created.'),
      );
    }
    else {
      $this->messenger()->addStatus(
        $this->t(
          'Published successfully. Venue NID @venue; Deal NID @deal.',
          [
            '@venue' => (int) $result['venue_nid'],
            '@deal' => (int) $result['deal_nid'],
          ],
        ),
      );
    }

    $form_state->setRedirect(
      'spotdeals_data_ingestion.deal_discovery_publish_preview',
      ['candidate_id' => $candidateId],
    );
  }

}
