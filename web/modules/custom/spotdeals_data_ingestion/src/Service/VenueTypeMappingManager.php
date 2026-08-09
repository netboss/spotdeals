<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Automatically maintains Geoapify mappings for SpotDeals venue types.
 *
 * High-confidence mappings are derived from the current provider category
 * catalog. Manual overrides remain authoritative when present.
 */
final class VenueTypeMappingManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GeoapifyCategoryCatalog $categoryCatalog,
    private readonly VenueTypeResolver $venueTypeResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Synchronizes all venue-type terms.
   *
   * @return array<int, array{
   *   tid: int,
   *   name: string,
   *   status: string,
   *   manual_categories: array<int, string>,
   *   automatic_categories: array<int, string>,
   *   suggested_categories: array<int, string>,
   *   score: float,
   *   changed: bool
   * }>
   */
  public function syncAll(bool $refreshCatalog = FALSE): array {
    $catalog = $refreshCatalog
      ? $this->categoryCatalog->refresh()
      : $this->categoryCatalog->categories(TRUE);

    $rows = [];
    foreach ($this->venueTypeTerms() as $term) {
      $rows[] = $this->syncTerm($term, $catalog);
    }

    return $rows;
  }

  /**
   * Audits mappings without changing taxonomy terms.
   *
   * @return array<int, array{
   *   tid: int,
   *   name: string,
   *   status: string,
   *   manual_categories: array<int, string>,
   *   automatic_categories: array<int, string>,
   *   suggested_categories: array<int, string>,
   *   score: float,
   *   changed: bool
   * }>
   */
  public function audit(bool $refreshCatalog = FALSE): array {
    $catalog = $refreshCatalog
      ? $this->categoryCatalog->refresh()
      : $this->categoryCatalog->categories(TRUE);

    $rows = [];
    foreach ($this->venueTypeTerms() as $term) {
      $suggestion = $this->suggestForTerm($term, $catalog);
      $manual = $this->fieldValues($term, VenueTypeResolver::GEOAPIFY_CATEGORIES_FIELD);
      $automatic = $this->fieldValues($term, VenueTypeResolver::GEOAPIFY_AUTO_CATEGORIES_FIELD);

      $rows[] = [
        'tid' => (int) $term->id(),
        'name' => $term->label(),
        'status' => $this->statusFor($manual, $automatic, $suggestion),
        'manual_categories' => $manual,
        'automatic_categories' => $automatic,
        'suggested_categories' => $suggestion['categories'],
        'score' => $suggestion['score'],
        'changed' => FALSE,
      ];
    }

    return $rows;
  }

  /**
   * Synchronizes a single venue-type term from a supplied or cached catalog.
   *
   * @param array<int, string>|null $catalog
   *   Provider category keys. NULL uses the cached catalog without refreshing.
   *
   * @return array{
   *   tid: int,
   *   name: string,
   *   status: string,
   *   manual_categories: array<int, string>,
   *   automatic_categories: array<int, string>,
   *   suggested_categories: array<int, string>,
   *   score: float,
   *   changed: bool
   * }
   */
  public function syncTerm(TermInterface $term, ?array $catalog = NULL): array {
    if ($term->bundle() !== $this->venueTypeResolver->venueTypeVocabularyId()) {
      return [
        'tid' => (int) $term->id(),
        'name' => $term->label(),
        'status' => 'not_venue_type',
        'manual_categories' => [],
        'automatic_categories' => [],
        'suggested_categories' => [],
        'score' => 0.0,
        'changed' => FALSE,
      ];
    }

    $catalog ??= $this->categoryCatalog->categories(FALSE);
    if ($catalog === []) {
      return [
        'tid' => (int) $term->id(),
        'name' => $term->label(),
        'status' => 'catalog_unavailable',
        'manual_categories' => $this->fieldValues($term, VenueTypeResolver::GEOAPIFY_CATEGORIES_FIELD),
        'automatic_categories' => $this->fieldValues($term, VenueTypeResolver::GEOAPIFY_AUTO_CATEGORIES_FIELD),
        'suggested_categories' => [],
        'score' => 0.0,
        'changed' => FALSE,
      ];
    }

    $suggestion = $this->suggestForTerm($term, $catalog);
    $manual = $this->fieldValues($term, VenueTypeResolver::GEOAPIFY_CATEGORIES_FIELD);
    $automatic = $this->fieldValues($term, VenueTypeResolver::GEOAPIFY_AUTO_CATEGORIES_FIELD);
    $desired = $suggestion['safe'] ? $suggestion['categories'] : [];

    $changed = FALSE;
    if (
      $term->hasField(VenueTypeResolver::GEOAPIFY_AUTO_CATEGORIES_FIELD)
      && $automatic !== $desired
    ) {
      $term->set(
        VenueTypeResolver::GEOAPIFY_AUTO_CATEGORIES_FIELD,
        array_map(
          static fn (string $category): array => ['value' => $category],
          $desired,
        ),
      );
      $term->save();
      $automatic = $desired;
      $changed = TRUE;
    }

    return [
      'tid' => (int) $term->id(),
      'name' => $term->label(),
      'status' => $this->statusFor($manual, $automatic, $suggestion),
      'manual_categories' => $manual,
      'automatic_categories' => $automatic,
      'suggested_categories' => $suggestion['categories'],
      'score' => $suggestion['score'],
      'changed' => $changed,
    ];
  }

  /**
   * @param array<int, string> $catalog
   *
   * @return array{
   *   categories: array<int, string>,
   *   score: float,
   *   safe: bool,
   *   ambiguous: bool
   * }
   */
  private function suggestForTerm(TermInterface $term, array $catalog): array {
    $phrases = [$term->label()];
    foreach ($this->fieldValues($term, VenueTypeResolver::SEARCH_ALIASES_FIELD) as $alias) {
      $phrases[] = $alias;
    }

    $catalogStats = $this->catalogTokenStats($catalog);
    $bestSuggestion = NULL;

    foreach ($phrases as $phrase) {
      $components = $this->phraseComponents($phrase);
      if ($components === []) {
        continue;
      }

      $componentSuggestions = [];
      foreach ($components as $component) {
        $componentSuggestions[] = $this->suggestForComponent(
          $component,
          $catalog,
          $catalogStats,
        );
      }

      $categories = [];
      $safe = TRUE;
      $ambiguous = FALSE;
      $score = 1.0;

      foreach ($componentSuggestions as $componentSuggestion) {
        $categories = array_merge($categories, $componentSuggestion['categories']);
        $safe = $safe && $componentSuggestion['safe'];
        $ambiguous = $ambiguous || $componentSuggestion['ambiguous'];
        $score = min($score, $componentSuggestion['score']);
      }

      $categories = array_values(array_unique($categories));
      sort($categories, SORT_STRING);

      $candidate = [
        'categories' => $categories,
        'score' => round($score, 4),
        'safe' => $safe && $categories !== [],
        'ambiguous' => $ambiguous,
      ];

      if (
        $bestSuggestion === NULL
        || ($candidate['safe'] && !$bestSuggestion['safe'])
        || ($candidate['safe'] === $bestSuggestion['safe'] && $candidate['score'] > $bestSuggestion['score'])
      ) {
        $bestSuggestion = $candidate;
      }
    }

    return $bestSuggestion ?? [
      'categories' => [],
      'score' => 0.0,
      'safe' => FALSE,
      'ambiguous' => FALSE,
    ];
  }

  /**
   * Suggests provider categories for one semantic component of a venue type.
   *
   * @param array<int, string> $catalog
   * @param array{count: int, token_frequency: array<string, int>, leaf_frequency: array<string, int>} $catalogStats
   *
   * @return array{
   *   categories: array<int, string>,
   *   score: float,
   *   safe: bool,
   *   ambiguous: bool
   * }
   */
  private function suggestForComponent(
    string $component,
    array $catalog,
    array $catalogStats,
  ): array {
    $phrase = $this->normalizePhrase($component);
    $phraseTokens = $this->tokens($phrase);
    if ($phrase === '' || $phraseTokens === []) {
      return [
        'categories' => [],
        'score' => 0.0,
        'safe' => FALSE,
        'ambiguous' => FALSE,
      ];
    }

    $scores = [];
    foreach ($catalog as $category) {
      $category = trim((string) $category);
      if ($category === '') {
        continue;
      }

      $score = $this->scoreCategory(
        $phrase,
        $phraseTokens,
        $category,
        $catalogStats,
      );
      if ($score > 0.0) {
        $scores[$category] = $score;
      }
    }

    if ($scores === []) {
      return [
        'categories' => [],
        'score' => 0.0,
        'safe' => FALSE,
        'ambiguous' => FALSE,
      ];
    }

    arsort($scores, SORT_NUMERIC);
    $bestScore = (float) reset($scores);
    $epsilon = 0.000001;
    $bestCategories = [];

    foreach ($scores as $category => $score) {
      if (abs($score - $bestScore) <= $epsilon) {
        $bestCategories[] = $category;
      }
    }

    $nextScore = 0.0;
    foreach ($scores as $category => $score) {
      if (!in_array($category, $bestCategories, TRUE)) {
        $nextScore = (float) $score;
        break;
      }
    }

    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $minimumScore = (float) ($config->get('mapping_min_score') ?? 0.90);
    $ambiguityDelta = (float) ($config->get('mapping_ambiguity_delta') ?? 0.05);

    $ambiguous = $nextScore > 0.0 && ($bestScore - $nextScore) < $ambiguityDelta;
    $safe = $bestScore >= $minimumScore && !$ambiguous;

    sort($bestCategories, SORT_STRING);

    return [
      'categories' => $bestCategories,
      'score' => round($bestScore, 4),
      'safe' => $safe,
      'ambiguous' => $ambiguous,
    ];
  }

  /**
   * Scores a provider category against a normalized venue-type component.
   *
   * Matching is structural rather than mapping-specific:
   * - exact provider leaf matches are strongest;
   * - order-independent equality against provider suffixes handles labels such
   *   as "American Restaurant" vs "catering.restaurant.american";
   * - rare exact leaf tokens may safely carry a descriptive modifier, which
   *   handles labels such as "Massage Therapy" without allowing common words
   *   such as "shop" to dominate unrelated matches;
   * - partial token overlap remains audit-only below the automatic threshold.
   *
   * @param array<int, string> $phraseTokens
   * @param array{count: int, token_frequency: array<string, int>, leaf_frequency: array<string, int>} $catalogStats
   */
  private function scoreCategory(
    string $phrase,
    array $phraseTokens,
    string $category,
    array $catalogStats,
  ): float {
    $segments = explode('.', $category);
    $leaf = $this->normalizePhrase((string) end($segments));
    $leafTokens = $this->tokens($leaf);

    if ($phrase === $leaf) {
      return 1.0;
    }

    if ($phraseTokens === $leafTokens && $phraseTokens !== []) {
      return 0.995;
    }

    $candidateForms = [];
    $segmentCount = count($segments);
    foreach ([2, 3] as $tailLength) {
      if ($segmentCount < $tailLength) {
        continue;
      }

      $candidateForms[] = $this->normalizePhrase(implode(
        ' ',
        array_slice($segments, -$tailLength),
      ));
    }
    $candidateForms[] = $this->normalizePhrase(str_replace('.', ' ', $category));

    $candidateScore = 0.0;
    foreach (array_values(array_unique($candidateForms)) as $index => $candidateForm) {
      $candidateTokens = $this->tokens($candidateForm);
      if ($candidateTokens === []) {
        continue;
      }

      if ($phrase === $candidateForm) {
        $candidateScore = max($candidateScore, $index === 0 ? 0.99 : 0.985);
        continue;
      }

      if ($phraseTokens === $candidateTokens) {
        $candidateScore = max($candidateScore, $index === 0 ? 0.99 : 0.985);
      }
    }

    if ($candidateScore >= 0.90) {
      return $candidateScore;
    }

    // A one-token provider leaf must not become an automatic mapping merely
    // because that token also appears in a longer SpotDeals label. This was
    // too permissive for provider leaves such as "shop" and "bar".
    //
    // The only data-driven exception is when the same leaf is represented by
    // multiple independent provider category paths. Agreement across provider
    // branches is stronger evidence that the shared leaf is the intended
    // concept rather than an incidental generic word.
    if (count($leafTokens) === 1 && in_array($leafTokens[0], $phraseTokens, TRUE)) {
      $matchingLeafCount = 0;
      foreach ($catalogStats['leaf_frequency'] as $token => $frequency) {
        if ($token === $leafTokens[0]) {
          $matchingLeafCount = $frequency;
          break;
        }
      }

      if ($matchingLeafCount >= 2) {
        $rarity = $this->tokenRarity($leafTokens[0], $catalogStats);
        $providerAgreementScore = 0.90 + (0.08 * $rarity);
        $candidateScore = max($candidateScore, $providerAgreementScore);
      }
    }

    $leafSimilarity = $this->weightedDiceCoefficient(
      $phraseTokens,
      $leafTokens,
      $catalogStats,
    );
    $candidateScore = max($candidateScore, $leafSimilarity * 0.88);

    foreach ($candidateForms as $candidateForm) {
      $candidateTokens = $this->tokens($candidateForm);
      if ($candidateTokens === []) {
        continue;
      }

      $candidateScore = max(
        $candidateScore,
        $this->weightedDiceCoefficient($phraseTokens, $candidateTokens, $catalogStats) * 0.89,
      );
    }

    return min(0.9899, $candidateScore);
  }

  /**
   * Splits one venue-type phrase into independently resolvable components.
   *
   * Slash, ampersand, plus, pipe and semicolon separators represent explicit
   * combinations in existing SpotDeals venue-type labels. No provider or
   * venue-specific vocabulary is embedded here.
   *
   * @return array<int, string>
   */
  private function phraseComponents(string $phrase): array {
    $parts = preg_split('/\s*(?:\/|&|\+|\||;)\s*/u', trim($phrase)) ?: [];
    $components = [];

    foreach ($parts as $part) {
      $normalized = $this->normalizePhrase($part);
      if ($normalized !== '') {
        $components[] = $normalized;
      }
    }

    return array_values(array_unique($components));
  }

  /**
   * Builds provider-catalog token frequency statistics.
   *
   * @param array<int, string> $catalog
   *
   * @return array{count: int, token_frequency: array<string, int>, leaf_frequency: array<string, int>}
   */
  private function catalogTokenStats(array $catalog): array {
    $frequency = [];
    $leafFrequency = [];
    $count = 0;

    foreach ($catalog as $category) {
      $category = trim((string) $category);
      if ($category === '') {
        continue;
      }

      $count++;
      $tokens = $this->tokens($this->normalizePhrase(str_replace('.', ' ', $category)));
      foreach ($tokens as $token) {
        $frequency[$token] = ($frequency[$token] ?? 0) + 1;
      }

      $segments = explode('.', $category);
      $leaf = $this->normalizePhrase((string) end($segments));
      $leafTokens = $this->tokens($leaf);
      if (count($leafTokens) === 1) {
        $leafToken = $leafTokens[0];
        $leafFrequency[$leafToken] = ($leafFrequency[$leafToken] ?? 0) + 1;
      }
    }

    return [
      'count' => max(1, $count),
      'token_frequency' => $frequency,
      'leaf_frequency' => $leafFrequency,
    ];
  }

  /**
   * @param array{count: int, token_frequency: array<string, int>, leaf_frequency: array<string, int>} $catalogStats
   */
  private function tokenRarity(string $token, array $catalogStats): float {
    $documentCount = max(1, (int) $catalogStats['count']);
    $frequency = max(0, (int) ($catalogStats['token_frequency'][$token] ?? 0));

    $rarity = 1.0 - (
      log(1.0 + $frequency)
      / log(1.0 + $documentCount)
    );

    return max(0.0, min(1.0, $rarity));
  }

  /**
   * @param array<int, string> $left
   * @param array<int, string> $right
   * @param array{count: int, token_frequency: array<string, int>, leaf_frequency: array<string, int>} $catalogStats
   */
  private function weightedDiceCoefficient(
    array $left,
    array $right,
    array $catalogStats,
  ): float {
    if ($left === [] || $right === []) {
      return 0.0;
    }

    $leftWeights = 0.0;
    foreach ($left as $token) {
      $leftWeights += 0.25 + $this->tokenRarity($token, $catalogStats);
    }

    $rightWeights = 0.0;
    foreach ($right as $token) {
      $rightWeights += 0.25 + $this->tokenRarity($token, $catalogStats);
    }

    $intersectionWeights = 0.0;
    foreach (array_intersect($left, $right) as $token) {
      $intersectionWeights += 0.25 + $this->tokenRarity($token, $catalogStats);
    }

    if (($leftWeights + $rightWeights) <= 0.0) {
      return 0.0;
    }

    return (2.0 * $intersectionWeights) / ($leftWeights + $rightWeights);
  }

  /**
   * @return array<int, TermInterface>
   */
  private function venueTypeTerms(): array {
    $vocabularyId = $this->venueTypeResolver->venueTypeVocabularyId();
    if ($vocabularyId === NULL) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabularyId)
      ->sort('name')
      ->execute();

    if ($ids === []) {
      return [];
    }

    return array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn (mixed $term): bool => $term instanceof TermInterface,
    ));
  }

  /**
   * @return array<int, string>
   */
  private function fieldValues(TermInterface $term, string $fieldName): array {
    if (!$term->hasField($fieldName) || $term->get($fieldName)->isEmpty()) {
      return [];
    }

    $values = [];
    foreach ($term->get($fieldName) as $item) {
      $value = trim((string) $item->value);
      if ($value !== '') {
        $values[] = $value;
      }
    }

    $values = array_values(array_unique($values));
    sort($values, SORT_STRING);
    return $values;
  }

  /**
   * @param array<int, string> $manual
   * @param array<int, string> $automatic
   * @param array{categories: array<int, string>, score: float, safe: bool, ambiguous: bool} $suggestion
   */
  private function statusFor(array $manual, array $automatic, array $suggestion): string {
    if ($manual !== []) {
      return 'manual_override';
    }

    if ($automatic !== []) {
      return 'automatic';
    }

    if ($suggestion['ambiguous']) {
      return 'ambiguous';
    }

    if ($suggestion['categories'] !== []) {
      return 'low_confidence';
    }

    return 'unmapped';
  }

  private function normalizePhrase(string $value): string {
    $value = str_replace(['_', '.', '-'], ' ', $value);
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

  /**
   * @return array<int, string>
   */
  private function tokens(string $value): array {
    $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_map([$this, 'singularizeToken'], $tokens);
    sort($tokens, SORT_STRING);
    return array_values(array_unique($tokens));
  }

  private function singularizeToken(string $token): string {
    if (strlen($token) > 4 && str_ends_with($token, 'ies')) {
      return substr($token, 0, -3) . 'y';
    }

    if (
      strlen($token) > 3
      && str_ends_with($token, 's')
      && !str_ends_with($token, 'ss')
    ) {
      return substr($token, 0, -1);
    }

    return $token;
  }

}
