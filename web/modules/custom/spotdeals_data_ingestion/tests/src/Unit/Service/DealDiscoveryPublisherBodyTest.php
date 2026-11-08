<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that publication consumes only preview-approved body content.
 *
 * These tests do not save nodes or require a Drupal database.
 */
#[CoversClass(DealDiscoveryPublisher::class)]
#[Group('spotdeals_data_ingestion')]
final class DealDiscoveryPublisherBodyTest extends TestCase {

  public function testPreviewApprovedBodyIsAssignedWithBasicHtml(): void {
    $description = 'Free admission is available to qualifying visitors on selected dates. Bring proof of eligibility to the admissions desk before entering.';
    $this->assertBodyAssignment(
      ['value' => $description, 'format' => 'basic_html'],
      ['value' => $description, 'format' => 'basic_html'],
    );
  }

  public function testUnapprovedBodyFormatsAreNotAssigned(): void {
    $this->assertBodyAssignment(
      ['value' => '<script>alert(1)</script>', 'format' => 'full_html'],
      NULL,
    );
  }

  public function testEmptyBodyIsNotAssigned(): void {
    $this->assertBodyAssignment(
      ['value' => '', 'format' => 'basic_html'],
      NULL,
    );
  }

  public function testMissingBodyIsNotAssigned(): void {
    $this->assertBodyAssignment(NULL, NULL);
  }

  public function testBodyIsNotAssignedWhenFieldIsUnavailable(): void {
    $this->assertBodyAssignment(
      ['value' => 'A verified offer description.', 'format' => 'basic_html'],
      NULL,
      FALSE,
    );
  }

  /**
   * @param array<string, mixed>|null $proposedBody
   * @param array<string, mixed>|null $expectedBody
   */
  private function assertBodyAssignment(?array $proposedBody, ?array $expectedBody, bool $hasBodyField = TRUE): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturnCallback(
      static fn (string $fieldName): bool => $fieldName === 'body' && $hasBodyField,
    );
    if ($expectedBody !== NULL) {
      $node->expects(self::once())->method('set')->with('body', $expectedBody);
    }
    else {
      $node->expects(self::never())->method('set');
    }

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects(self::once())
      ->method('create')
      ->with(self::callback(static fn (array $values): bool =>
        ($values['type'] ?? NULL) === 'deal'
        && ($values['langcode'] ?? NULL) === 'en'
        && ($values['title'] ?? NULL) === 'Test offer',
      ))
      ->willReturn($node);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects(self::once())
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    // The private node factory is isolated from publication, locking, and DB
    // writes so the test verifies the exact preview-to-node field contract.
    $reflection = new \ReflectionClass(DealDiscoveryPublisher::class);
    $publisher = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('entityTypeManager')->setValue($publisher, $entityTypeManager);

    $proposed = ['title' => 'Test offer'];
    if ($proposedBody !== NULL) {
      $proposed['body'] = $proposedBody;
    }
    $method = $reflection->getMethod('createDealNode');
    self::assertSame($node, $method->invoke($publisher, [], $proposed, 123));
  }

}
