<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/src/Service/DealDiscoveryStorage.php';

#[Group('spotdeals_data_ingestion')]
final class DealDiscoverySchedulerDiscardPolicyTest extends TestCase {

  #[DataProvider('candidateCases')]
  public function testDiscardEligibility(array $overrides, bool $expected): void {
    $candidate = array_replace([
      'status' => 'pending',
      'category' => 'entertainment.bowling_alley',
      'confidence' => 'review',
      'classification_reason' => 'score 6 is below configured automatic-approval score 8',
      'reviewed_by' => 0,
      'reviewed_at' => 0,
    ], $overrides);
    self::assertSame($expected, DealDiscoveryStorage::isSchedulerDiscardEligible($candidate));
  }

  public static function candidateCases(): iterable {
    yield 'classifier failed non-food' => [[], TRUE];
    yield 'restaurant protected' => [['category' => 'catering.restaurant'], FALSE];
    yield 'bar restaurant protected' => [['category' => 'catering.bar,catering.restaurant'], FALSE];
    yield 'brewery protected' => [['category' => 'production.brewery'], FALSE];
    yield 'winery protected' => [['category' => 'production.winery'], FALSE];
    yield 'cafe protected' => [['category' => 'catering.cafe'], FALSE];
    yield 'bakery protected' => [['category' => 'commercial.food_and_drink.bakery'], FALSE];
    yield 'unknown category protected' => [['category' => 'entertainment.arcade'], FALSE];
    yield 'auto-approved preserved' => [['status' => 'auto_approved'], FALSE];
    yield 'published preserved' => [['status' => 'published'], FALSE];
    yield 'already rejected preserved' => [['status' => 'rejected'], FALSE];
    yield 'publishing readiness preserved' => [['classification_reason' => 'publishing readiness: missing valid schedule'], FALSE];
    yield 'publishing readiness with score warning preserved' => [['classification_reason' => 'score below threshold; publishing readiness: missing address'], FALSE];
    yield 'high confidence preserved' => [['confidence' => 'high'], FALSE];
    yield 'missing classifier explanation preserved' => [['classification_reason' => ''], FALSE];
    yield 'reviewed by admin preserved' => [['reviewed_by' => 3], FALSE];
    yield 'reviewed timestamp preserved' => [['reviewed_at' => 1793885436], FALSE];
    yield 'pet category classifier failure' => [['category' => 'commercial.pet,pet,service.crematorium.pet'], TRUE];
    yield 'museum category classifier failure' => [['category' => 'building.entertainment,entertainment'], TRUE];
  }

}
