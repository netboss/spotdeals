<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\node\Entity\Node;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyCategoryCatalog;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryService;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyClient;
use Drupal\spotdeals_data_ingestion\Service\SpanishNodeTranslationCreator;
use Drupal\spotdeals_data_ingestion\Service\VenueCandidateValidator;
use Drupal\spotdeals_data_ingestion\Service\VenueMapper;
use Drupal\spotdeals_data_ingestion\Service\VenueTypeMappingManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for SpotDeals external data ingestion.
 */
final class GeoapifyDrushCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly GeoapifyClient $geoapifyClient,
    private readonly DealDiscoveryService $dealDiscoveryService,
    private readonly GeoapifyCategoryCatalog $geoapifyCategoryCatalog,
    private readonly VenueTypeMappingManager $venueTypeMappingManager,
    private readonly VenueMapper $venueMapper,
    private readonly VenueCandidateValidator $candidateValidator,
    private readonly SpanishNodeTranslationCreator $spanishTranslationCreator,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  /**
   * Performs a dry run of a Geoapify venue ingestion.
   */
  #[CLI\Command(
    name: 'spotdeals:geoapify-dry-run',
    aliases: ['sd:geoapify-dry-run'],
  )]
  #[CLI\Argument(
    name: 'category',
    description: 'Geoapify category, such as catering.cafe.',
  )]
  #[CLI\Argument(
    name: 'placeId',
    description: 'Geoapify place_id used for the city boundary filter.',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Number of records requested per API page.',
  )]
  #[CLI\Option(
    name: 'max-pages',
    description: 'Maximum number of API pages to request.',
  )]
  #[CLI\Option(
    name: 'sample',
    description: 'Maximum number of candidate venues to display.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:geoapify-dry-run catering.cafe PLACE_ID',
    description: 'Preview cafés returned for a Geoapify city place ID.',
  )]
  public function dryRun(
    string $category,
    string $placeId,
    array $options = [
      'limit' => 100,
      'max-pages' => 50,
      'sample' => 20,
    ],
  ): int {
    $apiKey = $this->getApiKey();
    if ($apiKey === NULL) {
      return 1;
    }

    $limit = max(1, min(500, (int) $options['limit']));
    $maxPages = max(1, (int) $options['max-pages']);
    $sampleLimit = max(0, (int) $options['sample']);

    $this->io()->title('SpotDeals Geoapify Venue Ingestion — Dry Run');
    $this->io()->definitionList(
      ['Category' => $category],
      ['Place ID' => $placeId],
      ['Page size' => (string) $limit],
      ['Maximum pages' => (string) $maxPages],
    );

    try {
      $features = $this->geoapifyClient->fetchPlaces(
        apiKey: $apiKey,
        placeId: $placeId,
        category: $category,
        pageSize: $limit,
        maxPages: $maxPages,
      );
    }
    catch (\Throwable $exception) {
      $this->io()->error($exception->getMessage());
      return 1;
    }

    $venues = array_map(
      fn (array $feature): array => $this->venueMapper->map($feature, $category),
      $features,
    );
    $venues = $this->candidateValidator->validateBatch($venues);

    $statistics = [
      'fetched' => count($features),
      'valid' => 0,
      'invalid' => 0,
      'duplicates' => 0,
      'would_create' => 0,
    ];

    $errorCounts = [];
    $sampleRows = [];

    foreach ($venues as $venue) {

      if (!$venue['valid']) {
        $statistics['invalid']++;
        foreach ($venue['errors'] as $error) {
          $errorCounts[$error] = ($errorCounts[$error] ?? 0) + 1;
        }
        continue;
      }

      $statistics['valid']++;

      if ($venue['existing_duplicate'] || $venue['batch_duplicate']) {
        $statistics['duplicates']++;
        continue;
      }

      $statistics['would_create']++;

      if (count($sampleRows) < $sampleLimit) {
        $address = $venue['address'];
        $sampleRows[] = [
          $venue['title'],
          $venue['venue_type_name'],
          $address['address_line1'],
          $address['locality'],
          $address['administrative_area'],
          $address['postal_code'],
          $venue['phone'] !== '' ? 'Yes' : 'No',
          $venue['website'] !== '' ? 'Yes' : 'No',
          $venue['external_id'],
        ];
      }
    }

    $this->io()->section('Summary');
    $this->io()->table(
      ['Metric', 'Count'],
      [
        ['Fetched', $statistics['fetched']],
        ['Valid', $statistics['valid']],
        ['Invalid', $statistics['invalid']],
        ['Existing duplicates', $statistics['duplicates']],
        ['Would create', $statistics['would_create']],
      ],
    );

    if ($errorCounts !== []) {
      ksort($errorCounts);
      $errorRows = [];
      foreach ($errorCounts as $error => $count) {
        $errorRows[] = [$error, $count];
      }
      $this->io()->section('Validation failures');
      $this->io()->table(['Reason', 'Count'], $errorRows);
    }

    if ($sampleRows !== []) {
      $this->io()->section(sprintf('Candidate sample — first %d', count($sampleRows)));
      $this->io()->table(
        ['Title', 'Type', 'Address', 'City', 'State', 'ZIP', 'Phone', 'Website', 'Geoapify place_id'],
        $sampleRows,
      );
    }

    $this->io()->success('Dry run completed. No venue nodes were created or changed.');
    return 0;
  }

  /**
   * Imports Geoapify venues as SpotDeals venue nodes.
   */
  #[CLI\Command(
    name: 'spotdeals:geoapify-import',
    aliases: ['sd:geoapify-import'],
  )]
  #[CLI\Argument(
    name: 'category',
    description: 'Geoapify category, such as catering.cafe.',
  )]
  #[CLI\Argument(
    name: 'placeId',
    description: 'Geoapify place_id used for the city boundary filter.',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Number of records requested per API page.',
  )]
  #[CLI\Option(
    name: 'max-pages',
    description: 'Maximum number of API pages to request.',
  )]
  #[CLI\Option(
    name: 'batch-size',
    description: 'Maximum number of venue nodes to create.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:geoapify-import catering.cafe PLACE_ID --batch-size=10',
    description: 'Import up to 10 cafés for a Geoapify city place ID.',
  )]
  public function import(
    string $category,
    string $placeId,
    array $options = [
      'limit' => 100,
      'max-pages' => 50,
      'batch-size' => 10,
    ],
  ): int {
    $apiKey = $this->getApiKey();
    if ($apiKey === NULL) {
      return 1;
    }

    $limit = max(1, min(500, (int) $options['limit']));
    $maxPages = max(1, (int) $options['max-pages']);
    $batchSize = max(1, (int) $options['batch-size']);

    $this->io()->title('SpotDeals Geoapify Venue Import');
    $this->io()->definitionList(
      ['Category' => $category],
      ['Place ID' => $placeId],
      ['Page size' => (string) $limit],
      ['Maximum pages' => (string) $maxPages],
      ['Maximum nodes to create' => (string) $batchSize],
    );

    if (!$this->io()->confirm('Create venue nodes in this Drupal database?', FALSE)) {
      $this->io()->warning('Import cancelled.');
      return 0;
    }

    try {
      $features = $this->geoapifyClient->fetchPlaces(
        apiKey: $apiKey,
        placeId: $placeId,
        category: $category,
        pageSize: $limit,
        maxPages: $maxPages,
      );
    }
    catch (\Throwable $exception) {
      $this->io()->error($exception->getMessage());
      return 1;
    }

    $venues = array_map(
      fn (array $feature): array => $this->venueMapper->map($feature, $category),
      $features,
    );
    $venues = $this->candidateValidator->validateBatch($venues);

    $statistics = [
      'fetched' => count($features),
      'valid' => 0,
      'invalid' => 0,
      'duplicates' => 0,
      'created' => 0,
      'not_processed_after_limit' => 0,
      'save_failures' => 0,
    ];

    $createdRows = [];

    foreach ($venues as $index => $venue) {
      if ($statistics['created'] >= $batchSize) {
        $statistics['not_processed_after_limit'] = count($venues) - $index;
        break;
      }

      if (!$venue['valid']) {
        $statistics['invalid']++;
        continue;
      }

      $statistics['valid']++;

      if ($venue['existing_duplicate'] || $venue['batch_duplicate']) {
        $statistics['duplicates']++;
        continue;
      }

      try {
        $values = [
          'type' => 'venue',
          'title' => $venue['title'],
          'status' => 1,
          'field_address' => $venue['address'],
          'field_coordinates' => $venue['coordinates_wkt'],
          'field_latitude' => $venue['latitude'],
          'field_longitude' => $venue['longitude'],
          'field_venue_type' => ['target_id' => $venue['venue_type_tid']],
          'field_external_source' => $venue['external_provider'],
          'field_external_id' => $venue['external_id'],
        ];

        if ($venue['phone'] !== '') {
          $values['field_phone'] = $venue['phone'];
        }

        if ($venue['website'] !== '') {
          $values['field_website'] = [
            'uri' => $venue['website'],
            'title' => '',
            'options' => [],
          ];
        }

        $node = Node::create($values);
        $node->save();
        $this->spanishTranslationCreator->ensureSpanishTranslation($node);

        $statistics['created']++;
        $createdRows[] = [
          (string) $node->id(),
          $venue['title'],
          $venue['address']['address_line1'],
          $venue['address']['locality'],
        ];
      }
      catch (\Throwable $exception) {
        $statistics['save_failures']++;
        $this->logger()->error(
          'Failed to create venue "{title}": {message}',
          [
            'title' => $venue['title'],
            'message' => $exception->getMessage(),
          ],
        );
      }
    }

    $this->io()->section('Summary');
    $this->io()->table(
      ['Metric', 'Count'],
      [
        ['Fetched', $statistics['fetched']],
        ['Valid processed', $statistics['valid']],
        ['Invalid', $statistics['invalid']],
        ['Existing duplicates', $statistics['duplicates']],
        ['Created', $statistics['created']],
        ['Save failures', $statistics['save_failures']],
        ['Not processed after batch limit', $statistics['not_processed_after_limit']],
      ],
    );

    if ($createdRows !== []) {
      $this->io()->section('Created venues');
      $this->io()->table(['Node ID', 'Title', 'Address', 'City'], $createdRows);
    }

    if ($statistics['save_failures'] > 0) {
      $this->io()->warning('Import completed with save failures. Review the messages above.');
      return 1;
    }

    $this->io()->success(sprintf('%d venue node(s) created.', $statistics['created']));
    return 0;
  }



  /**
   * Researches Geoapify venue candidates for review-only deal signals.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-dry-run',
    aliases: ['sd:deal-discovery-dry-run'],
  )]
  #[CLI\Argument(
    name: 'category',
    description: 'Geoapify category or comma-separated categories.',
  )]
  #[CLI\Argument(
    name: 'placeId',
    description: 'Geoapify place_id used for the city boundary filter.',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Number of records requested per Geoapify API page.',
  )]
  #[CLI\Option(
    name: 'max-pages',
    description: 'Maximum number of Geoapify API pages to request.',
  )]
  #[CLI\Option(
    name: 'candidates',
    description: 'Maximum number of valid, non-duplicate venues to research.',
  )]
  #[CLI\Option(
    name: 'site-pages',
    description: 'Maximum number of same-domain website pages to inspect per venue.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-dry-run entertainment PLACE_ID --candidates=10',
    description: 'Research up to 10 Geoapify candidates for review-only deal signals.',
  )]
  public function dealDiscoveryDryRun(
    string $category,
    string $placeId,
    array $options = [
      'limit' => 100,
      'max-pages' => 5,
      'candidates' => 10,
      'site-pages' => 5,
    ],
  ): int {
    $apiKey = $this->getApiKey();
    if ($apiKey === NULL) {
      return 1;
    }

    $limit = max(1, min(500, (int) $options['limit']));
    $maxPages = max(1, (int) $options['max-pages']);
    $candidateLimit = max(1, min(50, (int) $options['candidates']));
    $sitePages = max(1, min(10, (int) $options['site-pages']));

    $this->io()->title('SpotDeals Deal Discovery — Review-Only Dry Run');
    $this->io()->definitionList(
      ['Category' => $category],
      ['Place ID' => $placeId],
      ['Candidate limit' => (string) $candidateLimit],
      ['Website pages per candidate' => (string) $sitePages],
      ['Writes' => 'None'],
    );

    try {
      $features = $this->geoapifyClient->fetchPlaces(
        apiKey: $apiKey,
        placeId: $placeId,
        category: $category,
        pageSize: $limit,
        maxPages: $maxPages,
      );
    }
    catch (\Throwable $exception) {
      $this->io()->error($exception->getMessage());
      return 1;
    }

    $venues = array_map(
      fn (array $feature): array => $this->venueMapper->map($feature, $category),
      $features,
    );
    $venues = $this->candidateValidator->validateBatch($venues);

    $statistics = [
      'fetched' => count($features),
      'eligible' => 0,
      'researched' => 0,
      'website_resolved' => 0,
      'review' => 0,
      'manual_check' => 0,
      'skip' => 0,
      'deal_candidates' => 0,
    ];

    $rows = [];
    $details = [];
    $researchedWebsiteHosts = [];

    foreach ($venues as $venue) {
      if (!$venue['valid'] || $venue['existing_duplicate'] || $venue['batch_duplicate']) {
        continue;
      }

      $statistics['eligible']++;
      if ($statistics['researched'] >= $candidateLimit) {
        continue;
      }

      // Place details can contain a website even when the Places list result did
      // not. Keep the original Geoapify place ID as canonical identity.
      if (($venue['website'] ?? '') === '' && ($venue['external_id'] ?? '') !== '') {
        try {
          $detailsFeature = $this->geoapifyClient->getPlaceDetails(
            $apiKey,
            (string) $venue['external_id'],
          );
          $enrichedVenue = $this->venueMapper->map($detailsFeature, $category);
          if (($enrichedVenue['website'] ?? '') !== '') {
            $venue['website'] = $enrichedVenue['website'];
          }
          if (($venue['phone'] ?? '') === '' && ($enrichedVenue['phone'] ?? '') !== '') {
            $venue['phone'] = $enrichedVenue['phone'];
          }
        }
        catch (\Throwable $exception) {
          $this->logger()->notice(
            'Deal discovery place-details enrichment failed for {place_id}: {message}',
            [
              'place_id' => $venue['external_id'],
              'message' => $exception->getMessage(),
            ],
          );
        }
      }

      $websiteHost = $this->normalizedWebsiteHost((string) ($venue['website'] ?? ''));
      if ($websiteHost !== '' && isset($researchedWebsiteHosts[$websiteHost])) {
        $address = is_array($venue['address'] ?? NULL) ? $venue['address'] : [];
        $rows[] = [
          $venue['title'],
          (string) ($address['address_line1'] ?? ''),
          'duplicate_website',
          'Yes',
          '0',
          '0',
          '0',
          'SKIP',
        ];
        continue;
      }

      if ($websiteHost !== '') {
        $researchedWebsiteHosts[$websiteHost] = TRUE;
      }

      $statistics['researched']++;
      $result = $this->dealDiscoveryService->discover($venue, $sitePages);

      if ($result['website'] !== '') {
        $statistics['website_resolved']++;
      }

      $recommendation = (string) $result['recommendation'];
      if ($recommendation === 'REVIEW') {
        $statistics['review']++;
      }
      elseif ($recommendation === 'MANUAL_CHECK') {
        $statistics['manual_check']++;
      }
      else {
        $statistics['skip']++;
      }

      $dealCount = count($result['deal_candidates']);
      $statistics['deal_candidates'] += $dealCount;

      $address = is_array($venue['address'] ?? NULL) ? $venue['address'] : [];
      $rows[] = [
        $venue['title'],
        (string) ($address['address_line1'] ?? ''),
        (string) ($result['status'] ?? ''),
        $result['website'] !== '' ? 'Yes' : 'No',
        (string) $result['pages_checked'],
        (string) $result['location_confidence'],
        (string) $dealCount,
        $recommendation,
      ];

      if ($dealCount > 0) {
        $details[] = [
          'title' => $venue['title'],
          'website' => $result['website'],
          'recommendation' => $recommendation,
          'candidates' => $result['deal_candidates'],
        ];
      }
    }

    $this->io()->section('Summary');
    $this->io()->table(
      ['Metric', 'Count'],
      [
        ['Geoapify fetched', $statistics['fetched']],
        ['Eligible after validation/deduplication', $statistics['eligible']],
        ['Researched', $statistics['researched']],
        ['Website resolved', $statistics['website_resolved']],
        ['REVIEW', $statistics['review']],
        ['MANUAL_CHECK', $statistics['manual_check']],
        ['SKIP', $statistics['skip']],
        ['Deal candidate snippets', $statistics['deal_candidates']],
      ],
    );

    if ($rows !== []) {
      $this->io()->section('Candidate results');
      $this->io()->table(
        ['Venue', 'Address', 'Status', 'Website', 'Pages', 'Location', 'Deals', 'Recommendation'],
        $rows,
      );
    }

    foreach ($details as $detail) {
      $this->io()->section($detail['title'] . ' — ' . $detail['recommendation']);
      $this->io()->text('Website: ' . $detail['website']);

      $candidateRows = [];
      foreach ($detail['candidates'] as $candidate) {
        $candidateRows[] = [
          (string) $candidate['score'],
          (string) ($candidate['title'] ?? ''),
          (string) ($candidate['value'] ?? ''),
          (string) ($candidate['schedule'] ?? ''),
          (string) $candidate['source_url'],
          (string) ($candidate['reason'] ?? ''),
        ];
      }
      $this->io()->table(
        ['Score', 'Offer', 'Value', 'Schedule / validity', 'Source', 'Why it qualified'],
        $candidateRows,
      );
    }

    $this->io()->success(
      'Review-only deal discovery completed. No venue nodes, deal nodes, or CSV files were created or changed.',
    );

    return 0;
  }


  /**
   * Returns a normalized host for review-only website deduplication.
   */
  private function normalizedWebsiteHost(string $website): string {
    $website = trim($website);
    if ($website === '') {
      return '';
    }

    if (!preg_match('#^https?://#i', $website)) {
      $website = 'https://' . ltrim($website, '/');
    }

    $host = mb_strtolower((string) parse_url($website, PHP_URL_HOST));
    return preg_replace('/^www\./i', '', $host) ?? $host;
  }


  /**
   * Audits automatic Geoapify mappings for every SpotDeals venue type.
   */
  #[CLI\Command(
    name: 'spotdeals:geoapify-mappings:audit',
    aliases: ['sd:geoapify-mappings:audit'],
  )]
  #[CLI\Option(
    name: 'refresh-catalog',
    description: 'Refresh the provider category catalog before auditing.',
  )]
  public function auditMappings(
    array $options = [
      'refresh-catalog' => FALSE,
    ],
  ): int {
    $refresh = (bool) $options['refresh-catalog'];

    try {
      $rows = $this->venueTypeMappingManager->audit($refresh);
      $metadata = $this->geoapifyCategoryCatalog->metadata();
    }
    catch (\Throwable $exception) {
      $this->io()->error($exception->getMessage());
      return 1;
    }

    $this->io()->title('SpotDeals Geoapify Venue-Type Mapping Audit');
    $this->io()->definitionList(
      ['Catalog source' => $metadata['source_url'] !== '' ? $metadata['source_url'] : 'Not cached'],
      ['Catalog categories' => (string) $metadata['count']],
      ['Catalog fetched' => $metadata['fetched_at'] > 0 ? date(DATE_ATOM, $metadata['fetched_at']) : 'Never'],
      ['Catalog stale' => $metadata['stale'] ? 'Yes' : 'No'],
    );

    $tableRows = [];
    $counts = [];
    foreach ($rows as $row) {
      $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
      $tableRows[] = [
        (string) $row['tid'],
        $row['name'],
        $row['status'],
        $row['manual_categories'] !== [] ? implode(', ', $row['manual_categories']) : '—',
        $row['automatic_categories'] !== [] ? implode(', ', $row['automatic_categories']) : '—',
        $row['suggested_categories'] !== [] ? implode(', ', $row['suggested_categories']) : '—',
        number_format($row['score'], 4),
      ];
    }

    $this->io()->table(
      ['TID', 'Venue type', 'Status', 'Manual override', 'Automatic', 'Current suggestion', 'Score'],
      $tableRows,
    );

    ksort($counts);
    $summaryRows = [];
    foreach ($counts as $status => $count) {
      $summaryRows[] = [$status, (string) $count];
    }

    $this->io()->section('Summary');
    $this->io()->table(['Status', 'Count'], $summaryRows);

    return 0;
  }

  /**
   * Synchronizes high-confidence Geoapify mappings for all venue types.
   */
  #[CLI\Command(
    name: 'spotdeals:geoapify-mappings:sync',
    aliases: ['sd:geoapify-mappings:sync'],
  )]
  #[CLI\Option(
    name: 'refresh-catalog',
    description: 'Refresh the provider category catalog before synchronizing.',
  )]
  public function syncMappings(
    array $options = [
      'refresh-catalog' => FALSE,
    ],
  ): int {
    $refresh = (bool) $options['refresh-catalog'];

    try {
      $rows = $this->venueTypeMappingManager->syncAll($refresh);
      $metadata = $this->geoapifyCategoryCatalog->metadata();
    }
    catch (\Throwable $exception) {
      $this->io()->error($exception->getMessage());
      return 1;
    }

    $this->io()->title('SpotDeals Geoapify Venue-Type Mapping Sync');
    $this->io()->definitionList(
      ['Catalog source' => $metadata['source_url'] !== '' ? $metadata['source_url'] : 'Not cached'],
      ['Catalog categories' => (string) $metadata['count']],
      ['Catalog fetched' => $metadata['fetched_at'] > 0 ? date(DATE_ATOM, $metadata['fetched_at']) : 'Never'],
    );

    $tableRows = [];
    $counts = [];
    $changed = 0;

    foreach ($rows as $row) {
      $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
      if ($row['changed']) {
        $changed++;
      }

      $tableRows[] = [
        (string) $row['tid'],
        $row['name'],
        $row['status'],
        $row['automatic_categories'] !== [] ? implode(', ', $row['automatic_categories']) : '—',
        $row['manual_categories'] !== [] ? implode(', ', $row['manual_categories']) : '—',
        number_format($row['score'], 4),
        $row['changed'] ? 'Yes' : 'No',
      ];
    }

    $this->io()->table(
      ['TID', 'Venue type', 'Status', 'Automatic mapping', 'Manual override', 'Score', 'Changed'],
      $tableRows,
    );

    ksort($counts);
    $summaryRows = [['Terms changed', (string) $changed]];
    foreach ($counts as $status => $count) {
      $summaryRows[] = [$status, (string) $count];
    }

    $this->io()->section('Summary');
    $this->io()->table(['Metric', 'Count'], $summaryRows);

    $this->io()->success(
      'High-confidence automatic mappings are synchronized. Manual overrides were preserved.',
    );

    return 0;
  }

  private function getApiKey(): ?string {
    $apiKey = trim((string) $this->state->get(
      'spotdeals_data_ingestion.geoapify_api_key',
      '',
    ));

    if ($apiKey === '') {
      $this->io()->error(
        'No Geoapify API key is configured. Visit the SpotDeals Data Ingestion settings page.',
      );
      return NULL;
    }

    return $apiKey;
  }

}
