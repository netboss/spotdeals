<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Builds a no-write preview of publishing one discovered deal candidate.
 */
final class DealDiscoveryPublishPreviewService {

  private const API_KEY_STATE_NAME = 'spotdeals_data_ingestion.geoapify_api_key';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly GeoapifyClient $geoapifyClient,
    private readonly VenueMapper $venueMapper,
    private readonly VenueLocalMatcher $venueLocalMatcher,
    private readonly DealDiscoveryFieldDeriver $fieldDeriver,
    private readonly DealDiscoveryContentQualityService $contentQuality,
  ) {}

  /**
   * Builds a shadow publishing plan without saving any entity.
   *
   * @return array<string, mixed>
   */
  public function preview(array $candidate): array {
    $status = (string) ($candidate['status'] ?? '');
    if (!in_array($status, ['approved', 'auto_approved'], TRUE)) {
      return [
        'ready' => FALSE,
        'errors' => ['Only approved or auto-approved candidates can be previewed for publishing.'],
      ];
    }

    $venuePlan = $this->buildVenuePlan($candidate);
    $dealPlan = $this->buildDealPlan($candidate, $venuePlan);

    return [
      'ready' => $venuePlan['errors'] === [] && $dealPlan['blocking_fields'] === [] && !$dealPlan['duplicate_found'],
      'writes' => 'None',
      'venue' => $venuePlan,
      'deal' => $dealPlan,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildVenuePlan(array $candidate): array {
    $errors = [];
    $externalSource = strtolower(trim((string) ($candidate['external_source'] ?? '')));
    $externalId = trim((string) ($candidate['external_id'] ?? ''));
    $category = trim((string) ($candidate['category'] ?? ''));

    if ($externalSource !== 'geoapify' || $externalId === '') {
      return [
        'action' => 'unresolved',
        'nid' => NULL,
        'title' => (string) ($candidate['venue_name'] ?? ''),
        'match_method' => NULL,
        'mapped_venue' => [],
        'errors' => ['The candidate does not contain a complete Geoapify venue identity.'],
      ];
    }

    $apiKey = trim((string) $this->state->get(self::API_KEY_STATE_NAME, ''));
    if ($apiKey === '') {
      return [
        'action' => 'unresolved',
        'nid' => NULL,
        'title' => (string) ($candidate['venue_name'] ?? ''),
        'match_method' => NULL,
        'mapped_venue' => [],
        'errors' => ['The Geoapify API key is not configured.'],
      ];
    }

    try {
      $feature = $this->geoapifyClient->getPlaceDetails($apiKey, $externalId);
      $venueData = $this->venueMapper->map($feature, $category);
    }
    catch (\Throwable $exception) {
      return [
        'action' => 'unresolved',
        'nid' => NULL,
        'title' => (string) ($candidate['venue_name'] ?? ''),
        'match_method' => NULL,
        'mapped_venue' => [],
        'errors' => ['Venue resolution failed: ' . $exception->getMessage()],
      ];
    }

    if (trim((string) ($venueData['external_id'] ?? '')) !== $externalId) {
      $errors[] = 'Geoapify returned a different external place ID.';
    }

    if (empty($venueData['valid'])) {
      $errors[] = 'The mapped venue did not pass the existing venue validator contract.';
    }

    $match = $this->venueLocalMatcher->match($venueData);
    if (!empty($match['exists']) && !empty($match['nid'])) {
      return [
        'action' => 'reuse_existing',
        'nid' => (int) $match['nid'],
        'title' => (string) ($venueData['title'] ?? $candidate['venue_name'] ?? ''),
        'match_method' => (string) ($match['match_method'] ?? ''),
        'mapped_venue' => $venueData,
        'errors' => $errors,
      ];
    }

    return [
      'action' => 'would_create',
      'nid' => NULL,
      'title' => (string) ($venueData['title'] ?? $candidate['venue_name'] ?? ''),
      'match_method' => NULL,
      'mapped_venue' => $venueData,
      'errors' => $errors,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildDealPlan(array $candidate, array $venuePlan): array {
    $quality = $this->contentQuality->assessCandidate($candidate);
    $candidate = $quality['normalized'];
    $title = trim((string) ($candidate['offer_title'] ?? ''));
    $offerValue = trim((string) ($candidate['offer_value'] ?? ''));
    $schedule = trim((string) ($candidate['schedule'] ?? ''));

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $deal = $nodeStorage->create([
      'type' => 'deal',
      'title' => $title,
    ]);

    if (!$deal instanceof NodeInterface) {
      throw new \RuntimeException('Unable to construct an unsaved deal preview entity.');
    }

    $proposed = [
      'title' => $title,
      'field_price_offer_text' => $offerValue,
    ];

    if ($deal->hasField('field_price_offer_text')) {
      $deal->set('field_price_offer_text', $offerValue);
    }

    if ($deal->hasField('field_active')) {
      $proposed['field_active'] = 1;
    }

    if ($deal->hasField('field_recurring')) {
      $derivedRecurring = $this->fieldDeriver->deriveRecurring($schedule);
      $proposed['field_recurring'] = $derivedRecurring
        ?? $this->defaultFieldValue($deal, 'field_recurring');
    }

    if (($venuePlan['action'] ?? '') === 'reuse_existing' && !empty($venuePlan['nid'])) {
      $proposed['field_venue'] = (int) $venuePlan['nid'];
    }
    elseif (($venuePlan['action'] ?? '') === 'would_create') {
      $proposed['field_venue'] = 'new venue would be created first';
    }
    else {
      $proposed['field_venue'] = 'unresolved';
    }

    $blockingFields = [];
    $blockingSuggestions = [];

    if ($title === '') {
      $blockingFields['title'] = 'Offer title is required.';
    }
    if ($offerValue === '') {
      $blockingFields['field_price_offer_text'] = 'Offer value is required.';
    }
    if ($quality['blockers'] !== []) {
      $blockingFields['content_quality'] = implode(' ', $quality['blockers']);
    }

    $manualDay = $this->loadOverrideTerm(
      (int) ($candidate['override_day_of_week_tid'] ?? 0),
      'day_of_week',
    );
    $derivedDays = $manualDay !== NULL
      ? [$manualDay]
      : $this->fieldDeriver->deriveTaxonomyScheduleTerms(
        $schedule,
        $this->loadVocabularyTerms('day_of_week'),
      );

    if ($derivedDays !== []) {
      $proposed['field_day_of_week'] = $derivedDays;
      if ($manualDay !== NULL) {
        $proposed['field_day_of_week_override'] = 'manual candidate override';
      }
    }
    elseif ($this->fieldIsRequired('deal', 'field_day_of_week')) {
      $blockingFields['field_day_of_week'] = 'Required day-of-week values could not be derived safely from the discovery schedule and no valid manual override is assigned.';
      $blockingSuggestions['field_day_of_week'] = $this->suggestExistingTaxonomyFallback(
        fieldName: 'field_day_of_week',
        vocabulary: 'day_of_week',
        sourceContext: $schedule,
      );
    }

    $derivedStartTime = $this->fieldDeriver->deriveStartTime($schedule);
    if ($derivedStartTime !== '') {
      $proposed['field_start_time'] = $derivedStartTime;
    }
    elseif ($this->fieldIsRequired('deal', 'field_start_time')) {
      $blockingFields['field_start_time'] = 'A required start time could not be derived safely from the discovery schedule.';
    }

    $categoryContext = $this->cleanText(implode(' ', [
      $title,
      $offerValue,
      $schedule,
      (string) ($candidate['reason'] ?? ''),
    ]));
    $manualCategory = $this->loadOverrideTerm(
      (int) ($candidate['override_deal_category_tid'] ?? 0),
      'deal_category',
    );
    $derivedCategory = $manualCategory
      ?? $this->fieldDeriver->deriveExactTaxonomyTerm(
        $categoryContext,
        $this->loadVocabularyTerms('deal_category'),
      );

    if ($derivedCategory !== NULL) {
      $proposed['field_deal_category'] = $derivedCategory;
      if ($manualCategory !== NULL) {
        $proposed['field_deal_category_override'] = 'manual candidate override';
      }
    }
    elseif ($this->fieldIsRequired('deal', 'field_deal_category')) {
      $blockingFields['field_deal_category'] = 'A required deal category could not be derived safely from the discovery text and no valid manual override is assigned.';
      $blockingSuggestions['field_deal_category'] = $this->suggestExistingTaxonomyFallback(
        fieldName: 'field_deal_category',
        vocabulary: 'deal_category',
        sourceContext: $categoryContext,
      );
    }

    $informational = [
      'source_url' => (string) ($candidate['source_url'] ?? ''),
      'schedule_context' => $schedule,
      'discovery_score' => (int) ($candidate['score'] ?? 0),
      'confidence' => (string) ($candidate['confidence'] ?? ''),
      'status' => (string) ($candidate['status'] ?? ''),
      'content_quality_corrections' => $quality['corrections'],
      'content_quality_warnings' => $quality['warnings'],
    ];

    if ($derivedDays === [] && $this->bundleHasField('deal', 'field_day_of_week')) {
      $informational['field_day_of_week'] = $this->fieldIsRequired('deal', 'field_day_of_week')
        ? 'No safe taxonomy mapping derived; this Drupal field is required and therefore blocks publishing.'
        : 'No safe taxonomy mapping derived; this Drupal field is optional.';
    }

    if ($derivedStartTime === '' && $this->bundleHasField('deal', 'field_start_time')) {
      $informational['field_start_time'] = $this->fieldIsRequired('deal', 'field_start_time')
        ? 'No safe start time derived; this Drupal field is required and therefore blocks publishing.'
        : 'No safe start time derived; this Drupal field is optional.';
    }

    if ($derivedCategory === NULL && $this->bundleHasField('deal', 'field_deal_category')) {
      $informational['field_deal_category'] = $this->fieldIsRequired('deal', 'field_deal_category')
        ? 'No exact taxonomy-category phrase matched; this Drupal field is required and therefore blocks publishing.'
        : 'No exact taxonomy-category phrase matched; this Drupal field is optional.';
    }

    if ($this->bundleHasField('deal', 'field_recurring') && $derivedRecurring === NULL) {
      $informational['field_recurring'] = 'No explicit recurring schedule was derived; the optional boolean remains unset.';
    }

    if ($this->bundleHasField('deal', 'field_cta')) {
      $informational['field_cta'] = 'Not proposed automatically; source evidence is not necessarily the customer CTA.';
    }

    $duplicate = $this->findExistingDealDuplicate(
      $title,
      ($venuePlan['action'] ?? '') === 'reuse_existing' ? (int) ($venuePlan['nid'] ?? 0) : 0,
    );

    return [
      'proposed_fields' => $proposed,
      'blocking_fields' => $blockingFields,
      'blocking_suggestions' => $blockingSuggestions,
      'informational' => $informational,
      'duplicate_found' => $duplicate !== NULL,
      'duplicate_nid' => $duplicate,
    ];
  }

  /**
   * @return array<int, array{tid: int, name: string, weight: int}>
   */
  private function loadVocabularyTerms(string $vocabulary): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->sort('weight', 'ASC')
      ->sort('tid', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $terms = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $terms[] = [
        'tid' => (int) $term->id(),
        'name' => (string) $term->label(),
        'weight' => (int) $term->getWeight(),
      ];
    }

    return $terms;
  }

  private function fieldIsRequired(string $bundle, string $fieldName): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    return isset($definitions[$fieldName]) && $definitions[$fieldName]->isRequired();
  }

  private function cleanText(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  private function defaultFieldValue(NodeInterface $node, string $fieldName): mixed {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $value = $node->get($fieldName)->getValue();
    return $value[0]['value'] ?? $value;
  }

  private function bundleHasField(string $bundle, string $fieldName): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    return isset($definitions[$fieldName]);
  }

  private function findExistingDealDuplicate(string $title, int $venueNid): ?int {
    if ($title === '') {
      return NULL;
    }

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('title', $title)
      ->sort('nid', 'ASC')
      ->range(0, 10);

    if ($venueNid > 0 && $this->bundleHasField('deal', 'field_venue')) {
      $query->condition('field_venue.target_id', $venueNid);
    }

    $nids = $query->execute();
    if ($nids === []) {
      return NULL;
    }

    return (int) reset($nids);
  }


  /**
   * Suggests the most-used existing taxonomy value as a low-confidence fallback.
   *
   * The suggestion is never applied automatically. It exists only to give an
   * administrator a concrete starting point when automatic derivation blocks.
   *
   * @return array{
   *   type: string,
   *   target_id: int,
   *   name: string,
   *   confidence: string,
   *   reason: string,
   *   usage_count: int,
   *   source_context: string
   * }|null
   */
  private function suggestExistingTaxonomyFallback(
    string $fieldName,
    string $vocabulary,
    string $sourceContext,
  ): ?array {
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions('node', 'deal');
    if (!isset($fieldDefinitions[$fieldName])) {
      return NULL;
    }

    $storageDefinition = $fieldDefinitions[$fieldName]->getFieldStorageDefinition();
    if ($storageDefinition->getType() !== 'entity_reference') {
      return NULL;
    }

    $table = 'node__' . $fieldName;
    $targetColumn = $fieldName . '_target_id';

    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }

    $query = $this->database->select($table, 'f');
    $query->innerJoin('node_field_data', 'n', 'n.nid = f.entity_id');
    $query->innerJoin(
      'taxonomy_term_field_data',
      't',
      't.tid = f.' . $targetColumn . ' AND t.vid = :vocabulary',
      [':vocabulary' => $vocabulary],
    );
    $query->addField('f', $targetColumn, 'target_id');
    $query->addField('t', 'name', 'name');
    $query->addExpression('COUNT(DISTINCT f.entity_id)', 'usage_count');
    $query->condition('f.deleted', 0);
    $query->condition('n.type', 'deal');
    $query->groupBy('f.' . $targetColumn);
    $query->groupBy('t.name');
    $query->orderBy('usage_count', 'DESC');
    $query->orderBy('t.name', 'ASC');
    $query->range(0, 1);

    $row = $query->execute()->fetchAssoc();
    if ($row === FALSE) {
      return NULL;
    }

    $sourceContext = $this->cleanText($sourceContext);
    $reason = $sourceContext === ''
      ? 'No source schedule/category value could be derived. This is the most-used existing taxonomy value among current deal nodes and is offered only as a low-confidence administrative fallback.'
      : 'The source did not produce a safe exact mapping. This is the most-used existing taxonomy value among current deal nodes and is offered only as a low-confidence administrative fallback. Review the source before accepting it.';

    return [
      'type' => 'existing',
      'target_id' => (int) $row['target_id'],
      'name' => (string) $row['name'],
      'confidence' => 'low',
      'reason' => $reason,
      'usage_count' => (int) $row['usage_count'],
      'source_context' => $sourceContext,
    ];
  }


  /**
   * Loads and validates a manual taxonomy override.
   *
   * @return array{target_id: int, name: string}|null
   */
  private function loadOverrideTerm(int $tid, string $vocabulary): ?array {
    if ($tid <= 0) {
      return NULL;
    }

    $term = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->load($tid);

    if ($term === NULL || $term->bundle() !== $vocabulary) {
      return NULL;
    }

    return [
      'target_id' => (int) $term->id(),
      'name' => (string) $term->label(),
    ];
  }


}
