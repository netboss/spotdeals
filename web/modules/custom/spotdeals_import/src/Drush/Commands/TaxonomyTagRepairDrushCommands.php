<?php

declare(strict_types=1);

namespace Drupal\spotdeals_import\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Audits and repairs historical compound SpotDeals taxonomy tags.
 */
final class TaxonomyTagRepairDrushCommands extends DrushCommands {

  use AutowireTrait;

  private const VOCABULARY = 'tags';
  private const FIELD_NAME = 'field_tags';
  private const NODE_TYPE = 'venue';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  /**
   * Audits or repairs comma-delimited compound taxonomy tags on venues.
   */
  #[CLI\Command(
    name: 'spotdeals:repair-taxonomy-tags',
    aliases: ['sd:repair-taxonomy-tags'],
  )]
  #[CLI\Option(
    name: 'apply',
    description: 'Apply reference repairs. Without this option the command is read-only.',
  )]
  #[CLI\Option(
    name: 'tid',
    description: 'Optional comma-separated compound term IDs to inspect or repair.',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Optional maximum number of compound terms to inspect or repair.',
  )]
  #[CLI\Option(
    name: 'summary',
    description: 'Suppress the per-term table and print only aggregate audit totals.',
  )]
  #[CLI\Option(
    name: 'cleanup-orphans',
    description: 'Audit or delete comma-containing tag terms that have zero node references. Requires --apply to delete.',
  )]
  #[CLI\Option(
    name: 'consolidate-duplicates',
    description: 'Audit or consolidate case-insensitive duplicate standalone tags. Requires --apply to migrate references and delete verified duplicates.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags',
    description: 'Audit malformed compound tags without changing data.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --tid=2307',
    description: 'Audit one known compound tag and its venue references.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --summary',
    description: 'Audit all malformed compound tags and print aggregate totals only.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --apply',
    description: 'Create/reuse standalone tags and repair venue references. Compound terms are retained.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --cleanup-orphans',
    description: 'Audit comma-containing tags and report which are safe to delete.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --cleanup-orphans --apply',
    description: 'Delete only comma-containing tags verified to have zero node references.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --consolidate-duplicates',
    description: 'Audit case-insensitive duplicate standalone tags and show the selected canonical TID.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:repair-taxonomy-tags --consolidate-duplicates --apply',
    description: 'Migrate duplicate references to the canonical TID, then delete only verified unreferenced duplicates.',
  )]
  public function repair(
    array $options = [
      'apply' => FALSE,
      'tid' => NULL,
      'limit' => 0,
      'summary' => FALSE,
      'cleanup-orphans' => FALSE,
      'consolidate-duplicates' => FALSE,
    ],
  ): int {
    $apply = (bool) $options['apply'];
    $requestedTids = $this->parseIds($options['tid']);
    $limit = max(0, (int) $options['limit']);
    $summaryOnly = (bool) $options['summary'];
    $cleanupOrphans = (bool) $options['cleanup-orphans'];
    $consolidateDuplicates = (bool) $options['consolidate-duplicates'];

    if ($cleanupOrphans && $consolidateDuplicates) {
      $this->io()->error('Use only one maintenance mode at a time: --cleanup-orphans or --consolidate-duplicates.');
      return 1;
    }

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $query = $termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->sort('tid', 'ASC');

    if ($requestedTids !== []) {
      $query->condition('tid', $requestedTids, 'IN');
    }

    $candidateIds = array_values($query->execute());
    $candidateTerms = $termStorage->loadMultiple($candidateIds);
    $compoundTerms = [];

    foreach ($candidateIds as $tid) {
      $term = $candidateTerms[$tid] ?? NULL;
      if (!$term instanceof TermInterface || !str_contains($term->label(), ',')) {
        continue;
      }
      $compoundTerms[(int) $tid] = $term;
      if ($limit > 0 && count($compoundTerms) >= $limit) {
        break;
      }
    }

    if ($cleanupOrphans) {
      return $this->cleanupOrphanCompoundTerms($compoundTerms, $apply, $summaryOnly);
    }

    if ($consolidateDuplicates) {
      return $this->consolidateDuplicateStandaloneTerms($apply, $summaryOnly);
    }

    $allTagIds = array_values($termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->execute());
    $allTagTerms = $termStorage->loadMultiple($allTagIds);
    $standaloneIndex = $this->buildStandaloneIndex($allTagTerms);
    $canonicalTidMap = $this->buildCanonicalTidMap($standaloneIndex);

    $this->io()->title('SpotDeals Taxonomy Tag Repair');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Vocabulary' => self::VOCABULARY],
      ['Compound terms selected' => (string) count($compoundTerms)],
      ['Deletion policy' => 'Compound terms are NOT deleted by this command.'],
    );

    if ($compoundTerms === []) {
      $this->io()->success('No comma-containing tag terms matched the request.');
      return 0;
    }

    $stats = [
      'terms' => 0,
      'referenced_terms' => 0,
      'venue_references' => 0,
      'translations' => 0,
      'existing_components' => 0,
      'new_components' => 0,
      'ambiguous_components' => 0,
      'created_terms' => 0,
      'repaired_venues' => 0,
    ];
    $uniqueVenueIds = [];
    $uniqueExistingComponents = [];
    $uniqueMissingComponents = [];
    $uniqueAmbiguousComponents = [];
    $rows = [];

    // Venue saves performed by this maintenance command are data-repair writes,
    // not human listing edits. Mark this specific repair as active so
    // spotdeals_admin can skip its listing-modified notification side effect. Always
    // restore the prior state value, including when a repair throws.
    $notificationSuppressionKey = 'spotdeals_taxonomy_tag_repair_active';
    $previousNotificationSuppression = NULL;
    if ($apply) {
      $previousNotificationSuppression = $this->state->get($notificationSuppressionKey, NULL);
      $this->state->set($notificationSuppressionKey, TRUE);
    }

    try {
      foreach ($compoundTerms as $compoundTid => $compoundTerm) {
      $stats['terms']++;
      $components = $this->splitComponents($compoundTerm->label());
      $resolution = $this->resolveComponents($components, $standaloneIndex, $apply);

      $venueIds = array_values($nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', self::NODE_TYPE)
        ->condition(self::FIELD_NAME . '.target_id', $compoundTid)
        ->execute());

      if ($venueIds !== []) {
        $stats['referenced_terms']++;
      }
      $stats['venue_references'] += count($venueIds);
      foreach ($venueIds as $venueId) {
        $uniqueVenueIds[(int) $venueId] = TRUE;
      }
      $stats['existing_components'] += $resolution['existing_count'];
      $stats['new_components'] += $resolution['missing_count'];
      $stats['ambiguous_components'] += $resolution['ambiguous_count'];
      $stats['created_terms'] += $resolution['created_count'];
      foreach ($resolution['existing_keys'] as $key) {
        $uniqueExistingComponents[$key] = TRUE;
      }
      foreach ($resolution['missing_keys'] as $key) {
        $uniqueMissingComponents[$key] = TRUE;
      }
      foreach ($resolution['ambiguous_keys'] as $key) {
        $uniqueAmbiguousComponents[$key] = TRUE;
      }

      $changedVenueIds = [];
      foreach ($nodeStorage->loadMultiple($venueIds) as $node) {
        if (!$node instanceof NodeInterface) {
          continue;
        }

        $changedTranslations = $this->repairNodeTranslations(
          $node,
          $compoundTid,
          $resolution['target_ids'],
          $canonicalTidMap,
          $apply,
        );

        if ($changedTranslations > 0) {
          $stats['translations'] += $changedTranslations;
          $changedVenueIds[] = (int) $node->id();
          if ($apply) {
            $node->save();
            $stats['repaired_venues']++;
          }
        }
      }

        $rows[] = [
          (string) $compoundTid,
          $this->truncate($compoundTerm->label(), 58),
          (string) count($components),
          (string) count($venueIds),
          $this->formatResolution($resolution),
        ];
      }
    }
    finally {
      if ($apply) {
        if ($previousNotificationSuppression === NULL) {
          $this->state->delete($notificationSuppressionKey);
        }
        else {
          $this->state->set($notificationSuppressionKey, $previousNotificationSuppression);
        }
      }
    }

    if (!$summaryOnly) {
      $this->io()->table(
        ['TID', 'Compound tag', 'Parts', 'Venues', 'Resolution'],
        $rows,
      );
    }

    $this->io()->section('Summary');
    $this->io()->definitionList(
      ['Compound terms inspected' => (string) $stats['terms']],
      ['Referenced compound terms' => (string) $stats['referenced_terms']],
      ['Unique venues affected' => (string) count($uniqueVenueIds)],
      ['Compound-to-venue references found' => (string) $stats['venue_references']],
      ['Venue translations requiring repair' => (string) $stats['translations']],
      ['Component occurrences resolved to existing terms' => (string) $stats['existing_components']],
      ['Unique existing standalone components reused' => (string) count($uniqueExistingComponents)],
      ['Component occurrences missing before this run' => (string) $stats['new_components']],
      ['Unique missing standalone terms required' => (string) count($uniqueMissingComponents)],
      ['Ambiguous/case-duplicate component occurrences' => (string) $stats['ambiguous_components']],
      ['Unique ambiguous/case-duplicate names' => (string) count($uniqueAmbiguousComponents)],
      ['Standalone terms created' => (string) $stats['created_terms']],
      ['Venue nodes saved' => (string) $stats['repaired_venues']],
    );

    if ($apply) {
      $this->io()->success(
        'Reference repair completed. Compound source terms were intentionally retained for post-repair verification.',
      );
    }
    else {
      $this->io()->note(
        'Dry run only: no taxonomy terms or venue references were changed. Use --apply only after reviewing this audit.',
      );
    }

    return 0;
  }

  /**
   * Audits or deletes unreferenced comma-containing taxonomy terms.
   */
  private function cleanupOrphanCompoundTerms(
    array $compoundTerms,
    bool $apply,
    bool $summaryOnly,
  ): int {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $this->io()->title('SpotDeals Taxonomy Tag Orphan Cleanup');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Vocabulary' => self::VOCABULARY],
      ['Compound terms selected' => (string) count($compoundTerms)],
      ['Safety rule' => 'Only comma-containing terms with zero node references may be deleted.'],
    );

    if ($compoundTerms === []) {
      $this->io()->success('No comma-containing tag terms matched the request.');
      return 0;
    }

    $safe = 0;
    $blocked = 0;
    $deleted = 0;
    $rows = [];

    foreach ($compoundTerms as $tid => $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      // Do not limit this safety check to venue nodes. field_tags is a node
      // field, so any node reference blocks deletion even though the repair
      // phase itself was intentionally scoped to venues.
      $referenceCount = (int) $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition(self::FIELD_NAME . '.target_id', (int) $tid)
        ->count()
        ->execute();

      $status = $referenceCount === 0 ? 'SAFE' : 'BLOCKED';
      if ($referenceCount === 0) {
        $safe++;
        if ($apply) {
          $term->delete();
          $deleted++;
          $status = 'DELETED';
        }
      }
      else {
        $blocked++;
      }

      $rows[] = [
        (string) $tid,
        $this->truncate($term->label(), 72),
        (string) $referenceCount,
        $status,
      ];
    }

    if (!$summaryOnly) {
      $this->io()->table(
        ['TID', 'Compound tag', 'Node references', 'Status'],
        $rows,
      );
    }

    $this->io()->section('Summary');
    $this->io()->definitionList(
      ['Compound terms inspected' => (string) count($compoundTerms)],
      ['Safe orphan compound terms' => (string) $safe],
      ['Blocked referenced compound terms' => (string) $blocked],
      ['Compound terms deleted' => (string) $deleted],
    );

    if ($apply) {
      if ($blocked > 0) {
        $this->io()->warning(sprintf(
          '%d compound term(s) were retained because node references still exist.',
          $blocked,
        ));
      }
      $this->io()->success(sprintf(
        'Deleted %d verified orphan compound taxonomy term(s).',
        $deleted,
      ));
    }
    else {
      $this->io()->note(
        'Dry run only: no taxonomy terms were deleted. Use --cleanup-orphans --apply only after reviewing this audit.',
      );
    }

    return 0;
  }


  /**
   * Audits or consolidates case-insensitive duplicate standalone tag terms.
   */
  private function consolidateDuplicateStandaloneTerms(bool $apply, bool $summaryOnly): int {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $allTagIds = array_values($termStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->execute());
    $allTagTerms = $termStorage->loadMultiple($allTagIds);
    $index = $this->buildStandaloneIndex($allTagTerms);
    $duplicateGroups = array_filter(
      $index,
      static fn (array $matches): bool => count($matches) > 1,
    );

    $this->io()->title('SpotDeals Taxonomy Tag Duplicate Consolidation');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Vocabulary' => self::VOCABULARY],
      ['Duplicate groups selected' => (string) count($duplicateGroups)],
      ['Canonical rule' => 'Most venue references wins; lowest TID breaks ties.'],
      ['Safety rule' => 'A duplicate term is deleted only after all node references are migrated and a zero-reference check passes.'],
    );

    if ($duplicateGroups === []) {
      $this->io()->success('No case-insensitive duplicate standalone tag terms were found.');
      return 0;
    }

    $stats = [
      'groups' => 0,
      'duplicate_terms' => 0,
      'nodes_requiring_migration' => 0,
      'translations_requiring_migration' => 0,
      'nodes_saved' => 0,
      'terms_deleted' => 0,
      'blocked_terms' => 0,
    ];
    $rows = [];
    $notificationSuppressionKey = 'spotdeals_taxonomy_tag_repair_active';
    $previousNotificationSuppression = NULL;

    if ($apply) {
      $previousNotificationSuppression = $this->state->get($notificationSuppressionKey, NULL);
      $this->state->set($notificationSuppressionKey, TRUE);
    }

    try {
      foreach ($duplicateGroups as $key => $matches) {
        $stats['groups']++;
        $canonical = $matches[0];
        $canonicalTid = (int) $canonical['tid'];

        foreach (array_slice($matches, 1) as $duplicate) {
          $duplicateTid = (int) $duplicate['tid'];
          $stats['duplicate_terms']++;

          $nodeIds = array_values($nodeStorage->getQuery()
            ->accessCheck(FALSE)
            ->condition(self::FIELD_NAME . '.target_id', $duplicateTid)
            ->execute());
          $stats['nodes_requiring_migration'] += count($nodeIds);

          $changedNodes = 0;
          $changedTranslations = 0;
          foreach ($nodeStorage->loadMultiple($nodeIds) as $node) {
            if (!$node instanceof NodeInterface) {
              continue;
            }

            $nodeTranslationChanges = $this->replaceTagTidInNodeTranslations(
              $node,
              $duplicateTid,
              $canonicalTid,
              $apply,
            );
            if ($nodeTranslationChanges > 0) {
              $changedTranslations += $nodeTranslationChanges;
              $changedNodes++;
              if ($apply) {
                $node->save();
                $stats['nodes_saved']++;
              }
            }
          }
          $stats['translations_requiring_migration'] += $changedTranslations;

          $remainingReferences = (int) $nodeStorage->getQuery()
            ->accessCheck(FALSE)
            ->condition(self::FIELD_NAME . '.target_id', $duplicateTid)
            ->count()
            ->execute();

          $status = $apply ? 'READY' : 'DRY RUN';
          if ($apply) {
            if ($remainingReferences === 0) {
              $term = $termStorage->load($duplicateTid);
              if ($term instanceof TermInterface) {
                $term->delete();
                $stats['terms_deleted']++;
                $status = 'DELETED';
              }
              else {
                $status = 'MISSING';
              }
            }
            else {
              $stats['blocked_terms']++;
              $status = 'BLOCKED';
            }
          }

          $rows[] = [
            $canonical['name'],
            (string) $canonicalTid,
            $duplicate['name'],
            (string) $duplicateTid,
            (string) count($nodeIds),
            (string) $changedTranslations,
            $apply ? (string) $remainingReferences : 'not checked after writes',
            $status,
          ];
        }
      }
    }
    finally {
      if ($apply) {
        if ($previousNotificationSuppression === NULL) {
          $this->state->delete($notificationSuppressionKey);
        }
        else {
          $this->state->set($notificationSuppressionKey, $previousNotificationSuppression);
        }
      }
    }

    if (!$summaryOnly) {
      $this->io()->table(
        ['Canonical', 'Canonical TID', 'Duplicate', 'Duplicate TID', 'Nodes', 'Translations', 'Remaining refs', 'Status'],
        $rows,
      );
    }

    $this->io()->section('Summary');
    $this->io()->definitionList(
      ['Duplicate groups inspected' => (string) $stats['groups']],
      ['Duplicate terms inspected' => (string) $stats['duplicate_terms']],
      ['Node references requiring migration' => (string) $stats['nodes_requiring_migration']],
      ['Node translations requiring migration' => (string) $stats['translations_requiring_migration']],
      ['Nodes saved' => (string) $stats['nodes_saved']],
      ['Duplicate terms deleted' => (string) $stats['terms_deleted']],
      ['Duplicate terms blocked from deletion' => (string) $stats['blocked_terms']],
    );

    if ($apply) {
      if ($stats['blocked_terms'] > 0) {
        $this->io()->warning(sprintf(
          '%d duplicate term(s) were retained because node references still exist.',
          $stats['blocked_terms'],
        ));
      }
      $this->io()->success(sprintf(
        'Consolidated duplicate tags and deleted %d verified unreferenced duplicate term(s).',
        $stats['terms_deleted'],
      ));
    }
    else {
      $this->io()->note('Dry run only: no node references or taxonomy terms were changed.');
    }

    return 0;
  }

  /**
   * Replaces one tag TID with another in every translation of a node.
   */
  private function replaceTagTidInNodeTranslations(
    NodeInterface $node,
    int $sourceTid,
    int $targetTid,
    bool $apply,
  ): int {
    $changedTranslations = 0;

    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      if (!$translation->hasField(self::FIELD_NAME)) {
        continue;
      }

      $originalIds = array_map(
        static fn (array $item): int => (int) $item['target_id'],
        $translation->get(self::FIELD_NAME)->getValue(),
      );
      if (!in_array($sourceTid, $originalIds, TRUE)) {
        continue;
      }

      $newIds = array_map(
        static fn (int $tid): int => $tid === $sourceTid ? $targetTid : $tid,
        $originalIds,
      );
      $newIds = array_values(array_unique($newIds));
      $changedTranslations++;

      if ($apply) {
        $translation->set(
          self::FIELD_NAME,
          array_map(
            static fn (int $tid): array => ['target_id' => $tid],
            $newIds,
          ),
        );
      }
    }

    return $changedTranslations;
  }

  /**
   * Builds a case-insensitive index of existing standalone tags.
   *
   * Duplicate names are ordered by current venue-reference count, then TID.
   */
  private function buildStandaloneIndex(array $terms): array {
    $index = [];

    foreach ($terms as $term) {
      if (!$term instanceof TermInterface || str_contains($term->label(), ',')) {
        continue;
      }

      $key = $this->normalizeKey($term->label());
      if ($key === '') {
        continue;
      }

      $tid = (int) $term->id();
      $index[$key][] = [
        'tid' => $tid,
        'name' => $term->label(),
        'references' => 0,
      ];
    }

    foreach ($index as &$matches) {
      if (count($matches) > 1) {
        foreach ($matches as &$match) {
          $match['references'] = $this->countVenueReferences((int) $match['tid']);
        }
        unset($match);
      }
      usort(
        $matches,
        static fn (array $a, array $b): int =>
          ($b['references'] <=> $a['references']) ?: ($a['tid'] <=> $b['tid']),
      );
    }
    unset($matches);

    return $index;
  }

  /**
   * Maps duplicate standalone TIDs to the selected canonical TID.
   */
  private function buildCanonicalTidMap(array $index): array {
    $map = [];

    foreach ($index as $matches) {
      if ($matches === []) {
        continue;
      }
      $canonicalTid = (int) $matches[0]['tid'];
      foreach ($matches as $match) {
        $map[(int) $match['tid']] = $canonicalTid;
      }
    }

    return $map;
  }

  /**
   * Resolves component names to canonical existing or newly-created terms.
   */
  private function resolveComponents(array $components, array &$index, bool $apply): array {
    $targetIds = [];
    $details = [];
    $existingCount = 0;
    $missingCount = 0;
    $ambiguousCount = 0;
    $createdCount = 0;
    $existingKeys = [];
    $missingKeys = [];
    $ambiguousKeys = [];
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach ($components as $component) {
      $key = $this->normalizeKey($component);
      $matches = $index[$key] ?? [];

      if ($matches !== []) {
        $canonical = $matches[0];
        $targetIds[] = (int) $canonical['tid'];
        $existingCount++;
        $existingKeys[] = $key;
        if (count($matches) > 1) {
          $ambiguousCount++;
          $ambiguousKeys[] = $key;
        }
        $details[] = count($matches) > 1
          ? sprintf('%s→%d*', $component, $canonical['tid'])
          : sprintf('%s→%d', $component, $canonical['tid']);
        continue;
      }

      $missingCount++;
      $missingKeys[] = $key;
      if (!$apply) {
        $details[] = sprintf('%s→NEW', $component);
        continue;
      }

      $term = $termStorage->create([
        'vid' => self::VOCABULARY,
        'name' => $component,
        'langcode' => 'en',
      ]);
      $term->save();
      $tid = (int) $term->id();
      $targetIds[] = $tid;
      $createdCount++;
      $index[$key] = [[
        'tid' => $tid,
        'name' => $component,
        'references' => 0,
      ]];
      $details[] = sprintf('%s→%d NEW', $component, $tid);
    }

    return [
      'target_ids' => array_values(array_unique($targetIds)),
      'details' => $details,
      'existing_count' => $existingCount,
      'missing_count' => $missingCount,
      'ambiguous_count' => $ambiguousCount,
      'existing_keys' => array_values(array_unique($existingKeys)),
      'missing_keys' => array_values(array_unique($missingKeys)),
      'ambiguous_keys' => array_values(array_unique($ambiguousKeys)),
      'created_count' => $createdCount,
    ];
  }

  /**
   * Replaces one compound TID in every translation of a venue node.
   */
  private function repairNodeTranslations(
    NodeInterface $node,
    int $compoundTid,
    array $replacementTids,
    array $canonicalTidMap,
    bool $apply,
  ): int {
    $changedTranslations = 0;

    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation($langcode);
      if (!$translation->hasField(self::FIELD_NAME)) {
        continue;
      }

      $originalIds = array_map(
        static fn (array $item): int => (int) $item['target_id'],
        $translation->get(self::FIELD_NAME)->getValue(),
      );

      if (!in_array($compoundTid, $originalIds, TRUE)) {
        continue;
      }

      $newIds = [];
      foreach ($originalIds as $tid) {
        if ($tid === $compoundTid) {
          foreach ($replacementTids as $replacementTid) {
            $newIds[] = (int) ($canonicalTidMap[$replacementTid] ?? $replacementTid);
          }
        }
        else {
          $newIds[] = (int) ($canonicalTidMap[$tid] ?? $tid);
        }
      }
      $newIds = array_values(array_unique($newIds));

      $changedTranslations++;
      if ($apply) {
        $translation->set(
          self::FIELD_NAME,
          array_map(
            static fn (int $tid): array => ['target_id' => $tid],
            $newIds,
          ),
        );
      }
    }

    return $changedTranslations;
  }

  /**
   * Counts current venue references to a tag across field translations.
   */
  private function countVenueReferences(int $tid): int {
    return (int) $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::NODE_TYPE)
      ->condition(self::FIELD_NAME . '.target_id', $tid)
      ->count()
      ->execute();
  }

  /**
   * Splits a known compound term into trimmed, unique components.
   */
  private function splitComponents(string $name): array {
    $parts = preg_split('/,/u', $name) ?: [];
    $result = [];
    $seen = [];

    foreach ($parts as $part) {
      $part = trim($part);
      $key = $this->normalizeKey($part);
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $result[] = $part;
    }

    return $result;
  }

  /**
   * Parses an optional comma-separated ID option.
   */
  private function parseIds(mixed $value): array {
    if ($value === NULL || $value === FALSE || trim((string) $value) === '') {
      return [];
    }

    $ids = [];
    foreach (explode(',', (string) $value) as $candidate) {
      $candidate = trim($candidate);
      if (ctype_digit($candidate) && (int) $candidate > 0) {
        $ids[] = (int) $candidate;
      }
    }

    return array_values(array_unique($ids));
  }

  /**
   * Returns the case-insensitive matching key for a tag name.
   */
  private function normalizeKey(string $name): string {
    return mb_strtolower(trim($name), 'UTF-8');
  }

  /**
   * Formats component resolution for the audit table.
   */
  private function formatResolution(array $resolution): string {
    $details = implode(', ', $resolution['details']);
    return $this->truncate($details, 100);
  }

  /**
   * Truncates table output without changing the underlying audit behavior.
   */
  private function truncate(string $value, int $length): string {
    if (mb_strlen($value, 'UTF-8') <= $length) {
      return $value;
    }
    return mb_substr($value, 0, $length - 1, 'UTF-8') . '…';
  }

}
