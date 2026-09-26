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
