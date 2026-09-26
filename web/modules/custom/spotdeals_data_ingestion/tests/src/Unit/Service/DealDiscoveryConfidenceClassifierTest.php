<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryConfidenceClassifier;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityService;
use PHPUnit\Framework\TestCase;

// Keep this focused unit test deterministic when PHPUnit is invoked directly
// against the module test file. Drupal's runtime class loader is available in
// Drush, but direct PHPUnit execution does not always register custom-module
// PSR-4 namespaces before setUp() instantiates the service under test.
$moduleRoot = dirname(__DIR__, 4);
require_once $moduleRoot . '/src/Service/DealDiscoveryContentQualityService.php';
require_once $moduleRoot . '/src/Service/DealDiscoveryConfidenceClassifier.php';

/**
 * @coversDefaultClass \Drupal\spotdeals_data_ingestion\Service\DealDiscoveryConfidenceClassifier
 * @group spotdeals_data_ingestion
 */
final class DealDiscoveryConfidenceClassifierTest extends TestCase {

  private DealDiscoveryConfidenceClassifier $classifier;

  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['deal_discovery_auto_approve_score', 8],
      ['deal_discovery_auto_approve_location_confidence', 1],
      ['deal_discovery_auto_approve_require_schedule', TRUE],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory
      ->method('get')
      ->with('spotdeals_data_ingestion.settings')
      ->willReturn($config);

    $time = $this->createMock(TimeInterface::class);
    $time
      ->method('getCurrentTime')
      ->willReturn((new \DateTimeImmutable('2026-09-09T12:00:00+00:00'))->getTimestamp());

