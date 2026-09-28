<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Safely reclassifies terminal, unreviewed pending discovery candidates.
 */
final class DealDiscoveryPendingReclassifier {

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryConfidenceClassifier $classifier,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Evaluates pending candidates and optionally persists safe rejections.
   *
   * @return array{loaded:int,eligible:int,updated:int,errors:int,rows:array<int,array<string,mixed>>}
   */
  public function reclassify(int $limit = 1000, bool $apply = FALSE): array {
    $configuredLocationConfidence = $this->configFactory
      ->get('spotdeals_data_ingestion.settings')
      ->get('deal_discovery_auto_approve_location_confidence');

    if ($configuredLocationConfidence === NULL) {
      throw new \RuntimeException('Automatic-approval minimum location confidence is not configured.');
    }

    $candidates = $this->storage->list('pending', $limit);
    $result = [
      'loaded' => count($candidates),
      'eligible' => 0,
      'updated' => 0,
      'errors' => 0,
      'rows' => [],
    ];

    foreach ($candidates as $candidate) {
      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        continue;
      }

      try {
        $classification = $this->classifier->classify([
          'title' => (string) ($candidate['offer_title'] ?? ''),
          'value' => (string) ($candidate['offer_value'] ?? ''),
          'schedule' => (string) ($candidate['schedule'] ?? ''),
          'source_url' => (string) ($candidate['source_url'] ?? ''),
          'reason' => (string) ($candidate['reason'] ?? ''),
          'score' => (int) ($candidate['score'] ?? 0),
        ], (int) $configuredLocationConfidence);
      }
      catch (\Throwable $exception) {
        $result['errors']++;
        $result['rows'][] = [
          'id' => (int) ($candidate['id'] ?? 0),
          'venue' => (string) ($candidate['venue_name'] ?? ''),
          'offer' => (string) ($candidate['offer_title'] ?? ''),
          'value' => (string) ($candidate['offer_value'] ?? ''),
          'reason' => 'ERROR: ' . $exception->getMessage(),
          'updated' => FALSE,
        ];
        continue;
      }

      if ((string) ($classification['status'] ?? '') !== 'rejected') {
        continue;
      }

      $reasons = array_map('strval', (array) ($classification['reasons'] ?? []));
      $result['eligible']++;
      $didUpdate = $apply && $this->storage->markRejectedByClassifier(
        (int) ($candidate['id'] ?? 0),
        $reasons,
      );
      if ($didUpdate) {
        $result['updated']++;
      }

      $result['rows'][] = [
        'id' => (int) ($candidate['id'] ?? 0),
        'venue' => (string) ($candidate['venue_name'] ?? ''),
        'offer' => (string) ($candidate['offer_title'] ?? ''),
        'value' => (string) ($candidate['offer_value'] ?? ''),
        'reason' => implode('; ', $reasons),
        'updated' => $didUpdate,
      ];
    }

    return $result;
  }

}
