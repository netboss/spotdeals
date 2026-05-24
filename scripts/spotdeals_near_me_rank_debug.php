<?php

declare(strict_types=1);

use Drupal\node\NodeInterface;

/**
 * Temporary SpotDeals near-me ranking debug script.
 *
 * Usage from project root:
 * ddev drush php:script scripts/spotdeals_near_me_rank_debug.php -- "happy hour" 29.0210019 -80.9772265 25 40
 * ddev drush php:script scripts/spotdeals_near_me_rank_debug.php -- tacos 29.0210019 -80.9772265 25 40
 */

$args = spotdeals_rank_debug_args();
$keywords = spotdeals_rank_debug_normalize((string) ($args[0] ?? 'happy hour'));
$originLat = isset($args[1]) && is_numeric($args[1]) ? (float) $args[1] : 29.0210019;
$originLon = isset($args[2]) && is_numeric($args[2]) ? (float) $args[2] : -80.9772265;
$radiusKm = isset($args[3]) && is_numeric($args[3]) ? (float) $args[3] : 25.0;
$limit = isset($args[4]) && is_numeric($args[4]) ? max(1, (int) $args[4]) : 40;
$tokens = spotdeals_rank_debug_tokens($keywords);

$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
$dealNids = $nodeStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'deal')
  ->condition('status', 1)
  ->execute();

if (empty($dealNids)) {
  print "No active deal nodes found.\n";
  return;
}

$deals = $nodeStorage->loadMultiple($dealNids);
$venueIds = [];
foreach ($deals as $deal) {
  if ($deal instanceof NodeInterface && $deal->hasField('field_venue') && !$deal->get('field_venue')->isEmpty()) {
    $venueId = (int) $deal->get('field_venue')->target_id;
    if ($venueId > 0) {
      $venueIds[$venueId] = $venueId;
    }
  }
}

$venues = empty($venueIds) ? [] : $nodeStorage->loadMultiple(array_values($venueIds));
$freshnessScores = \Drupal::hasService('spotdeals_search_smart_location.freshness_scorer')
  ? \Drupal::service('spotdeals_search_smart_location.freshness_scorer')->scoreDealNids(array_map('intval', array_keys($deals)))
  : [];

