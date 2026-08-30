<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Read-only audit of candidate and published discovery-content quality.
 */
final class DealDiscoveryContentQualityAuditService {

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DealDiscoveryContentQualityService $qualityService,
  ) {}

  /**
   * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>}
   */
  public function audit(): array {
    $summary = [
      'candidates_checked' => 0,
      'published_deals_checked' => 0,
      'candidate_issues' => 0,
      'published_content_issues' => 0,
      'missing_published_nodes' => 0,
    ];
    $rows = [];

    foreach ($this->storage->list('all', 5000) as $candidate) {
      $summary['candidates_checked']++;
      $candidateId = (int) ($candidate['id'] ?? 0);
      $dealNid = (int) ($candidate['published_deal_nid'] ?? 0);

      $assessment = $this->qualityService->assessCandidate($candidate);
      foreach ($assessment['corrections'] as $field => $correction) {
        $summary['candidate_issues']++;
        $rows[] = [
          'candidate_id' => $candidateId,
          'deal_nid' => $dealNid,
          'scope' => 'candidate',
          'field' => $field,
          'current' => (string) $correction['from'],
          'suggested' => (string) $correction['to'],
          'issue' => 'Stored candidate contains a deterministic normalization artifact.',
        ];
      }
      foreach (array_merge($assessment['blockers'], $assessment['warnings']) as $issue) {
        $summary['candidate_issues']++;
        $rows[] = [
          'candidate_id' => $candidateId,
          'deal_nid' => $dealNid,
          'scope' => 'candidate',
          'field' => 'content',
          'current' => (string) ($candidate['offer_title'] ?? ''),
          'suggested' => '',
          'issue' => (string) $issue,
        ];
      }

      if ($dealNid <= 0) {
        continue;
      }

      $deal = $this->entityTypeManager->getStorage('node')->load($dealNid);
      if (!$deal instanceof NodeInterface || $deal->bundle() !== 'deal') {
        $summary['missing_published_nodes']++;
        $rows[] = [
          'candidate_id' => $candidateId,
          'deal_nid' => $dealNid,
          'scope' => 'published',
          'field' => 'node',
          'current' => '',
          'suggested' => '',
          'issue' => 'Recorded published deal node cannot be loaded.',
        ];
        continue;
      }

      $summary['published_deals_checked']++;
      $translations = [$deal->language()->getId() => $deal];
      foreach ($deal->getTranslationLanguages(FALSE) as $langcode => $language) {
        if ($deal->hasTranslation($langcode)) {
          $translations[$langcode] = $deal->getTranslation($langcode);
        }
      }

      foreach ($translations as $langcode => $translation) {
        $title = (string) $translation->label();
        $titleAssessment = $this->qualityService->assessPublishedTitle($title);
        foreach ($titleAssessment['issues'] as $issue) {
          $summary['published_content_issues']++;
          $rows[] = [
            'candidate_id' => $candidateId,
            'deal_nid' => $dealNid,
            'scope' => 'published:' . $langcode,
            'field' => 'title',
            'current' => $title,
            'suggested' => (string) $titleAssessment['normalized'],
            'issue' => (string) $issue,
          ];
        }
      }
    }

    return [
      'summary' => $summary,
      'rows' => $rows,
    ];
  }

}
