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
    private readonly SocialPrivacyManager $privacyManager,
  ) {}

  /**
   * Builds recent public activity from users followed by the given account.
   *
   * @return array<int, array<string, mixed>>
   *   Timeline entries ordered newest first.
   */
  public function build(UserInterface $account, int $limit = 12): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [];
    }

    $followedUids = $this->followedUserIds($uid);
    $blockedUids = $this->blockedUserIds($uid);
    if ($blockedUids) {
      $followedUids = array_values(array_diff($followedUids, $blockedUids));
    }

    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($followedUids);
    foreach ($users as $followed_uid => $followed_user) {
      if (!$followed_user instanceof UserInterface || !$this->privacyManager->canViewProfile($followed_user, $account)) {
        unset($users[$followed_uid]);
      }
    }
    $followedUids = array_values(array_map('intval', array_keys($users)));
    $activity = [];

    if ($followedUids) {
      $this->addDealVotes($followedUids, $users, $activity);
    $this->addVenueVotes($followedUids, $users, $activity);
    $this->addSavedItems($followedUids, $users, $activity);
    $this->addConversationActivity($followedUids, $users, $activity);
    }
    // Conversations are community content: show published, viewable posts
    // independently of the profile owner's follow relationships.
    $this->addCommunityConversations($activity);

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
   * Add community conversations to every accessible member profile.
   * Existing followed-user activity remains unchanged.
   */
  private function addCommunityConversations(array &$activity): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'conversation')
      ->condition('status', 1)
      ->sort('created', 'DESC')->range(0, 30)->execute();

    $existing = array_fill_keys(array_filter(array_column($activity, 'conversation_id')), TRUE);
    $ownerIds = [];
    $nodes = $storage->loadMultiple($ids);
    foreach ($nodes as $node) {
      $ownerIds[] = (int) $node->getOwnerId();
    }
    $owners = $this->entityTypeManager->getStorage('user')->loadMultiple(array_unique($ownerIds));
    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $ownerId = (int) $node->getOwnerId();
      if (isset($existing[$nid]) || !isset($owners[$ownerId]) || !$node->access('view')) {
        continue;
      }
      $owner = $owners[$ownerId];
      if (!$owner instanceof UserInterface || !$owner->isActive()) {
        continue;
      }
      $activity[] = $this->activityItem($owner, 'conversation',
        $this->t('started a conversation'), $node->label(),
        $node->toUrl()->toString(), (int) $node->getCreatedTime(), $nid);
    }
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
    if ($uid <= 0) {
      return [];
    }
    if (!$this->database->schema()->tableExists('flagging')) {
      return ['node_list'];
    }

    $tags = [
      \Drupal\spotdeals_social\Cache\SocialActivityCache::timelineTag($uid),
      'node_list',
      'comment_list',
    ];

    $followedUids = $this->followedUserIds($uid);
    $blockedUids = $this->blockedUserIds($uid);
    if ($blockedUids) {
      $followedUids = array_values(array_diff($followedUids, $blockedUids));
    }

    foreach ($followedUids as $followedUid) {
      $tags[] = \Drupal\spotdeals_social\Cache\SocialActivityCache::activityTag($followedUid);
      $tags[] = 'user:' . $followedUid;
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
   * Adds published conversation posts and published replies by followed users.
   *
   * Keeps the existing followed-user privacy and block filtering intact.
   */
  private function addConversationActivity(array $uids, array $users, array &$activity): void {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'conversation')
      ->condition('status', 1)
      ->condition('uid', $uids, 'IN')
      ->sort('created', 'DESC')
      ->range(0, 100)
      ->execute();

    // Collect one activity entry per conversation, rather than one per reply.
    // A reply to someone else's conversation is still eligible for the feed.
    $conversationItems = [];
    foreach ($nodeStorage->loadMultiple($ids) as $node) {
      $actorUid = (int) $node->getOwnerId();
      if (!isset($users[$actorUid]) || !$node->access('view')) {
        continue;
      }
      $conversationItems[(int) $node->id()] = $this->activityItem(
        $users[$actorUid],
        'conversation',
        $this->t('started a conversation'),
        $node->label(),
        $node->toUrl()->toString(),
        (int) $node->getCreatedTime(),
        (int) $node->id(),
      );
    }

    if ($this->entityTypeManager->hasDefinition('comment')) {
      $commentStorage = $this->entityTypeManager->getStorage('comment');
      $commentIds = $commentStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('comment_type', 'conversation_replies')
        ->condition('status', 1)
        ->condition('uid', $uids, 'IN')
        ->sort('created', 'DESC')
        ->range(0, 100)
        ->execute();
      $comments = $commentStorage->loadMultiple($commentIds);
      $parentIds = [];
      foreach ($comments as $comment) {
        $parentIds[] = (int) $comment->getCommentedEntityId();
      }
      $parents = $nodeStorage->loadMultiple(array_unique(array_filter($parentIds)));

      foreach ($comments as $comment) {
        $actorUid = (int) $comment->getOwnerId();
        $parentId = (int) $comment->getCommentedEntityId();
        if (!isset($users[$actorUid], $parents[$parentId])) {
          continue;
        }
        $parent = $parents[$parentId];
        if ($parent->bundle() !== 'conversation' || !$parent->isPublished()
          || !$parent->access('view') || !$comment->access('view')) {
          continue;
        }
        $timestamp = (int) $comment->getCreatedTime();
        if (!isset($conversationItems[$parentId]) || $timestamp > $conversationItems[$parentId]['timestamp']) {
          $conversationItems[$parentId] = $this->activityItem(
            $users[$actorUid],
            'conversation_reply',
            $this->t('replied to'),
            $parent->label(),
            $parent->toUrl()->toString(),
            $timestamp,
            $parentId,
          );
        }
      }
    }

    // Count all published replies, not just replies from followed accounts.
    // Preserve the existing timeline item's structure for theme compatibility.
    foreach ($conversationItems as $nid => $item) {
      if ($this->entityTypeManager->hasDefinition('comment')) {
        $count = (int) $this->entityTypeManager->getStorage('comment')->getQuery()
          ->accessCheck(TRUE)
          ->condition('entity_type', 'node')
          ->condition('entity_id', $nid)
          ->condition('comment_type', 'conversation_replies')
          ->condition('status', 1)
          ->count()
          ->execute();
        $item['reply_count'] = $count;
        $item['label'] = $this->t('@action · @replies', [
          '@action' => (string) $item['label'],
          '@replies' => (string) $this->formatPlural($count, '1 reply', '@count replies'),
        ]);
      }
      $activity[] = $item;
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
    int $conversationId = 0,
  ): array {
    return [
      'type' => $type,
      'actor_name' => $actor->getDisplayName(),
      'actor_url' => $actor->toUrl()->toString(),
      'label' => $label,
      'title' => $title,
      'url' => $url,
      'timestamp' => $timestamp,
      'conversation_id' => $conversationId,
    ];
  }

}
