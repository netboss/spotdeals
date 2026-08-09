<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Persists a venue only when no matching Drupal venue already exists.
 */
final class VenuePersistenceService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VenueLocalMatcher $venueLocalMatcher,
    private readonly SpanishNodeTranslationCreator $spanishTranslationCreator,
  ) {}

  /**
   * Reuses a matching venue or saves the supplied unsaved venue candidate.
   *
   * @param \Drupal\node\NodeInterface $candidate
   *   An unsaved venue node containing the values to use when creation is
   *   required.
   * @param array<string, mixed> $matchData
   *   Normalized venue data accepted by VenueLocalMatcher.
   *
   * @return array{node: \Drupal\node\NodeInterface, created: bool, match_method: ?string}
   *   The persisted venue, whether it was newly created, and the match method.
   */
  public function persist(NodeInterface $candidate, array $matchData): array {
    if ($candidate->bundle() !== 'venue') {
      throw new \InvalidArgumentException('VenuePersistenceService only accepts venue nodes.');
    }

    if (!$candidate->isNew()) {
      throw new \InvalidArgumentException('The venue candidate must be unsaved.');
    }

    $match = $this->venueLocalMatcher->match($matchData);

    if (!empty($match['exists']) && !empty($match['nid'])) {
      $existing = $this->entityTypeManager
        ->getStorage('node')
        ->load((int) $match['nid']);

      if (!$existing instanceof NodeInterface || $existing->bundle() !== 'venue') {
        throw new \RuntimeException('The matched venue could not be loaded.');
      }

      $this->attachExternalIdentity($existing, $matchData);

      return [
        'node' => $existing,
        'created' => FALSE,
        'match_method' => $match['match_method'] ?? NULL,
      ];
    }

    $this->attachExternalIdentity($candidate, $matchData);
    $candidate->save();
    $this->spanishTranslationCreator->ensureSpanishTranslation($candidate);

    return [
      'node' => $candidate,
      'created' => TRUE,
      'match_method' => NULL,
    ];
  }

  /**
   * Persists normalized venue data returned by VenueMapper.
   *
   * @param array<string, mixed> $venueData
   *   Normalized Geoapify venue data.
   *
   * @return array{node: \Drupal\node\NodeInterface, created: bool, match_method: ?string}
   *   The persisted venue result.
   */
  public function persistMappedVenue(array $venueData): array {
    if (empty($venueData['valid'])) {
      $errors = is_array($venueData['errors'] ?? NULL)
        ? implode(', ', $venueData['errors'])
        : 'unknown validation error';

      throw new \InvalidArgumentException(
        'Cannot persist invalid venue data: ' . $errors,
      );
    }

    $venueTypeTid = $venueData['venue_type_tid'] ?? NULL;
    if (!is_numeric($venueTypeTid) || (int) $venueTypeTid <= 0) {
      throw new \InvalidArgumentException('Cannot persist venue data without a resolved venue type.');
    }

    $values = [
      'type' => 'venue',
      'title' => (string) ($venueData['title'] ?? ''),
      'status' => 1,
      'field_address' => $venueData['address'] ?? [],
      'field_coordinates' => $venueData['coordinates_wkt'] ?? NULL,
      'field_latitude' => $venueData['latitude'] ?? NULL,
      'field_longitude' => $venueData['longitude'] ?? NULL,
      'field_venue_type' => [
        'target_id' => (int) $venueTypeTid,
      ],
    ];

    $phone = trim((string) ($venueData['phone'] ?? ''));
    if ($phone !== '') {
      $values['field_phone'] = $phone;
    }

    $website = trim((string) ($venueData['website'] ?? ''));
    if ($website !== '') {
      $values['field_website'] = [
        'uri' => $website,
        'title' => '',
        'options' => [],
      ];
    }

    $candidate = Node::create($values);

    return $this->persist($candidate, $venueData);
  }

  /**
   * Stores external identity without overwriting conflicting existing values.
   */
  private function attachExternalIdentity(NodeInterface $venue, array $matchData): void {
    $source = trim((string) ($matchData['external_provider'] ?? ''));
    $externalId = trim((string) ($matchData['external_id'] ?? ''));

    if ($source === '' || $externalId === '') {
      return;
    }

    $changed = FALSE;

    if ($venue->hasField('field_external_source') && $venue->get('field_external_source')->isEmpty()) {
      $venue->set('field_external_source', $source);
      $changed = TRUE;
    }

    if ($venue->hasField('field_external_id') && $venue->get('field_external_id')->isEmpty()) {
      $venue->set('field_external_id', $externalId);
      $changed = TRUE;
    }

    if (!$venue->isNew() && $changed) {
      $venue->save();
    }
  }

}
