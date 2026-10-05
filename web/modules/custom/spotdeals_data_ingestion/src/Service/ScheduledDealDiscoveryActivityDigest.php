<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Accumulates and sends a daily digest of scheduled discovery activity.
 */
final class ScheduledDealDiscoveryActivityDigest {

  private const STATE_KEY = 'spotdeals_data_ingestion.scheduled_deal_discovery_activity_digest';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records one successfully completed scheduled discovery run.
   *
   * @param array<string, mixed> $result
   *   The shared discovery runner result.
   */
  public function recordRun(array $result): void {
    $dateKey = $this->currentDateKey();
    $digest = $this->loadDigestState();
    $bucket = $this->normalizeBucket($digest[$dateKey] ?? []);

    $bucket['runs'][] = [
      'time' => $this->currentTimeLabel(),
      'category' => trim((string) ($result['category_label'] ?? '')),
      'location' => trim((string) ($result['location_label'] ?? '')),
      'researched' => max(0, (int) ($result['researched'] ?? 0)),
      'qualifying' => max(0, (int) ($result['review_venues'] ?? 0)),
      'queued' => max(0, (int) ($result['queued'] ?? 0)),
      'auto_approved' => max(0, (int) ($result['auto_approved'] ?? 0)),
      'duplicates_rejected' => max(0, (int) ($result['duplicates_rejected'] ?? 0)),
      'rejected' => max(0, (int) ($result['rejected'] ?? 0)),
      'pending' => max(0, (int) ($result['pending'] ?? 0)),
    ];

    $digest[$dateKey] = $bucket;
    $this->state->set(self::STATE_KEY, $digest);
  }

  /**
   * Sends completed daily activity buckets and retains any bucket that fails.
   */
  public function sendCompletedDigests(): void {
    $today = $this->currentDateKey();
    $digest = $this->loadDigestState();
    if ($digest === []) {
      return;
    }

    ksort($digest);
    $changed = FALSE;

    foreach ($digest as $dateKey => $storedBucket) {
      if (!is_string($dateKey) || $dateKey >= $today) {
        continue;
      }

      $bucket = $this->normalizeBucket($storedBucket);
      if ($bucket['runs'] === []) {
        unset($digest[$dateKey]);
        $changed = TRUE;
        continue;
      }

      if ($this->sendDigest($dateKey, $bucket)) {
        unset($digest[$dateKey]);
        $changed = TRUE;
      }
    }

    if (!$changed) {
      return;
    }

    if ($digest === []) {
      $this->state->delete(self::STATE_KEY);
    }
    else {
      $this->state->set(self::STATE_KEY, $digest);
    }
  }

  /**
   * Sends one completed activity digest.
   *
   * @param array{runs: array<int, array<string, int|string>>} $bucket
   *   Daily scheduled-discovery activity.
   */
  private function sendDigest(string $dateKey, array $bucket): bool {
    $recipient = trim((string) $this->configFactory->get('system.site')->get('mail'));
    if ($recipient === '') {
      $this->logger->warning(
        'Scheduled deal-discovery activity digest for {date} was not sent because the site email address is empty.',
        ['date' => $dateKey],
      );
      return FALSE;
    }

    $params = [
      'subject' => 'SpotDeals scheduled discovery activity — ' . $dateKey,
      'body' => $this->buildBody($dateKey, $bucket),
    ];

    try {
      $result = $this->mailManager->mail(
        'spotdeals_data_ingestion',
        'scheduled_deal_discovery_activity_digest',
        $recipient,
        'en',
        $params,
      );
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Scheduled deal-discovery activity digest for {date} failed to send: {message}',
        ['date' => $dateKey, 'message' => $exception->getMessage()],
      );
      return FALSE;
    }

    if (empty($result['result'])) {
      $this->logger->warning(
        'Scheduled deal-discovery activity digest for {date} was not accepted by the configured mail backend.',
        ['date' => $dateKey],
      );
      return FALSE;
    }

