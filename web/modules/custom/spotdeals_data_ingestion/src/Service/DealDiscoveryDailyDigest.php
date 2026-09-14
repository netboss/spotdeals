<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Accumulates and sends one daily digest for automatic deal publishing.
 */
final class DealDiscoveryDailyDigest {

  private const STATE_KEY = 'spotdeals_data_ingestion.deal_discovery_daily_digest';

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records one newly published deal and, when applicable, its newly created venue.
   *
   * @param array<string, mixed> $publishResult
   *   The controlled publisher result.
   */
  public function recordPublication(array $publishResult): void {
    if (!empty($publishResult['already_published'])) {
      return;
    }

    $dealNid = (int) ($publishResult['deal_nid'] ?? 0);
    $venueNid = (int) ($publishResult['venue_nid'] ?? 0);
    $venueCreated = !empty($publishResult['venue_created']);

    if ($dealNid <= 0) {
      return;
    }

    $dateKey = $this->currentDateKey();
    $digest = $this->loadDigestState();
    $bucket = $this->normalizeBucket($digest[$dateKey] ?? []);

    $deal = $this->loadNode($dealNid, 'deal');
    $bucket['deals'][(string) $dealNid] = [
      'nid' => $dealNid,
      'title' => $deal instanceof NodeInterface ? (string) $deal->label() : 'Deal node ' . $dealNid,
    ];

    if ($venueCreated && $venueNid > 0) {
      $venue = $this->loadNode($venueNid, 'venue');
      $bucket['venues'][(string) $venueNid] = [
        'nid' => $venueNid,
        'title' => $venue instanceof NodeInterface ? (string) $venue->label() : 'Venue node ' . $venueNid,
      ];
    }

    $digest[$dateKey] = $bucket;
    $this->state->set(self::STATE_KEY, $digest);
  }

  /**
   * Adds duplicate rejections from one automatic cron batch to today's digest.
   */
  public function recordDuplicateRejections(int $count): void {
    if ($count <= 0) {
      return;
    }

    $dateKey = $this->currentDateKey();
    $digest = $this->loadDigestState();
    $bucket = $this->normalizeBucket($digest[$dateKey] ?? []);
    $bucket['duplicates_rejected'] += $count;
    $digest[$dateKey] = $bucket;
    $this->state->set(self::STATE_KEY, $digest);
  }

  /**
   * Sends completed daily digest buckets and retains any bucket that fails.
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
      $venueCount = count($bucket['venues']);
      $dealCount = count($bucket['deals']);

      // The digest is specifically for publishing activity. Duplicate-only
      // days stay silent rather than creating another operational notification.
      if ($venueCount === 0 && $dealCount === 0) {
        unset($digest[$dateKey]);
        $changed = TRUE;
        continue;
      }

      if ($this->sendDigest($dateKey, $bucket)) {
        unset($digest[$dateKey]);
        $changed = TRUE;
      }
    }

    if ($changed) {
      if ($digest === []) {
        $this->state->delete(self::STATE_KEY);
      }
      else {
        $this->state->set(self::STATE_KEY, $digest);
      }
    }
  }

  /**
   * Sends one digest bucket.
   *
   * @param array{venues: array<string, array{nid: int, title: string}>, deals: array<string, array{nid: int, title: string}>, duplicates_rejected: int} $bucket
   *   Daily accumulated publishing activity.
   */
  private function sendDigest(string $dateKey, array $bucket): bool {
    $siteConfig = $this->configFactory->get('system.site');
    $recipient = trim((string) $siteConfig->get('mail'));

    if ($recipient === '') {
      $this->logger->warning(
        'Deal-discovery daily publishing digest for {date} was not sent because the site email address is empty.',
        ['date' => $dateKey],
      );
      return FALSE;
    }

    $params = [
      'subject' => 'SpotDeals daily publishing summary — ' . $dateKey,
      'body' => $this->buildBody($dateKey, $bucket),
    ];

    try {
      $result = $this->mailManager->mail(
        'spotdeals_data_ingestion',
        'deal_discovery_daily_digest',
        $recipient,
        'en',
        $params,
      );
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Deal-discovery daily publishing digest for {date} failed to send: {message}',
        [
          'date' => $dateKey,
          'message' => $exception->getMessage(),
        ],
      );
      return FALSE;
    }

