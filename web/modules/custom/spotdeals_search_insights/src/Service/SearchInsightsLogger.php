<?php

declare(strict_types=1);

namespace Drupal\spotdeals_search_insights\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Logs and aggregates searches for the popular searches block.
 */
final class SearchInsightsLogger {

  /**
   * Search log table name.
   */
  private const TABLE = 'spotdeals_search_insights_log';

  /**
   * Maximum number of rows returned by aggregation.
   */
  private const MAX_LIMIT = 20;

  /**
   * Minimum number of searches required to appear in the block.
   */
  private const MIN_SEARCH_COUNT = 2;

  /**
   * Minimum normalized keyword length to log.
   */
  private const MIN_KEYWORD_LENGTH = 3;

  /**
   * Constructs a search insights logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Stores one search if it passes basic quality checks.
   */
  public function log(string $raw, string $normalized): void {
    $raw = trim($raw);
    $normalized = $this->normalize($normalized);

    if (!$this->shouldLog($raw, $normalized)) {
      return;
    }

    $this->database->insert(self::TABLE)
      ->fields([
        'keyword_raw' => mb_substr($raw, 0, 255),
        'keyword_normalized' => mb_substr($normalized, 0, 255),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Returns most searched normalized keywords for the recent time window.
   *
   * @return array<int,array{keyword:string,search_count:int}>
   *   Aggregated popular searches.
   */
  public function getPopularSearches(int $limit = 8, int $days = 7): array {
    $limit = max(1, min($limit, self::MAX_LIMIT));
    $days = max(1, $days);
    $since = $this->time->getRequestTime() - ($days * 86400);

    $query = $this->database->select(self::TABLE, 's');
    $query->addField('s', 'keyword_normalized', 'keyword');
    $query->addExpression('COUNT(*)', 'search_count');
    $query->condition('created', $since, '>=');
    $query->groupBy('keyword_normalized');
    $query->having('COUNT(*) >= :min_search_count', [
      ':min_search_count' => self::MIN_SEARCH_COUNT,
    ]);
    $query->orderBy('search_count', 'DESC');
    $query->orderBy('keyword_normalized', 'ASC');
    $query->range(0, $limit);

    $results = $query->execute()->fetchAllAssoc('keyword');
    $popular = [];

    foreach ($results as $keyword => $row) {
      $keyword = is_string($keyword) ? trim($keyword) : '';
      if ($keyword === '') {
        continue;
      }

      $popular[] = [
        'keyword' => $keyword,
        'search_count' => (int) ($row->search_count ?? 0),
      ];
    }

    return $popular;
  }

  /**
   * Returns TRUE when the keyword should be logged.
   */
  private function shouldLog(string $raw, string $normalized): bool {
    if ($raw === '' || $normalized === '') {
      return FALSE;
    }

    if (mb_strlen($normalized) < self::MIN_KEYWORD_LENGTH) {
      return FALSE;
    }

    if (preg_match('/^\d{5}$/', $normalized)) {
      return FALSE;
    }

    if (preg_match('/^\d+$/', $normalized)) {
      return FALSE;
    }

    return (bool) preg_match('/[\p{L}\p{N}]/u', $normalized);
  }

  /**
   * Normalizes keywords for consistent aggregation.
   */
  private function normalize(string $value): string {
    $value = mb_strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
  }

}