    $this->logger->notice(
      'Sent scheduled deal-discovery activity digest for {date}: {runs} completed run(s).',
      ['date' => $dateKey, 'runs' => count($bucket['runs'])],
    );
    return TRUE;
  }

  /**
   * Builds the plain-text activity digest body.
   *
   * @param array{runs: array<int, array<string, int|string>>} $bucket
   *   Daily scheduled-discovery activity.
   */
  private function buildBody(string $dateKey, array $bucket): string {
    $totals = [
      'researched' => 0,
      'qualifying' => 0,
      'queued' => 0,
      'auto_approved' => 0,
      'duplicates_rejected' => 0,
      'rejected' => 0,
      'pending' => 0,
    ];

    foreach ($bucket['runs'] as $run) {
      foreach ($totals as $key => $value) {
        $totals[$key] = $value + max(0, (int) ($run[$key] ?? 0));
      }
    }

    $lines = [
      'SpotDeals Scheduled Deal Discovery Activity — ' . $dateKey,
      '',
      'Completed runs: ' . count($bucket['runs']),
      'Venues researched: ' . $totals['researched'],
      'Qualifying venues: ' . $totals['qualifying'],
      'Candidates queued/refreshed: ' . $totals['queued'],
      'Auto-approved: ' . $totals['auto_approved'],
      'Duplicates automatically rejected: ' . $totals['duplicates_rejected'],
      'Rejected/non-actionable: ' . $totals['rejected'],
      'Pending manual review: ' . $totals['pending'],
      '',
      'Runs',
    ];

    foreach ($bucket['runs'] as $run) {
      $category = trim((string) ($run['category'] ?? '')) ?: 'Unknown category';
      $location = trim((string) ($run['location'] ?? '')) ?: 'Unknown location';
      $time = trim((string) ($run['time'] ?? ''));
      $prefix = $time !== '' ? $time . ' — ' : '';
      $lines[] = sprintf(
        '• %s%s — %s: %d researched, %d qualifying, %d queued/refreshed, %d auto-approved, %d duplicate-rejected, %d rejected, %d pending.',
        $prefix,
        $category,
        $location,
        (int) ($run['researched'] ?? 0),
        (int) ($run['qualifying'] ?? 0),
        (int) ($run['queued'] ?? 0),
        (int) ($run['auto_approved'] ?? 0),
        (int) ($run['duplicates_rejected'] ?? 0),
        (int) ($run['rejected'] ?? 0),
        (int) ($run['pending'] ?? 0),
      );
    }

    return implode("\n", $lines);
  }

  /**
   * Returns the current site-local YYYY-MM-DD key.
   */
  private function currentDateKey(): string {
    return $this->siteLocalDate()->format('Y-m-d');
  }

  /**
   * Returns the current site-local time label for a run.
   */
  private function currentTimeLabel(): string {
    return $this->siteLocalDate()->format('H:i T');
  }

  /**
   * Returns current time in the configured site timezone.
   */
  private function siteLocalDate(): \DateTimeImmutable {
    $timezone = trim((string) $this->configFactory
      ->get('system.date')
      ->get('timezone.default'));
    if ($timezone === '') {
      $timezone = 'UTC';
    }

    $date = new \DateTimeImmutable('@' . $this->time->getCurrentTime());
    try {
      return $date->setTimezone(new \DateTimeZone($timezone));
    }
    catch (\Throwable) {
      return $date;
    }
  }

  /**
   * Loads the digest state in a defensive shape.
   *
   * @return array<string, mixed>
   *   Date-keyed digest buckets.
   */
  private function loadDigestState(): array {
    $stored = $this->state->get(self::STATE_KEY, []);
    return is_array($stored) ? $stored : [];
  }

  /**
   * Normalizes one digest bucket.
   *
   * @return array{runs: array<int, array<string, int|string>>}
   *   A normalized digest bucket.
   */
  private function normalizeBucket(mixed $stored): array {
    $stored = is_array($stored) ? $stored : [];
    $runs = is_array($stored['runs'] ?? NULL) ? $stored['runs'] : [];

    return [
      'runs' => array_values(array_filter($runs, 'is_array')),
    ];
  }

}
