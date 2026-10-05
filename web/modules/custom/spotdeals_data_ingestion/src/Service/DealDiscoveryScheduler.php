<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;

/**
 * Schedules bounded recurring deal discovery across selected categories/cities.
 */
final class DealDiscoveryScheduler {

  private const LOCK_NAME = 'spotdeals_data_ingestion.deal_discovery_scheduler';

  private const RUN_STATE_NAME = 'spotdeals_data_ingestion.deal_discovery_scheduler_runs';

  private const LAST_ATTEMPT_STATE_NAME = 'spotdeals_data_ingestion.deal_discovery_scheduler_last_attempt';

  private const BACKLOG_PAUSED_STATE_NAME = 'spotdeals_data_ingestion.deal_discovery_scheduler_backlog_paused';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DealDiscoveryStorage $storage,
    private readonly VenueTypeResolver $venueTypeResolver,
    private readonly DealDiscoveryLocationResolver $locationResolver,
    private readonly DealDiscoveryRunner $runner,
    private readonly LockBackendInterface $lock,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Runs at most one due automated discovery pass.
   *
   * @return array<string, mixed>
   *   Scheduler status and, when run, discovery counters.
   */
  public function processCron(): array {
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    if (!(bool) ($config->get('deal_discovery_scheduler_enabled') ?? FALSE)) {
      return ['status' => 'disabled'];
    }

    $selectedTids = array_values(array_unique(array_filter(array_map(
      'intval',
      (array) ($config->get('deal_discovery_scheduler_venue_types') ?? []),
    ))));
    if ($selectedTids === []) {
      return ['status' => 'no_categories'];
    }

    $pendingCount = $this->storage->count('pending');
    $pauseAt = max(1, (int) ($config->get('deal_discovery_scheduler_pause_pending') ?? 300));
    $resumeBelow = max(0, min(
      $pauseAt - 1,
      (int) ($config->get('deal_discovery_scheduler_resume_pending') ?? 250),
    ));
    $backlogPaused = (bool) $this->state->get(self::BACKLOG_PAUSED_STATE_NAME, FALSE);

    if ($backlogPaused && $pendingCount > $resumeBelow) {
      return [
        'status' => 'backlog_paused',
        'pending' => $pendingCount,
      ];
    }
    if ($backlogPaused) {
      $this->state->delete(self::BACKLOG_PAUSED_STATE_NAME);
    }
    if ($pendingCount >= $pauseAt) {
      $this->state->set(self::BACKLOG_PAUSED_STATE_NAME, TRUE);
      $this->logger->notice(
        'Automated deal discovery paused at {pending} pending candidate(s); it will resume at {resume} or fewer.',
        ['pending' => $pendingCount, 'resume' => $resumeBelow],
      );
      return [
        'status' => 'backlog_paused',
        'pending' => $pendingCount,
      ];
    }

    $now = $this->time->getCurrentTime();
    $minimumInterval = max(
      300,
      (int) ($config->get('deal_discovery_scheduler_minimum_interval_minutes') ?? 60) * 60,
    );
    $lastAttempt = (int) $this->state->get(self::LAST_ATTEMPT_STATE_NAME, 0);
    if ($lastAttempt > 0 && ($now - $lastAttempt) < $minimumInterval) {
      return ['status' => 'cooldown'];
    }

    $definitions = [];
    foreach ($this->venueTypeResolver->mappedVenueTypes() as $definition) {
      $tid = (int) ($definition['tid'] ?? 0);
      if ($tid > 0 && in_array($tid, $selectedTids, TRUE)) {
        $definitions[$tid] = $definition;
      }
    }
    if ($definitions === []) {
      return ['status' => 'no_mapped_categories'];
    }

    $locations = $this->locationResolver->locations();
    if ($locations === []) {
      return ['status' => 'no_locations'];
    }

    $refreshSeconds = max(
      86400,
      (int) ($config->get('deal_discovery_scheduler_refresh_days') ?? 7) * 86400,
    );
    $runState = $this->state->get(self::RUN_STATE_NAME, []);
    if (!is_array($runState)) {
      $runState = [];
    }

    $due = [];
    foreach ($definitions as $tid => $definition) {
      foreach ($locations as $token => $location) {
        $key = $tid . ':' . $token;
        $lastCompleted = (int) ($runState[$key]['last_completed'] ?? 0);
        if ($lastCompleted > 0 && ($now - $lastCompleted) < $refreshSeconds) {
          continue;
        }
        $due[] = [
          'key' => $key,
          'tid' => $tid,
          'definition' => $definition,
          'token' => $token,
          'location' => $location,
          'last_completed' => $lastCompleted,
        ];
      }
    }

    if ($due === []) {
      return ['status' => 'nothing_due'];
    }

    usort($due, static function (array $a, array $b): int {
      $byTime = $a['last_completed'] <=> $b['last_completed'];
      if ($byTime !== 0) {
        return $byTime;
      }
      $byCategory = strnatcasecmp(
        (string) ($a['definition']['name'] ?? ''),
        (string) ($b['definition']['name'] ?? ''),
      );
      if ($byCategory !== 0) {
        return $byCategory;
      }
      return strnatcasecmp(
        (string) ($a['location']['label'] ?? ''),
        (string) ($b['location']['label'] ?? ''),
      );
    });

    if (!$this->lock->acquire(self::LOCK_NAME, 3600.0)) {
      return ['status' => 'locked'];
    }

    $target = $due[0];
    $this->state->set(self::LAST_ATTEMPT_STATE_NAME, $now);

    try {
      $candidateLimit = max(1, min(
        50,
        (int) ($config->get('deal_discovery_scheduler_candidate_limit') ?? 25),
      ));
      $sitePages = max(1, min(
        10,
        (int) ($config->get('deal_discovery_scheduler_site_pages') ?? 5),
      ));

      $result = $this->runner->run(
        $target['definition'],
        [
          'city' => (string) $target['location']['city'],
          'state' => (string) $target['location']['state'],
          'country' => (string) $target['location']['country'],
        ],
        $candidateLimit,
        $sitePages,
      );

      $runState[$target['key']] = [
        'last_completed' => $this->time->getCurrentTime(),
        'category_tid' => $target['tid'],
        'category_label' => (string) ($target['definition']['name'] ?? ''),
        'location_token' => $target['token'],
        'location_label' => (string) ($target['location']['label'] ?? ''),
      ];
      $this->state->set(self::RUN_STATE_NAME, $runState);

      $this->logger->notice(
        'Automated deal discovery completed for {category} in {location}: researched {researched}, qualifying venues {review}, queued/refreshed {queued}, auto-approved {auto}, duplicate-rejected {duplicates}, rejected {rejected}, pending {pending}.',
        [
          'category' => (string) ($result['category_label'] ?? ''),
          'location' => (string) ($result['location_label'] ?? ''),
          'researched' => (int) ($result['researched'] ?? 0),
          'review' => (int) ($result['review_venues'] ?? 0),
          'queued' => (int) ($result['queued'] ?? 0),
          'auto' => (int) ($result['auto_approved'] ?? 0),
          'duplicates' => (int) ($result['duplicates_rejected'] ?? 0),
          'rejected' => (int) ($result['rejected'] ?? 0),
          'pending' => (int) ($result['pending'] ?? 0),
        ],
      );

      return ['status' => 'completed'] + $result;
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Automated deal discovery failed safely for {category} in {location}: {message}',
        [
          'category' => (string) ($target['definition']['name'] ?? ''),
          'location' => (string) ($target['location']['label'] ?? ''),
          'message' => $exception->getMessage(),
        ],
      );
      return [
        'status' => 'failed',
        'message' => $exception->getMessage(),
      ];
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

}
