<?php

namespace Drupal\spotdeals_revenue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Admin controller for submitted SpotDeals suggestions.
 */
class SuggestionAdminController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter, CsrfTokenGenerator $csrf_token) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->csrfToken = $csrf_token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('csrf_token')
    );
  }

  /**
   * Lists submitted suggestions.
   */
  public function overview(): array {
    $header = [
      $this->t('Submitted'),
      $this->t('Status'),
      $this->t('Type'),
      $this->t('Venue'),
      $this->t('Duplicate'),
      $this->t('Address'),
      $this->t('City/ZIP'),
      $this->t('Deal'),
      $this->t('Website'),
      $this->t('Contact'),
      $this->t('Flags'),
      $this->t('Operations'),
    ];

    $records = $this->database->select('spotdeals_suggestion', 's')
      ->fields('s')
      ->orderBy('status', 'ASC')
      ->orderBy('created', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAllAssoc('id');

    $venue_matches = $this->buildVenueMatchIndex();

    $rows = [];
    foreach ($records as $record) {
      $website = $this->buildWebsiteLink((string) $record->website);
      $deal_location = $this->getSuggestionLocation($record);

      $duplicate = $this->shouldShowDuplicateMatch($record)
        ? $this->getPossibleVenueMatch((string) $record->venue_name, $deal_location, $venue_matches)
        : '';

      $rows[] = [
        $this->dateFormatter->format((int) $record->created, 'short'),
        $this->getStatusLabel((string) $record->status),
        $record->type,
        ['data' => ['#markup' => $this->buildVenueColumn($record)]],
        ['data' => ['#markup' => $duplicate]],
        $this->getSuggestionAddress($record),
        $deal_location,
        [
          'data' => [
            '#markup' => $this->buildDealColumn($record),
          ],
        ],
        ['data' => ['#markup' => $website]],
        $record->email,
        ['data' => ['#markup' => $this->buildSuggestionFlags($record)]],
        ['data' => ['#markup' => $this->buildOperations($record, $venue_matches)]],
      ];
    }

    return [
      'description' => [
        '#markup' => '<p>' . $this->t('Review user-submitted venue and deal suggestions. Approved suggestions can be created directly as SpotDeals venue or deal content after admin review.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No suggestions have been submitted yet.'),
      ],
    ];
  }

  /**
   * Approves a suggestion for manual follow-up.
   */
  public function approve(int $suggestion_id): RedirectResponse {
    return $this->updateStatus($suggestion_id, 'approved', $this->t('Suggestion approved.'));
  }

  /**
   * Marks a suggestion as needing verification before it can be processed.
   */
  public function needsVerification(int $suggestion_id): RedirectResponse {
    return $this->updateStatus($suggestion_id, 'needs_verification', $this->t('Suggestion marked as needing verification.'));
  }

  /**
   * Creates a venue node from an approved suggestion.
   */
  public function createVenue(int $suggestion_id): RedirectResponse {
    $record = $this->loadSuggestion($suggestion_id);
    if (!$record) {
      $this->messenger()->addError($this->t('Suggestion not found.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    if (!in_array((string) $record->type, ['venue', 'both'], TRUE)) {
      $this->messenger()->addError($this->t('This suggestion is not a venue suggestion.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $address = $this->getAddressParts($record);
    $title = $this->buildVenueNodeTitle((string) $record->venue_name, $address['locality']);

    $node = Node::create([
      'type' => 'venue',
      'title' => $title,
      'status' => 1,
      'uid' => (int) $this->currentUser()->id(),
    ]);

    $this->setFieldIfExists($node, 'field_short_description', [
      'value' => $this->buildVenueDescription($record),
      'format' => 'basic_html',
    ]);
    $this->setFieldIfExists($node, 'field_website', [
      'uri' => (string) $record->website,
      'title' => 'Website',
    ]);
    $this->setFieldIfExists($node, 'field_cta', [
      'uri' => (string) $record->website,
      'title' => 'Website',
    ]);
    $this->setFieldIfExists($node, 'field_address', [
      'country_code' => $address['country_code'],
      'address_line1' => $address['address_line1'],
      'locality' => $address['locality'],
      'administrative_area' => $address['administrative_area'],
      'postal_code' => $address['postal_code'],
    ]);
    $this->setReferenceFieldIfExists($node, 'field_venue_type', $this->findTaxonomyTermId('venue_type', ['Restaurant']));
    $this->setReferenceFieldIfExists($node, 'field_cuisine', $this->inferCuisineTermId($record));
    $this->setFieldIfExists($node, 'field_claimed_listing', 0);
    $this->populateVenueCoordinates($node, $address);

    $node->save();
    $this->finalizeCreatedContent($node);
    $this->markPublished($suggestion_id, 'venue', (int) $node->id());

    $this->messenger()->addStatus($this->t('Venue created and published: @title', ['@title' => $title]));
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Creates venue and deal nodes from an approved "both" suggestion.
   */
  public function createVenueAndDeal(int $suggestion_id): RedirectResponse {
    $record = $this->loadSuggestion($suggestion_id);
    if (!$record) {
      $this->messenger()->addError($this->t('Suggestion not found.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    if ((string) $record->type !== 'both') {
      $this->messenger()->addError($this->t('This suggestion is not a venue and deal suggestion.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $address = $this->getAddressParts($record);
    $venue_title = $this->buildVenueNodeTitle((string) $record->venue_name, $address['locality']);

    $venue = Node::create([
      'type' => 'venue',
      'title' => $venue_title,
      'status' => 1,
      'uid' => (int) $this->currentUser()->id(),
    ]);

    $this->setFieldIfExists($venue, 'field_short_description', [
      'value' => $this->buildVenueDescription($record),
      'format' => 'basic_html',
    ]);
    $this->setFieldIfExists($venue, 'field_website', [
      'uri' => (string) $record->website,
      'title' => 'Website',
    ]);
    $this->setFieldIfExists($venue, 'field_cta', [
      'uri' => (string) $record->website,
      'title' => 'Website',
    ]);
    $this->setFieldIfExists($venue, 'field_address', [
      'country_code' => $address['country_code'],
      'address_line1' => $address['address_line1'],
      'locality' => $address['locality'],
      'administrative_area' => $address['administrative_area'],
      'postal_code' => $address['postal_code'],
    ]);
    $this->setReferenceFieldIfExists($venue, 'field_venue_type', $this->findTaxonomyTermId('venue_type', ['Restaurant']));
    $this->setReferenceFieldIfExists($venue, 'field_cuisine', $this->inferCuisineTermId($record));
    $this->setFieldIfExists($venue, 'field_claimed_listing', 0);
    $this->populateVenueCoordinates($venue, $address);

    $venue->save();
    $this->finalizeCreatedContent($venue);

    $deal = $this->createDealNodeForVenue($record, (int) $venue->id(), $venue_title);
    $this->finalizeCreatedContent($deal);

    // Keep the suggestion linked to the created venue because that is the
    // primary content shown in the admin Venue column. The deal is created and
    // attached to that venue in the same operation.
    $this->markPublished($suggestion_id, 'venue', (int) $venue->id());

    $this->messenger()->addStatus($this->t('Venue and deal created and published: @venue / @deal', [
      '@venue' => $venue_title,
      '@deal' => $deal->label(),
    ]));
    return $this->redirect('entity.node.canonical', ['node' => $venue->id()]);
  }

  /**
   * Creates a deal node from an approved suggestion with an existing venue match.
   */
  public function createDeal(int $suggestion_id): RedirectResponse {
    $record = $this->loadSuggestion($suggestion_id);
    if (!$record) {
      $this->messenger()->addError($this->t('Suggestion not found.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    if (!in_array((string) $record->type, ['deal', 'both'], TRUE)) {
      $this->messenger()->addError($this->t('This suggestion is not a deal suggestion.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    if (!empty($record->free_limit_blocked)) {
      $this->messenger()->addError($this->t('This venue has already reached the free suggested-deal limit. The claimed owner must review or add additional deals.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $venue_matches = $this->buildVenueMatchIndex();
    $match = $this->getVenueMatch((string) $record->venue_name, $this->getSuggestionLocation($record), $venue_matches);
    if (!$match) {
      $this->messenger()->addError($this->t('Create or match the venue before creating this deal.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $node = $this->createDealNodeForVenue($record, (int) $match['nid'], $match['title']);
    $this->finalizeCreatedContent($node);
    $this->markPublished($suggestion_id, 'deal', (int) $node->id());

    $this->messenger()->addStatus($this->t('Deal created and published: @title', ['@title' => $node->label()]));
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }


  /**
   * Publishes/indexes content already created from a suggestion.
   */
  public function publish(int $suggestion_id): RedirectResponse {
    $record = $this->loadSuggestion($suggestion_id);
    if (!$record || empty($record->created_entity_id) || (string) $record->created_entity_type !== 'node') {
      $this->messenger()->addError($this->t('No created content was found for this suggestion.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $node = Node::load((int) $record->created_entity_id);
    if (!$node) {
      $this->messenger()->addError($this->t('The created content no longer exists.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    if (!$node->isPublished()) {
      $node->setPublished(TRUE);
      $node->save();
    }

    $this->finalizeCreatedContent($node);
    $this->markPublished($suggestion_id, (string) $node->bundle(), (int) $node->id());

    $this->messenger()->addStatus($this->t('Suggestion published.'));
    return $this->redirect('spotdeals_revenue.suggestions_admin');
  }

  /**
   * Reindexes content already created from a suggestion.
   */
  public function reindex(int $suggestion_id): RedirectResponse {
    $record = $this->loadSuggestion($suggestion_id);
    if (!$record || empty($record->created_entity_id) || (string) $record->created_entity_type !== 'node') {
      $this->messenger()->addError($this->t('No created content was found for this suggestion.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $node = Node::load((int) $record->created_entity_id);
    if (!$node) {
      $this->messenger()->addError($this->t('The created content no longer exists.'));
      return $this->redirect('spotdeals_revenue.suggestions_admin');
    }

    $this->finalizeCreatedContent($node);

    if ($node->bundle() === 'venue') {
      foreach ($this->loadDealsForVenue((int) $node->id()) as $deal) {
        $this->finalizeCreatedContent($deal);
      }
    }

    $this->messenger()->addStatus($this->t('Created content was queued for search indexing.'));
    return $this->redirect('spotdeals_revenue.suggestions_admin');
  }

  /**
   * Rejects a suggestion.
   */
  public function reject(int $suggestion_id): RedirectResponse {
    return $this->updateStatus($suggestion_id, 'rejected', $this->t('Suggestion rejected.'));
  }

  /**
   * Archives a suggestion.
   */
  public function archive(int $suggestion_id): RedirectResponse {
    return $this->updateStatus($suggestion_id, 'archived', $this->t('Suggestion archived.'));
  }

  /**
   * Deletes a suggestion permanently.
   */
  public function delete(int $suggestion_id): RedirectResponse {
    $deleted = $this->database->delete('spotdeals_suggestion')
      ->condition('id', $suggestion_id)
      ->execute();

    if ($deleted) {
      $this->messenger()->addStatus($this->t('Suggestion deleted.'));
    }
    else {
      $this->messenger()->addError($this->t('Suggestion not found.'));
    }

    return $this->redirect('spotdeals_revenue.suggestions_admin');
  }

  /**
   * Gets the admin-facing status label.
   */
  private function getStatusLabel(string $status): string {
    return match ($status) {
      'approved', 'reviewed' => (string) $this->t('approved'),
      'needs_verification' => (string) $this->t('needs verification'),
      'published' => (string) $this->t('published'),
      'added', 'imported' => (string) $this->t('added'),
      'rejected' => (string) $this->t('rejected'),
      'archived' => (string) $this->t('archived'),
      default => (string) $this->t('new'),
    };
  }

  /**
   * Builds the operations markup for a suggestion row.
   */
  private function buildOperations(object $record, array $venue_matches): string {
    $operations = [];

    if ($record->status === 'archived') {
      $operations[] = (string) $this->t('Archived');
    }
    elseif ($record->status === 'published') {
      $operations[] = (string) $this->t('Published');
      $operations[] = $this->buildActionLink('Reindex', 'spotdeals_revenue.suggestion_reindex', (int) $record->id);
      $operations[] = $this->buildActionLink('Archive', 'spotdeals_revenue.suggestion_archive', (int) $record->id);
    }
    elseif (in_array($record->status, ['added', 'imported'], TRUE)) {
      $operations[] = (string) $this->t('Added');
      $operations[] = $this->buildActionLink('Publish', 'spotdeals_revenue.suggestion_publish', (int) $record->id);
      $operations[] = $this->buildActionLink('Archive', 'spotdeals_revenue.suggestion_archive', (int) $record->id);
    }
    elseif ($record->status === 'approved' || $record->status === 'reviewed') {
      // Treat old reviewed rows as approved for backward compatibility.
      $operations[] = (string) $this->t('Approved');
      $has_match = (bool) $this->getVenueMatch((string) $record->venue_name, $this->getSuggestionLocation($record), $venue_matches);
      if ((string) $record->type === 'both' && !$has_match) {
        $operations[] = $this->buildActionLink('Create venue/deal', 'spotdeals_revenue.suggestion_create_venue_deal', (int) $record->id);
      }
      elseif (in_array((string) $record->type, ['venue', 'both'], TRUE) && !$has_match) {
        $operations[] = $this->buildActionLink('Create venue', 'spotdeals_revenue.suggestion_create_venue', (int) $record->id);
      }

      if (in_array((string) $record->type, ['deal', 'both'], TRUE) && $has_match) {
        if (!empty($record->free_limit_blocked)) {
          $operations[] = (string) $this->t('Owner review needed');
          $operations[] = $this->buildActionLink('Needs verification', 'spotdeals_revenue.suggestion_needs_verification', (int) $record->id);
        }
        else {
          $operations[] = $this->buildActionLink('Create deal', 'spotdeals_revenue.suggestion_create_deal', (int) $record->id);
        }
      }
      $operations[] = $this->buildActionLink('Reject', 'spotdeals_revenue.suggestion_reject', (int) $record->id);
      $operations[] = $this->buildActionLink('Archive', 'spotdeals_revenue.suggestion_archive', (int) $record->id);
    }
    elseif ($record->status === 'needs_verification') {
      $operations[] = (string) $this->t('Needs verification');
      $operations[] = $this->buildActionLink('Approve', 'spotdeals_revenue.suggestion_approve', (int) $record->id);
      $operations[] = $this->buildActionLink('Reject', 'spotdeals_revenue.suggestion_reject', (int) $record->id);
      $operations[] = $this->buildActionLink('Archive', 'spotdeals_revenue.suggestion_archive', (int) $record->id);
    }
    elseif ($record->status === 'rejected') {
      $operations[] = (string) $this->t('Rejected');
      $operations[] = $this->buildActionLink('Approve', 'spotdeals_revenue.suggestion_approve', (int) $record->id);
      $operations[] = $this->buildActionLink('Needs verification', 'spotdeals_revenue.suggestion_needs_verification', (int) $record->id);
      $operations[] = $this->buildActionLink('Archive', 'spotdeals_revenue.suggestion_archive', (int) $record->id);
    }
    else {
      $operations[] = $this->buildActionLink('Approve', 'spotdeals_revenue.suggestion_approve', (int) $record->id);
      $operations[] = $this->buildActionLink('Needs verification', 'spotdeals_revenue.suggestion_needs_verification', (int) $record->id);
      $operations[] = $this->buildActionLink('Reject', 'spotdeals_revenue.suggestion_reject', (int) $record->id);
      $operations[] = $this->buildActionLink('Archive', 'spotdeals_revenue.suggestion_archive', (int) $record->id);
    }

    $operations[] = $this->buildActionLink('Delete', 'spotdeals_revenue.suggestion_delete', (int) $record->id);

    return implode(' | ', $operations);
  }

  /**
   * Builds a CSRF-protected admin action link.
   */
  private function buildActionLink(string $label, string $route_name, int $suggestion_id): string {
    $url = Url::fromRoute($route_name, ['suggestion_id' => $suggestion_id]);
    $url->setOption('query', [
      'token' => $this->csrfToken->get($url->getInternalPath()),
    ]);

    return Link::fromTextAndUrl($this->t($label), $url)->toString();
  }


  /**
   * Builds admin flags for a suggestion row.
   */
  private function buildSuggestionFlags(object $record): string {
    $flags = [];

    if (!empty($record->free_limit_blocked)) {
      $flags[] = (string) $this->t('Free deal limit reached');
    }

    if (!empty($record->owner_notified)) {
      $flags[] = (string) $this->t('Owner notified');
    }
    elseif (!empty($record->free_limit_blocked)) {
      $flags[] = (string) $this->t('Owner not notified');
    }

    if (!empty($record->matched_venue_nid)) {
      $flags[] = (string) $this->t('Matched venue #@nid', ['@nid' => (int) $record->matched_venue_nid]);
    }

    return $flags ? implode('<br>', $flags) : '';
  }

  /**
   * Builds the venue column.
   *
   * Once a suggestion creates SpotDeals content, the venue name becomes the
   * direct link to that created node. This keeps the admin table quieter and
   * avoids a separate "Created content" column.
   */
  private function buildVenueColumn(object $record): string {
    $venue_name = htmlspecialchars((string) $record->venue_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if (empty($record->created_entity_id) || (string) $record->created_entity_type !== 'node') {
      return $venue_name;
    }

    return Link::fromTextAndUrl((string) $record->venue_name, Url::fromRoute('entity.node.canonical', ['node' => (int) $record->created_entity_id]))->toString();
  }

  /**
   * Builds the deal column for the admin table.
   */
  private function buildDealColumn(object $record): string {
    $deal_name = trim((string) ($record->deal_name ?? ''));
    $deal_description = trim((string) ($record->deal_description ?? ''));
    $parts = [];

    if ($deal_name !== '') {
      $parts[] = '<strong>' . htmlspecialchars($deal_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
    }

    if ($deal_description !== '') {
      $parts[] = nl2br(htmlspecialchars($deal_description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    return $parts ? implode('<br>', $parts) : '';
  }

  /**
   * Builds the submitted website link for the admin table.
   */
  private function buildWebsiteLink(string $website): string {
    $website = trim($website);
    if ($website === '') {
      return '';
    }

    return Link::fromTextAndUrl($website, Url::fromUri($website, ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]))->toString();
  }

  /**
   * Loads a suggestion record.
   */
  private function loadSuggestion(int $suggestion_id): ?object {
    $record = $this->database->select('spotdeals_suggestion', 's')
      ->fields('s')
      ->condition('id', $suggestion_id)
      ->execute()
      ->fetchObject();

    return $record ?: NULL;
  }

  /**
   * Marks a suggestion as published and stores the created entity reference.
   */
  private function markPublished(int $suggestion_id, string $bundle, int $entity_id): void {
    $this->database->update('spotdeals_suggestion')
      ->fields([
        'status' => 'published',
        'created_entity_type' => 'node',
        'created_entity_bundle' => $bundle,
        'created_entity_id' => $entity_id,
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $suggestion_id)
      ->execute();
  }


  /**
   * Creates and saves a deal node attached to the given venue.
   */
  private function createDealNodeForVenue(object $record, int $venue_nid, string $venue_title): Node {
    $title = $this->buildDealNodeTitle($record, $venue_title);
    $node = Node::create([
      'type' => 'deal',
      'title' => $title,
      'status' => 1,
      'uid' => (int) $this->currentUser()->id(),
    ]);

    $this->setFieldIfExists($node, 'field_price_offer_text', (string) $record->deal_description);
    $this->setFieldIfExists($node, 'field_venue', ['target_id' => $venue_nid]);
    $this->setFieldIfExists($node, 'field_active', 1);
    $this->setFieldIfExists($node, 'field_recurring', 1);
    $this->setReferenceFieldIfExists($node, 'field_deal_category', $this->inferDealCategoryTermId($record));
    $this->setReferenceFieldIfExists($node, 'field_day_of_week', $this->inferDayOfWeekTermId($record));
    $this->setFieldIfExists($node, 'field_start_time', (string) ($record->times ?? ''));
    $this->setFieldIfExists($node, 'body', [
      'value' => (string) $record->deal_description,
      'format' => 'basic_html',
    ]);
    $this->setFieldIfExists($node, 'field_source_url', [
      'uri' => (string) $record->website,
      'title' => 'Source',
    ]);
    $this->setFieldIfExists($node, 'field_cta', [
      'uri' => (string) $record->website,
      'title' => 'View source',
    ]);

    $node->save();
    return $node;
  }


  /**
   * Loads published and unpublished deal nodes attached to a venue.
   *
   * @return \Drupal\node\Entity\Node[]
   *   Deal nodes referencing the provided venue.
   */
  private function loadDealsForVenue(int $venue_nid): array {
    if ($venue_nid <= 0) {
      return [];
    }

    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $nids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'deal')
        ->condition('field_venue.target_id', $venue_nid)
        ->execute();

      if (!$nids) {
        return [];
      }

      $nodes = $storage->loadMultiple($nids);
      return array_filter($nodes, static fn ($node): bool => $node instanceof Node);
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_revenue')->warning('Could not load deals for venue @nid during suggestion reindex: @message', [
        '@nid' => $venue_nid,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Populates venue coordinate fields from the submitted address.
   *
   * Suggested venues need coordinates because the public deals search can use
   * location-aware query processing even for keyword searches. Imported venues
   * already receive these values from venues.csv; created suggestions need the
   * same fields populated before the related deal is indexed.
   */
  private function populateVenueCoordinates(Node $venue, array $address): bool {
    if ($venue->bundle() !== 'venue') {
      return FALSE;
    }

    if (
      $venue->hasField('field_latitude') &&
      !$venue->get('field_latitude')->isEmpty() &&
      $venue->hasField('field_longitude') &&
      !$venue->get('field_longitude')->isEmpty()
    ) {
      $this->setVenueCoordinateFields(
        $venue,
        (string) $venue->get('field_latitude')->value,
        (string) $venue->get('field_longitude')->value
      );
      return TRUE;
    }

    $coordinates = $this->geocodeAddress($address);
    if (!$coordinates) {
      \Drupal::logger('spotdeals_revenue')->warning('Could not geocode suggested venue "@title" from address: @address', [
        '@title' => $venue->label(),
        '@address' => $this->formatAddressForGeocoding($address),
      ]);
      $this->messenger()->addWarning($this->t('The venue was created, but coordinates could not be generated automatically. Add coordinates before expecting it to appear in location-filtered search results.'));
      return FALSE;
    }

    $this->setVenueCoordinateFields($venue, (string) $coordinates['lat'], (string) $coordinates['lon']);

    return TRUE;
  }

  /**
   * Writes all venue coordinate fields used by Search API/Solr.
   */
  private function setVenueCoordinateFields(Node $venue, string $lat, string $lon): void {
    $lat = trim($lat);
    $lon = trim($lon);

    if ($lat === '' || $lon === '') {
      return;
    }

    $this->setFieldIfExists($venue, 'field_latitude', $lat);
    $this->setFieldIfExists($venue, 'field_longitude', $lon);

    if ($venue->hasField('field_coordinates')) {
      // Search API Solr reads the location value from this geofield. Keep the
      // raw value in the same lat,lon form that existing manually-fixed venues
      // and imported geofield records use, while also setting lat/lon columns.
      $venue->set('field_coordinates', [
        'value' => $lat . ',' . $lon,
        'lat' => $lat,
        'lon' => $lon,
      ]);
    }
  }

  /**
   * Geocodes an address using Nominatim first, then the US Census geocoder.
   */
  private function geocodeAddress(array $address): ?array {
    foreach ($this->buildGeocodingAttempts($address) as $attempt) {
      $coordinates = $this->runNominatimGeocodeAttempt($attempt);
      if ($coordinates) {
        return $coordinates;
      }
    }

    foreach ($this->buildCensusGeocodingAttempts($address) as $attempt) {
      $coordinates = $this->runCensusGeocodeAttempt($attempt);
      if ($coordinates) {
        return $coordinates;
      }
    }

    return NULL;
  }

  /**
   * Builds ordered geocoding attempts for the submitted address.
   *
   * @return array<int, array<string, mixed>>
   *   Nominatim query attempts.
   */
  private function buildGeocodingAttempts(array $address): array {
    $street = trim((string) ($address['address_line1'] ?? ''));
    $city = trim((string) ($address['locality'] ?? ''));
    $state = trim((string) ($address['administrative_area'] ?? ''));
    $zip = trim((string) ($address['postal_code'] ?? ''));
    $country = trim((string) ($address['country_code'] ?? 'US')) ?: 'US';

    $street_variants = array_values(array_unique(array_filter([
      $street,
      $this->normalizeStreetForGeocoding($street, FALSE),
      $this->normalizeStreetForGeocoding($street, TRUE),
    ])));

    $attempts = [];
    foreach ($street_variants as $street_variant) {
      $query = implode(', ', array_filter([$street_variant, $city, $state, $zip, $country], static fn ($value): bool => trim((string) $value) !== ''));
      if ($query !== '') {
        $attempts[] = [
          'query' => [
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 1,
            'countrycodes' => strtolower($country),
          ],
          'label' => $query,
        ];
      }

      if ($street_variant !== '' && $city !== '') {
        $attempts[] = [
          'query' => [
            'street' => $street_variant,
            'city' => $city,
            'state' => $state,
            'postalcode' => $zip,
            'country' => $country,
            'format' => 'jsonv2',
            'limit' => 1,
            'countrycodes' => strtolower($country),
          ],
          'label' => implode(', ', array_filter([$street_variant, $city, $state, $zip, $country])),
        ];
      }
    }

    return $attempts;
  }


  /**
   * Builds ordered US Census geocoding attempts for the submitted address.
   *
   * Nominatim can miss valid US addresses with highway abbreviations or suite
   * numbers. The Census endpoint is a strong no-key fallback for US addresses.
   *
   * @return array<int, array<string, string>>
   *   Census geocoding attempts.
   */
  private function buildCensusGeocodingAttempts(array $address): array {
    $country = strtoupper(trim((string) ($address['country_code'] ?? 'US')) ?: 'US');
    if ($country !== 'US') {
      return [];
    }

    $street = trim((string) ($address['address_line1'] ?? ''));
    $city = trim((string) ($address['locality'] ?? ''));
    $state = trim((string) ($address['administrative_area'] ?? ''));
    $zip = trim((string) ($address['postal_code'] ?? ''));

    $street_variants = array_values(array_unique(array_filter([
      $street,
      $this->normalizeStreetForGeocoding($street, FALSE),
      $this->normalizeStreetForGeocoding($street, TRUE),
      $this->normalizeStreetForCensus($street, FALSE),
      $this->normalizeStreetForCensus($street, TRUE),
    ])));

    $attempts = [];
    foreach ($street_variants as $street_variant) {
      $line = implode(', ', array_filter([$street_variant, $city, $state, $zip], static fn ($value): bool => trim((string) $value) !== ''));
      if ($line !== '') {
        $attempts[] = [
          'address' => $line,
          'label' => $line,
        ];
      }
    }

    return $attempts;
  }

  /**
   * Normalizes common street abbreviations that often break geocoding.
   */
  private function normalizeStreetForGeocoding(string $street, bool $remove_unit): string {
    $street = trim($street);
    if ($street === '') {
      return '';
    }

    if ($remove_unit) {
      $street = preg_replace('/\b(?:suite|ste|unit|apt|#)\s*[a-z0-9-]+\b/i', '', $street) ?? $street;
    }

    $replacements = [
      '/\bN\s+US\s*1\b/i' => 'North US Highway 1',
      '/\bS\s+US\s*1\b/i' => 'South US Highway 1',
      '/\bUS\s*1\b/i' => 'US Highway 1',
      '/\bUS-1\b/i' => 'US Highway 1',
      '/\bN\b/i' => 'North',
      '/\bS\b/i' => 'South',
      '/\bE\b/i' => 'East',
      '/\bW\b/i' => 'West',
      '/\bRd\b/i' => 'Road',
      '/\bSt\b/i' => 'Street',
      '/\bAve\b/i' => 'Avenue',
      '/\bBlvd\b/i' => 'Boulevard',
      '/\bDr\b/i' => 'Drive',
      '/\bHwy\b/i' => 'Highway',
    ];

    foreach ($replacements as $pattern => $replacement) {
      $street = preg_replace($pattern, $replacement, $street) ?? $street;
    }

    return trim(preg_replace('/\s+/', ' ', $street) ?? $street);
  }

  /**
   * Normalizes street text for the US Census geocoder.
   */
  private function normalizeStreetForCensus(string $street, bool $remove_unit): string {
    $street = trim($street);
    if ($street === '') {
      return '';
    }

    if ($remove_unit) {
      $street = preg_replace('/\b(?:suite|ste|unit|apt|#)\s*[a-z0-9-]+\b/i', '', $street) ?? $street;
    }

    $replacements = [
      '/\bN\s+US\s*-?\s*1\b/i' => 'N US Highway 1',
      '/\bS\s+US\s*-?\s*1\b/i' => 'S US Highway 1',
      '/\bUS\s*-?\s*1\b/i' => 'US Highway 1',
      '/\bRd\b/i' => 'Road',
      '/\bSt\b/i' => 'Street',
      '/\bAve\b/i' => 'Avenue',
      '/\bBlvd\b/i' => 'Boulevard',
      '/\bDr\b/i' => 'Drive',
      '/\bHwy\b/i' => 'Highway',
    ];

    foreach ($replacements as $pattern => $replacement) {
      $street = preg_replace($pattern, $replacement, $street) ?? $street;
    }

    return trim(preg_replace('/\s+/', ' ', $street) ?? $street);
  }

  /**
   * Executes one Nominatim geocode attempt.
   */
  private function runNominatimGeocodeAttempt(array $attempt): ?array {
    try {
      $response = \Drupal::httpClient()->get('https://nominatim.openstreetmap.org/search', [
        'headers' => [
          'User-Agent' => 'SpotDeals/1.0 (https://spotdeals.app)',
          'Accept' => 'application/json',
        ],
        'query' => $attempt['query'],
        'timeout' => 8,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return NULL;
      }

      $lat = (float) $data[0]['lat'];
      $lon = (float) $data[0]['lon'];

      if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return NULL;
      }

      return [
        'lat' => number_format($lat, 12, '.', ''),
        'lon' => number_format($lon, 12, '.', ''),
      ];
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_revenue')->warning('Nominatim geocoding failed for "@address": @message', [
        '@address' => (string) ($attempt['label'] ?? ''),
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Executes one US Census geocode attempt.
   */
  private function runCensusGeocodeAttempt(array $attempt): ?array {
    try {
      $response = \Drupal::httpClient()->get('https://geocoding.geo.census.gov/geocoder/locations/onelineaddress', [
        'headers' => [
          'User-Agent' => 'SpotDeals/1.0 (https://spotdeals.app)',
          'Accept' => 'application/json',
        ],
        'query' => [
          'address' => (string) ($attempt['address'] ?? ''),
          'benchmark' => 'Public_AR_Current',
          'format' => 'json',
        ],
        'timeout' => 8,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      $coordinates = $data['result']['addressMatches'][0]['coordinates'] ?? NULL;
      if (!is_array($coordinates) || !isset($coordinates['x'], $coordinates['y'])) {
        return NULL;
      }

      $lon = (float) $coordinates['x'];
      $lat = (float) $coordinates['y'];

      if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return NULL;
      }

      return [
        'lat' => number_format($lat, 12, '.', ''),
        'lon' => number_format($lon, 12, '.', ''),
      ];
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_revenue')->warning('US Census geocoding failed for "@address": @message', [
        '@address' => (string) ($attempt['label'] ?? ''),
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Builds a stable address string for geocoding.
   */
  private function formatAddressForGeocoding(array $address): string {
    $parts = [
      $address['address_line1'] ?? '',
      $address['locality'] ?? '',
      $address['administrative_area'] ?? '',
      $address['postal_code'] ?? '',
      $address['country_code'] ?? 'US',
    ];

    $parts = array_filter(array_map(static fn ($part) => trim((string) $part), $parts));
    return implode(', ', $parts);
  }

  /**
   * Finalizes newly-created content for public visibility.
   *
   * This keeps the suggestion workflow self-contained: admins do not need to
   * run Drush after creating content from an approved suggestion. The node is
   * published by createVenue()/createDeal(), relevant cache tags are invalidated,
   * and Search API is asked to index pending items when it is available.
   */
  private function finalizeCreatedContent(Node $node): void {
    \Drupal::service('cache_tags.invalidator')->invalidateTags([
      'node:' . $node->id(),
      'node_list',
      'node_list:' . $node->bundle(),
      'search_api_list:deals_solr',
    ]);

    $this->indexSearchApiContent($node);
  }

  /**
   * Attempts to index Search API content created from a suggestion.
   */
  private function indexSearchApiContent(Node $node): void {
    if (!\Drupal::moduleHandler()->moduleExists('search_api')) {
      return;
    }

    try {
      $index = \Drupal::entityTypeManager()
        ->getStorage('search_api_index')
        ->load('deals_solr');

      if (!$index || !$index->status()) {
        return;
      }

      // Mark the node datasource item as changed when the datasource exists.
      // Search API entity datasource item IDs are usually the entity ID with the
      // language code, but indexing pending items afterwards keeps this safe if
      // the exact item ID format differs between environments.
      if (method_exists($index, 'trackItemsUpdated')) {
        $item_ids = [
          (string) $node->id(),
          $node->id() . ':' . $node->language()->getId(),
        ];
        $index->trackItemsUpdated('entity:node', $item_ids);
      }

      if (method_exists($index, 'indexItems')) {
        $index->indexItems();
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('spotdeals_revenue')->warning('Could not index suggestion-created content @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }


  /**
   * Sets a field value only when that field exists on the node.
   */
  private function setFieldIfExists(Node $node, string $field_name, mixed $value): void {
    if ($node->hasField($field_name)) {
      if (is_array($value) && isset($value['uri']) && trim((string) $value['uri']) === '') {
        return;
      }
      if (is_string($value) && trim($value) === '') {
        return;
      }
      $node->set($field_name, $value);
    }
  }

  /**
   * Sets an entity reference field when both the field and target ID exist.
   */
  private function setReferenceFieldIfExists(Node $node, string $field_name, ?int $target_id): void {
    if ($target_id && $node->hasField($field_name)) {
      $node->set($field_name, ['target_id' => $target_id]);
    }
  }

  /**
   * Infers a deal category term for suggestion-created deal nodes.
   */
  private function inferDealCategoryTermId(object $record): ?int {
    $text = $this->normalizeLocation(implode(' ', [
      (string) ($record->deal_name ?? ''),
      (string) ($record->deal_description ?? ''),
    ]));

    $candidates = [];
    if (str_contains($text, 'happy hour')) {
      $candidates[] = 'Happy Hour';
    }
    if (str_contains($text, 'breakfast')) {
      $candidates[] = 'Breakfast Special';
    }
    if (str_contains($text, 'brunch')) {
      $candidates[] = 'Brunch Special';
    }
    if (str_contains($text, 'lunch')) {
      $candidates[] = 'Lunch Special';
    }
    if (str_contains($text, 'dinner')) {
      $candidates[] = 'Dinner Special';
    }
    if (str_contains($text, 'wine')) {
      $candidates[] = 'Wine Wednesday';
    }
    if (str_contains($text, 'taco')) {
      $candidates[] = 'Taco Tuesday';
    }

    $candidates[] = 'Daily Special';
    $candidates[] = 'Special';

    return $this->findTaxonomyTermId('deal_category', $candidates) ?? $this->findFirstTaxonomyTermId('deal_category');
  }

  /**
   * Infers a day-of-week term for suggestion-created deal nodes.
   */
  private function inferDayOfWeekTermId(object $record): ?int {
    $days = $this->normalizeLocation((string) ($record->days ?? ''));
    $candidates = [];

    if ($days !== '') {
      $day_map = [
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
      ];
      foreach ($day_map as $needle => $label) {
        if (str_contains($days, $needle)) {
          $candidates[] = $label;
        }
      }
      if (str_contains($days, 'daily') || str_contains($days, 'every day') || str_contains($days, 'all week')) {
        $candidates[] = 'Daily';
      }
      if (str_contains($days, 'weekend')) {
        $candidates[] = 'Saturday';
        $candidates[] = 'Sunday';
      }
    }

    $candidates[] = 'Daily';
    $candidates[] = 'Monday to Friday';
    $candidates[] = 'Monday';

    return $this->findTaxonomyTermId('day_of_week', $candidates) ?? $this->findFirstTaxonomyTermId('day_of_week');
  }

  /**
   * Infers a cuisine term for suggestion-created venue nodes.
   */
  private function inferCuisineTermId(object $record): ?int {
    $text = $this->normalizeLocation(implode(' ', [
      (string) ($record->venue_name ?? ''),
      (string) ($record->deal_name ?? ''),
      (string) ($record->deal_description ?? ''),
      (string) ($record->notes ?? ''),
    ]));

    $map = [
      'coffee' => ['Coffee', 'Cafe', 'Cafes'],
      'cafe' => ['Coffee', 'Cafe', 'Cafes'],
      'pizza' => ['Pizza'],
      'taco' => ['Mexican', 'Tex-Mex'],
      'mexican' => ['Mexican'],
      'sushi' => ['Japanese', 'Sushi'],
      'seafood' => ['Seafood'],
      'burger' => ['American', 'Burger', 'Burgers'],
      'bbq' => ['BBQ', 'Barbecue'],
      'breakfast' => ['Breakfast', 'Breakfast and Brunch'],
      'brunch' => ['Breakfast and Brunch', 'Brunch'],
      'italian' => ['Italian'],
      'thai' => ['Thai'],
      'indian' => ['Indian'],
      'cuban' => ['Cuban'],
      'mediterranean' => ['Mediterranean'],
    ];

    foreach ($map as $needle => $candidates) {
      if (str_contains($text, $needle)) {
        $tid = $this->findTaxonomyTermId('cuisine', $candidates);
        if ($tid) {
          return $tid;
        }
      }
    }

    return NULL;
  }

  /**
   * Finds a taxonomy term ID by vocabulary and possible names.
   */
  private function findTaxonomyTermId(string $vocabulary, array $names): ?int {
    if (!\Drupal::moduleHandler()->moduleExists('taxonomy')) {
      return NULL;
    }

    $normalized_names = array_map(fn(string $name): string => $this->normalizeLocation($name), $names);

    $query = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name'])
      ->condition('vid', $vocabulary)
      ->condition('default_langcode', 1);
    $records = $query->execute()->fetchAll();

    foreach ($records as $record) {
      if (in_array($this->normalizeLocation((string) $record->name), $normalized_names, TRUE)) {
        return (int) $record->tid;
      }
    }

    return NULL;
  }

  /**
   * Finds the first available taxonomy term ID in a vocabulary.
   */
  private function findFirstTaxonomyTermId(string $vocabulary): ?int {
    if (!\Drupal::moduleHandler()->moduleExists('taxonomy')) {
      return NULL;
    }

    $tid = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid'])
      ->condition('vid', $vocabulary)
      ->condition('default_langcode', 1)
      ->orderBy('weight', 'ASC')
      ->orderBy('name', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $tid ? (int) $tid : NULL;
  }


  /**
   * Gets a display address from structured or legacy suggestion fields.
   */
  private function getSuggestionAddress(object $record): string {
    $street = trim((string) ($record->street_address ?? ''));
    $city = trim((string) ($record->city ?? ''));
    $state = trim((string) ($record->state ?? ''));
    $zip = trim((string) ($record->zip ?? ''));
    $country = trim((string) ($record->country ?? ''));

    if ($street !== '' || $city !== '' || $state !== '' || $zip !== '' || $country !== '') {
      $state_zip = trim($state . ' ' . $zip);
      return implode(', ', array_filter([$street, $city, $state_zip, $country], static fn(string $value): bool => $value !== ''));
    }

    return trim((string) ($record->address ?? ''));
  }

  /**
   * Gets location text used for safe duplicate matching.
   */
  private function getSuggestionLocation(object $record): string {
    $city = trim((string) ($record->city ?? ''));
    $zip = trim((string) ($record->zip ?? ''));

    if ($city !== '' || $zip !== '') {
      return trim($city . ' ' . $zip);
    }

    return trim((string) ($record->deal_location ?? ''));
  }

  /**
   * Builds address field parts from structured fields, with legacy fallback.
   */
  private function getAddressParts(object $record): array {
    $street = trim((string) ($record->street_address ?? ''));
    $city = trim((string) ($record->city ?? ''));
    $state = trim((string) ($record->state ?? ''));
    $zip = trim((string) ($record->zip ?? ''));
    $country = strtoupper(trim((string) ($record->country ?? 'US')));

    if ($street !== '' || $city !== '' || $state !== '' || $zip !== '') {
      return [
        'country_code' => $country !== '' ? $country : 'US',
        'address_line1' => $street,
        'locality' => $city,
        'administrative_area' => $state,
        'postal_code' => $zip,
      ];
    }

    $legacy = $this->parseAddress((string) ($record->address ?? ''));
    $legacy['country_code'] = 'US';
    return $legacy;
  }

  /**
   * Builds a venue title using the SpotDeals venue-name convention.
   */
  private function buildVenueNodeTitle(string $venue_name, string $city): string {
    $venue_name = trim($venue_name);
    $city = trim($city);

    if ($city !== '' && !str_contains($venue_name, ' - ')) {
      return $venue_name . ' - ' . $city;
    }

    return $venue_name;
  }

  /**
   * Builds the initial venue description from the suggestion.
   */
  private function buildVenueDescription(object $record): string {
    $parts = [];
    if (!empty($record->notes)) {
      $parts[] = trim((string) $record->notes);
    }
    if (!empty($record->deal_description)) {
      $parts[] = 'Suggested deal details: ' . trim((string) $record->deal_description);
    }

    return implode("\n\n", $parts);
  }

  /**
   * Builds a deal title from suggestion data.
   */
  private function buildDealNodeTitle(object $record, string $venue_title): string {
    $deal_name = trim((string) ($record->deal_name ?? ''));
    if ($deal_name !== '') {
      $deal_name = preg_replace('/\s+/', ' ', $deal_name) ?? $deal_name;
      return mb_substr($deal_name, 0, 120, 'UTF-8');
    }

    $deal = trim((string) $record->deal_description);
    if ($deal !== '') {
      $deal = preg_replace('/\s+/', ' ', $deal) ?? $deal;
      $deal = mb_substr($deal, 0, 80, 'UTF-8');
      return $deal;
    }

    return $venue_title . ' Deal';
  }

  /**
   * Parses a simple submitted address into address field parts.
   */
  private function parseAddress(string $address): array {
    $parts = array_map('trim', explode(',', $address));
    $street = $parts[0] ?? '';
    $city = $parts[1] ?? '';
    $state_zip = $parts[2] ?? '';
    $state = '';
    $zip = '';

    if (preg_match('/^([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/', trim($state_zip), $matches)) {
      $state = $matches[1];
      $zip = $matches[2];
    }
    elseif (preg_match('/\b([A-Z]{2})\b/', trim($state_zip), $matches)) {
      $state = $matches[1];
    }

    return [
      'address_line1' => $street,
      'locality' => $city,
      'administrative_area' => $state,
      'postal_code' => $zip,
    ];
  }

  /**
   * Updates a suggestion status.
   */
  private function updateStatus(int $suggestion_id, string $status, string $success_message): RedirectResponse {
    $now = \Drupal::time()->getRequestTime();

    $updated = $this->database->update('spotdeals_suggestion')
      ->fields([
        'status' => $status,
        'changed' => $now,
      ])
      ->condition('id', $suggestion_id)
      ->execute();

    if ($updated) {
      $this->messenger()->addStatus($success_message);
    }
    else {
      $this->messenger()->addError($this->t('Suggestion not found.'));
    }

    return $this->redirect('spotdeals_revenue.suggestions_admin');
  }

  /**
   * Builds a normalized index of existing venue titles.
   *
   * Venue titles often include a trailing location suffix, such as
   * "Venue Name - Orlando". Suggestions are indexed by base venue name, but
   * the actual match also considers the submitted city or ZIP so admins do not
   * accidentally attach a deal to a similarly named venue in another city.
   *
   * @return array<string, list<array{nid:int,title:string}>>
   *   Existing venues keyed by normalized base title.
   */
  private function buildVenueMatchIndex(): array {
    $venues = [];

    if (!$this->database->schema()->tableExists('node_field_data')) {
      return $venues;
    }

    $records = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title'])
      ->condition('type', 'venue')
      ->condition('status', 1)
      ->condition('default_langcode', 1)
      ->execute();

    foreach ($records as $record) {
      $normalized = $this->normalizeVenueTitle((string) $record->title);
      if ($normalized !== '') {
        $venues[$normalized][] = [
          'nid' => (int) $record->nid,
          'title' => (string) $record->title,
        ];
      }
    }

    return $venues;
  }


  /**
   * Determines whether duplicate-match information is useful for a row.
   *
   * The Duplicate column is only for detecting possible duplicate venue
   * submissions. Deal-only suggestions can legitimately reference an existing
   * venue, so showing that matched venue in the Duplicate column is confusing.
   */
  private function shouldShowDuplicateMatch(object $record): bool {
    if (!empty($record->created_entity_id)) {
      return FALSE;
    }

    if (!in_array((string) $record->type, ['venue', 'both'], TRUE)) {
      return FALSE;
    }

    return !in_array((string) $record->status, ['added', 'imported', 'published'], TRUE);
  }

  /**
   * Gets a possible venue match for a submitted venue name and location.
   */
  private function getPossibleVenueMatch(string $venue_name, string $location, array $venue_matches): string {
    $match = $this->getVenueMatch($venue_name, $location, $venue_matches);

    if ($match) {
      return Link::fromTextAndUrl($match['title'], Url::fromRoute('entity.node.canonical', ['node' => $match['nid']]))->toString();
    }

    $normalized = $this->normalizeVenueTitle($venue_name);
    if ($normalized !== '' && !empty($venue_matches[$normalized]) && count($venue_matches[$normalized]) > 1 && trim($location) === '') {
      return (string) $this->t('Multiple possible matches. City or ZIP needed.');
    }

    return (string) $this->t('No obvious match');
  }

  /**
   * Gets a normalized venue match array for a submitted venue name and location.
   *
   * @return array{nid:int,title:string}|null
   *   The matching venue data, or NULL if no safe match exists.
   */
  private function getVenueMatch(string $venue_name, string $location, array $venue_matches): ?array {
    $normalized = $this->normalizeVenueTitle($venue_name);

    if ($normalized === '' || empty($venue_matches[$normalized])) {
      return NULL;
    }

    $candidates = $venue_matches[$normalized];
    $normalized_location = $this->normalizeLocation($location);

    if ($normalized_location !== '') {
      foreach ($candidates as $candidate) {
        $venue = Node::load((int) $candidate['nid']);
        if ($venue && $this->venueMatchesLocation($venue, $normalized_location)) {
          return $candidate;
        }
      }

      // Location was supplied but none of the same-name venues matched it.
      // Do not guess between similarly named venues in different cities.
      return NULL;
    }

    // Preserve the old behavior only when there is exactly one same-name venue.
    return count($candidates) === 1 ? reset($candidates) : NULL;
  }

  /**
   * Checks whether a venue node matches a normalized city or ZIP value.
   */
  private function venueMatchesLocation(\Drupal\node\NodeInterface $venue, string $normalized_location): bool {
    $parts = [$venue->label()];

    if ($venue->hasField('field_address') && !$venue->get('field_address')->isEmpty()) {
      foreach ($venue->get('field_address') as $address) {
        foreach (['locality', 'postal_code', 'administrative_area', 'address_line1'] as $property) {
          $value = trim((string) ($address->{$property} ?? ''));
          if ($value !== '') {
            $parts[] = $value;
          }
        }
      }
    }

    $haystack = $this->normalizeLocation(implode(' ', $parts));
    return $haystack !== '' && str_contains($haystack, $normalized_location);
  }

  /**
   * Normalizes a city or ZIP value for location matching.
   */
  private function normalizeLocation(string $location): string {
    $location = html_entity_decode($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $location = mb_strtolower(trim($location), 'UTF-8');
    $location = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $location) ?? $location;
    return trim(preg_replace('/\s+/u', ' ', $location) ?? $location);
  }

  /**
   * Normalizes a venue title for duplicate checks.
   */
  private function normalizeVenueTitle(string $title): string {
    $title = html_entity_decode($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = trim($title);

    // Remove one trailing location suffix, for example " - Orlando".
    $title = preg_replace('/\s+-\s+[^-]+$/u', '', $title) ?? $title;

    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

    $tokens = $title === '' ? [] : preg_split('/\s+/u', $title);
    $weak_suffixes = [
      'bar',
      'bistro',
      'cafe',
      'café',
      'cantina',
      'cuisine',
      'diner',
      'grill',
      'kitchen',
      'pub',
      'restaurant',
      'restaurants',
      'tavern',
    ];

    // Venue suggestions commonly omit generic trailing words from official
    // names, for example "Istanbul Turkish Mediterranean" vs.
    // "Istanbul Turkish Mediterranean Cuisine - Daytona Beach Shores". Remove
    // only weak trailing descriptors and only when enough distinctive words
    // remain, so names like "Cafe Don Juan" are not collapsed too aggressively.
    while (count($tokens) > 2 && in_array(end($tokens), $weak_suffixes, TRUE)) {
      array_pop($tokens);
    }

    return trim(implode(' ', $tokens));
  }

}
