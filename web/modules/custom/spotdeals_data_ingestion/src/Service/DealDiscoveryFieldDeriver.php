<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Derives structured deal fields from discovery text without writing data.
 */
final class DealDiscoveryFieldDeriver {

  /**
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   *
   * @return array<int, array{target_id: int, name: string}>
   */
  public function deriveTaxonomyScheduleTerms(string $schedule, array $terms): array {
    $schedule = $this->cleanText($schedule);
    if ($schedule === '' || $terms === []) {
      return [];
    }

    $canonicalSchedule = $this->canonicalScheduleText($schedule);
    if ($canonicalSchedule === '') {
      return [];
    }

    $normalizedSchedule = $this->normalizeText($schedule);

    if (preg_match('/(?:^|\s)weekends?(?:\s|$)/u', $normalizedSchedule) === 1) {
      $weekendMatches = [];
      foreach ($terms as $term) {
        $normalizedName = $this->normalizeText($term['name']);
        if (in_array($normalizedName, ['weekend', 'weekends'], TRUE)) {
          $weekendMatches[] = $term;
        }
      }

      if ($weekendMatches !== []) {
        usort($weekendMatches, static fn (array $left, array $right): int => $left['tid'] <=> $right['tid']);
        $term = $weekendMatches[0];
        return [['target_id' => $term['tid'], 'name' => $term['name']]];
      }
    }

    // Explicit ordinal recurrence such as "third Thursday of every
    // month" carries one unambiguous schedule token. Resolve that token
    // directly against an existing single-token taxonomy term rather than
    // relying on broader vocabulary-frequency heuristics.
    if (preg_match(
      '/(?<![\p{L}\p{N}])(?:first|second|third|fourth|fifth|last)\s+([\p{L}\p{N}]+)\s+of\s+(?:every|each)\s+[\p{L}\p{N}]+(?![\p{L}\p{N}])/iu',
      mb_strtolower($schedule),
      $ordinalMatch,
    ) === 1) {
      $scheduleToken = $this->normalizeText($ordinalMatch[1]);
      $ordinalTermMatches = [];

      foreach ($terms as $term) {
        $normalizedName = $this->normalizeText($term['name']);
        if ($normalizedName === '' || $normalizedName !== $scheduleToken) {
          continue;
        }

        $tokens = preg_split('/\s+/u', $normalizedName) ?: [];
        $tokens = array_values(array_filter(
          $tokens,
          static fn (string $token): bool => $token !== '',
        ));

        if (count($tokens) !== 1) {
          continue;
        }

        $ordinalTermMatches[] = $term;
      }

      if ($ordinalTermMatches !== []) {
        usort(
          $ordinalTermMatches,
          static fn (array $left, array $right): int =>
            $left['tid'] <=> $right['tid'],
        );

        $term = $ordinalTermMatches[0];

        return [[
          'target_id' => $term['tid'],
          'name' => $term['name'],
        ]];
      }
    }

    if (preg_match(
      '/(?<![\p{L}\p{N}])([\p{L}\p{N}]+)\s+(?:through|thru|to|-)\s+([\p{L}\p{N}]+)(?![\p{L}\p{N}])/iu',
      mb_strtolower($schedule),
      $range,
    ) === 1) {
      $startToken = $this->normalizeText($range[1]);
      $endToken = $this->normalizeText($range[2]);
      $rangeMatches = [];

      if ($startToken !== '' && $endToken !== '') {
        foreach ($terms as $term) {
          $rawName = $this->cleanText($term['name']);
          $canonicalName = $this->canonicalScheduleText($rawName);
          if ($canonicalName === '') {
            continue;
          }

          $clauses = preg_split('/\s*[;|]\s*/u', $rawName) ?: [];
          $clauses = array_values(array_filter(
            array_map([$this, 'cleanText'], $clauses),
            static fn (string $clause): bool => $clause !== '',
          ));
          if (count($clauses) !== 1) {
            continue;
          }

          $tokens = preg_split('/\s+/u', $canonicalName) ?: [];
          $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
          if (count($tokens) < 2) {
            continue;
          }

          $firstIndex = array_search($startToken, $tokens, TRUE);
          $lastIndex = array_search($endToken, $tokens, TRUE);
          if ($firstIndex === FALSE || $lastIndex === FALSE || $firstIndex >= $lastIndex) {
            continue;
          }
          if ($firstIndex !== 0 || $lastIndex !== count($tokens) - 1) {
            continue;
          }

          $rangeMatches[] = [
            'term' => $term,
            'canonical_name' => $canonicalName,
            'span' => $lastIndex - $firstIndex,
            'token_count' => count($tokens),
            'length' => mb_strlen($canonicalName),
          ];
        }
      }

      if ($rangeMatches !== []) {
        usort($rangeMatches, static function (array $left, array $right): int {
          return ($left['span'] <=> $right['span'])
            ?: ($left['token_count'] <=> $right['token_count'])
            ?: ($left['length'] <=> $right['length'])
            ?: ($left['term']['tid'] <=> $right['term']['tid']);
        });

        $bestSpan = $rangeMatches[0]['span'];
        $bestTokenCount = $rangeMatches[0]['token_count'];
        $bestLength = $rangeMatches[0]['length'];
        $bestMatches = array_values(array_filter(
          $rangeMatches,
          static fn (array $match): bool => $match['span'] === $bestSpan
            && $match['token_count'] === $bestTokenCount
            && $match['length'] === $bestLength,
        ));
        $canonicalNames = array_values(array_unique(array_column($bestMatches, 'canonical_name')));
        if (count($canonicalNames) === 1) {
          $term = $bestMatches[0]['term'];
          return [['target_id' => $term['tid'], 'name' => $term['name']]];
        }
      }

      return [];
    }

    $singleTokenMatches = [];
    $atomicTokenSupport = $this->atomicScheduleTokenSupport($terms);

    foreach ($terms as $term) {
      $normalizedName = $this->normalizeText($term['name']);
      if ($normalizedName === '') {
        continue;
      }

      $tokens = preg_split('/\s+/u', $normalizedName) ?: [];
      $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
      if (count($tokens) !== 1 || ($atomicTokenSupport[$normalizedName] ?? 0) < 1) {
        continue;
      }
      if (!$this->containsPhrase($normalizedSchedule, $normalizedName)) {
        continue;
      }

      $singleTokenMatches[] = [
        'term' => $term,
        'normalized_name' => $normalizedName,
        'support' => $atomicTokenSupport[$normalizedName],
      ];
    }

    if ($singleTokenMatches !== []) {
      $normalizedNames = array_values(array_unique(array_column(
        $singleTokenMatches,
        'normalized_name',
      )));

      if (count($normalizedNames) === 1) {
        usort(
          $singleTokenMatches,
          static fn (array $left, array $right): int =>
            $left['term']['tid'] <=> $right['term']['tid'],
        );
        $term = $singleTokenMatches[0]['term'];
        return [['target_id' => $term['tid'], 'name' => $term['name']]];
      }
    }

    $matches = $this->schedulePhraseMatches($canonicalSchedule, $terms);

    if ($matches === []) {
      return [];
    }

    usort($matches, static function (array $left, array $right): int {
      return ($right['length'] <=> $left['length']) ?: ($left['term']['tid'] <=> $right['term']['tid']);
    });
    $bestLength = $matches[0]['length'];
    $bestMatches = array_values(array_filter($matches, static fn (array $match): bool => $match['length'] === $bestLength));
    $canonicalNames = array_values(array_unique(array_column($bestMatches, 'canonical_name')));
    if (count($canonicalNames) !== 1) {
      return [];
    }

    $term = $bestMatches[0]['term'];
    return [['target_id' => $term['tid'], 'name' => $term['name']]];
  }

