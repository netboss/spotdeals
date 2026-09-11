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
   * Automatically rejects a discovery candidate that duplicates an existing deal.
   *
   * Only pending/system-auto-approved candidates are eligible. Administrative
   * approvals, rejections, and published records are never overridden. The
   * existing deal node is recorded in durable administrative notes so a later
   * discovery refresh cannot erase why the candidate left the review queue.
   */
  public function markRejectedAsDuplicate(int $id, int $duplicateDealNid): bool {
    if ($duplicateDealNid <= 0) {
      return FALSE;
    }

    $candidate = $this->load($id);
    if ($candidate === NULL) {
      return FALSE;
    }

    $status = (string) ($candidate['status'] ?? '');
    if (!in_array($status, ['pending', 'auto_approved'], TRUE)) {
      return FALSE;
    }

    $duplicateNote = 'Automatically rejected as duplicate of existing deal node ' . $duplicateDealNid . '.';
    $adminNotes = trim((string) ($candidate['admin_notes'] ?? ''));
    if (!str_contains($adminNotes, $duplicateNote)) {
      $adminNotes = $adminNotes === ''
        ? $duplicateNote
        : $adminNotes . "\n" . $duplicateNote;
    }

    $classificationReason = trim((string) ($candidate['classification_reason'] ?? ''));
    $duplicateReason = 'publishing readiness: duplicate deal already exists (node ' . $duplicateDealNid . ')';
    if (!str_contains($classificationReason, $duplicateReason)) {
      $classificationReason = $classificationReason === ''
        ? $duplicateReason
        : $classificationReason . '; ' . $duplicateReason;
    }

    $updated = $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields([
        'status' => 'rejected',
        'admin_notes' => $adminNotes,
        'classification_reason' => $classificationReason,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', ['pending', 'auto_approved'], 'IN')
      ->execute();

    return $updated > 0;
  }

  /**
   * Routes a system auto-approval back to manual review when publishing is not
   * ready, without recording an administrative review decision.
   *
   * @param string[] $reasons
   *   Publishing-readiness reasons to append to the classification explanation.
   */
  public function markPendingForPublishingReadiness(int $id, array $reasons): void {
    $candidate = $this->load($id);
    if ($candidate === NULL || (string) ($candidate['status'] ?? '') !== 'auto_approved') {
      return;
    }

    $reasonParts = [];
    foreach ($reasons as $reason) {
      $reason = trim((string) $reason);
      if ($reason !== '') {
        $reasonParts[] = $reason;
      }
    }

    $classificationReason = trim((string) ($candidate['classification_reason'] ?? ''));
    $readinessReason = $reasonParts !== []
      ? 'publishing readiness: ' . implode('; ', array_unique($reasonParts))
      : 'publishing readiness: candidate requires manual review';

    if ($classificationReason !== '') {
      $classificationReason .= '; ' . $readinessReason;
    }
    else {
      $classificationReason = $readinessReason;
    }

    $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields([
        'status' => 'pending',
        'classification_reason' => $classificationReason,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', 'auto_approved')
      ->execute();
  }


  /**
   * Restores a system-routed pending candidate to the auto-publish queue.
   *
   * This is intentionally limited to candidates that were previously
   * auto-approved by the classifier and then routed to pending only because
   * publishing readiness failed. Administrative reviews are never overridden.
   */
  public function restoreAutoApprovalAfterReadinessReevaluation(int $id): bool {
    $candidate = $this->load($id);
    if ($candidate === NULL) {
      return FALSE;
    }

    if ((string) ($candidate['status'] ?? '') !== 'pending') {
      return FALSE;
    }

    if (
      (int) ($candidate['reviewed_by'] ?? 0) > 0
      || (int) ($candidate['reviewed_at'] ?? 0) > 0
    ) {
      return FALSE;
    }

    $classificationReason = trim((string) ($candidate['classification_reason'] ?? ''));
    if (
      !str_contains(
        $classificationReason,
        'candidate met all configured automatic-approval requirements',
      )
      || !str_contains($classificationReason, 'publishing readiness:')
    ) {
      return FALSE;
    }

    $updated = $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields([
        'status' => 'auto_approved',
        'confidence' => 'high',
        'classification_reason' => 'candidate met all configured automatic-approval requirements',
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', 'pending')
      ->condition('reviewed_by', 0)
      ->condition('reviewed_at', 0)
      ->execute();

    return $updated > 0;
  }

  /**
   * Restores an unreviewed historical pending candidate to auto-approved status.
   *
   * This write guard is intentionally conservative because discovery-time
   * location confidence is not persisted. Candidates whose stored
   * classification explicitly records a location-confidence failure are never
   * changed, and administrative reviews are never overridden. The caller must
   * re-run the current classifier and publishing preview before invoking this
   * method.
   */
  public function restoreAutoApprovalAfterHistoricalReclassification(int $id): bool {
    $candidate = $this->load($id);
    if ($candidate === NULL) {
      return FALSE;
    }

    if ((string) ($candidate['status'] ?? '') !== 'pending') {
      return FALSE;
    }

    if (
      (int) ($candidate['reviewed_by'] ?? 0) > 0
      || (int) ($candidate['reviewed_at'] ?? 0) > 0
    ) {
      return FALSE;
    }

    $classificationReason = trim((string) ($candidate['classification_reason'] ?? ''));
    if (preg_match(
      '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
      $classificationReason,
    ) === 1) {
      return FALSE;
    }

    $updated = $this->database
      ->update('spotdeals_deal_discovery_candidate')
      ->fields([
        'status' => 'auto_approved',
        'confidence' => 'high',
        'classification_reason' => 'candidate met all configured automatic-approval requirements',
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $id)
      ->condition('status', 'pending')
      ->condition('reviewed_by', 0)
      ->condition('reviewed_at', 0)
      ->execute();

    return $updated > 0;
  }

  /**
   * Lists system-routed pending candidates eligible for readiness re-checking.
   *
   * Only candidates that previously met every automatic-approval requirement
   * and were routed to pending solely by publishing readiness are returned.
   * Administrative reviews are excluded so cron can never override an editor.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate records keyed by candidate ID.
   */
  public function listPendingForReadinessReevaluation(int $limit = 25): array {
    $limit = max(1, min(200, $limit));

    $query = $this->database
      ->select('spotdeals_deal_discovery_candidate', 'c')
      ->fields('c')
      ->condition('status', 'pending')
      ->condition('reviewed_by', 0)
      ->condition('reviewed_at', 0)
      ->condition(
        'classification_reason',
        '%' . $this->database->escapeLike('candidate met all configured automatic-approval requirements') . '%',
        'LIKE',
      )
      ->condition(
        'classification_reason',
        '%' . $this->database->escapeLike('publishing readiness:') . '%',
        'LIKE',
      )
      ->orderBy('changed', 'ASC')
      ->range(0, $limit);

    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Lists a bounded slice of pending candidates for historical reclassification.
   *
   * The caller supplies the last scanned candidate ID so cron can advance through
   * the whole pending queue instead of repeatedly evaluating the same oldest
   * records. Safety exclusions are still enforced by the classifier caller and
   * by restoreAutoApprovalAfterHistoricalReclassification().
   *
   * @return array<int, array<string, mixed>>
   *   Candidate records keyed by candidate ID.
   */
  public function listPendingForHistoricalReclassification(
    int $limit = 25,
    int $afterId = 0,
  ): array {
    $limit = max(1, min(200, $limit));
    $afterId = max(0, $afterId);

    $query = $this->database
      ->select('spotdeals_deal_discovery_candidate', 'c')
      ->fields('c')
      ->condition('status', 'pending');

    if ($afterId > 0) {
      $query->condition('id', $afterId, '>');
    }

    return $query
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Lists auto-approved candidates waiting for automatic publishing.
   *
   * Oldest candidates are returned first so a steady discovery stream cannot
   * starve earlier ready candidates.
   *
   * @return array<int, array<string, mixed>>
   *   Candidate records keyed by candidate ID.
   */
  public function listAutoApprovedForPublishing(int $limit = 25): array {
    $limit = max(1, min(200, $limit));

    return $this->database
      ->select('spotdeals_deal_discovery_candidate', 'c')
      ->fields('c')
      ->condition('status', 'auto_approved')
      ->orderBy('changed', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
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
