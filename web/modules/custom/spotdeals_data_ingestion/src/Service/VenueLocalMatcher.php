<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Matches normalized Geoapify venues to existing SpotDeals venue nodes.
 */
final class VenueLocalMatcher {

  private const EXTERNAL_SOURCE_FIELD = 'field_external_source';
  private const EXTERNAL_ID_FIELD = 'field_external_id';

  /** @var array<string, array<int, \Drupal\node\NodeInterface>> */
  private array $candidateCache = [];

  /**
   * Common US street suffixes normalized for conservative address matching.
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

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Returns local SpotDeals match metadata for one normalized venue.
   *
   * @return array{exists: bool, nid: ?int, match_method: ?string}
   *   Local match information.
   */
  public function match(array $venue): array {
    $externalId = trim((string) ($venue['external_id'] ?? ''));
    $externalProvider = trim((string) ($venue['external_provider'] ?? ''));

    if (
      $externalId !== ''
      && $externalProvider !== ''
      && $this->hasExternalIdentityFields()
    ) {
      $nid = $this->findByExternalIdentity($externalProvider, $externalId);

      if ($nid !== NULL) {
        return $this->matched($nid, 'external_id');
      }
    }

    $nid = $this->findByNormalizedTitleAndAddress($venue);

    if ($nid !== NULL) {
      return $this->matched($nid, 'normalized_title_address');
    }

    return [
      'exists' => FALSE,
      'nid' => NULL,
      'match_method' => NULL,
    ];
  }

  private function hasExternalIdentityFields(): bool {
    $definitions = $this->entityFieldManager
      ->getFieldStorageDefinitions('node');

    return isset(
      $definitions[self::EXTERNAL_SOURCE_FIELD],
      $definitions[self::EXTERNAL_ID_FIELD],
    );
  }

  private function findByExternalIdentity(
    string $externalProvider,
    string $externalId,
  ): ?int {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition(self::EXTERNAL_SOURCE_FIELD, $externalProvider)
      ->condition(self::EXTERNAL_ID_FIELD, $externalId)
      ->range(0, 1)
      ->execute();

    return $this->firstNid($nids);
  }

  /**
   * Finds a conservative fallback match without requiring an exact ZIP code.
   */
  private function findByNormalizedTitleAndAddress(array $venue): ?int {
    $address = is_array($venue['address'] ?? NULL)
      ? $venue['address']
      : [];

    $city = trim((string) ($address['locality'] ?? ''));
    $state = trim((string) ($address['administrative_area'] ?? ''));
    $addressLine1 = $this->normalizeStreetAddress(
      (string) ($address['address_line1'] ?? ''),
    );

    $titles = array_values(array_unique(array_filter([
      $this->normalizeTitle((string) ($venue['source_title'] ?? ''), $city),
      $this->normalizeTitle((string) ($venue['title'] ?? ''), $city),
    ])));

    if ($titles === [] || $addressLine1 === '' || $city === '' || $state === '') {
      return NULL;
    }

    foreach ($this->loadCandidates($city, $state) as $node) {
      if (!$node instanceof NodeInterface || $node->get('field_address')->isEmpty()) {
        continue;
      }

      $localAddress = $node->get('field_address')->first()?->getValue() ?? [];
      $localAddressLine1 = $this->normalizeStreetAddress(
        (string) ($localAddress['address_line1'] ?? ''),
      );
      $localTitle = $this->normalizeTitle($node->label(), $city);

      if ($localAddressLine1 === $addressLine1 && in_array($localTitle, $titles, TRUE)) {
        return (int) $node->id();
      }
    }

    return NULL;
  }

  /**
   * Loads local venue candidates once per city/state during the request.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   */
  private function loadCandidates(string $city, string $state): array {
    $cacheKey = mb_strtolower($city . '|' . $state);

    if (isset($this->candidateCache[$cacheKey])) {
      return $this->candidateCache[$cacheKey];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('field_address.locality', $city)
      ->condition('field_address.administrative_area', $state)
      ->execute();

    $nodes = $nids === [] ? [] : $storage->loadMultiple($nids);
    $this->candidateCache[$cacheKey] = $nodes;

    return $nodes;
  }

  private function normalizeTitle(string $title, string $city): string {
    $title = $this->normalizeText($title);
    $city = $this->normalizeText($city);

    if ($title === '' || $city === '') {
      return $title;
    }

    $suffix = ' ' . $city;
    if (str_ends_with($title, $suffix)) {
      $title = trim(substr($title, 0, -strlen($suffix)));
    }

    return $title;
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
    $value = mb_strtolower(trim($value));
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
  }

  /**
   * @param array<int|string, int|string> $nids
   */
  private function firstNid(array $nids): ?int {
    if ($nids === []) {
      return NULL;
    }

    return (int) reset($nids);
  }

  /**
   * @return array{exists: true, nid: int, match_method: string}
   */
  private function matched(int $nid, string $method): array {
    return [
      'exists' => TRUE,
      'nid' => $nid,
      'match_method' => $method,
    ];
  }

}