  public function deriveRecurring(string $schedule): ?int {
    $schedule = $this->normalizeText($schedule);
    if ($schedule === '') {
      return NULL;
    }

    if (preg_match('/(?:^|\s)(?:daily|weekly|monthly|yearly|annually)(?:\s|$)/u', $schedule) === 1) {
      return 1;
    }
    if (preg_match('/(?:^|\s)(?:every|each)\s+[\p{L}\p{N}]+(?:\s|$)/u', $schedule) === 1) {
      return 1;
    }
    if (preg_match('/(?:^|\s)(?:first|second|third|fourth|fifth|last)\s+[\p{L}\p{N}]+\s+of\s+every\s+[\p{L}\p{N}]+(?:\s|$)/u', $schedule) === 1) {
      return 1;
    }

    return NULL;
  }

  public function deriveStartTime(string $schedule): string {
    $schedule = $this->cleanText($schedule);
    if ($schedule === '') {
      return '';
    }

    if (preg_match(
      '/(?<!\d)(?:[01]?\d|2[0-3])(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?)(?![\p{L}\p{N}])/iu',
      $schedule,
      $matches,
    ) === 1) {
      return $this->cleanText($matches[0]);
    }

    return '';
  }

  /**
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   *
   * @return array{target_id: int, name: string}|null
   */
  public function deriveExactTaxonomyTerm(string $text, array $terms): ?array {
    $normalizedText = $this->normalizeText($text);
    if ($normalizedText === '') {
      return NULL;
    }

    $matches = [];
    foreach ($terms as $term) {
      $normalizedName = $this->normalizeText($term['name']);
      if ($normalizedName === '' || !$this->containsPhrase($normalizedText, $normalizedName)) {
        continue;
      }
      $matches[] = [
        'term' => $term,
        'normalized_name' => $normalizedName,
        'length' => mb_strlen($normalizedName),
      ];
    }

    if ($matches === []) {
      return NULL;
    }

    usort($matches, static function (array $left, array $right): int {
      return ($right['length'] <=> $left['length']) ?: ($left['term']['tid'] <=> $right['term']['tid']);
    });
    $bestLength = $matches[0]['length'];
    $bestMatches = array_values(array_filter($matches, static fn (array $match): bool => $match['length'] === $bestLength));
    $normalizedNames = array_values(array_unique(array_column($bestMatches, 'normalized_name')));
    if (count($normalizedNames) !== 1) {
      return NULL;
    }

    $term = $bestMatches[0]['term'];
    return ['target_id' => $term['tid'], 'name' => $term['name']];
  }

