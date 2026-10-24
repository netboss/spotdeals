<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_home_bubbles\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\spotdeals_home_bubbles\Service\ConversationRanking;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks the service is available in a Drupal kernel.
 *
 * @group spotdeals_home_bubbles
 */
#[RunTestsInSeparateProcesses]
final class ConversationRankingServiceTest extends KernelTestBase {

  protected static $modules = ['system', 'spotdeals_home_bubbles'];

  public function testServiceRegistrationAndRanking(): void {
    $ranking = $this->container->get('spotdeals_home_bubbles.ranking');
    $this->assertInstanceOf(ConversationRanking::class, $ranking);
    $this->assertSame([2, 1], $ranking->rank([1, 2], [1 => 0, 2 => 1], [1 => 1]));
  }

}
