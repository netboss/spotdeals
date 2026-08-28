<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishAuditService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk-publishes admin-reviewed candidates that are currently ready.
 */
final class DealDiscoveryPublishReadyForm extends ConfirmFormBase {

  private array $readyCandidateIds = [];

  public function __construct(
    private readonly DealDiscoveryPublishAuditService $auditService,
    private readonly DealDiscoveryPublisher $publisher,
    private readonly AccountProxyInterface $account,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('spotdeals_data_ingestion.deal_discovery_publish_audit'),
      $container->get('spotdeals_data_ingestion.deal_discovery_publisher'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'spotdeals_deal_discovery_publish_ready_form';
  }

  public function getQuestion(): string {
    return (string) $this->t(
      'Publish @count ready reviewed deal candidate(s)?',
      ['@count' => count($this->readyCandidateIds)],
    );
  }

  public function getDescription(): string {
    return (string) $this->t(
      'Only candidates with status Approved and a current Ready publishing audit are included. Every candidate is checked again by the controlled publisher immediately before writing. Auto-approved candidates are not included in this bulk manual action.',
    );
  }

  public function getConfirmText(): string {
    return (string) $this->t('Publish ready reviewed candidates');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute(
      'spotdeals_data_ingestion.deal_discovery_publish_audit',
      [],
      ['query' => ['result' => 'ready']],
    );
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $this->readyCandidateIds = $this->findReadyReviewedCandidateIds();

    if ($this->readyCandidateIds === []) {
      $this->messenger()->addStatus(
        $this->t('There are no admin-reviewed candidates that are currently ready to publish.'),
      );
      $form_state->setRedirect(
        'spotdeals_data_ingestion.deal_discovery_publish_audit',
        [],
        ['query' => ['result' => 'ready']],
      );
      return $form;
    }

    $form['candidate_ids'] = [
      '#type' => 'hidden',
      '#value' => implode(',', $this->readyCandidateIds),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Re-audit at submit time so a candidate that became blocked or changed
    // status after the confirmation page was rendered is never published.
    $candidateIds = $this->findReadyReviewedCandidateIds();

    $published = 0;
    $alreadyPublished = 0;
    $failed = 0;

    foreach ($candidateIds as $candidateId) {
      try {
        $result = $this->publisher->publish(
          $candidateId,
          (int) $this->account->id(),
          'manual',
        );

        if (!empty($result['already_published'])) {
          $alreadyPublished++;
        }
        else {
          $published++;
        }
      }
      catch (\Throwable) {
        // Fail closed per candidate. Other ready candidates can still proceed,
        // while this candidate remains visible as an exception in the audit.
        $failed++;
      }
    }

    $this->messenger()->addStatus($this->t(
      'Bulk publishing completed: @published published, @already already published, @failed safely skipped/failed.',
      [
        '@published' => $published,
        '@already' => $alreadyPublished,
        '@failed' => $failed,
      ],
    ));

    $form_state->setRedirect(
      'spotdeals_data_ingestion.deal_discovery_publish_audit',
    );
  }

  /**
   * Returns only reviewed Approved candidates whose current audit is Ready.
   *
   * @return int[]
   *   Candidate IDs.
   */
  private function findReadyReviewedCandidateIds(): array {
    $ids = [];

    foreach ($this->auditService->audit()['rows'] as $row) {
      if ((string) ($row['result'] ?? '') !== 'ready') {
        continue;
      }

      $candidate = is_array($row['candidate'] ?? NULL)
        ? $row['candidate']
        : [];

      if ((string) ($candidate['status'] ?? '') !== 'approved') {
        continue;
      }

      $candidateId = (int) ($candidate['id'] ?? 0);
      if ($candidateId > 0) {
        $ids[] = $candidateId;
      }
    }

    return $ids;
  }

}
