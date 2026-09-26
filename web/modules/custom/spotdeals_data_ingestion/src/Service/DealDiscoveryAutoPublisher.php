<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes ready auto-approved deal-discovery candidates from cron.
 */
final class DealDiscoveryAutoPublisher {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryConfidenceClassifier $confidenceClassifier,
    private readonly DealDiscoveryPublishPreviewService $previewService,
    private readonly DealDiscoveryPublisher $publisher,
    private readonly DealDiscoveryDailyDigest $dailyDigest,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Processes one bounded cron batch.
   *
   * Before publishing the normal auto-approved queue, cron also re-checks a
   * bounded set of candidates that had previously met every auto-approval rule
   * but were routed to pending solely because publishing readiness failed.
   * This lets safe publishing fixes take effect automatically without requiring
   * an editor or a one-off Drush cleanup command.
   *
   * @return array{
   *   processed: int,
   *   published: int,
   *   already_published: int,
   *   duplicate_rejected: int,
   *   routed_to_review: int,
   *   failed: int,
   *   readiness_rechecked: int,
   *   readiness_restored: int,
   *   readiness_still_blocked: int,
   *   historical_scanned: int,
   *   historical_classifier_eligible: int,
   *   historical_classifier_rejected: int,
   *   historical_restored: int,
   *   historical_duplicates: int,
   *   historical_blocked: int,
   *   historical_skipped_location: int,
   *   historical_skipped_reviewed: int,
   *   historical_errors: int
   * }
   *   Processing counters for logging and diagnostics.
   */
  public function processCronBatch(): array {
    $result = [
      'processed' => 0,
      'published' => 0,
      'already_published' => 0,
      'duplicate_rejected' => 0,
      'routed_to_review' => 0,
      'failed' => 0,
      'readiness_rechecked' => 0,
      'readiness_restored' => 0,
      'readiness_still_blocked' => 0,
      'historical_scanned' => 0,
      'historical_classifier_eligible' => 0,
      'historical_classifier_rejected' => 0,
      'historical_restored' => 0,
      'historical_duplicates' => 0,
      'historical_blocked' => 0,
      'historical_skipped_location' => 0,
      'historical_skipped_reviewed' => 0,
      'historical_errors' => 0,
    ];

    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    if (!(bool) ($config->get('deal_discovery_auto_publish_enabled') ?? TRUE)) {
      return $result;
    }

    $batchSize = max(1, min(
      200,
      (int) ($config->get('deal_discovery_auto_publish_batch_size') ?? 25),
    ));

    $this->reevaluatePendingReadiness($batchSize, $result);
    $this->reevaluateHistoricalPending($batchSize, $result);

    foreach ($this->storage->listAutoApprovedForPublishing($batchSize) as $candidate) {
      $candidateId = (int) ($candidate['id'] ?? 0);
      if ($candidateId <= 0) {
        continue;
      }

      $result['processed']++;

      try {
        $preview = $this->previewService->preview($candidate);
      }
      catch (\Throwable $exception) {
        $this->storage->markPendingForPublishingReadiness(
          $candidateId,
          ['Publishing readiness preview failed during cron: ' . $exception->getMessage()],
        );
        $result['routed_to_review']++;
        $this->logger->warning(
          'Deal-discovery candidate {candidate_id} was routed to manual review because its cron publishing preview failed: {message}',
          [
            'candidate_id' => $candidateId,
            'message' => $exception->getMessage(),
          ],
        );
        continue;
      }

      if (empty($preview['ready'])) {
        $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
        $duplicateNid = !empty($deal['duplicate_found'])
          ? (int) ($deal['duplicate_nid'] ?? 0)
          : 0;

        if (
          $duplicateNid > 0
          && $this->storage->markRejectedAsDuplicate($candidateId, $duplicateNid)
        ) {
          $result['duplicate_rejected']++;
          $this->logger->notice(
            'Deal-discovery candidate {candidate_id} was automatically rejected because existing deal node {duplicate_nid} already matches it.',
            [
              'candidate_id' => $candidateId,
              'duplicate_nid' => $duplicateNid,
            ],
          );
          continue;
        }

        $this->storage->markPendingForPublishingReadiness(
          $candidateId,
          $this->publishingReadinessReasons($preview),
        );
        $result['routed_to_review']++;
        continue;
      }

      try {
        $publishResult = $this->publisher->publish(
          $candidateId,
          0,
          'automatic',
        );

        if (!empty($publishResult['already_published'])) {
          $result['already_published']++;
        }
        else {
          $result['published']++;
          try {
            $this->dailyDigest->recordPublication($publishResult);
          }
          catch (\Throwable $exception) {
            $this->logger->warning(
              'Automatic publishing succeeded for deal-discovery candidate {candidate_id}, but recording it for the daily digest failed safely: {message}',
              [
                'candidate_id' => $candidateId,
                'message' => $exception->getMessage(),
              ],
            );
          }
        }
      }
      catch (\Throwable $exception) {
        // A candidate that passed preview remains auto-approved on a write-time
        // failure so a later cron run can retry it. The controlled publisher is
        // idempotent and re-runs readiness immediately before every write.
        $result['failed']++;
        $this->logger->warning(
          'Automatic publishing failed for deal-discovery candidate {candidate_id}; it remains queued for a later cron retry: {message}',
          [
            'candidate_id' => $candidateId,
            'message' => $exception->getMessage(),
          ],
        );
      }
    }

    try {
      $this->dailyDigest->recordDuplicateRejections((int) $result['duplicate_rejected']);
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Deal-discovery cron completed, but recording duplicate rejections for the daily digest failed safely: {message}',
        ['message' => $exception->getMessage()],
      );
    }

