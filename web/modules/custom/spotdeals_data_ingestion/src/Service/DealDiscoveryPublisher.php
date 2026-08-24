<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\node\NodeInterface;

/**
 * Performs controlled publishing for one ready deal-discovery candidate.
 */
final class DealDiscoveryPublisher {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryPublishPreviewService $previewService,
    private readonly VenuePersistenceService $venuePersistence,
    private readonly SpanishNodeTranslationCreator $spanishTranslationCreator,
  ) {}

  /**
   * Publishes one candidate after re-running the no-write readiness contract.
   *
   * @return array{
   *   venue_nid: int,
   *   deal_nid: int,
   *   venue_created: bool,
   *   already_published: bool
   * }
   */
  public function publish(int $candidateId, int $uid): array {
    $lockName = 'spotdeals_deal_discovery_publish:' . $candidateId;

    if (!$this->lock->acquire($lockName, 30.0)) {
      throw new \RuntimeException(
        'This candidate is already being published by another request.',
      );
    }

    try {
      $candidate = $this->storage->load($candidateId);
      if ($candidate === NULL) {
        throw new \RuntimeException('The deal-discovery candidate no longer exists.');
      }

      $publishedDealNid = (int) ($candidate['published_deal_nid'] ?? 0);
      $publishedVenueNid = (int) ($candidate['published_venue_nid'] ?? 0);

      if ($publishedDealNid > 0) {
        $deal = $this->entityTypeManager
          ->getStorage('node')
          ->load($publishedDealNid);

        if (!$deal instanceof NodeInterface || $deal->bundle() !== 'deal') {
          throw new \RuntimeException(
            'The candidate records a published deal that can no longer be loaded.',
          );
        }

        if ($publishedVenueNid <= 0 && $deal->hasField('field_venue') && !$deal->get('field_venue')->isEmpty()) {
          $publishedVenueNid = (int) $deal->get('field_venue')->target_id;
        }

        return [
          'venue_nid' => $publishedVenueNid,
          'deal_nid' => $publishedDealNid,
          'venue_created' => FALSE,
          'already_published' => TRUE,
        ];
      }

      if (!in_array((string) ($candidate['status'] ?? ''), ['approved', 'auto_approved'], TRUE)) {
        throw new \RuntimeException(
          'Only approved or auto-approved candidates can be published.',
        );
      }

      // Re-run the exact shadow-publishing contract immediately before writes.
      $preview = $this->previewService->preview($candidate);
      if (empty($preview['ready'])) {
        throw new \RuntimeException(
          'Publishing was refused because the candidate is no longer ready. Reopen Preview publish and resolve the current blockers.',
        );
      }

      $venuePlan = is_array($preview['venue'] ?? NULL) ? $preview['venue'] : [];
      $dealPlan = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $proposed = is_array($dealPlan['proposed_fields'] ?? NULL)
        ? $dealPlan['proposed_fields']
        : [];

      $transaction = $this->database->startTransaction();

      try {
        $venueResult = $this->persistVenue($venuePlan);
        $venue = $venueResult['node'];

        if (!$venue instanceof NodeInterface || $venue->bundle() !== 'venue' || $venue->isNew()) {
          throw new \RuntimeException('Publishing did not produce a persisted venue node.');
        }

        $deal = $this->createDealNode(
          $candidate,
          $proposed,
          (int) $venue->id(),
        );

        $violations = $deal->validate();
        if ($violations->count() > 0) {
          $messages = [];
          foreach ($violations as $violation) {
            $messages[] = (string) $violation->getPropertyPath()
              . ': '
              . (string) $violation->getMessage();
          }

          throw new \RuntimeException(
            'Deal validation failed: ' . implode('; ', $messages),
          );
        }

        $deal->save();
        $this->spanishTranslationCreator->ensureSpanishTranslation($deal);

        $this->storage->markPublished(
          $candidateId,
          (int) $venue->id(),
          (int) $deal->id(),
          $uid,
        );

        return [
          'venue_nid' => (int) $venue->id(),
          'deal_nid' => (int) $deal->id(),
          'venue_created' => (bool) $venueResult['created'],
          'already_published' => FALSE,
        ];
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Persists or reuses the venue described by the preview.
   *
   * @return array{node: \Drupal\node\NodeInterface, created: bool, match_method: ?string}
   */
  private function persistVenue(array $venuePlan): array {
    $action = (string) ($venuePlan['action'] ?? '');

    if ($action === 'reuse_existing') {
      $nid = (int) ($venuePlan['nid'] ?? 0);
      $venue = $nid > 0
        ? $this->entityTypeManager->getStorage('node')->load($nid)
        : NULL;

      if (!$venue instanceof NodeInterface || $venue->bundle() !== 'venue') {
        throw new \RuntimeException(
          'The venue selected by the publishing preview can no longer be loaded.',
        );
      }

      return [
        'node' => $venue,
        'created' => FALSE,
        'match_method' => (string) ($venuePlan['match_method'] ?? '') ?: NULL,
      ];
    }

    if ($action !== 'would_create') {
      throw new \RuntimeException(
        'Publishing cannot continue because the venue is unresolved.',
      );
    }

    $mappedVenue = is_array($venuePlan['mapped_venue'] ?? NULL)
      ? $venuePlan['mapped_venue']
      : [];

    return $this->venuePersistence->persistMappedVenue($mappedVenue);
  }

  /**
   * Creates an unsaved deal node strictly from the preview-approved plan.
   */
  private function createDealNode(
    array $candidate,
    array $proposed,
    int $venueNid,
  ): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $deal = $storage->create([
      'type' => 'deal',
      'langcode' => 'en',
      'status' => 1,
      'title' => (string) ($proposed['title'] ?? ''),
    ]);

    if (!$deal instanceof NodeInterface) {
      throw new \RuntimeException('Unable to construct the deal node.');
    }

    $this->setScalarIfPresent(
      $deal,
      'field_price_offer_text',
      $proposed['field_price_offer_text'] ?? NULL,
    );
    $this->setScalarIfPresent(
      $deal,
      'field_active',
      $proposed['field_active'] ?? NULL,
    );
    $this->setScalarIfPresent(
      $deal,
      'field_recurring',
      $proposed['field_recurring'] ?? NULL,
    );
    $this->setScalarIfPresent(
      $deal,
      'field_start_time',
      $proposed['field_start_time'] ?? NULL,
    );

    if ($deal->hasField('field_venue')) {
      $deal->set('field_venue', ['target_id' => $venueNid]);
    }

    $this->setEntityReferenceIfPresent(
      $deal,
      'field_day_of_week',
      $proposed['field_day_of_week'] ?? NULL,
    );
    $this->setEntityReferenceIfPresent(
      $deal,
      'field_deal_category',
      $proposed['field_deal_category'] ?? NULL,
    );

    $sourceUrl = trim((string) ($candidate['source_url'] ?? ''));
    if ($sourceUrl !== '' && $deal->hasField('field_source_url')) {
      $deal->set('field_source_url', [
        'uri' => $sourceUrl,
        'title' => '',
        'options' => [],
      ]);
    }

    return $deal;
  }

  private function setScalarIfPresent(
    NodeInterface $node,
    string $fieldName,
    mixed $value,
  ): void {
    if (!$node->hasField($fieldName) || $value === NULL || $value === '') {
      return;
    }

    $node->set($fieldName, $value);
  }

  private function setEntityReferenceIfPresent(
    NodeInterface $node,
    string $fieldName,
    mixed $value,
  ): void {
    if (!$node->hasField($fieldName) || !is_array($value) || $value === []) {
      return;
    }

    $items = isset($value['target_id'])
      ? [$value]
      : $value;

    $references = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $tid = (int) ($item['target_id'] ?? 0);
      if ($tid > 0) {
        $references[] = ['target_id' => $tid];
      }
    }

    if ($references !== []) {
      $node->set($fieldName, $references);
    }
  }

}
