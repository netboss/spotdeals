<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Detects external venues already represented by published SpotDeals deals.
 */
final class VenueDealPresenceMatcher {

  private const GENERIC_TITLE_WORDS = [
    'and', 'bar', 'cafe', 'diner', 'food', 'grill', 'house', 'italian',
    'original', 'pizza', 'pizzeria', 'pub', 'restaurant', 'room', 'the',
  ];

  private const STREET_REPLACEMENTS = [
    'north' => 'n',
    'south' => 's',
    'east' => 'e',
    'west' => 'w',
    'avenue' => 'ave',
    'boulevard' => 'blvd',
    'court' => 'ct',
    'drive' => 'dr',
    'freeway' => 'fwy',
    'highway' => 'hwy',
    'lane' => 'ln',
    'parkway' => 'pkwy',
    'road' => 'rd',
    'street' => 'st',
  ];

  /** @var array<string, array<int, \Drupal\node\NodeInterface>> */
  private array $dealVenueCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Returns TRUE when a venue is already represented by a published deal.
   */
  public function isRepresentedByDeal(array $venue): bool {
    $address = is_array($venue['address'] ?? NULL) ? $venue['address'] : [];
    $city = trim((string) ($address['locality'] ?? ''));
    $state = trim((string) ($address['administrative_area'] ?? ''));

    if ($city === '' || $state === '') {
      return FALSE;
    }

    foreach ($this->loadDealVenues($city, $state) as $localVenue) {
      if ($this->matches($venue, $localVenue)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Loads venues in the city/state that are referenced by published deals.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   */
  private function loadDealVenues(string $city, string $state): array {
    $cacheKey = mb_strtolower($city . '|' . $state);
    if (isset($this->dealVenueCache[$cacheKey])) {
      return $this->dealVenueCache[$cacheKey];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $venueNids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->condition('field_address.locality', $city)
      ->condition('field_address.administrative_area', $state)
      ->execute();

    if ($venueNids === []) {
      return $this->dealVenueCache[$cacheKey] = [];
    }

    $dealQuery = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('status', 1)
      ->condition('field_venue.target_id', array_values($venueNids), 'IN');

    $dealDefinitions = $this->entityFieldManager->getFieldDefinitions('node', 'deal');
    if (isset($dealDefinitions['field_active'])) {
      $dealQuery->condition('field_active', 1);
    }

    $dealNids = $dealQuery->execute();
    if ($dealNids === []) {
      return $this->dealVenueCache[$cacheKey] = [];
    }

    $representedVenueNids = [];
    foreach ($nodeStorage->loadMultiple($dealNids) as $deal) {
      if (!$deal instanceof NodeInterface
        || !$deal->hasField('field_venue')
        || $deal->get('field_venue')->isEmpty()) {
        continue;
      }

      $representedVenueNids[] = (int) $deal->get('field_venue')->target_id;
    }

    $representedVenueNids = array_values(array_unique(array_filter($representedVenueNids)));
    $venues = $representedVenueNids === []
      ? []
      : $nodeStorage->loadMultiple($representedVenueNids);

    return $this->dealVenueCache[$cacheKey] = array_values(array_filter(
      $venues,
      static fn(mixed $entity): bool => $entity instanceof NodeInterface,
    ));
  }

  private function matches(array $externalVenue, NodeInterface $localVenue): bool {
    $externalAddress = is_array($externalVenue['address'] ?? NULL)
      ? $externalVenue['address']
      : [];
    $localAddress = $localVenue->hasField('field_address')
      && !$localVenue->get('field_address')->isEmpty()
      ? ($localVenue->get('field_address')->first()?->getValue() ?? [])
      : [];

    $city = (string) ($externalAddress['locality'] ?? '');
    $externalTitle = $this->normalizeTitle(
      (string) ($externalVenue['source_title'] ?? $externalVenue['title'] ?? ''),
      $city,
    );
    $localTitle = $this->normalizeTitle($localVenue->label(), $city);

    if ($externalTitle !== '' && $externalTitle === $localTitle) {
      return TRUE;
    }

    $externalStreet = $this->normalizeStreet((string) ($externalAddress['address_line1'] ?? ''));
    $localStreet = $this->normalizeStreet((string) ($localAddress['address_line1'] ?? ''));
    $sharedDistinctive = $this->sharedDistinctiveToken($externalTitle, $localTitle);

    if ($externalStreet !== '' && $externalStreet === $localStreet && $sharedDistinctive) {
      return TRUE;
    }

    $distance = $this->distanceMiles(
      (float) ($externalVenue['latitude'] ?? 0),
      (float) ($externalVenue['longitude'] ?? 0),
      $this->coordinate($localVenue, 'field_latitude'),
      $this->coordinate($localVenue, 'field_longitude'),
    );

    return $sharedDistinctive && $distance !== NULL && $distance <= 0.25;
  }

  private function sharedDistinctiveToken(string $first, string $second): bool {
    $firstTokens = $this->distinctiveTokens($first);
    $secondTokens = $this->distinctiveTokens($second);

    return array_intersect($firstTokens, $secondTokens) !== [];
  }

  /** @return string[] */
  private function distinctiveTokens(string $title): array {
    $tokens = preg_split('/\s+/', $title) ?: [];
    $tokens = array_map(function (string $token): string {
      if (strlen($token) > 4 && str_ends_with($token, 's')) {
        return substr($token, 0, -1);
      }
      return $token;
    }, $tokens);

    return array_values(array_unique(array_filter(
      $tokens,
      static fn(string $token): bool => strlen($token) > 2
        && !in_array($token, self::GENERIC_TITLE_WORDS, TRUE),
    )));
  }

  private function normalizeTitle(string $title, string $city): string {
    $title = $this->normalizeText($title);
    $city = $this->normalizeText($city);

    if ($city !== '') {
      $suffix = ' ' . $city;
      while (str_ends_with($title, $suffix)) {
        $title = trim(substr($title, 0, -strlen($suffix)));
      }
    }

    return trim(preg_replace('/^the\s+/u', '', $title) ?? $title);
  }

  private function normalizeStreet(string $address): string {
    // Secondary-unit details are not part of the base street address used for
    // comparison. The original stored/displayed address remains unchanged.
    $address = preg_replace(
      '/\s+(?:(?:suite|ste|unit|apt|apartment|room|rm|floor|fl)\b|#\s*)[^,]*$/iu',
      '',
      trim($address),
    ) ?? $address;

    $address = $this->normalizeText($address);
    $address = preg_replace('/\bstate\s+(?:road|rd)\s+(\d+)\b/u', '$1', $address) ?? $address;
    $address = preg_replace('/\b(?:sr|fl)\s*(\d+)\b/u', '$1', $address) ?? $address;

    $parts = explode(' ', $address);
    foreach ($parts as &$part) {
      $part = self::STREET_REPLACEMENTS[$part] ?? $part;
    }
    unset($part);

    return trim(implode(' ', $parts));
  }

  private function normalizeText(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
  }

  private function coordinate(NodeInterface $venue, string $fieldName): ?float {
    if (!$venue->hasField($fieldName) || $venue->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $value = $venue->get($fieldName)->value;
    return is_numeric($value) ? (float) $value : NULL;
  }

  private function distanceMiles(float $lat1, float $lon1, ?float $lat2, ?float $lon2): ?float {
    if ($lat1 === 0.0 || $lon1 === 0.0 || $lat2 === NULL || $lon2 === NULL) {
      return NULL;
    }

    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

    return 3958.8 * 2 * atan2(sqrt($a), sqrt(1 - $a));
  }

}
