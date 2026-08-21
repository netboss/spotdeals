<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\node\NodeInterface;
use Drupal\pathauto\AliasCleanerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * One-time cleanup commands for historical SpotDeals venue URL aliases.
 */
final class VenueAliasCleanupDrushCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasCleanerInterface $aliasCleaner,
  ) {
    parent::__construct();
  }

  /**
   * Cleans historical venue aliases and preserves old URLs as 301 redirects.
   */
  #[CLI\Command(
    name: 'spotdeals:cleanup-venue-url-aliases',
    aliases: ['sd:cleanup-venue-url-aliases'],
  )]
  #[CLI\Option(
    name: 'apply',
    description: 'Apply the cleanup. Without this option the command is dry-run only.',
  )]
  #[CLI\Option(
    name: 'nid',
    description: 'Optional comma-separated venue node IDs to inspect.',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Optional maximum number of venue nodes to inspect.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:cleanup-venue-url-aliases',
    description: 'Preview historical venue URL alias cleanup without changing data.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:cleanup-venue-url-aliases --nid=123,456',
    description: 'Preview only selected venue nodes.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:cleanup-venue-url-aliases --apply',
    description: 'Apply the cleanup and ensure 301 redirects from old aliases.',
  )]
  public function cleanup(
    array $options = [
      'apply' => FALSE,
      'nid' => NULL,
      'limit' => 0,
    ],
  ): int {
    $apply = (bool) $options['apply'];
    $requestedNids = $this->parseNodeIds($options['nid']);
    $limit = max(0, (int) $options['limit']);

    if (!$this->entityTypeManager->hasDefinition('redirect')) {
      $this->io()->error(
        'The Redirect module/entity type is unavailable. Cleanup was not run.',
      );
      return 1;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $pathAliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $redirectStorage = $this->entityTypeManager->getStorage('redirect');

    $query = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->sort('nid', 'ASC');

    if ($requestedNids !== []) {
      $query->condition('nid', $requestedNids, 'IN');
    }

    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $nids = array_values($query->execute());

    $this->io()->title('SpotDeals Historical Venue URL Alias Cleanup');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Venue nodes selected' => (string) count($nids)],
      [
        'Node filter' => $requestedNids === []
          ? 'All venue nodes'
          : implode(', ', $requestedNids),
      ],
      ['Limit' => $limit > 0 ? (string) $limit : 'None'],
    );

    if ($nids === []) {
      $this->io()->warning('No venue nodes matched the requested scope.');
      return 0;
    }

    $statistics = [
      'translations_inspected' => 0,
      'already_clean' => 0,
      'would_change' => 0,
      'changed' => 0,
      'skipped_unexpected' => 0,
      'conflicts' => 0,
      'redirects_existing' => 0,
      'redirects_created' => 0,
      'errors' => 0,
    ];

    $rows = [];

    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (
        !$node instanceof NodeInterface
        || $node->bundle() !== 'venue'
      ) {
        continue;
      }

      foreach ($node->getTranslationLanguages(TRUE) as $langcode => $language) {
        $statistics['translations_inspected']++;

        $translation = $node->getTranslation($langcode);
        $source = '/node/' . $node->id();

        $currentAliasEntity = $this->loadExactPathAliasEntity(
          $pathAliasStorage,
          $source,
          $langcode,
        );

        if ($currentAliasEntity === NULL) {
          $rows[] = [
            $node->id(),
            $langcode,
            'SKIP',
            '(none)',
            '(none)',
            'No exact alias',
          ];
          $statistics['skipped_unexpected']++;
          continue;
        }

        $currentAlias = (string) $currentAliasEntity
          ->get('alias')
          ->value;

        $candidates = $this->buildVenueAliasCandidates($translation);

        if ($candidates === NULL) {
          $rows[] = [
            $node->id(),
            $langcode,
            'SKIP',
            $currentAlias,
            '(none)',
            'Missing title/city or no cleanable city suffix',
          ];
          $statistics['skipped_unexpected']++;
          continue;
        }

        [$oldStyleAlias, $cleanAlias] = $candidates;

        if ($currentAlias === $cleanAlias) {
          $statistics['already_clean']++;
          continue;
        }

        // Only migrate the exact historical generated form. Anything else may
        // be a manual alias or another unexpected legacy state.
        if ($currentAlias !== $oldStyleAlias) {
          $rows[] = [
            $node->id(),
            $langcode,
            'SKIP',
            $currentAlias,
            $cleanAlias,
            'Current alias is not the exact old generated form',
          ];
          $statistics['skipped_unexpected']++;
          continue;
        }

        $aliasConflict = $this->findAliasConflict(
          $pathAliasStorage,
          $cleanAlias,
          $langcode,
          (int) $currentAliasEntity->id(),
        );

        if ($aliasConflict !== NULL) {
          $rows[] = [
            $node->id(),
            $langcode,
            'CONFLICT',
            $currentAlias,
            $cleanAlias,
            $aliasConflict,
          ];
          $statistics['conflicts']++;
          continue;
        }

        $redirectState = $this->inspectRedirect(
          $redirectStorage,
          $currentAlias,
          $cleanAlias,
          $source,
          $langcode,
        );

        if ($redirectState['conflict']) {
          $rows[] = [
            $node->id(),
            $langcode,
            'CONFLICT',
            $currentAlias,
            $cleanAlias,
            $redirectState['message'],
          ];
          $statistics['conflicts']++;
          continue;
        }

        $statistics['would_change']++;

        if (!$apply) {
          $rows[] = [
            $node->id(),
            $langcode,
            'WOULD CHANGE',
            $currentAlias,
            $cleanAlias,
            $redirectState['message'],
          ];
          continue;
        }

        try {
          $currentAliasEntity->set('alias', $cleanAlias);
          $currentAliasEntity->save();

          $statistics['changed']++;

          // Redirect's automatic alias handling normally creates a redirect
          // to the canonical node source, e.g. internal:/node/91923. Accept
          // either that representation or a direct internal alias target.
          $redirectState = $this->inspectRedirect(
            $redirectStorage,
            $currentAlias,
            $cleanAlias,
            $source,
            $langcode,
          );

          if ($redirectState['matching']) {
            $statistics['redirects_existing']++;

            $rows[] = [
              $node->id(),
              $langcode,
              'CHANGED',
              $currentAlias,
              $cleanAlias,
              '301 redirect verified',
            ];
            continue;
          }

          if ($redirectState['conflict']) {
            throw new \RuntimeException(
              $redirectState['message'],
            );
          }

          // If Redirect did not automatically create one, explicitly point
          // the old alias to the canonical Drupal node source. Drupal will
          // resolve that source through the current clean alias.
          $redirectValues = [
            'redirect_source' => [
              'path' => ltrim($currentAlias, '/'),
              'query' => [],
            ],
            'redirect_redirect' => [
              'uri' => 'internal:' . $source,
            ],
            'status_code' => 301,
          ];

          $redirectLanguageField = $this->getEntityLanguageFieldName(
            $redirectStorage,
          );

          if ($redirectLanguageField !== NULL) {
            $redirectValues[$redirectLanguageField] = $langcode;
          }

          $redirect = $redirectStorage->create($redirectValues);
          $redirect->save();

          $statistics['redirects_created']++;

          $rows[] = [
            $node->id(),
            $langcode,
            'CHANGED',
            $currentAlias,
            $cleanAlias,
            '301 redirect created',
          ];
        }
        catch (\Throwable $exception) {
          $statistics['errors']++;

          $rows[] = [
            $node->id(),
            $langcode,
            'ERROR',
            $currentAlias,
            $cleanAlias,
            $exception->getMessage(),
          ];
        }
      }
    }

    if ($rows !== []) {
      $this->io()->section(
        $apply
          ? 'Changes and skips'
          : 'Dry-run changes and skips',
      );

      $this->io()->table(
        [
          'NID',
          'Lang',
          'Result',
          'Current alias',
          'Clean alias',
          'Details',
        ],
        $rows,
      );
    }

    $this->io()->section('Summary');

    $this->io()->table(
      ['Metric', 'Count'],
      [
        [
          'Translations inspected',
          $statistics['translations_inspected'],
        ],
        [
          'Already clean',
          $statistics['already_clean'],
        ],
        [
          'Would change',
          $statistics['would_change'],
        ],
        [
          'Changed',
          $statistics['changed'],
        ],
        [
          'Skipped unexpected/manual',
          $statistics['skipped_unexpected'],
        ],
        [
          'Conflicts',
          $statistics['conflicts'],
        ],
        [
          'Matching redirects already present',
          $statistics['redirects_existing'],
        ],
        [
          'Redirects created explicitly',
          $statistics['redirects_created'],
        ],
        [
          'Errors',
          $statistics['errors'],
        ],
      ],
    );

    if (!$apply) {
      $this->io()->success(
        'Dry run completed. No aliases or redirects were changed.',
      );
    }
    elseif (
      $statistics['errors'] === 0
      && $statistics['conflicts'] === 0
    ) {
      $this->io()->success(
        'Historical venue URL alias cleanup completed successfully.',
      );
    }
    else {
      $this->io()->warning(
        'Cleanup completed with conflicts and/or errors. Review the table before proceeding further.',
      );
    }

    return $statistics['errors'] > 0 ? 1 : 0;
  }

  /**
   * Parses an optional comma-separated node ID list.
   *
   * @return int[]
   *   Positive unique node IDs.
   */
  private function parseNodeIds(mixed $value): array {
    if (!is_string($value) || trim($value) === '') {
      return [];
    }

    $ids = [];

    foreach (explode(',', $value) as $item) {
      $nid = (int) trim($item);

      if ($nid > 0) {
        $ids[$nid] = $nid;
      }
    }

    return array_values($ids);
  }

  /**
   * Loads the newest exact path alias entity for source + language.
   */
  private function loadExactPathAliasEntity(
    EntityStorageInterface $storage,
    string $source,
    string $langcode,
  ): ?object {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', $source)
      ->condition('langcode', $langcode)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $entity = $storage->load(reset($ids));

    return is_object($entity)
      ? $entity
      : NULL;
  }

  /**
   * Builds the exact old generated alias and the desired clean alias.
   *
   * @return array{0: string, 1: string}|null
   *   Old-style alias and clean alias, or NULL when not applicable.
   */
  private function buildVenueAliasCandidates(
    NodeInterface $translation,
  ): ?array {
    if (
      !$translation->hasField('field_address')
      || $translation->get('field_address')->isEmpty()
    ) {
      return NULL;
    }

    $title = trim((string) $translation->label());
    $locality = trim(
      (string) $translation->get('field_address')->locality,
    );

    if ($title === '' || $locality === '') {
      return NULL;
    }

    $citySlug = trim(
      $this->aliasCleaner->cleanString($locality),
      '/',
    );

    $titleSlug = trim(
      $this->aliasCleaner->cleanString($title),
      '/',
    );

    if ($citySlug === '' || $titleSlug === '') {
      return NULL;
    }

    $oldStyleAlias = sprintf(
      '/venue/%s/%s',
      $citySlug,
      $titleSlug,
    );

    $cleanAlias =
      \_spotdeals_data_ingestion_clean_venue_alias_candidate(
        $oldStyleAlias,
      );

    if (
      $cleanAlias === NULL
      || $cleanAlias === $oldStyleAlias
    ) {
      return NULL;
    }

    return [
      $oldStyleAlias,
      $cleanAlias,
    ];
  }

  /**
   * Finds another exact-language alias already using the clean alias.
   */
  private function findAliasConflict(
    EntityStorageInterface $storage,
    string $cleanAlias,
    string $langcode,
    int $currentAliasId,
  ): ?string {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $cleanAlias)
      ->condition('langcode', $langcode)
      ->condition('id', $currentAliasId, '<>')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $conflict = $storage->load(reset($ids));

    if (!is_object($conflict)) {
      return 'Clean alias is already in use';
    }

    return sprintf(
      'Clean alias already belongs to %s',
      (string) $conflict->get('path')->value,
    );
  }

  /**
   * Checks whether the old alias already has a compatible redirect.
   *
   * Redirect module may store an automatically-created destination as the
   * canonical node source (internal:/node/123) instead of the Pathauto alias.
   * Both forms are valid for this cleanup.
   *
   * @return array{matching: bool, conflict: bool, message: string}
   *   Redirect state for the source alias and language.
   */
  private function inspectRedirect(
    EntityStorageInterface $storage,
    string $oldAlias,
    string $cleanAlias,
    string $nodeSource,
    string $langcode,
  ): array {
    $sourcePath = ltrim($oldAlias, '/');

    $validDestinationUris = [
      'internal:' . $cleanAlias,
      'internal:' . $nodeSource,
    ];

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('redirect_source.path', $sourcePath)
      ->execute();

    if ($ids === []) {
      return [
        'matching' => FALSE,
        'conflict' => FALSE,
        'message' => '301 redirect would be created',
      ];
    }

    foreach ($storage->loadMultiple($ids) as $redirect) {
      $redirectLangcode = $redirect->language()->getId();

      if (
        $redirectLangcode !== $langcode
        && $redirectLangcode !== LanguageInterface::LANGCODE_NOT_SPECIFIED
      ) {
        continue;
      }

      $destination = (string) $redirect
        ->get('redirect_redirect')
        ->uri;

      $statusCode = (int) $redirect
        ->get('status_code')
        ->value;

      if (
        in_array($destination, $validDestinationUris, TRUE)
        && $statusCode === 301
      ) {
        return [
          'matching' => TRUE,
          'conflict' => FALSE,
          'message' => 'Matching 301 redirect already exists',
        ];
      }

      return [
        'matching' => FALSE,
        'conflict' => TRUE,
        'message' => sprintf(
          'Old alias already has a different redirect for language %s: %s',
          $redirectLangcode,
          $destination,
        ),
      ];
    }

    return [
      'matching' => FALSE,
      'conflict' => FALSE,
      'message' => '301 redirect would be created',
    ];
  }

  /**
   * Returns the actual storage field used as the entity language key.
   */
  private function getEntityLanguageFieldName(
    EntityStorageInterface $storage,
  ): ?string {
    $fieldName = $storage
      ->getEntityType()
      ->getKey('langcode');

    if (
      !is_string($fieldName)
      || $fieldName === ''
    ) {
      return NULL;
    }

    return $fieldName;
  }

}
