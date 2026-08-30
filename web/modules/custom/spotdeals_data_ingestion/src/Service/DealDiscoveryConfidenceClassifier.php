<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Classifies discovered deals for automatic or manual administrative routing.
 */
final class DealDiscoveryConfidenceClassifier {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DealDiscoveryContentQualityService $contentQuality,
  ) {}

  /**
   * @return array{status: string, confidence: string, reasons: array<int, string>}
   */
  public function classify(array $candidate, int $locationConfidence): array {
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredScore = $config->get('deal_discovery_auto_approve_score');
    $configuredLocationConfidence = $config->get('deal_discovery_auto_approve_location_confidence');
    $configuredRequireSchedule = $config->get('deal_discovery_auto_approve_require_schedule');

    if (
      $configuredScore === NULL
      || $configuredLocationConfidence === NULL
      || $configuredRequireSchedule === NULL
    ) {
      return [
        'status' => 'pending',
        'confidence' => 'review',
        'reasons' => [
          'automatic-approval configuration is incomplete',
        ],
      ];
    }

    $autoApproveScore = (int) $configuredScore;
    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $requireSchedule = (bool) $configuredRequireSchedule;

    $score = (int) ($candidate['score'] ?? 0);
    $schedule = trim((string) ($candidate['schedule'] ?? ''));
    $sourceUrl = trim((string) ($candidate['source_url'] ?? ''));
    $value = trim((string) ($candidate['value'] ?? ''));
    $title = trim((string) ($candidate['title'] ?? ''));
    $quality = $this->contentQuality->assessCandidate($candidate);

    $reasons = [];
    foreach ($quality['blockers'] as $qualityBlocker) {
      $reasons[] = 'content quality: ' . $qualityBlocker;
    }
    foreach ($quality['warnings'] as $qualityWarning) {
      $reasons[] = 'content quality review: ' . $qualityWarning;
    }
    if ($score < $autoApproveScore) {
      $reasons[] = sprintf('score %d is below configured automatic-approval score %d', $score, $autoApproveScore);
    }
    if ($locationConfidence < $minimumLocationConfidence) {
      $reasons[] = sprintf('location confidence %d is below configured minimum %d', $locationConfidence, $minimumLocationConfidence);
    }
    if ($requireSchedule && $schedule === '') {
      $reasons[] = 'schedule or validity context is missing';
    }
    if ($sourceUrl === '') {
      $reasons[] = 'source URL is missing';
    }
    if ($value === '') {
      $reasons[] = 'offer value is missing';
    }
    if ($title === '') {
      $reasons[] = 'offer title is missing';
    }

    if ($reasons === []) {
      return [
        'status' => 'auto_approved',
        'confidence' => 'high',
        'reasons' => ['candidate met all configured automatic-approval requirements'],
      ];
    }

    return [
      'status' => 'pending',
      'confidence' => 'review',
      'reasons' => $reasons,
    ];
  }

}
