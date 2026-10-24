<?php

declare(strict_types=1);

namespace Drupal\spotdeals_home_bubbles\Service;

/**
 * Deterministic engagement scoring for homepage conversations.
 */
final class ConversationRanking {

  /**
   * Scores verified engagement signals; shares have no reliable counter yet.
   */
  public function score(int $upvotes, int $replies): int {
    return 2 * max(0, $upvotes) + max(0, $replies);
  }

  /**
   * Ranks IDs by score, then newest numeric ID for stable ties.
   *
   * @param int[] $ids
   * @param array<int, int> $votes
   * @param array<int, int> $replies
   *
   * @return int[]
   */
  public function rank(array $ids, array $votes, array $replies): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    usort($ids, function (int $a, int $b) use ($votes, $replies): int {
      $a_score = $this->score($votes[$a] ?? 0, $replies[$a] ?? 0);
      $b_score = $this->score($votes[$b] ?? 0, $replies[$b] ?? 0);
      return ($b_score <=> $a_score) ?: ($b <=> $a);
    });
    return $ids;
  }

}
