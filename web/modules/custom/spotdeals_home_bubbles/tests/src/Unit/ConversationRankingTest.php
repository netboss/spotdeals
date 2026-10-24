<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_home_bubbles\Unit;

use Drupal\spotdeals_home_bubbles\Service\ConversationRanking;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\spotdeals_home_bubbles\Service\ConversationRanking
 * @group spotdeals_home_bubbles
 */
final class ConversationRankingTest extends UnitTestCase {

  public function testScoreClampsNegativeValues(): void {
    $ranking = new ConversationRanking();
    $this->assertSame(8, $ranking->score(3, 2));
    $this->assertSame(0, $ranking->score(-3, -2));
  }

  public function testPopularityOrderingAndStableTies(): void {
    $ranking = new ConversationRanking();
    $this->assertSame([12, 10, 11], $ranking->rank(
      [11, 10, 12, 12],
      [10 => 2, 11 => 0, 12 => 1],
      [10 => 0, 11 => 1, 12 => 2],
    ));
  }

  public function testEmptyCandidates(): void {
    $this->assertSame([], (new ConversationRanking())->rank([], [], []));
  }

}