$rows = [];
$position = 0;
foreach ($deals as $deal) {
  if (!$deal instanceof NodeInterface) {
    continue;
  }
  if (!$deal->hasField('field_venue') || $deal->get('field_venue')->isEmpty()) {
    continue;
  }

  $venueId = (int) $deal->get('field_venue')->target_id;
  $venue = $venues[$venueId] ?? NULL;
  if (!$venue instanceof NodeInterface) {
    continue;
  }

  $coords = spotdeals_rank_debug_coords($venue);
  if ($coords === NULL) {
    continue;
  }

  [$lat, $lon] = $coords;
  $distance = spotdeals_rank_debug_haversine($originLat, $originLon, $lat, $lon);
  if ($distance > $radiusKm) {
    continue;
  }

  $dealTitle = spotdeals_rank_debug_normalize((string) $deal->label());
  $dealCategory = spotdeals_rank_debug_normalize(spotdeals_rank_debug_deal_category($deal));
  $dealBody = spotdeals_rank_debug_normalize(spotdeals_rank_debug_field_value($deal, 'body'));
  $venueTitle = spotdeals_rank_debug_normalize((string) $venue->label());
  $venueCuisine = spotdeals_rank_debug_normalize(spotdeals_rank_debug_term_labels($venue, 'field_cuisine'));
  $venueTags = spotdeals_rank_debug_normalize(spotdeals_rank_debug_term_labels($venue, 'field_tags'));
  $venueDescription = spotdeals_rank_debug_normalize(spotdeals_rank_debug_field_value($venue, 'field_short_description'));

  [$dealScore, $dealReasons] = spotdeals_rank_debug_text_score($keywords, $tokens, [
    'deal_title' => [$dealTitle, 300, 45],
    'deal_category' => [$dealCategory, 220, 40],
    'deal_body' => [$dealBody, 140, 25],
  ]);

  [$venueScoreRaw, $venueReasons] = spotdeals_rank_debug_text_score($keywords, $tokens, [
    'venue_title' => [$venueTitle, 45, 10],
    'venue_cuisine' => [$venueCuisine, 70, 12],
    'venue_tags' => [$venueTags, 45, 8],
    'venue_description' => [$venueDescription, 20, 4],
  ]);

  $cuisineAliases = spotdeals_rank_debug_cuisine_intent_aliases($keywords, $tokens);
  $isCuisineIntent = $cuisineAliases !== [];
  $cuisineVenueScore = 0;
  $cuisineDealScore = 0;

  if ($isCuisineIntent) {
    foreach ($cuisineAliases as $alias) {
      if ($alias === '') {
        continue;
      }

      if ($dealTitle !== '' && str_contains($dealTitle, $alias)) {
        $cuisineDealScore += $alias === $keywords ? 60 : 35;
        $dealReasons[] = 'deal_title:' . $alias;
      }
      if ($dealCategory !== '' && str_contains($dealCategory, $alias)) {
        $cuisineDealScore += $alias === $keywords ? 50 : 30;
        $dealReasons[] = 'deal_category:' . $alias;
      }
      if ($dealBody !== '' && str_contains($dealBody, $alias)) {
        $cuisineDealScore += $alias === $keywords ? 30 : 18;
        $dealReasons[] = 'deal_body:' . $alias;
      }

      if ($venueTitle !== '' && str_contains($venueTitle, $alias)) {
        $cuisineVenueScore += $alias === $keywords ? 45 : 25;
        $venueReasons[] = 'venue_title:' . $alias;
      }
      if ($venueCuisine !== '' && str_contains($venueCuisine, $alias)) {
        $cuisineVenueScore += $alias === $keywords ? 70 : 40;
        $venueReasons[] = 'venue_cuisine:' . $alias;
      }
      if ($venueTags !== '' && str_contains($venueTags, $alias)) {
        $cuisineVenueScore += $alias === $keywords ? 45 : 28;
        $venueReasons[] = 'venue_tags:' . $alias;
      }
      if ($venueDescription !== '' && str_contains($venueDescription, $alias)) {
        $cuisineVenueScore += $alias === $keywords ? 20 : 12;
        $venueReasons[] = 'venue_description:' . $alias;
      }
    }
  }

  $dealScore += $cuisineDealScore;
  $distanceScore = max(0, (int) round(120 - ($distance * 8)));
  $freshnessScore = (int) ($freshnessScores[(int) $deal->id()]['score'] ?? 0);

  if ($keywords !== '' && $dealScore <= 0) {
    if ($isCuisineIntent && ($venueScoreRaw + $cuisineVenueScore) > 0) {
      $qualified = 'yes';
      $venueScore = min(140, $venueScoreRaw + $cuisineVenueScore);
      $totalScore = 100 + $venueScore + $distanceScore + $freshnessScore;
    }
    else {
      $qualified = 'no';
      $venueScore = 0;
      $totalScore = $venueScoreRaw + $distanceScore + $freshnessScore;
    }
  }
  else {
    $qualified = 'yes';
    $venueScore = $isCuisineIntent ? min(140, $venueScoreRaw + $cuisineVenueScore) : min(40, $venueScoreRaw);
    $totalScore = ($isCuisineIntent ? 500 : 0) + ($dealScore * 3) + $venueScore + $distanceScore + $freshnessScore;
  }

  $rows[] = [
    'qualified' => $qualified,
    'nid' => (int) $deal->id(),
    'distance' => $distance,
    'deal_score' => $dealScore,
    'venue_score_raw' => $venueScoreRaw,
    'venue_score_used' => $venueScore,
    'distance_score' => $distanceScore,
    'freshness_score' => $freshnessScore,
    'total_score' => $totalScore,
    'deal_title' => (string) $deal->label(),
    'deal_category' => spotdeals_rank_debug_deal_category($deal),
    'venue_title' => (string) $venue->label(),
    'venue_city' => spotdeals_rank_debug_city($venue),
    'deal_reasons' => implode('|', $dealReasons),
    'venue_reasons' => implode('|', $venueReasons),
    'position' => $position++,
  ];
}

