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

}
