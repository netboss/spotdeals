<?php

declare(strict_types=1);

namespace Drupal\spotdeals_vote;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Coordinates vote validation, storage, and aggregation.
 */
final class VoteManager {

  /**
   * Allowed vote fields.
   *
   * @var array<int,string>
   */
  private const ALLOWED_FIELDS = [
    'worth_it',
    'would_go_again',
  ];

  /**
   * Constructs a vote manager.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VoteStorage $voteStorage,
    private readonly VoteAggregateStorage $aggregateStorage,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns current aggregate and optionally current user vote state.
   *
   * @return array<string,mixed>
   *   Vote state array.
   */
  public function getDealVoteState(int $dealNid, int $uid = 0): array {
    $aggregate = $this->aggregateStorage->loadDealAggregate($dealNid);
    $userVote = [
      'worth_it' => NULL,
      'would_go_again' => NULL,
    ];

    if ($uid > 0) {
      $row = $this->voteStorage->loadUserDealVote($uid, $dealNid);
      if (is_array($row)) {
        $userVote['worth_it'] = $row['worth_it'] !== NULL ? (int) $row['worth_it'] : NULL;
        $userVote['would_go_again'] = $row['would_go_again'] !== NULL ? (int) $row['would_go_again'] : NULL;
      }
    }

    return [
      'deal_nid' => $dealNid,
      'aggregate' => $aggregate,
      'user_vote' => $userVote,
    ];
  }

  /**
   * Validates and stores a vote update.
   *
   * @return array<string,mixed>
   *   Normalized response payload.
   */
  public function submitVote(int $uid, int $dealNid, int $venueNid, string $fieldName, int $value, ?string $source = NULL): array {
    if ($uid <= 0 || !$this->currentUser->isAuthenticated()) {
      throw new \InvalidArgumentException('Authentication is required.');
    }

    if (!in_array($fieldName, self::ALLOWED_FIELDS, TRUE)) {
      throw new \InvalidArgumentException('Invalid vote field.');
    }

    if (!in_array($value, [0, 1], TRUE)) {
      throw new \InvalidArgumentException('Invalid vote value.');
    }

    $deal = $this->loadDeal($dealNid);
    if (!$deal instanceof NodeInterface || $deal->bundle() !== 'deal' || !$deal->isPublished()) {
      throw new \InvalidArgumentException('Invalid deal.');
    }

    if (!$deal->access('view', $this->currentUser)) {
      throw new \InvalidArgumentException('Access denied for this deal.');
    }

    $dealVenue = $this->resolveVenueNid($deal);
    if ($dealVenue <= 0 || $dealVenue !== $venueNid) {
      throw new \InvalidArgumentException('Deal and venue do not match.');
    }

    $this->voteStorage->upsertVote($uid, $dealNid, $venueNid, [$fieldName => $value], $source);
    $this->aggregateStorage->rebuildDealAggregate($dealNid, $venueNid);
    $this->cacheTagsInvalidator->invalidateTags([
      'node:' . $dealNid,
      'spotdeals_vote:' . $dealNid,
    ]);

    $state = $this->getDealVoteState($dealNid, $uid);

    return [
      'ok' => TRUE,
      'deal_nid' => $dealNid,
      'venue_nid' => $venueNid,
      'user_vote' => $state['user_vote'],
      'aggregate' => $state['aggregate'],
    ];
  }

  /**
   * Loads a deal node.
   */
  private function loadDeal(int $dealNid): ?NodeInterface {
    $deal = $this->entityTypeManager->getStorage('node')->load($dealNid);
    return $deal instanceof NodeInterface ? $deal : NULL;
  }

  /**
   * Resolves venue node ID from a deal.
   */
  private function resolveVenueNid(NodeInterface $deal): int {
    if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
      return 0;
    }

    $venue = $deal->get('field_venue')->entity;
    return $venue instanceof NodeInterface ? (int) $venue->id() : 0;
  }
}