usort($rows, static function (array $a, array $b): int {
  // Show qualified deal-owned matches first, then total score, then distance.
  if ($a['qualified'] !== $b['qualified']) {
    return $a['qualified'] === 'yes' ? -1 : 1;
  }
  if ($a['total_score'] !== $b['total_score']) {
    return $b['total_score'] <=> $a['total_score'];
  }
  if (abs($a['distance'] - $b['distance']) > 0.0001) {
    return $a['distance'] <=> $b['distance'];
  }
  return $a['position'] <=> $b['position'];
});

print "SpotDeals near-me ranking debug\n";
print "query={$keywords} tokens=" . implode('|', $tokens) . " origin={$originLat},{$originLon} radius_km={$radiusKm} candidates=" . count($rows) . "\n\n";
printf("%-4s %-4s %-7s %-5s %-5s %-5s %-5s %-5s %-6s %-8s %-34s %-28s %-22s %s\n", '#', 'Q', 'dist', 'deal', 'venR', 'venU', 'distS', 'fresh', 'total', 'nid', 'deal title', 'venue', 'category', 'reasons');
print str_repeat('-', 185) . "\n";

foreach (array_slice($rows, 0, $limit) as $index => $row) {
  printf(
    "%-4d %-4s %-7.2f %-5d %-5d %-5d %-5d %-5d %-6d %-8d %-34s %-28s %-22s %s%s\n",
    $index + 1,
    $row['qualified'],
    $row['distance'],
    $row['deal_score'],
    $row['venue_score_raw'],
    $row['venue_score_used'],
    $row['distance_score'],
    $row['freshness_score'],
    $row['total_score'],
    $row['nid'],
    spotdeals_rank_debug_cut($row['deal_title'], 34),
    spotdeals_rank_debug_cut($row['venue_title'], 28),
    spotdeals_rank_debug_cut($row['deal_category'], 22),
    $row['deal_reasons'] !== '' ? 'deal:' . $row['deal_reasons'] : 'deal:-',
    $row['venue_reasons'] !== '' ? ' venue:' . $row['venue_reasons'] : ''
  );
}

function spotdeals_rank_debug_args(): array {
  global $extra, $argv;

  // Drush php:script exposes user arguments differently across versions.
  // Prefer the explicit values after "--" when present; otherwise remove
  // Drush/internal tokens such as "php", "script", and the script path.
  if (isset($extra) && is_array($extra) && $extra !== []) {
    $values = array_values(array_map('strval', $extra));
    $separator = array_search('--', $values, TRUE);
    if ($separator !== FALSE) {
      return array_values(array_slice($values, $separator + 1));
    }
    return array_values(array_filter($values, static function (string $value): bool {
      return $value !== '' && $value !== '--';
    }));
  }

  $values = array_values(array_map('strval', $argv ?? []));
  $separator = array_search('--', $values, TRUE);
  if ($separator !== FALSE) {
    return array_values(array_slice($values, $separator + 1));
  }

  $filtered = [];
  foreach ($values as $value) {
    $base = basename($value);
    if ($value === '' || $value === '--') {
      continue;
    }
    if (in_array($value, ['php', 'script', 'php:script'], TRUE)) {
      continue;
    }
    if ($base === 'spotdeals_near_me_rank_debug.php') {
      continue;
    }
    if (str_ends_with($value, '/spotdeals_near_me_rank_debug.php')) {
      continue;
    }
    $filtered[] = $value;
  }

  return array_values($filtered);
}