    $this->classifier = new DealDiscoveryConfidenceClassifier(
      $configFactory,
      new DealDiscoveryContentQualityService(),
      $time,
    );
  }

  /**
   * @covers ::classify
   */
  public function testExplicitStandingDiscountCanAutoApproveWithoutSchedule(): void {
    $result = $this->classifier->classify([
      'title' => 'Military & First Responders Discount',
      'value' => '15% Off',
      'schedule' => '',
      'source_url' => 'https://example.com/deals',
      'score' => 6,
      'reason' => 'promotion=discount; value=15% Off; binding=article',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
    self::assertSame('high', $result['confidence']);
  }

  /**
   * @covers ::classify
   */
  public function testFreeAdmissionWithPromotionalTitleCanAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Free Admission Every Wednesday',
      'value' => 'free admission',
      'schedule' => '',
      'source_url' => 'https://example.com/free-admission',
      'score' => 6,
      'reason' => 'promotion=free; value=free admission; binding=heading',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testFreeAdmissionAloneDoesNotMakeWeakTitleAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'MORE FUN',
      'value' => 'free admission',
      'schedule' => '',
      'source_url' => 'https://example.com/admission',
      'score' => 7,
      'reason' => 'promotion=free; value=free admission; binding=heading',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'score 7 is below configured automatic-approval score 8',
      $result['reasons'],
    );
    self::assertContains(
      'schedule or validity context is missing',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testTextFallbackCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Save 20% Today',
      'value' => '20% off',
      'schedule' => '',
      'source_url' => 'https://example.com/deals',
      'score' => 6,
      'reason' => 'promotion=save; value=20% off; binding=text_fallback',
    ], 1);

    self::assertSame('pending', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testStrongEvidenceNeverBypassesLocationConfidence(): void {
    $result = $this->classifier->classify([
      'title' => 'Save 20% Today',
      'value' => '20% off',
      'schedule' => '',
      'source_url' => 'https://example.com/deals',
      'score' => 6,
      'reason' => 'promotion=save; value=20% off; binding=article',
    ], 0);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'location confidence 0 is below configured minimum 1',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testInformationalTitleRemainsBlockedEvenWithFreeAdmission(): void {
    $result = $this->classifier->classify([
      'title' => 'Hours',
      'value' => 'free admission',
      'schedule' => 'Wednesday',
      'source_url' => 'https://example.com/hours',
      'score' => 9,
      'reason' => 'promotion=free; value=free admission; binding=heading; schedule=Wednesday',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertNotEmpty($result['reasons']);
  }

  /**
   * @covers ::classify
   */
  public function testGenericWaysToSaveContainerCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Ways to Save',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/save',
      'score' => 10,
      'reason' => 'promotion=free; value=Free Admission; binding=heading',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'schedule or validity context is missing',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testGenericProgramsAndSpecialOffersContainerCannotUseBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Programs & Special Offers',
      'value' => 'FREE admission',
      'schedule' => '',
      'source_url' => 'https://example.com/offers',
      'score' => 8,
      'reason' => 'promotion=free; value=FREE admission; binding=heading',
    ], 1);

    self::assertSame('pending', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testBareFreeTitleCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Free',
      'value' => 'free admission',
      'schedule' => '',
      'source_url' => 'https://example.com/free',
      'score' => 6,
      'reason' => 'promotion=free; value=free admission; binding=heading',
    ], 1);

    self::assertSame('pending', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testMultipleDistinctDiscountsCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => '50% Off Arcade Games and 20% Off Food & Non-Alcoholic Drinks',
      'value' => '20% Off',
      'schedule' => '',
      'source_url' => 'https://example.com/military',
      'score' => 5,
      'reason' => 'promotion=discount; value=20% Off; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'score 5 is below configured automatic-approval score 8',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testFormExtractionBleedCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'FULL NAME EMAIL CAU STUDENT ID CAMPUS CODE UNLOCK FREE ENTRY',
      'value' => 'FREE ENTRY',
      'schedule' => '',
      'source_url' => 'https://example.com/student-pass',
      'score' => 5,
      'reason' => 'promotion=free; value=FREE ENTRY; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
  }



  /**
   * @covers ::classify
   */
  public function testExpiredExplicitValidityCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'FREE admission for ages 12 and under (through June 2026)',
      'value' => 'FREE admission',
      'schedule' => '',
      'source_url' => 'https://example.com/free-admission',
      'score' => 6,
      'reason' => 'promotion=free; value=FREE admission; binding=article',
    ], 1);

    self::assertSame('rejected', $result['status']);
    self::assertSame('low', $result['confidence']);
    self::assertContains(
      'candidate is expired: explicit validity ended on 2026-06-30',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testSelectDaysWithoutConcreteDatesCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Free Admission for Veterans and Active Military on Select Days',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/select-days',
      'score' => 6,
      'reason' => 'promotion=free; value=Free Admission; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: offer applies only on select days, but no concrete validity dates were extracted',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testSelectDaysWithConcreteScheduleCanAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Free Admission for Veterans and Active Military on Select Days',
      'value' => 'Free Admission',
      'schedule' => 'September 12 and September 19, 2026',
      'source_url' => 'https://example.com/select-days',
      'score' => 6,
      'reason' => 'promotion=free; value=Free Admission; binding=article',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testMovingHolidayRangeWithoutYearCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'From Armed Forces Day through Labor Day, free admission',
      'value' => 'free admission',
      'schedule' => '',
      'source_url' => 'https://example.com/blue-star',
      'score' => 6,
      'reason' => 'promotion=free; value=free admission; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: seasonal holiday range is present without a concrete year or date',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testCurrentMonthPromotionCanAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Save $10 off membership all September-long!',
      'value' => '$10 off',
      'schedule' => '',
      'source_url' => 'https://example.com/membership',
      'score' => 5,
      'reason' => 'promotion=save; value=$10 off; binding=article',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testPastMonthOnlyPromotionCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Save $10 off membership all August-long!',
      'value' => '$10 off',
      'schedule' => '',
      'source_url' => 'https://example.com/membership',
      'score' => 5,
      'reason' => 'promotion=save; value=$10 off; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: month-specific promotion references August, but the current month is September',
      $result['reasons'],
    );
  }


  /**
   * @covers ::classify
   */
  public function testSeasonalValidityWithPossessiveConnectorCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'The College Football Hall of Fame Announces Two Free Admission Opportunities during its Summer of Celebrations',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/summer-celebrations',
      'score' => 6,
      'reason' => 'promotion=free; value=Free Admission; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: seasonal validity is present without concrete dates',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testSeasonWordInOfferNameDoesNotCreateValidityBlocker(): void {
    $result = $this->classifier->classify([
      'title' => 'Summer Reading Challenge reward, offering 50% off one book after completion',
      'value' => '50% off',
      'schedule' => '',
      'source_url' => 'https://example.com/reading-challenge',
      'score' => 5,
      'reason' => 'promotion=discount; value=50% off; binding=article',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }


  /**
   * @covers ::classify
   */
  public function testFreeAdmissionDayWithoutConcreteDateCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'UNIQLO Free Admission Day',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/free-day',
      'score' => 5,
      'reason' => 'promotion=free; value=Free Admission; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: offer refers to specific promotional day(s), but no concrete dates were extracted',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testFreeAdmissionEveryDayRemainsAutoApprovable(): void {
    $result = $this->classifier->classify([
      'title' => 'Free Admission Every Day',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/every-day',
      'score' => 6,
      'reason' => 'promotion=free; value=Free Admission; binding=article',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testPlayDatesWithoutConcreteDatesCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Play Dates / Free Admission for Families',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/play-dates',
      'score' => 6,
      'reason' => 'promotion=free; value=Free Admission; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: offer refers to specific promotional day(s), but no concrete dates were extracted',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testFreeDaysContainerCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Free Days and Other Free Admission Offers',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/free-days',
      'score' => 7,
      'reason' => 'promotion=free; value=Free Admission; binding=heading',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'schedule or validity context is missing',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testFreeAdmissionOpportunitiesContainerCannotUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Opportunities for Free Admission',
      'value' => 'Free Admission',
      'schedule' => '',
      'source_url' => 'https://example.com/opportunities',
      'score' => 7,
      'reason' => 'promotion=free; value=Free Admission; binding=heading',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'schedule or validity context is missing',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testStartingMonthDayWithoutYearCannotAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'FREE admission for ages 17 and under (starting July 1)',
      'value' => 'FREE admission',
      'schedule' => '',
      'source_url' => 'https://example.com/starting-date',
      'score' => 5,
      'reason' => 'promotion=free; value=FREE admission; binding=article',
    ], 1);

    self::assertSame('pending', $result['status']);
    self::assertContains(
      'validity review: offer has a starting month and day without a concrete year',
      $result['reasons'],
    );
  }


  /**
   * @covers ::classify
   */
  public function testSaveUpToPercentageCanUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Save up to 69% with Season Tickets',
      'value' => 'Save up to 69%',
      'schedule' => '',
      'source_url' => 'https://example.com/season-tickets',
      'score' => 5,
      'reason' => 'promotion=save; value=Save up to 69%; binding=heading',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testSavePercentageCanUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Groups of 15 or more save 15% on tickets.',
      'value' => 'save 15%',
      'schedule' => '',
      'source_url' => 'https://example.com/group-tickets',
      'score' => 5,
      'reason' => 'promotion=save; value=save 15%; binding=section',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testIdenticalComparisonPricesAreRejected(): void {
    $result = $this->classifier->classify([
      'title' => 'Starburst Acrylic',
      'value' => '$250.00 vs $250.00',
      'schedule' => '',
      'source_url' => 'https://example.com/product',
      'score' => 6,
      'reason' => 'promotion=sale; value=$250.00 vs $250.00; binding=heading',
    ], 1);

    self::assertSame('rejected', $result['status']);
    self::assertSame('low', $result['confidence']);
    self::assertContains(
      'candidate is not a deal: current and regular comparison prices are identical',
      $result['reasons'],
    );
  }

  /**
   * @covers ::classify
   */
  public function testUnequalComparisonPricesRemainPendingForReview(): void {
    $result = $this->classifier->classify([
      'title' => 'Meet You at Home',
      'value' => '$39 vs $28.99',
      'schedule' => '',
      'source_url' => 'https://example.com/product-sale',
      'score' => 6,
      'reason' => 'promotion=sale; value=$39 vs $28.99; binding=section',
    ], 1);

    self::assertSame('pending', $result['status']);
  }


  /**
   * @covers ::classify
   */
  public function testFixedPriceFamilyDealCanUseStrongEvidenceBypass(): void {
    $result = $this->classifier->classify([
      'title' => '$49.99 ULTIMATE FALL FAMILY DEAL',
      'value' => 'only $49.99',
      'schedule' => 'GET COUPON Expires 11/1/2026',
      'source_url' => 'https://example.com/family-deal',
      'score' => 7,
      'reason' => 'promotion=deal,coupon; value=only $49.99; binding=heading; schedule=Expires 11/1/2026',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testNamedMuseumsOnUsRecurringProgramCanAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Bank of America Museums on Us',
      'value' => 'free admission',
      'schedule' => 'Cardholders are eligible during the first full weekend of every month.',
      'source_url' => 'https://example.com/museums-on-us',
      'score' => 6,
      'reason' => 'promotion=free admission; value=free admission; binding=heading; schedule=first full weekend of every month',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testNamedFamilyDaysRecurringProgramCanAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Boston Family Days',
      'value' => 'free admission',
      'schedule' => 'On the first and second Sunday of each month, Boston school-aged children and their two guests receive free admission.',
      'source_url' => 'https://example.com/family-days',
      'score' => 6,
      'reason' => 'promotion=free admission; value=free admission; binding=heading; schedule=first and second Sunday of each month',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testNamedAccessProgramCanAutoApprove(): void {
    $result = $this->classifier->classify([
      'title' => 'Access for All at OMA',
      'value' => 'free admission',
      'schedule' => 'On the third Thursday of every month, enjoy extended hours from 10 am to 8 pm.',
      'source_url' => 'https://example.com/access-for-all',
      'score' => 6,
      'reason' => 'promotion=free admission; value=free admission; binding=li; schedule=third Thursday of every month',
    ], 1);

    self::assertSame('auto_approved', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testGenericAdmissionTitleDoesNotUseRecurringProgramBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Admission',
      'value' => 'Free admission',
      'schedule' => 'Admission is free to all visitors every day. Open Tuesday-Sunday 10am-5pm.',
      'source_url' => 'https://example.com/admission',
      'score' => 7,
      'reason' => 'promotion=free admission; value=Free admission; binding=section; schedule=every day',
    ], 1);

    self::assertSame('pending', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testTermsAndConditionsTitleDoesNotUseRecurringProgramBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'Terms and Conditions',
      'value' => '25% off',
      'schedule' => 'Weekday Party Discount Offer Monday through Friday.',
      'source_url' => 'https://example.com/terms',
      'score' => 7,
      'reason' => 'promotion=discount; value=25% off; binding=heading; schedule=Monday through Friday',
    ], 1);

    self::assertSame('pending', $result['status']);
  }

  /**
   * @covers ::classify
   */
  public function testLongEventContainerDoesNotUseRecurringProgramBypass(): void {
    $result = $this->classifier->classify([
      'title' => 'BECHTLER EVENTS JAZZ AT THE BECHTLER Experience the most modern of musical art forms in our iconic lobby. Held the first Friday of every month',
      'value' => 'free admission',
      'schedule' => 'Held the first Friday of every month.',
      'source_url' => 'https://example.com/events',
      'score' => 6,
      'reason' => 'promotion=free admission; value=free admission; binding=section; schedule=first Friday of every month',
    ], 1);

    self::assertSame('pending', $result['status']);
  }


}
