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
    'deal_title' => [$dealTitle, 180, 45],
    'deal_category' => [$dealCategory, 160, 40],
    'deal_body' => [$dealBody, 90, 20],
  ]);

  [$venueScoreRaw, $venueReasons] = spotdeals_rank_debug_text_score($keywords, $tokens, [
    'venue_title' => [$venueTitle, 45, 10],
    'venue_cuisine' => [$venueCuisine, 35, 8],
    'venue_tags' => [$venueTags, 25, 6],
    'venue_description' => [$venueDescription, 20, 4],
  ]);

  // This is the candidate formula to inspect. Venue text can boost, but cannot
  // make a keyword result relevant by itself. Nearby distance remains strong.
  $venueScore = $dealScore > 0 ? min(40, $venueScoreRaw) : 0;
  $distanceScore = max(0, (int) round(120 - ($distance * 8)));
  $freshnessScore = (int) ($freshnessScores[(int) $deal->id()]['score'] ?? 0);
  $totalScore = ($dealScore * 3) + $venueScore + $distanceScore + $freshnessScore;

  if ($keywords !== '' && $dealScore <= 0) {
    $qualified = 'no';
    $totalScore = $venueScoreRaw + $distanceScore + $freshnessScore;
  }
  else {
    $qualified = 'yes';
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
  // Mirror NearMeRanker keyword ordering: qualified matches first, then
  // distance bucket, then score, then exact distance. This keeps the debug
  // script honest when testing whether location is being preferred among
  // similarly relevant results.
  if ($a['qualified'] !== $b['qualified']) {
    return $a['qualified'] === 'yes' ? -1 : 1;
  }

  $aBucket = (int) floor(max(0.0, (float) $a['distance']) * 2);
  $bBucket = (int) floor(max(0.0, (float) $b['distance']) * 2);
  if ($aBucket !== $bBucket) {
    return $aBucket <=> $bBucket;
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

  // Drush php:script argument handling is inconsistent across Drush versions.
  // In this project, $extra may include Drush's own command words, e.g.
  // ["php", "script", "scripts/spotdeals_near_me_rank_debug.php", "--", "happy hour", ...].
  // Always prefer everything after the explicit "--" separator.
  $sources = [];
  if (isset($extra) && is_array($extra) && $extra !== []) {
    $sources[] = array_values($extra);
  }
  if (isset($argv) && is_array($argv) && $argv !== []) {
    $sources[] = array_values($argv);
  }

  foreach ($sources as $source) {
    $separator = array_search('--', $source, TRUE);
    if ($separator !== FALSE) {
      return array_values(array_slice($source, $separator + 1));
    }
  }

  $args = $sources[0] ?? [];

  // Fallback cleanup when Drush strips the "--" separator. Remove known
  // command/script tokens from the front until the first real user argument.
  while ($args !== []) {
    $first = (string) $args[0];
    $normalized = str_replace(':', ' ', mb_strtolower($first));

    if (in_array($normalized, ['php', 'script', 'php script', 'php:script'], TRUE)) {
      array_shift($args);
      continue;
    }

    if (str_ends_with($first, '.php') || str_contains($first, 'spotdeals_near_me_rank_debug.php')) {
      array_shift($args);
      continue;
    }

    break;
  }

  return array_values($args);
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
      if ($token !== '' && str_contains($text, $token)) {
        $score += $tokenWeight;
        $reasons[] = $fieldName . ':' . $token;
      }
    }
  }
  return [$score, array_values(array_unique($reasons))];
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
