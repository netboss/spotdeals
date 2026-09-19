<?php

declare(strict_types=1);

namespace Drupal\spotdeals_social\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;

/**
 * Builds the activity timeline for users followed by a SpotDeals member.
 */
final class FollowingActivityBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds recent public activity from users followed by the given account.
   *
   * @return array<int, array<string, mixed>>
   *   Timeline entries ordered newest first.
   */
  public function build(UserInterface $account, int $limit = 12): array {
    if (!$this->database->schema()->tableExists('flagging')) {
      return [];
    }

    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [];
    }

    $followedUids = $this->followedUserIds($uid);
    if (!$followedUids) {
      return [];
    }

    $blockedUids = $this->blockedUserIds($uid);
    if ($blockedUids) {
      $followedUids = array_values(array_diff($followedUids, $blockedUids));
    }

    if (!$followedUids) {
      return [];
    }

    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($followedUids);
    $activity = [];

    $this->addDealVotes($followedUids, $users, $activity);
    $this->addVenueVotes($followedUids, $users, $activity);
    $this->addSavedItems($followedUids, $users, $activity);

    usort($activity, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    $activity = array_slice($activity, 0, max(1, $limit));

    foreach ($activity as &$item) {
      $item['date'] = $this->dateFormatter->formatTimeDiffSince(
        $item['timestamp'],
        ['granularity' => 1],
      );
    }
    unset($item);

    return $activity;
  }


  /**
   * Returns cache tags required by a user's following timeline.
   *
   * The timeline composition depends on the viewer's follow/block graph, while
   * its entries depend on activity performed by every currently followed user.
   *
   * @return string[]
   *   Cache tags for the timeline render.
   */
  public function getCacheTags(UserInterface $account): array {
    $uid = (int) $account->id();
    if ($uid <= 0 || !$this->database->schema()->tableExists('flagging')) {
      return [];
    }

    $tags = [
      \Drupal\spotdeals_social\Cache\SocialActivityCache::timelineTag($uid),
    ];

    $followedUids = $this->followedUserIds($uid);
    $blockedUids = $this->blockedUserIds($uid);
    if ($blockedUids) {
      $followedUids = array_values(array_diff($followedUids, $blockedUids));
    }

    foreach ($followedUids as $followedUid) {
      $tags[] = \Drupal\spotdeals_social\Cache\SocialActivityCache::activityTag($followedUid);
    }

    return array_values(array_unique($tags));
  }

  /**
   * Returns user IDs followed by the account.
   *
   * For a personal user flag, uid is the actor and entity_id is the followed
   * user account.
   *
   * @return int[]
   *   Followed user IDs.
   */
  private function followedUserIds(int $uid): array {
    $rows = $this->database->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('flag_id', 'follow_user')
      ->condition('uid', $uid)
      ->execute()
      ->fetchCol();

    return array_values(array_unique(array_filter(array_map('intval', $rows))));
  }

  /**
   * Returns users blocked in either direction relative to the account.
   *
   * @return int[]
   *   User IDs that must not contribute activity to this account's timeline.
   */
  private function blockedUserIds(int $uid): array {
    $query = $this->database->select('flagging', 'f');
    $query->fields('f', ['uid', 'entity_id']);
    $query->condition('flag_id', 'block_user');

    $or = $query->orConditionGroup()
      ->condition('uid', $uid)
      ->condition('entity_id', $uid);
    $query->condition($or);

    $blocked = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $actor = (int) $row->uid;
      $target = (int) $row->entity_id;
      $blocked[] = $actor === $uid ? $target : $actor;
    }

    return array_values(array_unique(array_filter($blocked)));
  }

  /**
   * Adds deal vote activity for followed users.
   */
  private function addDealVotes(array $uids, array $users, array &$activity): void {
    if (!$this->database->schema()->tableExists('spotdeals_vote')) {
      return;
    }

    $rows = $this->database->select('spotdeals_vote', 'v')
      ->fields('v', ['uid', 'deal_nid', 'changed'])
      ->condition('uid', $uids, 'IN')
      ->execute()
      ->fetchAll();

    $this->addVoteActivity($rows, 'deal_nid', $users, $activity);
  }

  /**
   * Adds venue vote activity for followed users.
   */
  private function addVenueVotes(array $uids, array $users, array &$activity): void {
    if (!$this->database->schema()->tableExists('spotdeals_vote_venue')) {
      return;
    }

    $rows = $this->database->select('spotdeals_vote_venue', 'v')
      ->fields('v', ['uid', 'venue_nid', 'changed'])
      ->condition('uid', $uids, 'IN')
      ->execute()
      ->fetchAll();

    $this->addVoteActivity($rows, 'venue_nid', $users, $activity);
  }

  /**
   * Converts vote rows into timeline entries.
   */
  private function addVoteActivity(array $rows, string $nodeIdField, array $users, array &$activity): void {
    $nodeIds = array_values(array_unique(array_map(
      static fn(object $row): int => (int) $row->{$nodeIdField},
      $rows,
    )));
    $nodes = $nodeIds
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds)
      : [];

    foreach ($rows as $row) {
      $actorUid = (int) $row->uid;
      $nid = (int) $row->{$nodeIdField};

      if (!isset($users[$actorUid], $nodes[$nid])) {
        continue;
      }

      $activity[] = $this->activityItem(
        $users[$actorUid],
        'vote',
        $this->t('voted on'),
        $nodes[$nid]->label(),
        $nodes[$nid]->toUrl()->toString(),
        (int) $row->changed,
      );
    }
  }

  /**
   * Adds saved deal and venue activity for followed users.
   */
  private function addSavedItems(array $uids, array $users, array &$activity): void {
    $rows = $this->database->select('flagging', 'f')
      ->fields('f', ['uid', 'flag_id', 'entity_id', 'created'])
      ->condition('uid', $uids, 'IN')
      ->condition('flag_id', ['deal', 'venue'], 'IN')
      ->execute()
      ->fetchAll();

    $nodeIds = array_values(array_unique(array_map(
      static fn(object $row): int => (int) $row->entity_id,
      $rows,
    )));
    $nodes = $nodeIds
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds)
      : [];

    foreach ($rows as $row) {
      $actorUid = (int) $row->uid;
      $nid = (int) $row->entity_id;

      if (!isset($users[$actorUid], $nodes[$nid])) {
        continue;
      }

      $activity[] = $this->activityItem(
        $users[$actorUid],
        'saved',
        $row->flag_id === 'deal' ? $this->t('saved a deal') : $this->t('saved a venue'),
        $nodes[$nid]->label(),
        $nodes[$nid]->toUrl()->toString(),
        (int) $row->created,
      );
    }
  }

  /**
   * Creates one normalized timeline entry.
   */
  private function activityItem(
    UserInterface $actor,
    string $type,
    mixed $label,
    string $title,
    string $url,
    int $timestamp,
  ): array {
    return [
      'type' => $type,
      'actor_name' => $actor->getDisplayName(),
      'actor_url' => $actor->toUrl()->toString(),
      'label' => $label,
      'title' => $title,
      'url' => $url,
      'timestamp' => $timestamp,
    ];
  }

}
