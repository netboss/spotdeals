<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryFieldDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for deal-discovery publishing field derivation.
 *
 * @group spotdeals_data_ingestion
 */
final class DealDiscoveryFieldDeriverTest extends TestCase {

  private DealDiscoveryFieldDeriver $deriver;

  protected function setUp(): void {
    parent::setUp();
    $this->deriver = new DealDiscoveryFieldDeriver();
  }

  public function testNightowlRegression(): void {
    $schedule = 'Get $5 off per player at 10 pm or later Sunday through Friday.';

    $this->assertSame(
      [['target_id' => 1636, 'name' => 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday']],
      $this->deriver->deriveTaxonomyScheduleTerms($schedule, $this->dayTerms()),
    );
    $this->assertSame('10 pm', $this->deriver->deriveStartTime($schedule));
    $this->assertNull($this->deriver->deriveRecurring($schedule));
    $this->assertSame(
      ['target_id' => 1181, 'name' => 'Promotion'],
      $this->deriver->deriveExactTaxonomyTerm(
        'NIGHTOWL $5 off promotion ' . $schedule,
        $this->categoryTerms(),
      ),
    );
  }

  public function testEarlybirdRegression(): void {
    $schedule = 'This offer is valid for missions before noon Sunday through Friday.';

    $this->assertSame(
      [['target_id' => 1636, 'name' => 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday']],
      $this->deriver->deriveTaxonomyScheduleTerms($schedule, $this->dayTerms()),
    );
    $this->assertSame('', $this->deriver->deriveStartTime($schedule));
    $this->assertNull($this->deriver->deriveRecurring($schedule));
  }

  public function testAccessForAllAtOmaRegression(): void {
    $schedule = 'On the third Thursday of every month, enjoy extended hours from 10 am to 8 pm.';

    $this->assertSame(
      [['target_id' => 50, 'name' => 'Thursday']],
      $this->deriver->deriveTaxonomyScheduleTerms($schedule, $this->dayTerms()),
    );
    $this->assertSame('10 am', $this->deriver->deriveStartTime($schedule));
    $this->assertSame(1, $this->deriver->deriveRecurring($schedule));
  }

  public function testFreeAdmissionWeekendRegression(): void {
    $schedule = 'Complimentary admission on the first full weekend of each month.';

    $this->assertSame(
      [['target_id' => 90, 'name' => 'Weekends']],
      $this->deriver->deriveTaxonomyScheduleTerms($schedule, $this->dayTerms()),
    );
    $this->assertSame('', $this->deriver->deriveStartTime($schedule));
    $this->assertSame(1, $this->deriver->deriveRecurring($schedule));
  }

  public function testGroupDiscountWithoutScheduleRemainsUnresolved(): void {
    $this->assertSame([], $this->deriver->deriveTaxonomyScheduleTerms('', $this->dayTerms()));
    $this->assertSame('', $this->deriver->deriveStartTime(''));
    $this->assertNull($this->deriver->deriveRecurring(''));
    $this->assertSame(
      ['target_id' => 3690, 'name' => 'Group Discount'],
      $this->deriver->deriveExactTaxonomyTerm(
        'Group discount: take 20% off when you buy 8 or more tickets.',
        $this->categoryTerms(),
      ),
    );
  }

  public function testRangeDoesNotReverseOrAddExtraScheduleSemantics(): void {
    $schedule = 'Valid Sunday through Friday.';
    $terms = [
      ['tid' => 2255, 'name' => 'Friday-Sunday', 'weight' => 0],
      ['tid' => 1902, 'name' => 'Sunday-Friday; Friday-Saturday late night', 'weight' => 0],
    ];

    $this->assertSame([], $this->deriver->deriveTaxonomyScheduleTerms($schedule, $terms));
  }

  /**
   * @return array<int, array{tid: int, name: string, weight: int}>
   */
  private function dayTerms(): array {
    return [
      ['tid' => 47, 'name' => 'Monday', 'weight' => 0],
      ['tid' => 48, 'name' => 'Tuesday', 'weight' => 0],
      ['tid' => 49, 'name' => 'Wednesday', 'weight' => 0],
      ['tid' => 50, 'name' => 'Thursday', 'weight' => 0],
      ['tid' => 51, 'name' => 'Friday', 'weight' => 0],
      ['tid' => 52, 'name' => 'Saturday', 'weight' => 0],
      ['tid' => 53, 'name' => 'Sunday', 'weight' => 0],
      ['tid' => 90, 'name' => 'Weekends', 'weight' => 0],
      ['tid' => 369, 'name' => 'Monday-Friday', 'weight' => 0],
      ['tid' => 151, 'name' => 'Tuesday-Friday', 'weight' => 0],
      ['tid' => 155, 'name' => 'Sunday-Thursday', 'weight' => 0],
      ['tid' => 1636, 'name' => 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday', 'weight' => 0],
      ['tid' => 1633, 'name' => 'Sunday, Monday, Tuesday, Wednesday, Thursday, Friday, Saturday', 'weight' => 0],
      ['tid' => 1902, 'name' => 'Sunday-Friday; Friday-Saturday late night', 'weight' => 0],
      ['tid' => 2255, 'name' => 'Friday-Sunday', 'weight' => 0],
      ['tid' => 2282, 'name' => 'All', 'weight' => 0],
    ];
  }

  /**
   * @return array<int, array{tid: int, name: string, weight: int}>
   */
  private function categoryTerms(): array {
    return [
      ['tid' => 1181, 'name' => 'Promotion', 'weight' => 0],
      ['tid' => 3690, 'name' => 'Group Discount', 'weight' => 0],
    ];
  }

}
