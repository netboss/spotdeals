<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_search_smart_location\Unit;

use Drupal\spotdeals_search_smart_location\RecommendationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the recommendation candidate budget.
 *
 */
#[CoversClass(RecommendationService::class)]
#[Group('spotdeals_search_smart_location')]
final class RecommendationCandidateSelectionTest extends TestCase {

  /**
   * Calls the shared pure selection helper without bootstrapping Drupal.
   */
  private function select(array $ranked, array $excluded): array {
    $method = new \ReflectionMethod(RecommendationService::class, 'boundedUnexcludedDealNids');
    return $method->invoke(NULL, $ranked, $excluded);
  }

  /**
   * Previously shown deals cannot consume the 50-candidate budget.
   */
  public function testExclusionsBeforeLimit(): void {
    $this->assertSame(range(31, 80), $this->select(range(1, 100), range(1, 30)));
  }

  /**
   * Noncontiguous exclusions do not disturb near-me ranking order.
   */
  public function testRankingOrderPreserved(): void {
    $this->assertSame([91, 72, 63, 45], $this->select([91, 84, 72, 63, 56, 45], [84, 56]));
  }

  /**
   * The candidate budget remains bounded with no exclusions.
   */
  public function testCandidateLimit(): void {
    $this->assertSame(range(1, 50), $this->select(range(1, 100), []));
  }

  /**
   * An exhausted pool is handled without errors.
   */
  public function testAllExcluded(): void {
    $this->assertSame([], $this->select([7, 8, 9], [7, 8, 9]));
  }

}
