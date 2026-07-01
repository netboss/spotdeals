<?php

namespace Drupal\spotdeals_import\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Preserves manually edited or user-voted imported venue/deal nodes.
 */
final class PreserveEditedImportedNodesSubscriber implements EventSubscriberInterface {

  /**
   * Migration IDs protected by this subscriber.
   */
  private const PROTECTED_MIGRATIONS = [
    'spotdeals_venues' => 'venue',
    'spotdeals_deals' => 'deal',
  ];

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_ROLLBACK => 'onPreRollback',
      MigrateEvents::PRE_ROW_SAVE => 'onPreRowSave',
      MigrateEvents::PRE_ROW_DELETE => 'onPreRowDelete',
    ];
  }

  /**
   * Marks manually edited or user-voted imported nodes as preserve.
   */
  public function onPreRollback(MigrateRollbackEvent $event): void {
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    if (!$this->isProtectedMigration($migration_id)) {
      return;
    }

    $map_table = $this->getMapTableName($migration_id);

    if (!$this->database->schema()->tableExists($map_table)) {
      return;
    }

    $query = $this->database->select($map_table, 'map');
    $query->fields('map');
    $query->condition('map.destid1', NULL, 'IS NOT NULL');

    $rows = $query->execute();

    $preserved_count = 0;
    $edited_count = 0;
    $voted_count = 0;

    foreach ($rows as $row) {
      $nid = isset($row->destid1) && is_numeric($row->destid1) ? (int) $row->destid1 : NULL;

      if (!$nid) {
        continue;
      }

      $is_edited = $this->isEditedProtectedNode($nid, $migration_id);
      $has_votes = $this->hasProtectedVotes($nid, $migration_id);

      if (!$is_edited && !$has_votes) {
        continue;
      }

      $this->database->update($map_table)
        ->fields([
          'rollback_action' => MigrateIdMapInterface::ROLLBACK_PRESERVE,
        ])
        ->condition('source_ids_hash', $row->source_ids_hash)
        ->execute();

      $preserved_count++;

      if ($is_edited) {
        $edited_count++;
      }

      if ($has_votes) {
        $voted_count++;
      }
    }

    if ($preserved_count > 0) {
      $this->logger->notice(
        'Marked @count @type migration rows as rollback preserve before rolling back @migration. Edited: @edited. With votes: @voted.',
        [
          '@count' => $preserved_count,
          '@type' => self::PROTECTED_MIGRATIONS[$migration_id],
          '@migration' => $migration_id,
          '@edited' => $edited_count,
          '@voted' => $voted_count,
        ]
      );
    }
  }

  /**
   * Maps protected existing nodes back to their source rows without exceptions.
   */
  public function onPreRowSave(MigratePreRowSaveEvent $event): void {
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    if (!$this->isProtectedMigration($migration_id)) {
      return;
    }

    $row = $event->getRow();
    $destination_ids = $migration->getIdMap()->lookupDestinationIds($row->getSourceIdValues());

    if (!empty($destination_ids)) {
      foreach ($destination_ids as $destination_id_values) {
        $nid = $this->extractNodeId($destination_id_values);

        if (!$nid || !$this->isProtectedNode($nid, $migration_id)) {
          continue;
        }

        $this->mapRowToExistingProtectedNode($row, $nid, $migration_id);

        $this->logger->notice(
          'Mapped migration @migration source row to existing protected @type node @nid.',
          [
            '@migration' => $migration_id,
            '@type' => self::PROTECTED_MIGRATIONS[$migration_id],
            '@nid' => $nid,
          ]
        );

        return;
      }

      return;
    }

    $existing_nid = $this->findExistingProtectedNodeForRow($migration_id, $row);

    if (!$existing_nid) {
      return;
    }

    $this->mapRowToExistingProtectedNode($row, $existing_nid, $migration_id);

    $migration->getIdMap()->saveIdMapping(
      $row,
      ['nid' => $existing_nid],
      MigrateIdMapInterface::STATUS_IMPORTED,
      MigrateIdMapInterface::ROLLBACK_PRESERVE
    );

    $this->logger->notice(
      'Relinked migration @migration source row to existing protected @type node @nid without creating a duplicate.',
      [
        '@migration' => $migration_id,
        '@type' => self::PROTECTED_MIGRATIONS[$migration_id],
        '@nid' => $existing_nid,
      ]
    );
  }

  /**
   * Logs protected rollback rows without throwing.
   */
  public function onPreRowDelete(MigrateRowDeleteEvent $event): void {
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    if (!$this->isProtectedMigration($migration_id)) {
      return;
    }

    $nid = $this->extractNodeId($event->getDestinationIdValues());

    if (!$nid || !$this->isProtectedNode($nid, $migration_id)) {
      return;
    }

    $this->logger->warning(
      'Protected @type node @nid reached PRE_ROW_DELETE during @migration rollback. The row should have been marked rollback preserve before deletion.',
      [
        '@type' => self::PROTECTED_MIGRATIONS[$migration_id],
        '@nid' => $nid,
        '@migration' => $migration_id,
      ]
    );
  }

  /**
   * Checks whether this migration should be protected.
   */
  private function isProtectedMigration(string $migration_id): bool {
    return isset(self::PROTECTED_MIGRATIONS[$migration_id]);
  }

  /**
   * Gets the migrate map table name for a migration ID.
   */
  private function getMapTableName(string $migration_id): string {
    return 'migrate_map_' . $migration_id;
  }

  /**
   * Extracts a node ID from destination ID values.
   */
  private function extractNodeId(array $destination_id_values): ?int {
    $nid = reset($destination_id_values);

    if (is_array($nid)) {
      $nid = reset($nid);
    }

    return is_numeric($nid) ? (int) $nid : NULL;
  }

  /**
   * Checks whether a destination node is protected from rollback/import.
   */
  private function isProtectedNode(int $nid, string $migration_id): bool {
    return $this->isEditedProtectedNode($nid, $migration_id)
      || $this->hasProtectedVotes($nid, $migration_id);
  }

  /**
   * Checks whether a destination node was manually edited after creation.
   */
  private function isEditedProtectedNode(int $nid, string $migration_id): bool {
    $node = $this->loadProtectedNode($nid, $migration_id);

    if (!$node) {
      return FALSE;
    }

    return (int) $node->getChangedTime() !== (int) $node->getCreatedTime();
  }

  /**
   * Loads a node only when it belongs to the protected migration bundle.
   */
  private function loadProtectedNode(int $nid, string $migration_id): ?NodeInterface {
    $node = $this->entityTypeManager
      ->getStorage('node')
      ->load($nid);

    if (!$node instanceof NodeInterface || $node->bundle() !== self::PROTECTED_MIGRATIONS[$migration_id]) {
      return NULL;
    }

    return $node;
  }

  /**
   * Maps a row to an existing protected node and preserves current node values.
   */
  private function mapRowToExistingProtectedNode(Row $row, int $nid, string $migration_id): void {
    $node = $this->loadProtectedNode($nid, $migration_id);

    if (!$node) {
      return;
    }

    $row->setDestinationProperty('nid', $nid);
    $row->setDestinationProperty('title', $node->label());

    if ($migration_id === 'spotdeals_venues') {
      $this->preserveVenueDestinationProperties($row, $node);
      return;
    }

    if ($migration_id === 'spotdeals_deals') {
      $this->preserveDealDestinationProperties($row, $node);
    }
  }

  /**
   * Preserves current venue field values during protected relink.
   */
  private function preserveVenueDestinationProperties(Row $row, NodeInterface $node): void {
    $this->setFieldDestinationValue($row, $node, 'field_phone');
    $this->setFieldDestinationValue($row, $node, 'field_short_description');
    $this->setFieldDestinationValue($row, $node, 'field_website');
    $this->setFieldDestinationValue($row, $node, 'field_menu_url');
    $this->setFieldDestinationValue($row, $node, 'field_cta');
    $this->setFieldDestinationValue($row, $node, 'field_claimed_listing');
    $this->setFieldDestinationValue($row, $node, 'field_address');
    $this->setFieldDestinationValue($row, $node, 'field_latitude');
    $this->setFieldDestinationValue($row, $node, 'field_longitude');
    $this->setFieldDestinationValue($row, $node, 'field_coordinates');
    $this->setFieldDestinationValue($row, $node, 'field_venue_type');
    $this->setFieldDestinationValue($row, $node, 'field_cuisine');
    $this->setFieldDestinationValue($row, $node, 'field_tags');
  }

  /**
   * Preserves current deal field values during protected relink.
   */
  private function preserveDealDestinationProperties(Row $row, NodeInterface $node): void {
    $this->setFieldDestinationValue($row, $node, 'field_price_offer_text');
    $this->setFieldDestinationValue($row, $node, 'field_start_time');
    $this->setFieldDestinationValue($row, $node, 'field_active');
    $this->setFieldDestinationValue($row, $node, 'field_recurring');
    $this->setFieldDestinationValue($row, $node, 'field_day_of_week');
    $this->setFieldDestinationValue($row, $node, 'field_deal_category');
    $this->setFieldDestinationValue($row, $node, 'field_cta');
    $this->setFieldDestinationValue($row, $node, 'field_venue');
  }

  /**
   * Copies the current node field value into the migrate destination row.
   */
  private function setFieldDestinationValue(Row $row, NodeInterface $node, string $field_name): void {
    if (!$node->hasField($field_name)) {
      return;
    }

    $row->setDestinationProperty($field_name, $node->get($field_name)->getValue());
  }

  /**
   * Checks whether a destination node has votes that must survive rollback.
   */
  private function hasProtectedVotes(int $nid, string $migration_id): bool {
    if ($migration_id === 'spotdeals_deals') {
      return $this->tableHasMatchingRow('spotdeals_vote', 'deal_nid', $nid);
    }

    if ($migration_id === 'spotdeals_venues') {
      return $this->tableHasMatchingRow('spotdeals_vote_venue', 'venue_nid', $nid)
        || $this->tableHasMatchingRow('spotdeals_vote', 'venue_nid', $nid);
    }

    return FALSE;
  }

  /**
   * Checks whether a table has at least one matching row.
   */
  private function tableHasMatchingRow(string $table, string $field, int $value): bool {
    if (!$this->database->schema()->tableExists($table)) {
      return FALSE;
    }

    $query = $this->database->select($table, 't');
    $query->addExpression('1');
    $query->condition($field, $value);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Finds an existing protected node matching the current source row.
   */
  private function findExistingProtectedNodeForRow(string $migration_id, Row $row): ?int {
    if ($migration_id === 'spotdeals_venues') {
      $title = trim((string) $row->getSourceProperty('title'));
      $address = trim((string) $row->getSourceProperty('field_address_address_line1'));
      $city = trim((string) $row->getSourceProperty('field_address_locality'));
      $state = trim((string) $row->getSourceProperty('field_address_administrative_area'));
      $zip = trim((string) $row->getSourceProperty('field_address_postal_code'));

      return $this->findExistingProtectedVenueNode($title, $address, $city, $state, $zip);
    }

    if ($migration_id === 'spotdeals_deals') {
      $title = trim((string) $row->getSourceProperty('title'));
      $venue_title = trim((string) $row->getSourceProperty('field_venue'));
      $start_time = trim((string) $row->getSourceProperty('field_start_time'));

      return $this->findExistingProtectedDealNode($title, $venue_title, $start_time);
    }

    return NULL;
  }

  /**
   * Finds an existing protected venue node by source identity.
   */
  private function findExistingProtectedVenueNode(string $title, string $address = '', string $city = '', string $state = '', string $zip = ''): ?int {
    if ($title === '') {
      return NULL;
    }

    $exact_nid = $this->findExistingProtectedVenueNodeByExactTitle($title);
    if ($exact_nid) {
      return $exact_nid;
    }

    return $this->findExistingProtectedVenueNodeByCanonicalIdentity($title, $address, $city, $state, $zip);
  }

  /**
   * Finds an existing protected venue node by exact title.
   */
  private function findExistingProtectedVenueNodeByExactTitle(string $title): ?int {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('title', $title)
      ->sort('nid', 'ASC')
      ->range(0, 10)
      ->execute();

    foreach ($nids as $nid) {
      $nid = (int) $nid;

      if ($this->isProtectedNode($nid, 'spotdeals_venues')) {
        return $nid;
      }
    }

    return NULL;
  }

  /**
   * Finds an existing protected venue node by canonical title/address identity.
   */
  private function findExistingProtectedVenueNodeByCanonicalIdentity(string $title, string $address, string $city, string $state, string $zip): ?int {
    $source_brand_key = $this->normalizeVenueBrandKey($title, $city);
    $source_city_key = $this->normalizeStrictKey($city);
    $source_address_key = $this->normalizeAddressKey($address, $city, $state, $zip);

    if ($source_brand_key === '') {
      return NULL;
    }

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->sort('nid', 'ASC');

    $nids = $query->execute();
    if (empty($nids)) {
      return NULL;
    }

    $best_nid = NULL;
    $best_score = 0;
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $nid = (int) $node->id();
      if (!$this->isProtectedNode($nid, 'spotdeals_venues')) {
        continue;
      }

      $node_address = $this->getNodeAddressParts($node);
      $node_city = $node_address['city'];
      $node_brand_key = $this->normalizeVenueBrandKey($node->label(), $node_city);

      if ($node_brand_key !== $source_brand_key) {
        continue;
      }

      $score = 10;
      if ($source_city_key !== '' && $this->normalizeStrictKey($node_city) === $source_city_key) {
        $score += 20;
      }
      elseif ($source_city_key !== '') {
        continue;
      }

      if ($source_address_key !== '') {
        $node_address_key = $this->normalizeAddressKey(
          $node_address['address'],
          $node_address['city'],
          $node_address['state'],
          $node_address['zip']
        );

        if ($node_address_key === $source_address_key) {
          $score += 50;
        }
      }

      if ($score > $best_score) {
        $best_score = $score;
        $best_nid = $nid;
      }
    }

    return $best_nid;
  }

  /**
   * Finds an existing protected deal node by source identity.
   */
  private function findExistingProtectedDealNode(string $title, string $venue_title, string $start_time): ?int {
    if ($title === '') {
      return NULL;
    }

    $venue_nid = $this->findExistingVenueNodeByTitle($venue_title);

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'deal')
      ->condition('title', $title)
      ->sort('nid', 'ASC')
      ->range(0, 20);

    if ($venue_nid) {
      $query->condition('field_venue.target_id', $venue_nid);
    }

    if ($start_time !== '') {
      $query->condition('field_start_time', $start_time);
    }

    $nids = $query->execute();

    foreach ($nids as $nid) {
      $nid = (int) $nid;

      if ($this->isProtectedNode($nid, 'spotdeals_deals')) {
        return $nid;
      }
    }

    return NULL;
  }

  /**
   * Finds a venue node by title.
   */
  private function findExistingVenueNodeByTitle(string $title): ?int {
    if ($title === '') {
      return NULL;
    }

    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('title', $title)
      ->sort('nid', 'ASC')
      ->range(0, 1)
      ->execute();

    if (!empty($nids)) {
      return (int) reset($nids);
    }

    return $this->findExistingProtectedVenueNode($title);
  }

  /**
   * Extracts address field values from a venue node.
   *
   * @return array{address:string,city:string,state:string,zip:string}
   *   Normalized address parts.
   */
  private function getNodeAddressParts(NodeInterface $node): array {
    $parts = [
      'address' => '',
      'city' => '',
      'state' => '',
      'zip' => '',
    ];

    if (!$node->hasField('field_address') || $node->get('field_address')->isEmpty()) {
      return $parts;
    }

    $value = $node->get('field_address')->first()?->getValue() ?? [];
    $parts['address'] = trim((string) ($value['address_line1'] ?? ''));
    $parts['city'] = trim((string) ($value['locality'] ?? ''));
    $parts['state'] = trim((string) ($value['administrative_area'] ?? ''));
    $parts['zip'] = trim((string) ($value['postal_code'] ?? ''));

    return $parts;
  }

  /**
   * Normalizes a value for identity comparison.
   */
  private function normalizeStrictKey(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== false) {
      $value = $transliterated;
    }

    $value = mb_strtolower($value);
    $value = str_replace(['&', '+'], ' and ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);

    return trim((string) $value);
  }

  /**
   * Normalizes a venue title to a business-root key for duplicate protection.
   */
  private function normalizeVenueBrandKey(string $title, string $city): string {
    $value = $this->normalizeStrictKey($title);
    $city_key = $this->normalizeStrictKey($city);

    if ($value === '') {
      return '';
    }

    if ($city_key !== '') {
      $value = preg_replace('/(?:^| )' . preg_quote($city_key, '/') . '$/', '', $value);
    }

    $tokens = preg_split('/\s+/', trim((string) $value));
    if ($tokens === false || empty($tokens)) {
      return '';
    }

    $city_tokens = $city_key !== '' ? preg_split('/\s+/', $city_key) : [];
    if ($city_tokens === false) {
      $city_tokens = [];
    }

    $location_tokens = array_merge($city_tokens, ['nsb']);
    while (!empty($tokens) && in_array(end($tokens), $location_tokens, true)) {
      array_pop($tokens);
    }

    if (($tokens[0] ?? '') === 'the') {
      array_shift($tokens);
    }

    $suffix_tokens = [
      'restaurant', 'restaurants',
      'cafe', 'cafes', 'caf',
      'shop', 'shops',
      'ice', 'cream',
      'oceanfront',
      'mexican',
      'bar', 'bars',
      'grill', 'grille',
      'kitchen',
      'pizzeria', 'pizza',
      'pub', 'tavern',
      'bistro',
      'company', 'co',
      'place',
      'bakery', 'bakehouse',
      'cantina',
      'diner',
      'eatery',
      'food', 'foods',
      'cuisine',
      'lounge',
      'sports',
      'waterfront',
    ];

    while (!empty($tokens) && in_array(end($tokens), $suffix_tokens, true)) {
      array_pop($tokens);
    }

    while (!empty($tokens) && in_array(end($tokens), $location_tokens, true)) {
      array_pop($tokens);
    }

    if (($tokens[0] ?? '') === 'the') {
      array_shift($tokens);
    }

    $brand_key = implode(' ', array_values(array_filter($tokens)));

    if (mb_strlen(str_replace(' ', '', $brand_key)) < 5) {
      return '';
    }

    return $brand_key;
  }

  /**
   * Builds a normalized address key.
   */
  private function normalizeAddressKey(string $address, string $city, string $state, string $zip): string {
    $value = $this->normalizeStrictKey($address) . '|' . $this->normalizeStrictKey($city) . '|' . $this->normalizeStrictKey($state) . '|' . $this->normalizeStrictKey($zip);
    return trim($value, '|');
  }

}
