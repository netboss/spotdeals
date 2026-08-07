<?php

declare(strict_types=1);

namespace Drupal\spotdeals_import\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyClient;
use Drupal\spotdeals_data_ingestion\Service\GeoapifyVenueResolver;
use Drupal\spotdeals_data_ingestion\Service\VenueMapper;
use Drupal\spotdeals_data_ingestion\Service\VenuePersistenceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a deal venue through migration, Drupal, or Geoapify.
 *
 * @MigrateProcessPlugin(
 *   id = "spotdeals_resolve_venue_reference"
 * )
 */
final class ResolveVenueReference extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly GeoapifyClient $geoapifyClient,
    private readonly GeoapifyVenueResolver $geoapifyVenueResolver,
    private readonly VenueMapper $venueMapper,
    private readonly VenuePersistenceService $venuePersistence,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('spotdeals_data_ingestion.geoapify_client'),
      $container->get('spotdeals_data_ingestion.geoapify_venue_resolver'),
      $container->get('spotdeals_data_ingestion.venue_mapper'),
      $container->get('spotdeals_data_ingestion.venue_persistence'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform(
    mixed $value,
    MigrateExecutableInterface $migrate_executable,
    Row $row,
    $destination_property,
  ): int {
    $existingVenueId = $this->extractVenueId($value);
    if ($existingVenueId !== NULL) {
      return $existingVenueId;
    }

    $venueTitle = trim((string) $row->getSourceProperty('field_venue'));
    $existingVenueId = $this->findExistingVenueByTitle($venueTitle);
    if ($existingVenueId !== NULL) {
      return $existingVenueId;
    }

    $externalSource = strtolower(trim((string) $row->getSourceProperty('field_venue_external_source')));
    $externalId = trim((string) $row->getSourceProperty('field_venue_external_id'));
    $category = trim((string) $row->getSourceProperty('field_venue_geoapify_category'));

    if ($externalSource !== '' && $externalSource !== 'geoapify') {
      throw new MigrateSkipRowException(
        sprintf('Skipping deal because external venue source "%s" is not supported.', $externalSource),
      );
    }

    if ($category === '') {
      $category = 'catering.restaurant';
    }

    $apiKey = trim((string) $this->state->get(
      'spotdeals_data_ingestion.geoapify_api_key',
      '',
    ));

    if ($apiKey === '') {
      throw new MigrateSkipRowException(
        'Skipping deal because the Geoapify API key is not configured.',
      );
    }

    try {
      if ($externalId !== '') {
        $feature = $this->geoapifyClient->getPlaceDetails($apiKey, $externalId);
      }
      else {
        if ($venueTitle === '') {
          throw new \RuntimeException('The deal does not provide a venue title.');
        }

        $searchFeature = $this->geoapifyVenueResolver->resolve(
          $apiKey,
          $venueTitle,
          $category,
        );

        $externalId = trim((string) ($searchFeature['properties']['place_id'] ?? ''));
        if ($externalId === '') {
          throw new \RuntimeException('The selected Geoapify venue has no place ID.');
        }

        $feature = $this->geoapifyClient->getPlaceDetails($apiKey, $externalId);
      }

      $venueData = $this->venueMapper->map($feature, $category);

      if (trim((string) ($venueData['external_id'] ?? '')) !== $externalId) {
        throw new \RuntimeException('Geoapify returned a different place ID.');
      }

      $result = $this->venuePersistence->persistMappedVenue($venueData);
      return (int) $result['node']->id();
    }
    catch (\Throwable $exception) {
      throw new MigrateSkipRowException(
        sprintf(
          'Skipping deal because its Geoapify venue could not be resolved: %s',
          $exception->getMessage(),
        ),
      );
    }
  }

  /**
   * Finds an existing Drupal venue using its exact stored title.
   */
  private function findExistingVenueByTitle(string $venueTitle): ?int {
    if ($venueTitle === '') {
      return NULL;
    }

    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('title', $venueTitle)
      ->range(0, 2)
      ->execute();

    if (count($nids) !== 1) {
      return NULL;
    }

    return (int) reset($nids);
  }

  /**
   * Extracts a scalar venue ID from migration_lookup output.
   */
  private function extractVenueId(mixed $value): ?int {
    while (is_array($value)) {
      if ($value === []) {
        return NULL;
      }
      $value = reset($value);
    }

    return is_numeric($value) && (int) $value > 0 ? (int) $value : NULL;
  }

}
