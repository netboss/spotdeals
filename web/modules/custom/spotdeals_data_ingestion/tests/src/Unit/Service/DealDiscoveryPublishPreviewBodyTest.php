<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryFieldDeriver;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishPreviewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks the no-write preview's optional description contract.
 *
 * Taxonomy and node storage are mocked. No Drupal database or nodes are used.
 */
#[CoversClass(DealDiscoveryPublishPreviewService::class)]
#[Group('spotdeals_data_ingestion')]
final class DealDiscoveryPublishPreviewBodyTest extends TestCase {

  public function testSourceGroundedDescriptionAppearsInProposedFields(): void {
    $snippet = 'Qualifying visitors receive free admission during the promotional period. Present the required eligibility documentation at the entrance before entering.';
    $plan = $this->buildPlan($snippet, TRUE);
    self::assertSame(['value' => $snippet, 'format' => 'basic_html'], $plan['proposed_fields']['body']);
    self::assertArrayNotHasKey('body', $plan['blocking_fields']);
  }

  public function testUnusableExcerptIsNotProposed(): void {
    $plan = $this->buildPlan('Read more', TRUE);
    self::assertArrayNotHasKey('body', $plan['proposed_fields']);
    self::assertArrayNotHasKey('body', $plan['blocking_fields']);
  }

  public function testMissingBodyFieldDoesNotBlockPreview(): void {
    $snippet = 'Qualifying visitors receive free admission during the promotional period. Present the required eligibility documentation at the entrance before entering.';
    $plan = $this->buildPlan($snippet, FALSE);
    self::assertArrayNotHasKey('body', $plan['proposed_fields']);
    self::assertArrayNotHasKey('body', $plan['blocking_fields']);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildPlan(string $snippet, bool $hasBodyField): array {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnCallback(
      static fn (string $name): bool => $name === 'body' && $hasBodyField,
    );
    if ($hasBodyField && mb_strlen($snippet) >= 90) {
      $node->expects(self::once())->method('set')->with('body', self::callback(
        static fn (array $body): bool => ($body['format'] ?? NULL) === 'basic_html' && ($body['value'] ?? NULL) === $snippet,
      ));
    }
    else {
      $node->expects(self::never())->method('set');
    }

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('create')->willReturn($node);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('getQuery')->willReturn($query);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(
      static fn (string $type): EntityStorageInterface => $type === 'node' ? $nodeStorage : $termStorage,
    );
    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')->willReturn([]);

    // The method is exercised without constructing its external Geoapify and
    // database collaborators. The venue plan is explicitly a new venue, so
    // duplicate lookups are not required by the existing preview contract.
    $reflection = new \ReflectionClass(DealDiscoveryPublishPreviewService::class);
    $preview = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($preview, $manager);
    $reflection->getProperty('entityFieldManager')->setValue($preview, $fieldManager);
    $reflection->getProperty('fieldDeriver')->setValue($preview, new DealDiscoveryFieldDeriver());
    $reflection->getProperty('contentQuality')->setValue($preview, new DealDiscoveryContentQualityService());

    $method = $reflection->getMethod('buildDealPlan');
    return $method->invoke($preview, [
      'offer_title' => 'Free admission for eligible visitors',
      'offer_value' => 'Free admission',
      'schedule' => '',
      'snippet' => $snippet,
    ], ['action' => 'would_create']);
  }

}
