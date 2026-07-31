<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

final class ExternalVenueReportStorage {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  public function create(array $values): int {
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('spotdeals_external_venue_report')
      ->fields([
        'external_source' => $values['external_source'],
        'external_id' => $values['external_id'],
        'venue_name' => $values['venue_name'],
        'venue_address' => $values['venue_address'] ?? '',
        'reason' => $values['reason'],
        'details' => $values['details'] ?? '',
        'email' => $values['email'] ?? '',
        'uid' => $values['uid'] ?? 0,
        'ip_hash' => $values['ip_hash'],
        'status' => 'pending',
        'admin_notes' => '',
        'created' => $now,
        'changed' => $now,
        'reviewed_by' => 0,
        'reviewed_at' => 0,
      ])->execute();
  }

  public function load(int $id): ?array {
    $record = $this->database->select('spotdeals_external_venue_report', 'r')
      ->fields('r')->condition('id', $id)->execute()->fetchAssoc();
    return $record ?: NULL;
  }

  public function list(string $status = 'pending', int $limit = 100): array {
    $query = $this->database->select('spotdeals_external_venue_report', 'r')
      ->fields('r')->orderBy('created', 'DESC')->range(0, $limit);
    if ($status !== 'all') {
      $query->condition('status', $status);
    }
    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  public function hasRecentDuplicate(string $source, string $externalId, int $uid, string $ipHash, int $seconds = 86400): bool {
    $query = $this->database->select('spotdeals_external_venue_report', 'r')
      ->condition('external_source', $source)
      ->condition('external_id', $externalId)
      ->condition('created', $this->time->getRequestTime() - $seconds, '>=');
    $group = $query->orConditionGroup()->condition('ip_hash', $ipHash);
    if ($uid > 0) {
      $group->condition('uid', $uid);
    }
    return (bool) $query->condition($group)->countQuery()->execute()->fetchField();
  }

  public function review(int $id, string $status, string $notes, int $uid): void {
    $now = $this->time->getRequestTime();
    $this->database->update('spotdeals_external_venue_report')->fields([
      'status' => $status,
      'admin_notes' => $notes,
      'changed' => $now,
      'reviewed_by' => $uid,
      'reviewed_at' => $now,
    ])->condition('id', $id)->execute();
  }

  public function exclude(array $report, string $reason, int $uid): void {
    $now = $this->time->getRequestTime();
    $this->database->merge('spotdeals_external_venue_exclusion')
      ->keys(['external_source' => $report['external_source'], 'external_id' => $report['external_id']])
      ->fields([
        'venue_name' => $report['venue_name'],
        'venue_address' => $report['venue_address'],
        'reason' => $reason,
        'active' => 1,
        'created' => $now,
        'changed' => $now,
        'created_by' => $uid,
      ])->execute();
  }

  public function restore(string $source, string $externalId): void {
    $this->database->update('spotdeals_external_venue_exclusion')
      ->fields(['active' => 0, 'changed' => $this->time->getRequestTime()])
      ->condition('external_source', $source)->condition('external_id', $externalId)->execute();
  }

  public function isExcluded(string $source, string $externalId): bool {
    return (bool) $this->database->select('spotdeals_external_venue_exclusion', 'e')
      ->condition('external_source', $source)->condition('external_id', $externalId)
      ->condition('active', 1)->countQuery()->execute()->fetchField();
  }
}
