<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote_venue;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\spotdeals_vote\VoteFields;

/**
 * Coordinates venue vote validation, storage, and aggregation.
 */
final class VenueVoteManager {

  /**
   * Constructs a venue vote manager.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VenueVoteStorage $voteStorage,
    private readonly VenueVoteAggregateStorage $aggregateStorage,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly VoteFields $voteFields,
  ) {}

  /**
   * Returns current aggregate and optionally current user vote state.
   *
   * @return array<string,mixed>
   *   Vote state array.
   */
  public function getVenueVoteState(int $venueNid, int $uid = 0): array {
    $aggregate = $this->aggregateStorage->loadVenueAggregate($venueNid);
    $userVote = [
      'worth_it' => NULL,
      'would_go_again' => NULL,
    ];

    if ($uid > 0) {
      $row = $this->voteStorage->loadUserVenueVote($uid, $venueNid);
      if (is_array($row)) {
        $userVote['worth_it'] = $row['worth_it'] !== NULL ? (int) $row['worth_it'] : NULL;
        $userVote['would_go_again'] = $row['would_go_again'] !== NULL ? (int) $row['would_go_again'] : NULL;
      }
    }

    return [
      'venue_nid' => $venueNid,
      'aggregate' => $aggregate,
      'user_vote' => $userVote,
    ];
  }

  /**
   * Validates and stores a venue vote update.
   *
   * @return array<string,mixed>
   *   Normalized response payload.
   */
  public function submitVote(int $uid, int $venueNid, string $fieldName, int $value, ?string $source = NULL): array {
    if ($uid <= 0 || !$this->currentUser->isAuthenticated()) {
      throw new \InvalidArgumentException('Authentication is required.');
    }

    if (!$this->voteFields->isAllowed($fieldName)) {
      throw new \InvalidArgumentException('Invalid vote field.');
    }

    if (!in_array($value, [0, 1], TRUE)) {
      throw new \InvalidArgumentException('Invalid vote value.');
    }

    $venue = $this->loadVenue($venueNid);
    if (!$venue instanceof NodeInterface || $venue->bundle() !== 'venue' || !$venue->isPublished()) {
      throw new \InvalidArgumentException('Invalid venue.');
    }

    if (!$venue->access('view', $this->currentUser)) {
      throw new \InvalidArgumentException('Access denied for this venue.');
    }

    $this->voteStorage->upsertVote($uid, $venueNid, [$fieldName => $value], $source);
    $this->aggregateStorage->rebuildVenueAggregate($venueNid);
    $this->cacheTagsInvalidator->invalidateTags([
      'node:' . $venueNid,
      'spotdeals_vote_venue:' . $venueNid,
    ]);

    $state = $this->getVenueVoteState($venueNid, $uid);

    return [
      'ok' => TRUE,
      'venue_nid' => $venueNid,
      'vote_scope' => 'venue:' . $venueNid,
      'user_vote' => $state['user_vote'],
      'aggregate' => $state['aggregate'],
    ];
  }

  /**
   * Loads a venue node.
   */
  private function loadVenue(int $venueNid): ?NodeInterface {
    $venue = $this->entityTypeManager->getStorage('node')->load($venueNid);
    return $venue instanceof NodeInterface ? $venue : NULL;
  }

}