    if (empty($result['result'])) {
      $this->logger->warning(
        'Deal-discovery daily publishing digest for {date} was not accepted by the configured mail backend.',
        ['date' => $dateKey],
      );
      return FALSE;
    }

    $this->logger->notice(
      'Sent deal-discovery daily publishing digest for {date}: {venues} new venue(s), {deals} new deal(s), {duplicates} duplicate candidate(s) rejected.',
      [
        'date' => $dateKey,
        'venues' => count($bucket['venues']),
        'deals' => count($bucket['deals']),
        'duplicates' => $bucket['duplicates_rejected'],
      ],
    );

    return TRUE;
  }

  /**
   * Builds the plain-text digest body.
   *
   * @param array{venues: array<string, array{nid: int, title: string}>, deals: array<string, array{nid: int, title: string}>, duplicates_rejected: int} $bucket
   *   Daily accumulated publishing activity.
   */
  private function buildBody(string $dateKey, array $bucket): string {
    $venueCount = count($bucket['venues']);
    $dealCount = count($bucket['deals']);

    $lines = [
      'SpotDeals Daily Publishing Summary — ' . $dateKey,
      '',
      'New venues: ' . $venueCount,
      'New deals: ' . $dealCount,
      'Duplicates automatically rejected: ' . $bucket['duplicates_rejected'],
    ];

    $siteTotals = $this->loadPublishedSiteTotals();
    if ($siteTotals !== NULL) {
      $lines[] = '';
      $lines[] = 'Current published site totals';
      $lines[] = 'Total venues: ' . $siteTotals['venues'];
      $lines[] = 'Total deals: ' . $siteTotals['deals'];
    }

    if ($venueCount > 0) {
      $lines[] = '';
      $lines[] = 'New venues';
      foreach ($bucket['venues'] as $venue) {
        $lines[] = '• ' . $venue['title'] . ' (node ' . $venue['nid'] . ')';
      }
    }

    if ($dealCount > 0) {
      $lines[] = '';
      $lines[] = 'New deals';
      foreach ($bucket['deals'] as $deal) {
        $lines[] = '• ' . $deal['title'] . ' (node ' . $deal['nid'] . ')';
      }
    }

    return implode("\n", $lines);
  }

  /**
   * Returns current published venue/deal totals without risking the digest.
   *
   * @return array{venues: int, deals: int}|null
   *   Published site totals, or NULL when they cannot be loaded safely.
   */
  private function loadPublishedSiteTotals(): ?array {
    try {
      $storage = $this->entityTypeManager->getStorage('node');

      $venues = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'venue')
        ->condition('status', 1)
        ->count()
        ->execute();

      $deals = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'deal')
        ->condition('status', 1)
        ->count()
        ->execute();

      return [
        'venues' => $venues,
        'deals' => $deals,
      ];
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Deal-discovery daily publishing digest could not load current published site totals: {message}',
        ['message' => $exception->getMessage()],
      );
      return NULL;
    }
  }

  /**
   * Returns the current site-local YYYY-MM-DD key.
   */
  private function currentDateKey(): string {
    $timezone = trim((string) $this->configFactory
      ->get('system.date')
      ->get('timezone.default'));

    if ($timezone === '') {
      $timezone = 'UTC';
    }

    try {
      $date = (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
        ->setTimezone(new \DateTimeZone($timezone));
    }
    catch (\Throwable) {
      $date = new \DateTimeImmutable('@' . $this->time->getCurrentTime());
    }

    return $date->format('Y-m-d');
  }

  /**
   * Loads one node when it still exists and matches the expected bundle.
   */
  private function loadNode(int $nid, string $bundle): ?NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node instanceof NodeInterface && $node->bundle() === $bundle
      ? $node
      : NULL;
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
   * @return array{venues: array<string, array{nid: int, title: string}>, deals: array<string, array{nid: int, title: string}>, duplicates_rejected: int}
   *   A normalized digest bucket.
   */
  private function normalizeBucket(mixed $stored): array {
    $stored = is_array($stored) ? $stored : [];

    return [
      'venues' => is_array($stored['venues'] ?? NULL) ? $stored['venues'] : [],
      'deals' => is_array($stored['deals'] ?? NULL) ? $stored['deals'] : [],
      'duplicates_rejected' => max(0, (int) ($stored['duplicates_rejected'] ?? 0)),
    ];
  }

}
