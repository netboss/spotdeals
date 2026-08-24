<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Audits deal-discovery candidates through the shadow publishing pipeline.
 */
final class DealDiscoveryPublishAuditService {

  public function __construct(
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryPublishPreviewService $previewService,
  ) {}

  /**
   * Runs a read-only audit of all stored candidates.
   *
   * @return array{
   *   summary: array<string, int>,
   *   rows: array<int, array<string, mixed>>
   * }
   */
  public function audit(): array {
    $summary = [
      'total' => 0,
      'ready' => 0,
      'blocked' => 0,
      'not_eligible' => 0,
      'published' => 0,
      'duplicate' => 0,
      'venue_unresolved' => 0,
      'error' => 0,
    ];
    $rows = [];

    foreach ($this->storage->list('all', 1000) as $candidate) {
      $summary['total']++;

      $status = (string) ($candidate['status'] ?? '');

      if ($status === 'published' || (int) ($candidate['published_deal_nid'] ?? 0) > 0) {
        $summary['published']++;
        $rows[] = [
          'candidate' => $candidate,
          'result' => 'published',
          'message' => 'Candidate has already been published to Drupal.',
          'blocking_fields' => [],
        ];
        continue;
      }

      if (!in_array($status, ['approved', 'auto_approved'], TRUE)) {
        $summary['not_eligible']++;
        $rows[] = [
          'candidate' => $candidate,
          'result' => 'not_eligible',
          'message' => 'Candidate is not approved for publishing preview.',
          'blocking_fields' => [],
        ];
        continue;
      }

      try {
        $preview = $this->previewService->preview($candidate);
      }
      catch (\Throwable $exception) {
        $summary['error']++;
        $rows[] = [
          'candidate' => $candidate,
          'result' => 'error',
          'message' => $exception->getMessage(),
          'blocking_fields' => [],
        ];
        continue;
      }

      if (!empty($preview['ready'])) {
        $summary['ready']++;
        $rows[] = [
          'candidate' => $candidate,
          'result' => 'ready',
          'message' => 'Ready for publishing.',
          'blocking_fields' => [],
        ];
        continue;
      }

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $venue = is_array($preview['venue'] ?? NULL) ? $preview['venue'] : [];
      $blockingFields = is_array($deal['blocking_fields'] ?? NULL)
        ? $deal['blocking_fields']
        : [];

      if (!empty($deal['duplicate_found'])) {
        $summary['duplicate']++;
        $result = 'duplicate';
        $message = 'An existing matching deal blocks publishing.';
      }
      elseif (($venue['action'] ?? '') === 'unresolved' || ($venue['errors'] ?? []) !== []) {
        $summary['venue_unresolved']++;
        $result = 'venue_unresolved';
        $message = implode('; ', array_map(
          'strval',
          is_array($venue['errors'] ?? NULL) ? $venue['errors'] : [],
        ));
        if ($message === '') {
          $message = 'Venue could not be resolved safely.';
        }
      }
      else {
        $summary['blocked']++;
        $result = 'blocked';
        $message = $blockingFields !== []
          ? implode('; ', array_map(
            static fn (string $field, mixed $reason): string =>
              $field . ': ' . (string) $reason,
            array_keys($blockingFields),
            array_values($blockingFields),
          ))
          : 'Publishing preview is not ready.';

        $suggestions = is_array($deal['blocking_suggestions'] ?? NULL)
          ? $deal['blocking_suggestions']
          : [];
        foreach ($suggestions as $field => $suggestion) {
          if (!is_array($suggestion) || !isset($blockingFields[$field])) {
            continue;
          }

          $message .= ' Suggested ' . $field . ': '
            . (string) ($suggestion['name'] ?? '')
            . ' (' . strtoupper((string) ($suggestion['confidence'] ?? ''))
            . ' confidence).';
        }
      }

      $rows[] = [
        'candidate' => $candidate,
        'result' => $result,
        'message' => $message,
        'blocking_fields' => $blockingFields,
      ];
    }

    return [
      'summary' => $summary,
      'rows' => $rows,
    ];
  }

}
