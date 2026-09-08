<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityService;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityService
 * @group spotdeals_data_ingestion
 */
final class DealDiscoveryContentQualityServiceTest extends TestCase {

  private DealDiscoveryContentQualityService $service;

  protected function setUp(): void {
    parent::setUp();
    $this->service = new DealDiscoveryContentQualityService();
  }

  /**
   * @covers ::normalizeTitle
   */
  public function testTrailingHeadingDelimiterIsRemoved(): void {
    self::assertSame(
      'Access for All at OMA',
      $this->service->normalizeTitle('Access for All at OMA :'),
    );
    self::assertSame(
      'Access for All at OMA',
      $this->service->normalizeTitle('Access for All at OMA;'),
    );
  }

  /**
   * @covers ::normalizeTitle
   */
  public function testMeaningfulTitlePunctuationIsPreserved(): void {
    self::assertSame(
      'Who’s Ready for 50% Off?',
      $this->service->normalizeTitle('Who’s Ready for 50% Off?'),
    );
    self::assertSame(
      'EARLYBIRD - Save 15%',
      $this->service->normalizeTitle('EARLYBIRD - Save 15%'),
    );
    self::assertSame(
      'Group discount: take 20% off when you buy eight or more tickets to one ...',
      $this->service->normalizeTitle('Group discount: take 20% off when you buy eight or more tickets to one ...'),
    );
  }

  /**
   * @covers ::assessCandidate
   */
  public function testDeterministicCorrectionDoesNotBecomeBlocker(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Access for All at OMA :',
      'value' => 'free admission',
      'schedule' => 'Thursday',
    ]);

    self::assertSame('Access for All at OMA', $assessment['normalized']['title']);
    self::assertArrayHasKey('title', $assessment['corrections']);
    self::assertSame([], $assessment['blockers']);
  }

  /**
   * @covers ::assessCandidate
   */
  public function testSuspiciousUrlTitleBlocksAutomaticPublishing(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'https://example.com/deals',
      'value' => '20% off',
      'schedule' => 'Friday',
    ]);

    self::assertNotSame([], $assessment['blockers']);
  }

  /**
   * @covers ::assessCandidate
   */
  public function testGenericVenueHoursTitleBlocksAutomaticPublishing(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Museum & Shop Hours',
      'value' => 'free admission',
      'schedule' => 'Daily',
    ]);

    self::assertContains(
      'Offer title appears to describe general venue information rather than a promotion.',
      $assessment['blockers'],
    );
  }

  /**
   * @covers ::assessCandidate
   */
  public function testGenericInformationalTitlesBlockAutomaticPublishing(): void {
    foreach (['Description', 'Related products'] as $title) {
      $assessment = $this->service->assessCandidate([
        'title' => $title,
        'value' => 'free admission',
        'schedule' => 'Daily',
      ]);

      self::assertContains(
        'Offer title appears to describe general venue information rather than a promotion.',
        $assessment['blockers'],
      );
    }
  }

  /**
   * @covers ::assessCandidate
   */
  public function testPromotionalHoursTitleRemainsEligible(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Happy Hour Special',
      'value' => '50% off',
      'schedule' => 'Friday',
    ]);

    self::assertSame([], $assessment['blockers']);
  }

  /**
   * @covers ::assessCandidate
   */
  public function testTicketingTitleBlocksEvenWhenNearbyValueLooksPromotional(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Ticketing',
      'value' => 'free admission',
      'schedule' => 'Daily',
    ]);

    self::assertContains(
      'Offer title appears to describe general venue information rather than a promotion.',
      $assessment['blockers'],
    );
  }

  /**
   * @covers ::assessCandidate
   */
  public function testTicketingInformationTitleBlocksAutomaticPublishing(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Ticketing Information',
      'value' => 'free admission',
      'schedule' => 'Daily',
    ]);

    self::assertContains(
      'Offer title appears to describe general venue information rather than a promotion.',
      $assessment['blockers'],
    );
  }

  /**
   * @covers ::assessCandidate
   */
  public function testMuseumAdmissionWithConcreteFreeValueRemainsEligible(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Museum Admission',
      'value' => 'free admission',
      'schedule' => 'Daily',
    ]);

    self::assertSame([], $assessment['blockers']);
  }

  /**
   * @covers ::assessCandidate
   */
  public function testGenericAdmissionWithoutPromotionalValueBlocksAutomaticPublishing(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Admission',
      'value' => 'general admission',
      'schedule' => 'Daily',
    ]);

    self::assertContains(
      'Offer title appears to describe general venue information rather than a promotion.',
      $assessment['blockers'],
    );
  }

  /**
   * @covers ::assessCandidate
   */
  public function testSeasonTicketBenefitsWithFreeTicketsRemainsEligible(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'Season Ticket Holder Benefits',
      'value' => 'free tickets',
      'schedule' => 'Daily',
    ]);

    self::assertSame([], $assessment['blockers']);
  }

  /**
   * @covers ::assessCandidate
   */
  public function testFaqAndBoxOfficeExtractionBleedBlocksAutomaticPublishing(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'SEE OUR FAQs ABOUT HOW A SEASON SUBSCRIPTION WORKS . Contact our Box Office about promotional packages and group bookings',
      'value' => '$3 off',
      'schedule' => 'Thursday or Friday',
    ]);

    self::assertContains(
      'Offer title appears to contain navigation, call-to-action, or extraction-bleed text and requires manual review.',
      $assessment['blockers'],
    );
  }

  /**
   * @covers ::assessCandidate
   */
  public function testLegitimateLongPromotionalTitleRemainsEligible(): void {
    $assessment = $this->service->assessCandidate([
      'title' => 'For a limited time, we’re offering 20% OFF all entry passes purchased online using the code ‘20off’ at checkout!',
      'value' => '20% OFF',
      'schedule' => 'Daily',
    ]);

    self::assertSame([], $assessment['blockers']);
  }

  /**
   * @covers ::assessCandidate
   */
  public function testExcessivelyLongTitleBlocksAutomaticPublishing(): void {
    $assessment = $this->service->assessCandidate([
      'title' => str_repeat('This extracted website sentence keeps going with surrounding page copy. ', 4),
      'value' => '25% off',
      'schedule' => 'Daily',
    ]);

    self::assertContains(
      'Offer title is unusually long and requires manual review for possible extraction bleed.',
      $assessment['blockers'],
    );
  }


}
