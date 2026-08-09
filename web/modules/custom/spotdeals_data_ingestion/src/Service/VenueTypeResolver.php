<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves search intent and venue types from taxonomy-owned metadata.
 *
 * Geoapify category mappings and search aliases live on venue-type taxonomy
 * terms. No venue-type term IDs, labels, or provider category mappings are
 * embedded in application code.
 */
final class VenueTypeResolver {

  public const GEOAPIFY_CATEGORIES_FIELD = 'field_geoapify_categories';

  public const GEOAPIFY_AUTO_CATEGORIES_FIELD = 'field_geoapify_auto_categories';

  public const SEARCH_ALIASES_FIELD = 'field_search_aliases';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Resolves a raw user search into configured Geoapify categories.
   *
   * @return array{
   *   query: string,
   *   categories: array<int, string>,
   *   term_ids: array<int, int>,
   *   term_names: array<int, string>,
   *   matched_phrases: array<int, string>
   * }|null
   *   Resolved intent, or NULL when no configured venue type matches.
   */
  public function resolveSearchIntent(string $query): ?array {
    $normalizedQuery = $this->normalize($query);
    if ($normalizedQuery === '') {
      return NULL;
    }

    $matches = [];
    foreach ($this->definitions() as $definition) {
      if ($definition['categories'] === []) {
        continue;
      }

      foreach ($definition['phrases'] as $phrase) {
        $match = $this->phraseMatch($normalizedQuery, $phrase, $definition['categories']);
        if ($match === NULL) {
          continue;
        }

        $matches[] = [
          'score' => $match['score'],
          'phrase' => $phrase,
          'categories' => $match['categories'],
          'definition' => $definition,
        ];
      }
    }

    if ($matches === []) {
      return NULL;
    }

    $bestScore = max(array_column($matches, 'score'));
    $bestMatches = array_values(array_filter(
      $matches,
      static fn (array $match): bool => $match['score'] === $bestScore,
    ));

    $categories = [];
    $termIds = [];
    $termNames = [];
    $phrases = [];

    foreach ($bestMatches as $match) {
      $definition = $match['definition'];
      $categories = array_merge($categories, $match['categories']);
      $termIds[] = $definition['tid'];
      $termNames[] = $definition['name'];
      $phrases[] = $match['phrase'];
    }

    return [
      'query' => trim($query),
      'categories' => array_values(array_unique($categories)),
      'term_ids' => array_values(array_unique($termIds)),
      'term_names' => array_values(array_unique($termNames)),
      'matched_phrases' => array_values(array_unique($phrases)),
    ];
  }

  /**
   * Resolves a provider result to a configured SpotDeals venue type.
   *
   * Preferred term IDs come from a resolved user search and are used first.
   * For ingestion flows without search intent, provider categories are matched
   * against the taxonomy-owned Geoapify category metadata.
   *
   * @param array<int, string> $providerCategories
   *   Geoapify categories on the returned feature.
   * @param array<int, int> $preferredTermIds
   *   Venue-type term IDs selected by search-intent resolution.
   * @param array<int, string> $requestedCategories
   *   Geoapify categories sent in the request.
   *
   * @return array{tid: int, name: string}|null
   *   Resolved taxonomy term, or NULL when no configured mapping exists.
   */
  public function resolveVenueType(
    array $providerCategories,
    array $preferredTermIds = [],
    array $requestedCategories = [],
  ): ?array {
    $definitions = $this->definitions();

    foreach ($preferredTermIds as $preferredTid) {
      foreach ($definitions as $definition) {
        if ($definition['tid'] === (int) $preferredTid) {
          return [
            'tid' => $definition['tid'],
            'name' => $definition['name'],
          ];
        }
      }
    }

    $actualCategories = array_values(array_unique(array_filter(array_map(
      static fn (mixed $value): string => trim((string) $value),
      array_merge($providerCategories, $requestedCategories),
    ))));

    $best = NULL;
    foreach ($definitions as $definition) {
      foreach ($definition['categories'] as $configuredCategory) {
        foreach ($actualCategories as $actualCategory) {
          $score = $this->categoryMatchScore($configuredCategory, $actualCategory);
          if ($score <= 0 || ($best !== NULL && $score <= $best['score'])) {
            continue;
          }

          $best = [
            'score' => $score,
            'tid' => $definition['tid'],
            'name' => $definition['name'],
          ];
        }
      }
    }

    if ($best === NULL) {
      return NULL;
    }

    return [
      'tid' => $best['tid'],
      'name' => $best['name'],
    ];
  }

