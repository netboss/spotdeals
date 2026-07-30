<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the stable public result contract for hybrid venue searches.
 */
final class VenueSearchResultBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds one frontend-oriented hybrid venue search result.
   *
   * @param array<string, mixed> $venue
   *   Normalized external venue data.
   * @param array{exists: bool, nid: ?int, match_method: ?string} $match
   *   Local venue match metadata.
   *
   * @return array<string, mixed>
   *   Stable public search result.
   */
  public function build(array $venue, array $match): array {
    $node = $this->loadMatchedVenue($match);
    $externalProvider = trim((string) ($venue['external_provider'] ?? ''));
    $externalId = trim((string) ($venue['external_id'] ?? ''));

    return [
      'id' => $this->buildResultId($externalProvider, $externalId, $venue),
      'source' => $externalProvider,
      'external_id' => $externalId !== '' ? $externalId : NULL,
      'persisted' => $node instanceof NodeInterface,
      'venue' => [
        'nid' => $node instanceof NodeInterface ? (int) $node->id() : NULL,
        'title' => (string) ($venue['title'] ?? ''),
        'source_title' => (string) ($venue['source_title'] ?? ''),
        'type' => [
          'tid' => isset($venue['venue_type_tid'])
            ? (int) $venue['venue_type_tid']
            : NULL,
          'name' => (string) ($venue['venue_type_name'] ?? ''),
        ],
        'address' => is_array($venue['address'] ?? NULL)
          ? $venue['address']
          : [],
        'neighborhood' => (string) ($venue['neighborhood'] ?? ''),
        'latitude' => $venue['latitude'] ?? NULL,
        'longitude' => $venue['longitude'] ?? NULL,
        'phone' => (string) ($venue['phone'] ?? ''),
        'website' => (string) ($venue['website'] ?? ''),
        'formatted_address' => (string) ($venue['formatted_address'] ?? ''),
        'url' => $node instanceof NodeInterface
          ? $node->toUrl('canonical')->toString()
          : NULL,
      ],
      'spotdeals' => [
        'match_method' => $match['match_method'] ?? NULL,
        'claimed' => $this->isClaimed($node),
        'claim_status' => $this->claimStatus($node),
        'claimable' => $node instanceof NodeInterface
          ? !$this->isClaimed($node)
          : TRUE,
        // Deal enrichment requires the deal-to-venue field and active-deal
        // rules from the deal module. Keep these keys stable until that
        // contract is wired in rather than returning misleading counts.
        'active_deal_count' => NULL,
        'has_active_deals' => NULL,
      ],
    ];
  }

  /**
   * @param array{exists: bool, nid: ?int, match_method: ?string} $match
   */
  private function loadMatchedVenue(array $match): ?NodeInterface {
    if (!($match['exists'] ?? FALSE) || empty($match['nid'])) {
      return NULL;
    }

    $node = $this->entityTypeManager
      ->getStorage('node')
      ->load((int) $match['nid']);

    return $node instanceof NodeInterface && $node->bundle() === 'venue'
      ? $node
      : NULL;
  }

  /**
   * @param array<string, mixed> $venue
   */
  private function buildResultId(
    string $externalProvider,
    string $externalId,
    array $venue,
  ): string {
    if ($externalProvider !== '' && $externalId !== '') {
      return $externalProvider . ':' . $externalId;
    }

    $fallback = implode('|', [
      (string) ($venue['source_title'] ?? ''),
      (string) (($venue['address']['address_line1'] ?? '')),
      (string) (($venue['address']['locality'] ?? '')),
      (string) (($venue['address']['administrative_area'] ?? '')),
    ]);

    return ($externalProvider !== '' ? $externalProvider : 'external')
      . ':fallback:' . hash('sha256', $fallback);
  }

  private function isClaimed(?NodeInterface $node): bool {
    if (!$node instanceof NodeInterface || !$node->hasField('field_claimed_listing')) {
      return FALSE;
    }

    return (bool) $node->get('field_claimed_listing')->value;
  }

  private function claimStatus(?NodeInterface $node): ?string {
    if (!$node instanceof NodeInterface || !$node->hasField('field_claim_status')) {
      return NULL;
    }

    $value = trim((string) $node->get('field_claim_status')->value);
    return $value !== '' ? $value : NULL;
  }

}