  /**
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   *
   * @return array<string, int>
   */
  private function atomicScheduleTokenSupport(array $terms): array {
    $singleTokens = [];

    foreach ($terms as $term) {
      $normalizedName = $this->normalizeText($term['name']);
      if ($normalizedName === '') {
        continue;
      }

      $tokens = preg_split('/\s+/u', $normalizedName) ?: [];
      $tokens = array_values(array_filter(
        $tokens,
        static fn (string $token): bool => $token !== '',
      ));

      if (count($tokens) === 1) {
        $singleTokens[$tokens[0]] = 0;
      }
    }

    if ($singleTokens === []) {
      return [];
    }

    foreach ($terms as $term) {
      $normalizedName = $this->normalizeText($term['name']);
      if ($normalizedName === '') {
        continue;
      }

      $tokens = preg_split('/\s+/u', $normalizedName) ?: [];
      $tokens = array_values(array_unique(array_filter(
        $tokens,
        static fn (string $token): bool => $token !== '',
      )));

      if (count($tokens) < 2) {
        continue;
      }

      // Count structural support only from pure composite labels made entirely
      // from existing single-token taxonomy terms. This prevents unrelated
      // words from noisy schedule labels from outranking actual day atoms.
      $isPureAtomicComposite = TRUE;
      foreach ($tokens as $token) {
        if (!array_key_exists($token, $singleTokens)) {
          $isPureAtomicComposite = FALSE;
          break;
        }
      }

      if (!$isPureAtomicComposite) {
        continue;
      }

      foreach ($tokens as $token) {
        $singleTokens[$token]++;
      }
    }

    return $singleTokens;
  }

  /**
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   *
   * @return array<int, array<string, mixed>>
   */
  private function schedulePhraseMatches(string $canonicalSchedule, array $terms): array {
    $matches = [];
    foreach ($terms as $term) {
      $canonicalName = $this->canonicalScheduleText($term['name']);
      if ($canonicalName === '' || !$this->containsPhrase($canonicalSchedule, $canonicalName)) {
        continue;
      }
      $matches[] = [
        'term' => $term,
        'canonical_name' => $canonicalName,
        'length' => mb_strlen($canonicalName),
      ];
    }
    return $matches;
  }

  private function canonicalScheduleText(string $text): string {
    $text = mb_strtolower($this->cleanText($text));
    $text = preg_replace('/\b(?:through|thru|to)\b/u', ' ', $text) ?? $text;
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  private function containsPhrase(string $text, string $phrase): bool {
    return preg_match('/(?:^|\s)' . preg_quote($phrase, '/') . '(?:\s|$)/iu', $text) === 1;
  }

  private function normalizeText(string $text): string {
    $text = mb_strtolower($this->cleanText($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  private function cleanText(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

}
