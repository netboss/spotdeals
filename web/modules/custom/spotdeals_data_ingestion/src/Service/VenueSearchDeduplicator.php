<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Deduplicates normalized hybrid-search venue candidates conservatively.
 */
final class VenueSearchDeduplicator {

  /**
   * Common US street suffixes normalized for duplicate comparison.
   */
  private const STREET_SUFFIXES = [
    'avenue' => 'ave',
    'boulevard' => 'blvd',
    'circle' => 'cir',
    'court' => 'ct',
    'drive' => 'dr',
    'highway' => 'hwy',
    'lane' => 'ln',
    'parkway' => 'pkwy',
    'place' => 'pl',
    'road' => 'rd',
    'square' => 'sq',
    'street' => 'st',
    'terrace' => 'ter',
    'trail' => 'trl',
    'turnpike' => 'tpke',
    'way' => 'way',
  ];

  /**
   * Deduplicates candidates with equivalent names at the same address.
   *
   * Distinct businesses sharing one address are preserved because both the
   * normalized venue name and normalized address must match.
   *
   * @param array<int, array{venue: array<string, mixed>, match: array<string, mixed>}> $candidates
   *   Mapped venues and their local-match metadata.
   *
   * @return array{candidates: array<int, array{venue: array<string, mixed>, match: array<string, mixed>}>, discarded: int}
   *   Deduplicated candidates and the number removed.
   */
  public function deduplicate(array $candidates): array {
    $unique = [];
    $keyIndexes = [];
    $discarded = 0;

    foreach ($candidates as $candidate) {
      $key = $this->duplicateKey($candidate['venue']);

      // Preserve candidates that do not have enough identity data for a safe
      // duplicate comparison.
      if ($key === '') {
        $unique[] = $candidate;
        continue;
      }

      if (!isset($keyIndexes[$key])) {
        $keyIndexes[$key] = count($unique);
        $unique[] = $candidate;
        continue;
      }

      $existingIndex = $keyIndexes[$key];
      if ($this->score($candidate) > $this->score($unique[$existingIndex])) {
        $unique[$existingIndex] = $candidate;
      }

      $discarded++;
    }

    return [
      'candidates' => array_values($unique),
      'discarded' => $discarded,
    ];
  }

  /**
   * Builds a conservative key from equivalent title and exact location data.
   */
  private function duplicateKey(array $venue): string {
    $address = is_array($venue['address'] ?? NULL)
      ? $venue['address']
      : [];

    $city = $this->normalizeText((string) ($address['locality'] ?? ''));
    $state = $this->normalizeText((string) ($address['administrative_area'] ?? ''));
    $streetAddress = $this->normalizeStreetAddress(
      (string) ($address['address_line1'] ?? ''),
    );
    $title = $this->normalizeTitle(
      (string) ($venue['source_title'] ?? $venue['title'] ?? ''),
      $city,
    );

    if ($title === '' || $streetAddress === '' || $city === '' || $state === '') {
      return '';
    }

    return implode('|', [$title, $streetAddress, $city, $state]);
  }

  /**
   * Prefers a persisted local match, then the most complete external record.
   */
  private function score(array $candidate): int {
    $venue = $candidate['venue'];
    $match = $candidate['match'];
    $score = !empty($match['exists']) ? 100 : 0;

    foreach (['website', 'phone', 'email', 'formatted_address'] as $field) {
      if (trim((string) ($venue[$field] ?? '')) !== '') {
        $score += 10;
      }
    }

    if (trim((string) ($venue['external_id'] ?? '')) !== '') {
      $score++;
    }

    return $score;
  }

  private function normalizeTitle(string $title, string $normalizedCity): string {
    $title = $this->normalizeText($title);

    if ($title === '') {
      return '';
    }

    if ($normalizedCity !== '') {
      $suffix = ' ' . $normalizedCity;
      if (str_ends_with($title, $suffix)) {
        $title = trim(substr($title, 0, -strlen($suffix)));
      }
    }

    // A leading article is not part of a venue's stable identity.
    $title = preg_replace('/^the\s+/u', '', $title) ?? $title;

    return trim($title);
  }

  private function normalizeStreetAddress(string $address): string {
    $address = $this->normalizeText($address);

    if ($address === '') {
      return '';
    }

    $parts = explode(' ', $address);
    foreach ($parts as &$part) {
      if (isset(self::STREET_SUFFIXES[$part])) {
        $part = self::STREET_SUFFIXES[$part];
      }
    }
    unset($part);

    return implode(' ', $parts);
  }

  private function normalizeText(string $value): string {
    $value = trim($value);
    $value = function_exists('mb_strtolower')
      ? mb_strtolower($value)
      : strtolower($value);
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
  }

}
