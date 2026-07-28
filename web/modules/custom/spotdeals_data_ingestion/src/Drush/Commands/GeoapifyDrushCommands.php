<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\node\Entity\Node;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyClient;
use Drupal\spotdeals_data_ingestion\Service\VenueCandidateValidator;
use Drupal\spotdeals_data_ingestion\Service\VenueMapper;
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
    private readonly VenueMapper $venueMapper,
    private readonly VenueCandidateValidator $candidateValidator,
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
