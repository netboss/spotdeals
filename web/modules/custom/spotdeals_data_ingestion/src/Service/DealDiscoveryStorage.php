<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores deal-discovery candidates for administrative review.
 */
final class DealDiscoveryStorage {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates or refreshes one discovered candidate.
   *
   * Existing administrative decisions are preserved when a candidate is seen
   * again. Extraction data and last-seen timestamps are refreshed.
   */
  public function createOrRefresh(array $values): int {
    $fingerprint = $this->fingerprint($values);
    $now = $this->time->getRequestTime();

    $existingId = $this->database
      ->select('spotdeals_deal_discovery_candidate', 'c')
      ->fields('c', ['id'])
      ->condition('fingerprint', $fingerprint)
      ->execute()
      ->fetchField();

    $fields = [
      'external_source' => (string) ($values['external_source'] ?? ''),
      'external_id' => (string) ($values['external_id'] ?? ''),
      'venue_name' => (string) ($values['venue_name'] ?? ''),
      'venue_address' => (string) ($values['venue_address'] ?? ''),
      'venue_website' => (string) ($values['venue_website'] ?? ''),
      'category' => (string) ($values['category'] ?? ''),
      'place_id' => (string) ($values['place_id'] ?? ''),
      'offer_title' => (string) ($values['offer_title'] ?? ''),
      'offer_value' => (string) ($values['offer_value'] ?? ''),
      'schedule' => (string) ($values['schedule'] ?? ''),
      'source_url' => (string) ($values['source_url'] ?? ''),
      'reason' => (string) ($values['reason'] ?? ''),
      'score' => (int) ($values['score'] ?? 0),
      'confidence' => (string) ($values['confidence'] ?? 'review'),
      'classification_reason' => (string) ($values['classification_reason'] ?? ''),
      'changed' => $now,
      'last_seen' => $now,
    ];

    if ($existingId !== FALSE) {
      $existingStatus = (string) $this->database
        ->select('spotdeals_deal_discovery_candidate', 'c')
        ->fields('c', ['status'])
        ->condition('id', (int) $existingId)
        ->execute()
        ->fetchField();

      if (!in_array($existingStatus, ['approved', 'rejected', 'published'], TRUE)) {
        $fields['status'] = (string) ($values['status'] ?? 'pending');
      }

      $this->database
        ->update('spotdeals_deal_discovery_candidate')
        ->fields($fields)
        ->condition('id', (int) $existingId)
        ->execute();

      return (int) $existingId;
    }

    $fields += [
      'fingerprint' => $fingerprint,
      'status' => (string) ($values['status'] ?? 'pending'),
      'admin_notes' => '',
      'created' => $now,
      'reviewed_by' => 0,
      'reviewed_at' => 0,
      'override_day_of_week_tid' => 0,
      'override_deal_category_tid' => 0,
      'published_venue_nid' => 0,
      'published_deal_nid' => 0,
      'published_by' => 0,
      'published_at' => 0,
      'published_via' => '',
    ];

    return (int) $this->database
      ->insert('spotdeals_deal_discovery_candidate')
      ->fields($fields)
      ->execute();
  }

  public function load(int $id): ?array {
    $record = $this->database
      ->select('spotdeals_deal_discovery_candidate', 'c')
      ->fields('c')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();

    return $record ?: NULL;
  }

  /**
   * Lists candidates by administrative status.
   */
  public function list(string $status = 'pending', int $limit = 200): array {
    $query = $this->database
      ->select('spotdeals_deal_discovery_candidate', 'c')
      ->fields('c')
      ->orderBy('last_seen', 'DESC')
      ->range(0, $limit);

    if ($status !== 'all') {
      $query->condition('status', $status);
    }

    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Saves an administrative decision and any reviewed candidate edits.
   */
  public function review(
    int $id,
    string $status,
    string $notes,
    int $uid,
    array $candidateValues = [],
  ): void {
    $now = $this->time->getRequestTime();

    $fields = [
      'status' => $status,
      'admin_notes' => $notes,
      'changed' => $now,
      'reviewed_by' => $uid,
      'reviewed_at' => $now,
    ];

    foreach (['offer_title', 'offer_value', 'schedule', 'source_url'] as $field) {
      if (array_key_exists($field, $candidateValues)) {
        $fields[$field] = (string) $candidateValues[$field];
      }
    }

    foreach (['override_day_of_week_tid', 'override_deal_category_tid'] as $field) {
      if (array_key_exists($field, $candidateValues)) {
        $fields[$field] = (int) $candidateValues[$field];
      }
    }

    $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Saves publishing overrides without changing the review decision.
   *
   * @param array<string, int> $overrides
   */
  public function savePublishingOverrides(int $id, array $overrides): void {
    $allowed = [
      'override_day_of_week_tid',
      'override_deal_category_tid',
    ];

    $fields = [
      'changed' => $this->time->getRequestTime(),
    ];

    foreach ($allowed as $field) {
      if (array_key_exists($field, $overrides)) {
        $fields[$field] = max(0, (int) $overrides[$field]);
      }
    }

    $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks a candidate as published and records the resulting Drupal nodes.
   */
  public function markPublished(
    int $id,
    int $venueNid,
    int $dealNid,
    int $uid,
    string $publishedVia = 'manual',
  ): void {
    if ($venueNid <= 0 || $dealNid <= 0) {
      throw new \InvalidArgumentException(
        'Published venue and deal node IDs must be positive.',
      );
    }

    $publishedVia = in_array($publishedVia, ['manual', 'automatic'], TRUE)
      ? $publishedVia
      : 'manual';

    $now = $this->time->getRequestTime();

    $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields([
        'status' => 'published',
        'published_venue_nid' => $venueNid,
        'published_deal_nid' => $dealNid,
        'published_by' => max(0, $uid),
        'published_at' => $now,
        'published_via' => $publishedVia,
        'changed' => $now,
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Builds a stable fingerprint without venue- or deal-specific hardcoding.
   */
  private function fingerprint(array $values): string {
    $parts = [
      mb_strtolower(trim((string) ($values['external_source'] ?? ''))),
      mb_strtolower(trim((string) ($values['external_id'] ?? ''))),
      mb_strtolower(trim((string) ($values['source_url'] ?? ''))),
      mb_strtolower(trim((string) ($values['offer_title'] ?? ''))),
      mb_strtolower(trim((string) ($values['offer_value'] ?? ''))),
    ];

    return hash('sha256', implode('|', $parts));
  }

}
