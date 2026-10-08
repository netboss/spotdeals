<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryScheduler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 4) . '/src/Service/DealDiscoveryScheduler.php';

/**
 * Exercises the real processCron() entry-point without Drupal state or APIs.
 */
#[Group('spotdeals_data_ingestion')]
final class DealDiscoverySchedulerCronTest extends TestCase {

  #[DataProvider('earlyExitCases')]
  public function testEarlyExitWithoutRunningDiscovery(bool $enabled, array $types, string $expected): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($enabled, $types): mixed {
      return match ($key) {
        'deal_discovery_scheduler_enabled' => $enabled,
        'deal_discovery_scheduler_venue_types' => $types,
        default => NULL,
      };
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->expects(self::once())->method('get')
      ->with('spotdeals_data_ingestion.settings')->willReturn($config);

    // Intentionally do not construct the scheduler's real runner, storage,
    // state, lock or external integrations. These early exits must not use them.
    $reflection = new \ReflectionClass(DealDiscoveryScheduler::class);
    $scheduler = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('configFactory');
    $property->setValue($scheduler, $factory);

    self::assertSame(['status' => $expected], $scheduler->processCron());
  }

  public static function earlyExitCases(): iterable {
    yield 'disabled with categories' => [FALSE, [43, 2293], 'disabled'];
    yield 'enabled without categories' => [TRUE, [], 'no_categories'];
    yield 'enabled with invalid category IDs' => [TRUE, [0, '', NULL], 'no_categories'];
  }

}