  /**
   * Returns the venue-type vocabulary referenced by venue nodes.
   */
  public function venueTypeVocabularyId(): ?string {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'venue');
    $field = $definitions['field_venue_type'] ?? NULL;
    if ($field === NULL) {
      return NULL;
    }

    $settings = $field->getSettings();
    $handlerSettings = is_array($settings['handler_settings'] ?? NULL)
      ? $settings['handler_settings']
      : [];
    $targetBundles = is_array($handlerSettings['target_bundles'] ?? NULL)
      ? array_filter($handlerSettings['target_bundles'])
      : [];

    if ($targetBundles !== []) {
      return (string) array_key_first($targetBundles);
    }

    return NULL;
  }

  /**
   * Loads taxonomy-owned venue type definitions.
   *
   * @return array<int, array{
   *   tid: int,
   *   name: string,
   *   categories: array<int, string>,
   *   phrases: array<int, string>
   * }>
   */
  private function definitions(): array {
    $vocabularyId = $this->venueTypeVocabularyId();
    if ($vocabularyId === NULL) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tree = $storage->loadTree($vocabularyId);
    if ($tree === []) {
      return [];
    }

    $termIds = array_map(
      static fn (object $item): int => (int) $item->tid,
      $tree,
    );
    $terms = $storage->loadMultiple($termIds);

    $definitions = [];
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      $manualCategories = $this->fieldValues($term, self::GEOAPIFY_CATEGORIES_FIELD);
      $automaticCategories = $this->fieldValues($term, self::GEOAPIFY_AUTO_CATEGORIES_FIELD);
      $categories = $manualCategories !== [] ? $manualCategories : $automaticCategories;
      $aliases = $this->fieldValues($term, self::SEARCH_ALIASES_FIELD);
      $phrases = [$this->normalize($term->label())];

      foreach ($aliases as $alias) {
        $normalizedAlias = $this->normalize($alias);
        if ($normalizedAlias !== '') {
          $phrases[] = $normalizedAlias;
        }
      }

      $definitions[] = [
        'tid' => (int) $term->id(),
        'name' => $term->label(),
        'categories' => array_values(array_unique($categories)),
        'phrases' => array_values(array_unique(array_filter($phrases))),
      ];
    }

    return $definitions;
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

    return $values;
  }

  private function normalize(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\\s+/u', ' ', $value) ?? '');
  }

  /**
   * Matches a normalized query against a taxonomy-owned search phrase.
   *
   * Exact phrase matches retain all configured categories. When the user uses
   * a natural singular/plural or shortened form (for example, "tacos" for a
   * taxonomy phrase containing "Taco Shop"), fallback matching is allowed
   * only when the shared query token is also represented by the configured
   * Geoapify category path. This keeps the resolver data-driven and prevents
   * unrelated generic words from being enough to resolve an intent.
   *
   * @param array<int, string> $categories
   *   Configured Geoapify categories for the taxonomy term.
   *
   * @return array{score: int, categories: array<int, string>}|null
   *   Match metadata, or NULL when the phrase is not safely matched.
   */
  private function phraseMatch(string $query, string $phrase, array $categories): ?array {
    if ($phrase === '') {
      return NULL;
    }

    if (str_contains(' ' . $query . ' ', ' ' . $phrase . ' ')) {
      return [
        'score' => 100000 + (substr_count($phrase, ' ') + 1) * 1000 + strlen($phrase),
        'categories' => $categories,
      ];
    }

    $queryTokens = $this->canonicalTokens($query);
    $phraseTokens = $this->canonicalTokens($phrase);
    if ($queryTokens === [] || $phraseTokens === []) {
      return NULL;
    }

    $sharedTokens = array_values(array_intersect($queryTokens, $phraseTokens));
    if ($sharedTokens === []) {
      return NULL;
    }

    $categoryTokenCounts = [];
    $categoryTokens = [];
    foreach ($categories as $category) {
      $tokens = $this->canonicalTokens(str_replace('.', ' ', $category));
      $categoryTokens[$category] = $tokens;
      foreach (array_unique($tokens) as $token) {
        $categoryTokenCounts[$token] = ($categoryTokenCounts[$token] ?? 0) + 1;
      }
    }

    $supportedTokens = array_values(array_filter(
      $sharedTokens,
      static fn (string $token): bool => isset($categoryTokenCounts[$token]),
    ));
    if ($supportedTokens === []) {
      return NULL;
    }

    // Prefer the most discriminating shared token inside this term's own
    // category set. This prevents a generic token such as "shop" from
    // widening a "taco shop" search to an unrelated category when a more
    // specific shared token such as "taco" is available.
    $bestFrequency = min(array_map(
      static fn (string $token): int => $categoryTokenCounts[$token],
      $supportedTokens,
    ));
    $bestTokens = array_values(array_filter(
      $supportedTokens,
      static fn (string $token): bool => $categoryTokenCounts[$token] === $bestFrequency,
    ));

    $matchedCategories = [];
    foreach ($categoryTokens as $category => $tokens) {
      if (array_intersect($bestTokens, $tokens) !== []) {
        $matchedCategories[] = $category;
      }
    }

    if ($matchedCategories === []) {
      return NULL;
    }

    $matchedTokenLength = max(array_map('strlen', $bestTokens));

    return [
      'score' => 50000 + count($bestTokens) * 1000 + $matchedTokenLength,
      'categories' => array_values(array_unique($matchedCategories)),
    ];
  }

  /**
   * Returns conservative singular/plural-normalized tokens.
   *
   * @return array<int, string>
   */
  private function canonicalTokens(string $value): array {
    $normalized = $this->normalize($value);
    if ($normalized === '') {
      return [];
    }

    $tokens = preg_split('/\s+/u', $normalized) ?: [];
    $canonical = [];
    foreach ($tokens as $token) {
      $token = trim($token);
      if ($token === '') {
        continue;
      }
      $canonical[] = $this->singularizeToken($token);
    }

    return array_values(array_unique($canonical));
  }

  /**
   * Handles common English plural forms without maintaining venue synonyms.
   */
  private function singularizeToken(string $token): string {
    $length = strlen($token);
    if ($length <= 3) {
      return $token;
    }

    if ($length > 4 && str_ends_with($token, 'ies')) {
      return substr($token, 0, -3) . 'y';
    }

    if (str_ends_with($token, 'ses') || str_ends_with($token, 'xes') || str_ends_with($token, 'zes') || str_ends_with($token, 'ches') || str_ends_with($token, 'shes')) {
      return substr($token, 0, -2);
    }

    if (str_ends_with($token, 's') && !str_ends_with($token, 'ss')) {
      return substr($token, 0, -1);
    }

    return $token;
  }

  private function categoryMatchScore(string $configured, string $actual): int {
    if ($configured === $actual) {
      return 100000 + strlen($configured);
    }

    if (str_starts_with($actual, $configured . '.')) {
      return 50000 + strlen($configured);
    }

    if (str_starts_with($configured, $actual . '.')) {
      return 25000 + strlen($actual);
    }

    return 0;
  }

}
