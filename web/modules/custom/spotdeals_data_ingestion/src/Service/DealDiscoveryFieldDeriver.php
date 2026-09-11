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

    // Resolve explicit broad weekday applicability before general phrase
    // matching. These expressions are common in discovery titles and map
    // deterministically to existing composite day-of-week taxonomy terms.
    if (preg_match('/(?:^|\s)(?:every\s+day|everyday|daily)(?:\s|$)/u', $normalizedSchedule) === 1) {
      $allDays = $this->deriveCompositeDayTerm(
        $terms,
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        ['daily'],
      );
      if ($allDays !== NULL) {
        return [$allDays];
      }
    }

    if (preg_match('/(?:^|\s)weekdays?(?:\s|$)/u', $normalizedSchedule) === 1) {
      // Prefer an explicit Monday-Friday taxonomy range when the vocabulary
      // provides one. A hyphenated range normalizes to the two endpoints, so
      // it cannot be discovered by the generic five-token composite matcher.
      $weekdayRange = $this->deriveNamedScheduleTerm($terms, 'monday friday');
      if ($weekdayRange !== NULL) {
        return [$weekdayRange];
      }

      $weekdays = $this->deriveCompositeDayTerm(
        $terms,
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ['weekday', 'weekdays'],
      );
      if ($weekdays !== NULL) {
        return [$weekdays];
      }
    }

    // Broad-validity evidence such as an explicit blackout-date exception
    // means the offer applies generally rather than only on named weekdays.
    // Resolve that deterministically to the existing Daily term before the
    // more general phrase/range parsing below can misinterpret punctuation
    // such as the hyphen in "full-price" as a schedule range.
    $dailyFallback = $this->deriveUnrestrictedDailyTerm($schedule, $terms);
    if ($dailyFallback !== NULL) {
      return [$dailyFallback];
    }

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
      '/(?<![\p{L}])([\p{L}]+)\s+(?:through|thru|to|-)\s+([\p{L}]+)(?![\p{L}])/iu',
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

      $dailyFallback = $this->deriveUnrestrictedDailyTerm($schedule, $terms);
      if ($dailyFallback !== NULL) {
        return [$dailyFallback];
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
      $dailyFallback = $this->deriveUnrestrictedDailyTerm($schedule, $terms);
      return $dailyFallback !== NULL ? [$dailyFallback] : [];
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


  /**
   * Resolves one taxonomy term by its normalized schedule label.
   *
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   *
   * @return array{target_id: int, name: string}|null
   */
  private function deriveNamedScheduleTerm(array $terms, string $normalizedName): ?array {
    $matches = [];
    foreach ($terms as $term) {
      if ($this->normalizeText($term['name']) === $normalizedName) {
        $matches[] = $term;
      }
    }

    if ($matches === []) {
      return NULL;
    }

    usort(
      $matches,
      static fn (array $left, array $right): int =>
        $left['tid'] <=> $right['tid'],
    );

    return [
      'target_id' => $matches[0]['tid'],
      'name' => $matches[0]['name'],
    ];
  }

  /**
   * Resolves a deterministic composite weekday term from the vocabulary.
   *
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   * @param string[] $requiredDays
   * @param string[] $literalNames
   *
   * @return array{target_id: int, name: string}|null
   */
  private function deriveCompositeDayTerm(
    array $terms,
    array $requiredDays,
    array $literalNames = [],
  ): ?array {
    $requiredDays = array_values(array_unique($requiredDays));
    sort($requiredDays);

    $matches = [];
    foreach ($terms as $term) {
      $normalizedName = $this->normalizeText($term['name']);
      if ($normalizedName === '') {
        continue;
      }

      if (in_array($normalizedName, $literalNames, TRUE)) {
        $matches[] = ['priority' => 0, 'term' => $term];
        continue;
      }

      $tokens = preg_split('/\s+/u', $normalizedName) ?: [];
      $tokens = array_values(array_unique(array_filter(
        $tokens,
        static fn (string $token): bool => $token !== '',
      )));
      sort($tokens);

      if ($tokens === $requiredDays) {
        $matches[] = ['priority' => 1, 'term' => $term];
      }
    }

    if ($matches === []) {
      return NULL;
    }

    usort($matches, static function (array $left, array $right): int {
      return ($left['priority'] <=> $right['priority'])
        ?: ($left['term']['tid'] <=> $right['term']['tid']);
    });

    $term = $matches[0]['term'];
    return [
      'target_id' => $term['tid'],
      'name' => $term['name'],
    ];
  }

  /**
   * Derives Daily only from explicit unrestricted-validity evidence.
   *
   * This is deliberately narrower than the administrative fallback used by
   * publishing preview. It does not choose the most common taxonomy value and
   * it does not infer Daily merely because a schedule is non-empty. The source
   * must contain wording that indicates the offer remains generally valid
   * across a period, such as an explicit blackout-date exception or an
   * explicit valid-through / valid-until statement.
   *
   * @param array<int, array{tid: int, name: string, weight: int}> $terms
   *
   * @return array{target_id: int, name: string}|null
   */
  private function deriveUnrestrictedDailyTerm(string $schedule, array $terms): ?array {
    $normalizedSchedule = $this->normalizeText($schedule);
    if ($normalizedSchedule === '') {
      return NULL;
    }

    $hasUnrestrictedValidityEvidence = preg_match(
      '/(?:^|\s)(?:select\s+)?blackout\s+dates?(?:\s|$)/u',
      $normalizedSchedule,
    ) === 1
      || preg_match(
        '/(?:^|\s)valid\s+(?:through|thru|until)\s+[\p{L}\p{N}]/u',
        $normalizedSchedule,
      ) === 1
      || preg_match(
        '/(?:^|\s)valid\s+for\s+(?:\d+|a|an|one|twelve)\s+(?:full\s+)?(?:days?|months?|years?)(?:\s|$)/u',
        $normalizedSchedule,
      ) === 1;

    if (!$hasUnrestrictedValidityEvidence) {
      return NULL;
    }

    $dailyMatches = [];
    foreach ($terms as $term) {
      if ($this->normalizeText($term['name']) === 'daily') {
        $dailyMatches[] = $term;
      }
    }

    if ($dailyMatches === []) {
      return NULL;
    }

    usort(
      $dailyMatches,
      static fn (array $left, array $right): int =>
        $left['tid'] <=> $right['tid'],
    );

    $term = $dailyMatches[0];

    return [
      'target_id' => $term['tid'],
      'name' => $term['name'],
    ];
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
   * @param array<int, int> $preferredTermIds
   *   Existing taxonomy term IDs that are already used by deal nodes. These
   *   IDs are consulted only to resolve an otherwise ambiguous equal-length
   *   exact-match tie. They never override a unique longest exact match.
   *
   * @return array{target_id: int, name: string}|null
   */
  public function deriveExactTaxonomyTerm(
    string $text,
    array $terms,
    array $preferredTermIds = [],
  ): ?array {
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
      $preferredLookup = array_fill_keys(
        array_values(array_unique(array_map('intval', $preferredTermIds))),
        TRUE,
      );
      $preferredMatches = array_values(array_filter(
        $bestMatches,
        static fn (array $match): bool => isset($preferredLookup[(int) $match['term']['tid']]),
      ));
      $preferredNames = array_values(array_unique(array_column(
        $preferredMatches,
        'normalized_name',
      )));

      if (count($preferredNames) !== 1) {
        return NULL;
      }

      $bestMatches = $preferredMatches;
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
