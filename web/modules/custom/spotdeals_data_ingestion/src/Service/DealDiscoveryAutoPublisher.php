<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes ready auto-approved deal-discovery candidates from cron.
 */
final class DealDiscoveryAutoPublisher {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryPublishPreviewService $previewService,
    private readonly DealDiscoveryPublisher $publisher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Processes one bounded cron batch.
   *
   * @return array{processed: int, published: int, already_published: int, routed_to_review: int, failed: int}
   *   Processing counters for logging and diagnostics.
   */
  public function processCronBatch(): array {
    $result = [
      'processed' => 0,
      'published' => 0,
      'already_published' => 0,
      'routed_to_review' => 0,
      'failed' => 0,
    ];

    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    if (!(bool) ($config->get('deal_discovery_auto_publish_enabled') ?? TRUE)) {
      return $result;
    }

    $batchSize = max(1, min(
      200,
      (int) ($config->get('deal_discovery_auto_publish_batch_size') ?? 25),
    ));

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

    return $result;
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
