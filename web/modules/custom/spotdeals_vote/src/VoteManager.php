<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Psr\Container\ContainerInterface;
use Drupal\spotdeals_vote_deal\DealVoteManager;

/**
 * Legacy compatibility wrapper around the deal vote manager.
 */
final class VoteManager {

  /**
   * Constructs the legacy vote manager.
   */
  public function __construct(
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Returns current aggregate and optionally current user vote state.
   *
   * @return array<string,mixed>
   *   Vote state array.
   */
  public function getDealVoteState(int $dealNid, int $uid = 0): array {
    return $this->getDealVoteManager()->getDealVoteState($dealNid, $uid);
  }

  /**
   * Validates and stores a vote update.
   *
   * @return array<string,mixed>
   *   Normalized response payload.
   */
  public function submitVote(int $uid, int $dealNid, int $venueNid, string $fieldName, int $value, ?string $source = NULL): array {
    return $this->getDealVoteManager()->submitVote($uid, $dealNid, $venueNid, $fieldName, $value, $source);
  }

  /**
   * Returns the deal vote manager service.
   */
  private function getDealVoteManager(): DealVoteManager {
    if (!$this->container->has('spotdeals_vote_deal.manager')) {
      throw new \RuntimeException('The spotdeals_vote_deal module must be enabled.');
    }

    $service = $this->container->get('spotdeals_vote_deal.manager');
    if (!$service instanceof DealVoteManager) {
      throw new \RuntimeException('The spotdeals_vote_deal.manager service is invalid.');
    }

    return $service;
  }

}
