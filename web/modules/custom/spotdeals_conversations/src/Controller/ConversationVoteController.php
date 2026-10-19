<?php

declare(strict_types=1);

namespace Drupal\spotdeals_conversations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Handles authenticated conversation voting without affecting venue/deal votes. */
final class ConversationVoteController extends ControllerBase {

  /** Reads precomputed totals plus the current user's indexed vote. */
  public static function voteState(int $nid, int $uid): array {
    $db = \Drupal::database();
    $totals = $db->select('spotdeals_conversation_vote_totals', 't')
      ->fields('t', ['upvotes', 'downvotes'])
      ->condition('nid', $nid)
      ->execute()->fetchAssoc() ?: [];
    $mine = 0;
    if ($uid > 0) {
      $mine = (int) ($db->select('spotdeals_conversation_vote', 'v')
        ->fields('v', ['vote'])
        ->condition('nid', $nid)
        ->condition('uid', $uid)
        ->execute()->fetchField() ?: 0);
    }
    return [
      'up' => (int) ($totals['upvotes'] ?? 0),
      'down' => (int) ($totals['downvotes'] ?? 0),
      'mine' => $mine,
    ];
  }

  public function submit(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'conversation' || !$node->isPublished() || !$node->access('view')) {
      throw new NotFoundHttpException();
    }
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException();
    }
    $payload = json_decode($request->getContent(), TRUE);
    $vote = $payload['vote'] ?? NULL;
    if (!is_int($vote) || !in_array($vote, [-1, 1], TRUE)) {
      return new JsonResponse(['error' => 'Invalid vote.'], 400);
    }
    // A per-user limit also reduces automated abuse without punishing other users.
    $flood = \Drupal::service('flood');
    $flood_id = $uid . ':' . $node->id();
    if (!$flood->isAllowed('spotdeals_conversations.vote', 30, 60, $flood_id)) {
      return new JsonResponse(['error' => 'Too many voting requests. Try again shortly.'], 429);
    }
    $flood->register('spotdeals_conversations.vote', 60, $flood_id);

    // Serialize changes for the same conversation so totals cannot drift.
    $lock = \Drupal::lock();
    $lock_name = 'spotdeals_conversation_vote:' . $node->id();
    if (!$lock->acquire($lock_name, 10.0)) {
      return new JsonResponse(['error' => 'Voting is busy. Please retry.'], 409);
    }
    try {
      $db = \Drupal::database();
      $transaction = $db->startTransaction();
      $current = (int) ($db->select('spotdeals_conversation_vote', 'v')
        ->fields('v', ['vote'])
        ->condition('nid', $node->id())
        ->condition('uid', $uid)
        ->execute()->fetchField() ?: 0);
      $next = $current === $vote ? 0 : $vote;
      if ($next === 0) {
        $db->delete('spotdeals_conversation_vote')
          ->condition('nid', $node->id())->condition('uid', $uid)->execute();
      }
      else {
        $db->merge('spotdeals_conversation_vote')
          ->keys(['nid' => $node->id(), 'uid' => $uid])
          ->fields(['vote' => $next, 'changed' => \Drupal::time()->getRequestTime()])
          ->execute();
      }
      // Ensure a totals row exists, then use atomic SQL increments.
      $exists = $db->select('spotdeals_conversation_vote_totals', 't')
        ->fields('t', ['nid'])->condition('nid', $node->id())
        ->execute()->fetchField();
      if (!$exists) {
        $db->insert('spotdeals_conversation_vote_totals')
          ->fields(['nid' => $node->id(), 'upvotes' => 0, 'downvotes' => 0])
          ->execute();
      }
      $up_delta = (int) ($next === 1) - (int) ($current === 1);
      $down_delta = (int) ($next === -1) - (int) ($current === -1);
      if ($up_delta || $down_delta) {
        $db->update('spotdeals_conversation_vote_totals')
          ->expression('upvotes', 'upvotes + :up_delta', [':up_delta' => $up_delta])
          ->expression('downvotes', 'downvotes + :down_delta', [':down_delta' => $down_delta])
          ->condition('nid', $node->id())
          ->execute();
      }
      unset($transaction);
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['node:' . $node->id()]);
      return new JsonResponse(['ok' => TRUE] + self::voteState((int) $node->id(), $uid));
    }
    finally {
      $lock->release($lock_name);
    }
  }

}