function spotdeals_rank_debug_cuisine_intent_aliases(string $keywords, array $tokens): array {
  $map = [
    'american' => ['american'],
    'asian' => ['asian', 'thai', 'japanese', 'chinese', 'sushi', 'ramen', 'hibachi'],
    'bbq' => ['bbq', 'barbecue', 'bar b q', 'bar-b-q'],
    'barbecue' => ['barbecue', 'bbq', 'bar b q', 'bar-b-q'],
    'burger' => ['burger', 'burgers'],
    'burgers' => ['burgers', 'burger'],
    'burrito' => ['burrito', 'burritos', 'mexican', 'tex mex', 'tex-mex'],
    'burritos' => ['burritos', 'burrito', 'mexican', 'tex mex', 'tex-mex'],
    'cafe' => ['cafe', 'coffee'],
    'chinese' => ['chinese', 'asian'],
    'coffee' => ['coffee', 'cafe'],
    'deli' => ['deli', 'sandwich', 'sandwiches'],
    'hibachi' => ['hibachi', 'japanese', 'asian'],
    'italian' => ['italian', 'pizza', 'pasta'],
    'japanese' => ['japanese', 'sushi', 'ramen', 'hibachi', 'asian'],
    'mexican' => ['mexican', 'tex mex', 'tex-mex', 'taco', 'tacos', 'burrito', 'burritos', 'quesadilla', 'quesadillas', 'enchilada', 'enchiladas'],
    'pasta' => ['pasta', 'italian'],
    'pizza' => ['pizza', 'italian'],
    'ramen' => ['ramen', 'japanese', 'asian'],
    'sandwich' => ['sandwich', 'sandwiches', 'deli'],
    'sandwiches' => ['sandwiches', 'sandwich', 'deli'],
    'seafood' => ['seafood', 'oyster', 'oysters', 'raw bar'],
    'sushi' => ['sushi', 'japanese', 'asian'],
    'taco' => ['taco', 'tacos', 'mexican', 'tex mex', 'tex-mex'],
    'tacos' => ['tacos', 'taco', 'mexican', 'tex mex', 'tex-mex'],
    'thai' => ['thai', 'pad thai', 'thai curry', 'asian'],
    'tex mex' => ['tex mex', 'tex-mex', 'mexican', 'taco', 'tacos'],
    'tex-mex' => ['tex-mex', 'tex mex', 'mexican', 'taco', 'tacos'],
    'wings' => ['wings', 'chicken'],
  ];

  $aliases = [];
  foreach (array_filter(array_merge([$keywords], $tokens)) as $candidate) {
    $candidate = spotdeals_rank_debug_normalize((string) $candidate);
    if (isset($map[$candidate])) {
      $aliases = array_merge($aliases, $map[$candidate]);
    }
  }

  return array_values(array_unique(array_map('spotdeals_rank_debug_normalize', $aliases)));
}

function spotdeals_rank_debug_text_score(string $keywords, array $tokens, array $fields): array {
  if ($keywords === '') {
    return [10, ['empty_query']];
  }

  $score = 0;
  $reasons = [];
  foreach ($fields as $fieldName => [$text, $phraseWeight, $tokenWeight]) {
    if ($text === '') {
      continue;
    }
    if (str_contains($text, $keywords)) {
      $score += $phraseWeight;
      $reasons[] = $fieldName . ':phrase';
    }
    foreach ($tokens as $token) {
      if ($token === '') {
        continue;
      }
      foreach (spotdeals_rank_debug_token_variants($token) as $variant) {
        if ($variant !== '' && str_contains($text, $variant)) {
          $score += $variant === $token ? $tokenWeight : max(1, (int) round($tokenWeight * 0.85));
          $reasons[] = $fieldName . ':' . $variant;
          break;
        }
      }
    }
  }
  return [$score, array_values(array_unique($reasons))];
}

