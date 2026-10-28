<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryService;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

$moduleRoot = dirname(__DIR__, 4);
require_once $moduleRoot . '/src/Service/DealDiscoveryService.php';

/**
 * @coversDefaultClass \Drupal\spotdeals_data_ingestion\Service\DealDiscoveryService
 * @group spotdeals_data_ingestion
 */
final class DealDiscoveryServiceTest extends TestCase {

  private DealDiscoveryService $service;

  protected function setUp(): void {
    parent::setUp();

    $this->service = new DealDiscoveryService(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * @covers ::discover
   */
  public function testHeadingValueDoesNotBindDifferentSiblingDiscounts(): void {
    $html = <<<'HTML'
      <h2>Stay on routine! Get 40% OFF your 1st Autoship order</h2>
      <p>Save 10% on recurring orders and save 20% on select items.</p>
      HTML;

    $candidates = $this->extractCandidates($html);

    self::assertCount(1, $candidates);
    self::assertSame('40% OFF', $candidates[0]['value']);
    self::assertStringContainsString('40% OFF', $candidates[0]['title']);
  }

  /**
   * @covers ::discover
   */
  public function testHeadingWithMultipleExplicitValuesKeepsItsOwnValues(): void {
    $html = <<<'HTML'
      <h2>50% Off Arcade Games and 20% Off Food</h2>
      <p>Limited offer for members.</p>
      HTML;

    $candidates = $this->extractCandidates($html);
    $values = array_column($candidates, 'value');

    self::assertContains('50% Off', $values);
    self::assertContains('20% Off', $values);
  }

  /**
   * @covers ::discover
   */
  public function testConditionalBowlingDiscountIsNotFlattened(): void {
    $html = <<<'HTML'
      <section>
        <h3>The Special</h3>
        <p>Daily | Open – Close</p>
        <p>Buy two games, get the third 50% off! The Special makes every game more fun.</p>
      </section>
      HTML;

    $candidates = $this->extractCandidates($html);

    self::assertCount(1, $candidates);
    self::assertSame('Buy two games, get the third 50% off', $candidates[0]['value']);
    self::assertSame('The Special', $candidates[0]['title']);
    self::assertStringContainsString('Daily', $candidates[0]['schedule']);
  }

  /**
   * @covers ::discover
   */
  public function testSeparateBowlingOffersKeepTheirOwnValues(): void {
    $html = <<<'HTML'
      <section>
        <h3>The Special</h3>
        <p>Daily | Open – Close</p>
        <p>Buy two games, get the third 50% off!</p>
      </section>
      <section>
        <h3>After Party</h3>
        <p>Our special gives you unlimited bowling for only $25.99.</p>
        <p>Friday starting at 9PM. Saturday starting at 9PM.</p>
      </section>
      HTML;

    $candidates = $this->extractCandidates($html);
    $byTitle = [];
    foreach ($candidates as $candidate) {
      $byTitle[$candidate['title']][] = $candidate['value'];
    }

    self::assertSame(['Buy two games, get the third 50% off'], $byTitle['The Special'] ?? []);
    self::assertSame(['only $25.99'], $byTitle['After Party'] ?? []);
  }

  /**
   * @covers ::discover
   */
  public function testBowlingScheduleIsNotContaminatedByProse(): void {
    $html = <<<'HTML'
      <section>
        <h3>Family Unlimited</h3>
        <h4>Every Saturday & Sunday | 11AM – 3PM</h4>
        <p>Enjoy unlimited bowling, shoes included, for only $15.99.</p>
      </section>
      <section>
        <h3>After Party</h3>
        <p>Our special gives you unlimited bowling for only $25.99.</p>
        <p>Friday starting at 9PM Saturday starting at 9PM Walk-in only.</p>
      </section>
      HTML;

    $byTitle = [];
    foreach ($this->extractCandidates($html) as $candidate) {
      $byTitle[$candidate['title']] = $candidate;
    }
    self::assertSame('Every Saturday & Sunday | 11AM – 3PM', $byTitle['Family Unlimited']['schedule']);
    self::assertSame('Friday starting at 9PM Saturday starting at 9PM', $byTitle['After Party']['schedule']);
  }

  /**
   * @covers ::discover
   */
  public function testScheduleHeadingKeepsPrecedingOfferName(): void {
    $html = <<<'HTML'
      <section>
        <h3>The Special</h3>
        <br>
        <h4>Daily | Open – Close</h4>
        <br>
        <p><b>Buy two games, get the third 50% off!</b></p>
      </section>
      HTML;
    $candidates = $this->extractCandidates($html);
    self::assertCount(1, $candidates);
    self::assertSame('The Special', $candidates[0]['title']);
    self::assertSame('Daily | Open – Close', $candidates[0]['schedule']);
  }

  /**
   * @covers ::discover
   */
  public function testGenericHoursHeadingDoesNotBindMultipleUnrelatedDiscounts(): void {
    $html = <<<'HTML'
      <section>
        <h2>Hours</h2>
        <p>Monday to Friday 11am to 9pm.</p>
        <p>Happy Hour: 50% off draft beer.</p>
        <p>Taco Tuesday: $3 off select tacos.</p>
      </section>
      HTML;

    $candidates = $this->extractCandidates($html);
    self::assertNotContains('Hours', array_column($candidates, 'title'));
  }

  /**
   * @covers ::discover
   */
  public function testGenericHoursHeadingDoesNotBindSingleDiscount(): void {
    $html = <<<'HTML'
      <section>
        <h2>Hours</h2>
        <p>Happy Hour: 50% off draft beer every Friday.</p>
      </section>
      HTML;

    $candidates = $this->extractCandidates($html);
    self::assertNotContains('Hours', array_column($candidates, 'title'));
  }

  /**
   * @covers ::discover
   */
  public function testSpecificOfferTitleIsRetainedBesideGenericHeading(): void {
    $html = <<<'HTML'
      <section>
        <h2>Hours</h2>
        <p>Happy Hour: 50% off draft beer every Friday.</p>
      </section>
      <section>
        <h3>Friday Draft Beer Special</h3>
        <p>Every Friday get 50% off draft beer.</p>
      </section>
      HTML;

    $candidates = $this->extractCandidates($html);
    self::assertNotContains('Hours', array_column($candidates, 'title'));
    self::assertContains('Friday Draft Beer Special', array_column($candidates, 'title'));
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function extractCandidates(string $html): array {
    $method = new \ReflectionMethod(DealDiscoveryService::class, 'extractDealCandidates');

    /** @var array<int, array<string, mixed>> $result */
    $result = $method->invoke(
      $this->service,
      $html,
      trim(strip_tags($html)),
      'https://example.com/offers',
    );

    return $result;
  }

}