    return $result;
  }

  /**
   * Re-checks system-routed pending candidates against current readiness logic.
   *
   * Ready candidates are restored to auto-approved status and can be published
   * later in this same cron run. Re-verified duplicates are rejected. Anything
   * still blocked remains untouched in pending for genuine manual review.
   *
   * @param array<string, int> $result
   *   Processing counters updated by reference.
   */
  private function reevaluatePendingReadiness(int $limit, array &$result): void {
    foreach ($this->storage->listPendingForReadinessReevaluation($limit) as $candidate) {
      $candidateId = (int) ($candidate['id'] ?? 0);
      if ($candidateId <= 0) {
        continue;
      }

      $result['readiness_rechecked']++;
      $previewCandidate = $candidate;
      $previewCandidate['status'] = 'auto_approved';

      try {
        $preview = $this->previewService->preview($previewCandidate);
      }
      catch (\Throwable $exception) {
        $result['readiness_still_blocked']++;
        $this->logger->warning(
          'Automatic readiness re-evaluation failed safely for pending deal-discovery candidate {candidate_id}: {message}',
          [
            'candidate_id' => $candidateId,
            'message' => $exception->getMessage(),
          ],
        );
        continue;
      }

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $duplicateNid = !empty($deal['duplicate_found'])
        ? (int) ($deal['duplicate_nid'] ?? 0)
        : 0;

      if ($duplicateNid > 0) {
        if ($this->storage->markRejectedAsDuplicate($candidateId, $duplicateNid)) {
          $result['duplicate_rejected']++;
          $this->logger->notice(
            'Pending deal-discovery candidate {candidate_id} was automatically rejected after readiness re-evaluation confirmed duplicate deal node {duplicate_nid}.',
            [
              'candidate_id' => $candidateId,
              'duplicate_nid' => $duplicateNid,
            ],
          );
        }
        else {
          $result['readiness_still_blocked']++;
        }
        continue;
      }

      if (!empty($preview['ready'])) {
        if ($this->storage->restoreAutoApprovalAfterReadinessReevaluation($candidateId)) {
          $result['readiness_restored']++;
          $this->logger->notice(
            'Pending deal-discovery candidate {candidate_id} passed current publishing readiness and was restored to the automatic publishing queue.',
            ['candidate_id' => $candidateId],
          );
        }
        else {
          $result['readiness_still_blocked']++;
        }
        continue;
      }

      $result['readiness_still_blocked']++;
    }
  }

  /**
   * Re-runs current safety rules against a rotating slice of historical pending.
   *
   * Discovery-time location confidence is not persisted, so candidates whose
   * stored classification explicitly records a location-confidence failure are
   * never reconsidered. Administrative reviews are never overridden. A state
   * cursor advances by candidate ID so repeated cron runs eventually inspect
   * the full pending queue instead of getting stuck on the same first batch.
   *
   * @param array<string, int> $result
   *   Processing counters updated by reference.
   */
  private function reevaluateHistoricalPending(int $limit, array &$result): void {
    $configuredLocationConfidence = $this->configFactory
      ->get('spotdeals_data_ingestion.settings')
      ->get('deal_discovery_auto_approve_location_confidence');

    if ($configuredLocationConfidence === NULL) {
      $result['historical_errors']++;
      $this->logger->warning(
        'Automatic historical pending reclassification was skipped because the minimum location confidence is not configured.',
      );
      return;
    }

    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $cursorKey = 'spotdeals_data_ingestion.deal_discovery_historical_reclassification_cursor';
    $cursor = max(0, (int) $this->state->get($cursorKey, 0));
    $candidates = $this->storage->listPendingForHistoricalReclassification(
      $limit,
      $cursor,
    );

    // Wrap to the beginning after reaching the end of the pending ID space.
    if ($candidates === [] && $cursor > 0) {
      $cursor = 0;
      $candidates = $this->storage->listPendingForHistoricalReclassification(
        $limit,
        0,
      );
    }

    $lastScannedId = $cursor;
    foreach ($candidates as $candidate) {
      $candidateId = (int) ($candidate['id'] ?? 0);
      if ($candidateId <= 0) {
        continue;
      }

      $lastScannedId = $candidateId;
      $result['historical_scanned']++;

      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        $result['historical_skipped_reviewed']++;
        continue;
      }

      $storedReason = trim((string) ($candidate['classification_reason'] ?? ''));
      if (preg_match(
        '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
        $storedReason,
      ) === 1) {
        $result['historical_skipped_location']++;
        continue;
      }

      $classifierCandidate = [
        'title' => (string) ($candidate['offer_title'] ?? ''),
        'value' => (string) ($candidate['offer_value'] ?? ''),
        'schedule' => (string) ($candidate['schedule'] ?? ''),
        'source_url' => (string) ($candidate['source_url'] ?? ''),
        'reason' => (string) ($candidate['reason'] ?? ''),
        'score' => (int) ($candidate['score'] ?? 0),
      ];

      try {
        $classification = $this->confidenceClassifier->classify(
          $classifierCandidate,
          $minimumLocationConfidence,
        );
      }
      catch (\Throwable $exception) {
        $result['historical_errors']++;
        $this->logger->warning(
          'Automatic historical reclassification failed safely for pending deal-discovery candidate {candidate_id}: {message}',
          [
            'candidate_id' => $candidateId,
            'message' => $exception->getMessage(),
          ],
        );
        continue;
      }

      $classificationStatus = (string) ($classification['status'] ?? '');
      if ($classificationStatus === 'rejected') {
        $reasons = is_array($classification['reasons'] ?? NULL)
          ? $classification['reasons']
          : [];
        if ($this->storage->markRejectedByClassifier($candidateId, $reasons)) {
          $result['historical_classifier_rejected']++;
          $this->logger->notice(
            'Historical pending deal-discovery candidate {candidate_id} was automatically rejected by the current classifier.',
            ['candidate_id' => $candidateId],
          );
        }
        continue;
      }

      if ($classificationStatus !== 'auto_approved') {
        continue;
      }

      $result['historical_classifier_eligible']++;
      $previewCandidate = $candidate;
      $previewCandidate['status'] = 'auto_approved';

      try {
        $preview = $this->previewService->preview($previewCandidate);
      }
      catch (\Throwable $exception) {
        $result['historical_errors']++;
        $this->logger->warning(
          'Automatic historical publishing preview failed safely for pending deal-discovery candidate {candidate_id}: {message}',
          [
            'candidate_id' => $candidateId,
            'message' => $exception->getMessage(),
          ],
        );
        continue;
      }

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $duplicateNid = !empty($deal['duplicate_found'])
        ? (int) ($deal['duplicate_nid'] ?? 0)
        : 0;

      if ($duplicateNid > 0) {
        if ($this->storage->markRejectedAsDuplicate($candidateId, $duplicateNid)) {
          $result['historical_duplicates']++;
          $result['duplicate_rejected']++;
          $this->logger->notice(
            'Historical pending deal-discovery candidate {candidate_id} was automatically rejected after current rules confirmed duplicate deal node {duplicate_nid}.',
            [
              'candidate_id' => $candidateId,
              'duplicate_nid' => $duplicateNid,
            ],
          );
        }
        else {
          $result['historical_blocked']++;
        }
        continue;
      }

      if (!empty($preview['ready'])) {
        if ($this->storage->restoreAutoApprovalAfterHistoricalReclassification($candidateId)) {
          $result['historical_restored']++;
          $this->logger->notice(
            'Historical pending deal-discovery candidate {candidate_id} passed the current classifier and publishing preview and was restored to the automatic publishing queue.',
            ['candidate_id' => $candidateId],
          );
        }
        else {
          $result['historical_blocked']++;
        }
        continue;
      }

      $result['historical_blocked']++;
    }

    if ($lastScannedId !== $cursor || ($cursor === 0 && $candidates === [])) {
      $this->state->set($cursorKey, $lastScannedId);
    }
  }

  /**
   * Builds concise reasons for routing a candidate back to manual review.
   *
   * @param array<string, mixed> $preview
   *   The current no-write publishing preview.
   *
   * @return string[]
   *   Human-readable readiness reasons.
   */
  private function publishingReadinessReasons(array $preview): array {
    $reasons = [];

    foreach ((array) ($preview['errors'] ?? []) as $error) {
      $error = trim((string) $error);
      if ($error !== '') {
        $reasons[] = $error;
      }
    }

    $venue = is_array($preview['venue'] ?? NULL) ? $preview['venue'] : [];
    foreach ((array) ($venue['errors'] ?? []) as $error) {
      $error = trim((string) $error);
      if ($error !== '') {
        $reasons[] = $error;
      }
    }

    $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
    foreach ((array) ($deal['blocking_fields'] ?? []) as $field => $message) {
      $message = trim((string) $message);
      if ($message !== '') {
        $reasons[] = (string) $field . ': ' . $message;
      }
    }

    if (!empty($deal['duplicate_found'])) {
      $duplicateNid = (int) ($deal['duplicate_nid'] ?? 0);
      $reasons[] = $duplicateNid > 0
        ? 'A duplicate deal already exists (node ' . $duplicateNid . ').'
        : 'A duplicate deal already exists.';
    }

    if ($reasons === []) {
      $reasons[] = 'The publishing preview did not report the candidate as ready.';
    }

    return array_values(array_unique($reasons));
  }

}