function spotdeals_rank_debug_token_variants(string $token): array {
  $token = trim($token);
  if ($token === '') {
    return [];
  }

  $variants = [$token];
  if (mb_strlen($token) > 3 && str_ends_with($token, 's')) {
    $variants[] = mb_substr($token, 0, -1);
  }
  elseif (mb_strlen($token) > 2 && !str_ends_with($token, 's')) {
    $variants[] = $token . 's';
  }

  return array_values(array_unique($variants));
}

function spotdeals_rank_debug_tokens(string $value): array {
  $parts = preg_split('/\s+/', $value) ?: [];
  $parts = array_filter(array_map('trim', $parts), static fn(string $part): bool => mb_strlen($part) >= 2);
  return array_values(array_unique($parts));
}

function spotdeals_rank_debug_normalize(string $value): string {
  $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = mb_strtolower($value);
  $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
  $value = preg_replace('/\s+/', ' ', $value) ?? $value;
  return trim($value);
}

function spotdeals_rank_debug_field_value(NodeInterface $node, string $field): string {
  if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
    return '';
  }
  $item = $node->get($field)->first();
  if (!$item) {
    return '';
  }
  if (isset($item->value)) {
    return (string) $item->value;
  }
  return '';
}

function spotdeals_rank_debug_deal_category(NodeInterface $deal): string {
  if (!$deal->hasField('field_deal_category') || $deal->get('field_deal_category')->isEmpty()) {
    return '';
  }
  $labels = [];
  foreach ($deal->get('field_deal_category')->referencedEntities() as $entity) {
    $labels[] = (string) $entity->label();
  }
  return implode(' ', $labels);
}

function spotdeals_rank_debug_term_labels(NodeInterface $node, string $field): string {
  if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
    return '';
  }
  $labels = [];
  foreach ($node->get($field) as $item) {
    if (isset($item->entity) && $item->entity) {
      $labels[] = (string) $item->entity->label();
    }
    elseif (isset($item->value) && trim((string) $item->value) !== '') {
      $labels[] = (string) $item->value;
    }
  }
  return implode(' ', $labels);
}

function spotdeals_rank_debug_coords(NodeInterface $node): ?array {
  if ($node->hasField('field_coordinates') && !$node->get('field_coordinates')->isEmpty()) {
    $raw = trim((string) $node->get('field_coordinates')->value);
    if (preg_match('/POINT\s*\(\s*(-?[0-9.]+)\s+(-?[0-9.]+)\s*\)/i', $raw, $matches)) {
      return [(float) $matches[2], (float) $matches[1]];
    }
  }
  if ($node->hasField('field_latitude') && !$node->get('field_latitude')->isEmpty() && $node->hasField('field_longitude') && !$node->get('field_longitude')->isEmpty()) {
    $lat = $node->get('field_latitude')->value;
    $lon = $node->get('field_longitude')->value;
    if (is_numeric($lat) && is_numeric($lon)) {
      return [(float) $lat, (float) $lon];
    }
  }
  return NULL;
}

function spotdeals_rank_debug_city(NodeInterface $node): string {
  if (!$node->hasField('field_address') || $node->get('field_address')->isEmpty()) {
    return '';
  }
  $address = $node->get('field_address')->first();
  return trim((string) ($address->locality ?? ''));
}

function spotdeals_rank_debug_haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
  $earthRadiusKm = 6371.0;
  $lat1Rad = deg2rad($lat1);
  $lon1Rad = deg2rad($lon1);
  $lat2Rad = deg2rad($lat2);
  $lon2Rad = deg2rad($lon2);
  $dlat = $lat2Rad - $lat1Rad;
  $dlon = $lon2Rad - $lon1Rad;
  $a = sin($dlat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($dlon / 2) ** 2;
  return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function spotdeals_rank_debug_cut(string $value, int $length): string {
  $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
  if (mb_strlen($value) <= $length) {
    return $value;
  }
  return mb_substr($value, 0, max(0, $length - 1)) . '…';
}
