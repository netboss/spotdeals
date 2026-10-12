<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryScheduler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Executes the real scheduler against in-memory fakes; no discovery APIs.
 *
 * Isolation is required because the production collaborators are final classes.
 */
#[Group('spotdeals_data_ingestion')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class DealDiscoverySchedulerBatchTest extends TestCase {

  private function scheduler(int $batchSize, array $pendingCounts, ?int $failOnRun = NULL, bool $allRecentlyCompleted = FALSE): array {
    // These collaborators are intentionally defined only in this isolated PHP
    // process. Their real implementations are final and cannot be PHPUnit-mocked.
    if (!class_exists('Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage', FALSE)) {
      eval(<<<'STUBS'
namespace Drupal\spotdeals_data_ingestion\Service;
class DealDiscoveryStorage {
  public array $counts = [];
  public function count(string $status = 'pending'): int {
    return array_shift($this->counts) ?? 0;
  }
}
class VenueTypeResolver {
  public function mappedVenueTypes(): array {
    return [
      ['tid' => 43, 'name' => 'Brewery', 'categories' => []],
      ['tid' => 44, 'name' => 'Restaurant', 'categories' => []],
    ];
  }
}
class DealDiscoveryLocationResolver {
  public function locations(): array {
    return [
      'alpha' => ['city' => 'Alpha', 'state' => 'FL', 'country' => 'US', 'label' => 'Alpha, FL'],
      'beta' => ['city' => 'Beta', 'state' => 'FL', 'country' => 'US', 'label' => 'Beta, FL'],
      'gamma' => ['city' => 'Gamma', 'state' => 'FL', 'country' => 'US', 'label' => 'Gamma, FL'],
    ];
  }
}
class DealDiscoveryRunner {
  public array $calls = [];
  public ?int $failOn = NULL;
  public function run(array $venueType, array $location, int $candidateLimit = 50, int $sitePages = 5): array {
    $this->calls[] = [$venueType['tid'], $location['city']];
    if (count($this->calls) === $this->failOn) {
      throw new \RuntimeException('simulated discovery failure');
    }
    return ['category_label' => $venueType['name'], 'location_label' => $location['city'], 'queued' => 0];
  }
}
class ScheduledDealDiscoveryActivityDigest {
  public array $recorded = [];
  public function recordRun(array $result): void { $this->recorded[] = $result; }
}
STUBS);
    }
    require_once dirname(__DIR__, 4) . '/src/Service/DealDiscoveryScheduler.php';

    $values = [
      'deal_discovery_scheduler_enabled' => TRUE,
      'deal_discovery_scheduler_venue_types' => [43, 44],
      'deal_discovery_scheduler_batch_size' => $batchSize,
      'deal_discovery_scheduler_pause_pending' => 300,
      'deal_discovery_scheduler_resume_pending' => 250,
      'deal_discovery_scheduler_minimum_interval_minutes' => 60,
      'deal_discovery_scheduler_refresh_days' => 7,
    ];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    $stored = (object) ['values' => []];
    if ($allRecentlyCompleted) {
      $runs = [];
      foreach ([43, 44] as $tid) {
        foreach (['alpha', 'beta', 'gamma'] as $token) {
          $runs[$tid . ':' . $token] = ['last_completed' => 1800000000];
        }
      }
      $stored->values['spotdeals_data_ingestion.deal_discovery_scheduler_runs'] = $runs;
    }
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, mixed $default = NULL) use ($stored): mixed {
      return $stored->values[$key] ?? $default;
    });
    $state->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($stored): void {
      $stored->values[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(static function (string $key) use ($stored): void {
      unset($stored->values[$key]);
    });

    $lock = $this->createMock(LockBackendInterface::class);
    $lockState = (object) ['held' => FALSE, 'acquires' => 0, 'releases' => 0];
    $lock->method('acquire')->willReturnCallback(static function () use ($lockState): bool {
      $lockState->acquires++;
      if ($lockState->held) {
        return FALSE;
      }
      $lockState->held = TRUE;
      return TRUE;
    });
    $lock->method('release')->willReturnCallback(static function () use ($lockState): void {
      $lockState->releases++;
      $lockState->held = FALSE;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1800000000);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $storage = new \Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage();
    $storage->counts = $pendingCounts;
    $runner = new \Drupal\spotdeals_data_ingestion\Service\DealDiscoveryRunner();
    $runner->failOn = $failOnRun;
    $digest = new \Drupal\spotdeals_data_ingestion\Service\ScheduledDealDiscoveryActivityDigest();
    $scheduler = new DealDiscoveryScheduler(
      $factory, $storage,
      new \Drupal\spotdeals_data_ingestion\Service\VenueTypeResolver(),
      new \Drupal\spotdeals_data_ingestion\Service\DealDiscoveryLocationResolver(),
      $runner, $digest, $lock, $state, $time, $logger,
    );
    return [$scheduler, $runner, $digest, $stored, $lockState];
  }

  public function testBatchLimitAndUniqueRotatingTargets(): void {
    [$scheduler, $runner, $digest, $stored] = $this->scheduler(4, array_fill(0, 8, 0));
    $result = $scheduler->processCron();
    self::assertSame('completed', $result['status']);
    self::assertSame(4, $result['completed']);
    self::assertSame([[43, 'Alpha'], [44, 'Alpha'], [43, 'Beta'], [44, 'Beta']], $runner->calls);
    self::assertCount(4, array_unique(array_map('json_encode', $runner->calls)));
    self::assertCount(4, $digest->recorded);
    self::assertCount(4, $stored->values['spotdeals_data_ingestion.deal_discovery_scheduler_runs']);
  }

  public function testBacklogStopsBatchBetweenPasses(): void {
    [$scheduler, $runner, $digest, $stored] = $this->scheduler(5, [0, 0, 299, 300]);
    $result = $scheduler->processCron();
    self::assertSame('backlog_paused', $result['status']);
    self::assertSame(2, $result['completed']);
    self::assertCount(2, $runner->calls);
    self::assertTrue($stored->values['spotdeals_data_ingestion.deal_discovery_scheduler_backlog_paused']);
  }

  public function testFailurePreservesEarlierSuccessfulPasses(): void {
    [$scheduler, $runner, $digest, $stored] = $this->scheduler(5, array_fill(0, 8, 0), 3);
    $result = $scheduler->processCron();
    self::assertSame('failed', $result['status']);
    self::assertSame(2, $result['completed']);
    self::assertCount(3, $runner->calls);
    self::assertCount(2, $digest->recorded);
    self::assertCount(2, $stored->values['spotdeals_data_ingestion.deal_discovery_scheduler_runs']);
  }

  /**
   * No due work must not run discovery or leave a scheduler lock held.
   */
  public function testNothingDueReleasesLockForSingleAndMultiplePasses(): void {
    foreach ([1, 5] as $batchSize) {
      [$scheduler, $runner, $digest, $stored, $lockState] = $this->scheduler(
        $batchSize,
        array_fill(0, 10, 0),
        NULL,
        TRUE,
      );
      $result = $scheduler->processCron();
      self::assertSame('nothing_due', $result['status']);
      self::assertSame([], $runner->calls);
      self::assertSame([], $digest->recorded);
      self::assertSame(1, $lockState->acquires);
      self::assertSame(1, $lockState->releases);
      self::assertFalse($lockState->held);
      self::assertArrayNotHasKey('spotdeals_data_ingestion.deal_discovery_scheduler_last_attempt', $stored->values);
    }
  }

  /**
   * A later invocation can acquire the released lock and process due work.
   */
  public function testNothingDueDoesNotBlockSubsequentDiscovery(): void {
    [$scheduler, $runner, $digest, $stored, $lockState] = $this->scheduler(
      5,
      array_fill(0, 10, 0),
      NULL,
      TRUE,
    );
    self::assertSame('nothing_due', $scheduler->processCron()['status']);
    self::assertFalse($lockState->held);

    // Simulate one location/category becoming due before the next cron call.
    $runs = $stored->values['spotdeals_data_ingestion.deal_discovery_scheduler_runs'];
    $runs['43:alpha']['last_completed'] = 1800000000 - 8 * 86400;
    $stored->values['spotdeals_data_ingestion.deal_discovery_scheduler_runs'] = $runs;

    $result = $scheduler->processCron();
    self::assertSame('nothing_due', $result['status']);
    self::assertSame(1, $result['completed']);
    self::assertSame([[43, 'Alpha']], $runner->calls);
    self::assertCount(1, $digest->recorded);
    self::assertSame(2, $lockState->acquires);
    self::assertSame(2, $lockState->releases);
    self::assertFalse($lockState->held);
  }

  public function testDefaultSinglePassResponseContract(): void {
    [$scheduler, $runner] = $this->scheduler(1, [0, 0]);
    $result = $scheduler->processCron();
    self::assertSame('completed', $result['status']);
    self::assertSame('Brewery', $result['category_label']);
    self::assertArrayNotHasKey('results', $result);
    self::assertCount(1, $runner->calls);
  }

}
