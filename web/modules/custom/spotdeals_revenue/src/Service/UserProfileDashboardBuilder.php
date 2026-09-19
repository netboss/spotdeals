<?php

declare(strict_types=1);

namespace Drupal\spotdeals_revenue\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;

/**
 * Builds the consumer-facing user profile dashboard data.
 */
final class UserProfileDashboardBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Builds profile dashboard data for one user account.
   *
   * @return array<string, mixed>
   *   Prepared profile dashboard data for the theme.
   */
  public function build(UserInterface $account): array {
    $uid = (int) $account->id();
    $canManage = $this->currentUser->hasPermission('administer users');
    $isOwner = $this->currentUser->isAuthenticated() && (int) $this->currentUser->id() === $uid;
    $showPrivate = $isOwner || $canManage;

    $profile = [
      'is_owner' => $isOwner,
      'show_private' => $showPrivate,
      'display_name' => $account->getDisplayName(),
      'member_since' => $this->dateFormatter->format((int) $account->getCreatedTime(), 'custom', 'M j, Y'),
      'plan_tier' => $this->userFieldLabel($account, 'field_plan_tier', 'Free'),
      'plan_status' => $this->userFieldLabel($account, 'field_plan_status'),
      'email' => $showPrivate ? $account->getEmail() : '',
      'stats' => [
        'positive' => 0,
        'negative' => 0,
        'saved' => 0,
        'suggestions' => 0,
      ],
      'saved_deals' => [],
      'saved_venues' => [],
      'recent_activity' => [],
    ];

    if (!$showPrivate) {
      return $profile;
    }

    $activity = [];
    $this->addDealVotes($uid, $profile, $activity);
    $this->addVenueVotes($uid, $profile, $activity);
    $this->addSavedItems($uid, $profile, $activity);
    $this->addSuggestions($uid, $profile, $activity);

    usort($activity, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    $activity = array_slice($activity, 0, 8);

    foreach ($activity as &$item) {
      $item['date'] = $this->dateFormatter->formatTimeDiffSince($item['timestamp'], ['granularity' => 1]);
    }
    unset($item);

    $profile['recent_activity'] = $activity;
    return $profile;
  }

  /**
   * Adds deal vote statistics and activity.
   */
  private function addDealVotes(int $uid, array &$profile, array &$activity): void {
    if (!$this->database->schema()->tableExists('spotdeals_vote')) {
      return;
    }

    $rows = $this->database->select('spotdeals_vote', 'v')
      ->fields('v', ['deal_nid', 'worth_it', 'would_go_again', 'changed'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();

    $this->addVoteStats($rows, $profile);
    $this->addVoteActivity($rows, 'deal_nid', $activity);
  }

  /**
   * Adds venue vote statistics and activity.
   */
  private function addVenueVotes(int $uid, array &$profile, array &$activity): void {
    if (!$this->database->schema()->tableExists('spotdeals_vote_venue')) {
      return;
    }

    $rows = $this->database->select('spotdeals_vote_venue', 'v')
      ->fields('v', ['venue_nid', 'worth_it', 'would_go_again', 'changed'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();

    $this->addVoteStats($rows, $profile);
    $this->addVoteActivity($rows, 'venue_nid', $activity);
  }

  /**
   * Adds positive and negative counts from vote rows.
   */
  private function addVoteStats(array $rows, array &$profile): void {
    foreach ($rows as $row) {
      foreach (['worth_it', 'would_go_again'] as $field) {
        if ($row->{$field} === NULL) {
          continue;
        }

        if ((int) $row->{$field} === 1) {
          $profile['stats']['positive']++;
        }
        else {
          $profile['stats']['negative']++;
        }
      }
    }
  }

  /**
   * Adds vote rows to recent activity candidates.
   */
  private function addVoteActivity(array $rows, string $nodeIdField, array &$activity): void {
    $nodeIds = array_values(array_unique(array_map(
      static fn(object $row): int => (int) $row->{$nodeIdField},
      $rows,
    )));
    $nodes = $nodeIds
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds)
      : [];

    foreach ($rows as $row) {
      $nid = (int) $row->{$nodeIdField};
      if (!isset($nodes[$nid])) {
        continue;
      }

      $activity[] = [
        'type' => 'vote',
        'label' => $this->t('Voted on'),
        'title' => $nodes[$nid]->label(),
        'url' => $nodes[$nid]->toUrl()->toString(),
        'timestamp' => (int) $row->changed,
      ];
    }
  }

  /**
   * Adds saved deals and venues plus their activity entries.
   */
  private function addSavedItems(int $uid, array &$profile, array &$activity): void {
    if (!$this->database->schema()->tableExists('flagging')) {
      return;
    }

    $flaggings = $this->database->select('flagging', 'f')
      ->fields('f', ['flag_id', 'entity_id', 'created'])
      ->condition('uid', $uid)
      ->condition('flag_id', ['deal', 'venue'], 'IN')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    $nodeIds = array_values(array_unique(array_map(
      static fn(object $row): int => (int) $row->entity_id,
      $flaggings,
    )));
    $nodes = $nodeIds
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds)
      : [];

    foreach ($flaggings as $flagging) {
      $nid = (int) $flagging->entity_id;
      if (!isset($nodes[$nid])) {
        continue;
      }

      $item = [
        'title' => $nodes[$nid]->label(),
        'url' => $nodes[$nid]->toUrl()->toString(),
        'timestamp' => (int) $flagging->created,
      ];

      if ($flagging->flag_id === 'deal') {
        $profile['saved_deals'][] = $item;
      }
      else {
        $profile['saved_venues'][] = $item;
      }

      $activity[] = $item + [
        'type' => 'saved',
        'label' => $flagging->flag_id === 'deal' ? $this->t('Saved deal') : $this->t('Saved venue'),
      ];
    }

    $profile['stats']['saved'] = count($profile['saved_deals']) + count($profile['saved_venues']);
  }

  /**
   * Adds suggestion statistics and activity.
   */
  private function addSuggestions(int $uid, array &$profile, array &$activity): void {
    if (!$this->database->schema()->tableExists('spotdeals_suggestion')) {
      return;
    }

    $suggestions = $this->database->select('spotdeals_suggestion', 's')
      ->fields('s', ['id', 'type', 'venue_name', 'deal_name', 'status', 'created'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    $profile['stats']['suggestions'] = count($suggestions);

    foreach ($suggestions as $suggestion) {
      $title = trim((string) ($suggestion->deal_name ?: $suggestion->venue_name));
      $activity[] = [
        'type' => 'suggestion',
        'label' => $this->t('Suggested'),
        'title' => $title !== '' ? $title : $this->t('Venue or deal'),
        'url' => '',
        'timestamp' => (int) $suggestion->created,
        'status' => (string) $suggestion->status,
      ];
    }
  }

  /**
   * Returns a human-readable value for a user list/string field.
   */
  private function userFieldLabel(UserInterface $account, string $fieldName, string $fallback = ''): string {
    if (!$account->hasField($fieldName) || $account->get($fieldName)->isEmpty()) {
      return $fallback;
    }

    $item = $account->get($fieldName)->first();
    $value = (string) ($item->value ?? '');
    $allowed = $account->get($fieldName)->getFieldDefinition()->getSetting('allowed_values') ?: [];

    return isset($allowed[$value])
      ? (string) $allowed[$value]
      : ($value !== '' ? ucfirst($value) : $fallback);
  }

}
