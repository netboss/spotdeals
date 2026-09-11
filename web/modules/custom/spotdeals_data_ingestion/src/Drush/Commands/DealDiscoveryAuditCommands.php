<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryConfidenceClassifier;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityAuditService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishAuditService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishPreviewService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryStorage;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Read-only publishing audit commands.
 */
final class DealDiscoveryAuditCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly DealDiscoveryPublishAuditService $auditService,
    private readonly DealDiscoveryContentQualityAuditService $contentQualityAuditService,
    private readonly DealDiscoveryContentQualityService $contentQualityService,
    private readonly DealDiscoveryStorage $storage,
    private readonly DealDiscoveryPublishPreviewService $previewService,
    private readonly DealDiscoveryConfidenceClassifier $confidenceClassifier,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  #[CLI\Command(
    name: 'spotdeals:deal-publish-audit',
    aliases: ['sd:deal-publish-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-publish-audit',
    description: 'Audits stored deal candidates through the shadow publishing pipeline.',
  )]
  public function audit(): int {
    $audit = $this->auditService->audit();
    $summary = $audit['summary'];

    $this->io()->title('SpotDeals Deal Publishing — Candidate Audit');
    $this->io()->definitionList(
      ['Total candidates' => (string) $summary['total']],
      ['Ready' => (string) $summary['ready']],
      ['Blocked fields' => (string) $summary['blocked']],
      ['Needs review' => (string) $summary['needs_review']],
      ['Published manually' => (string) $summary['published']],
      ['Auto-published' => (string) $summary['auto_published']],
      ['Duplicate' => (string) $summary['duplicate']],
      ['Venue unresolved' => (string) $summary['venue_unresolved']],
      ['Errors' => (string) $summary['error']],
      ['Writes' => 'None'],
    );

    $rows = [];
    foreach ($audit['rows'] as $row) {
      $candidate = $row['candidate'];
      $rows[] = [
        (string) $candidate['id'],
        (string) $candidate['venue_name'],
        (string) $candidate['offer_title'],
        str_replace('_', ' ', (string) $candidate['status']),
        str_replace('_', ' ', (string) $row['result']),
        (string) $row['message'],
      ];
    }

    $this->io()->table(
      ['ID', 'Venue', 'Offer', 'Status', 'Audit result', 'Reason'],
      $rows,
    );

    if ($summary['error'] > 0) {
      $this->io()->error('Audit completed with runtime errors. No data was written.');
      return 1;
    }

    $this->io()->success('Audit completed. No data was written.');
    return 0;
  }



  /**
   * Re-evaluates pending candidates previously blocked only at publish time.
   *
   * The command is dry-run by default. It never reclassifies ordinary pending
   * candidates because the original discovery-time location confidence is not
   * stored with the candidate. Instead, it limits itself to candidates whose
   * stored classification proves they already met every automatic-approval
   * requirement and were routed to pending solely by publishing readiness.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-readiness-reevaluate',
    aliases: ['sd:deal-discovery-readiness-reevaluate'],
  )]
  #[CLI\Option(
    name: 'apply',
    description: 'Restore newly ready candidates to auto-approved status and reject re-verified duplicates. Without this option the command is dry-run only.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-readiness-reevaluate',
    description: 'Preview stale publishing-readiness candidates against the current publishing logic without changing data.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-readiness-reevaluate --apply',
    description: 'Restore newly ready candidates to the automatic publishing queue and reject any re-verified duplicates.',
  )]
  public function readinessReevaluate(
    array $options = ['apply' => FALSE],
  ): int {
    $apply = (bool) ($options['apply'] ?? FALSE);
    $candidates = $this->storage->list('pending', 1000);

    $this->io()->title('SpotDeals Deal Discovery — Publishing Readiness Re-evaluation');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Pending candidates loaded' => (string) count($candidates)],
    );

    $eligible = 0;
    $ready = 0;
    $duplicates = 0;
    $stillBlocked = 0;
    $changed = 0;
    $errors = 0;
    $rows = [];

    foreach ($candidates as $candidate) {
      $classificationReason = trim(
        (string) ($candidate['classification_reason'] ?? ''),
      );

      if (
        !str_contains(
          $classificationReason,
          'candidate met all configured automatic-approval requirements',
        )
        || !str_contains($classificationReason, 'publishing readiness:')
      ) {
        continue;
      }

      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        continue;
      }

      $eligible++;
      $previewCandidate = $candidate;
      $previewCandidate['status'] = 'auto_approved';

      try {
        $preview = $this->previewService->preview($previewCandidate);
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) $candidate['id'],
          (string) $candidate['venue_name'],
          (string) $candidate['offer_title'],
          'ERROR',
          $exception->getMessage(),
        ];
        continue;
      }

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $duplicateNid = !empty($deal['duplicate_found'])
        ? (int) ($deal['duplicate_nid'] ?? 0)
        : 0;

      if ($duplicateNid > 0) {
        $duplicates++;
        $action = 'WOULD REJECT DUPLICATE';

        if ($apply) {
          if ($this->storage->markRejectedAsDuplicate(
            (int) $candidate['id'],
            $duplicateNid,
          )) {
            $changed++;
            $action = 'REJECTED DUPLICATE';
          }
          else {
            $action = 'SKIPPED';
          }
        }

        $rows[] = [
          (string) $candidate['id'],
          (string) $candidate['venue_name'],
          (string) $candidate['offer_title'],
          $action,
          'Existing matching deal node ' . $duplicateNid . '.',
        ];
        continue;
      }

      if (!empty($preview['ready'])) {
        $ready++;
        $action = 'WOULD RESTORE AUTO-APPROVAL';

        if ($apply) {
          if ($this->storage->restoreAutoApprovalAfterReadinessReevaluation(
            (int) $candidate['id'],
          )) {
            $changed++;
            $action = 'RESTORED AUTO-APPROVAL';
          }
          else {
            $action = 'SKIPPED';
          }
        }

        $rows[] = [
          (string) $candidate['id'],
          (string) $candidate['venue_name'],
          (string) $candidate['offer_title'],
          $action,
          'Current publishing preview is ready.',
        ];
        continue;
      }

      $stillBlocked++;
      $rows[] = [
        (string) $candidate['id'],
        (string) $candidate['venue_name'],
        (string) $candidate['offer_title'],
        'STILL BLOCKED',
        $this->previewBlockingSummary($preview),
      ];
    }

    $this->io()->table(
      ['ID', 'Venue', 'Offer', 'Action', 'Current result'],
      $rows,
    );

    $this->io()->definitionList(
      ['Eligible stale readiness candidates' => (string) $eligible],
      ['Now ready' => (string) $ready],
      ['Duplicates re-verified' => (string) $duplicates],
      ['Still blocked' => (string) $stillBlocked],
      [$apply ? 'Records changed' : 'Would change' => (string) ($apply ? $changed : ($ready + $duplicates))],
      ['Errors' => (string) $errors],
    );

    if ($errors > 0) {
      $this->io()->warning('Readiness re-evaluation completed with errors. Review the rows above.');
      return 1;
    }

    if ($apply) {
      $this->io()->success('Publishing-readiness re-evaluation completed.');
    }
    else {
      $this->io()->success('Dry run completed. No data was written.');
    }

    return 0;
  }

  /**
   * Audits the pending queue into conservative cleanup buckets.
   *
   * This command never writes candidate data. It deliberately separates
   * deterministic extraction/content failures from informational-title cases
   * and ordinary score/schedule review cases so automatic rejection rules can
   * be designed from observed production data instead of broad assumptions.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-pending-audit',
    aliases: ['sd:deal-discovery-pending-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-pending-audit',
    description: 'Summarizes the pending discovery queue into conservative cleanup buckets without writing data.',
  )]
  public function pendingAudit(): int {
    $candidates = $this->storage->list('pending', 1000);

    $bucketCounts = [
      'terminal_content_quality' => 0,
      'informational_title_review' => 0,
      'publishing_readiness' => 0,
      'low_score_and_missing_schedule' => 0,
      'low_score_only' => 0,
      'missing_schedule_only' => 0,
      'other_review' => 0,
    ];
    $examples = [];

    foreach ($candidates as $candidate) {
      $assessment = $this->contentQualityService->assessCandidate($candidate);
      $blockers = array_map('strval', (array) ($assessment['blockers'] ?? []));
      $classificationReason = mb_strtolower(
        trim((string) ($candidate['classification_reason'] ?? '')),
      );

      $hasInformationalTitle = FALSE;
      $hasTerminalBlocker = FALSE;
      foreach ($blockers as $blocker) {
        if (str_contains($blocker, 'general venue information rather than a promotion')) {
          $hasInformationalTitle = TRUE;
          continue;
        }
        $hasTerminalBlocker = TRUE;
      }

      $hasPublishingReadiness = str_contains(
        $classificationReason,
        'publishing readiness:',
      );
      $hasLowScore = preg_match(
        '/score\s+\d+\s+is\s+below\s+configured\s+automatic-approval\s+score\s+\d+/u',
        $classificationReason,
      ) === 1;
      $hasMissingSchedule = str_contains(
        $classificationReason,
        'schedule or validity context is missing',
      );

      if ($hasTerminalBlocker) {
        $bucket = 'terminal_content_quality';
      }
      elseif ($hasInformationalTitle) {
        $bucket = 'informational_title_review';
      }
      elseif ($hasPublishingReadiness) {
        $bucket = 'publishing_readiness';
      }
      elseif ($hasLowScore && $hasMissingSchedule) {
        $bucket = 'low_score_and_missing_schedule';
      }
      elseif ($hasLowScore) {
        $bucket = 'low_score_only';
      }
      elseif ($hasMissingSchedule) {
        $bucket = 'missing_schedule_only';
      }
      else {
        $bucket = 'other_review';
      }

      $bucketCounts[$bucket]++;
      $examples[$bucket] ??= [];
      if (count($examples[$bucket]) < 5) {
        $examples[$bucket][] = [
          'id' => (int) ($candidate['id'] ?? 0),
          'venue' => (string) ($candidate['venue_name'] ?? ''),
          'offer' => (string) ($candidate['offer_title'] ?? ''),
          'value' => (string) ($candidate['offer_value'] ?? ''),
          'score' => (int) ($candidate['score'] ?? 0),
          'reason' => (string) ($candidate['classification_reason'] ?? ''),
        ];
      }
    }

    $labels = [
      'terminal_content_quality' => 'Terminal content-quality blocker',
      'informational_title_review' => 'Informational/generic title — keep review',
      'publishing_readiness' => 'Publishing-readiness blocker',
      'low_score_and_missing_schedule' => 'Low score + missing schedule',
      'low_score_only' => 'Low score only',
      'missing_schedule_only' => 'Missing schedule only',
      'other_review' => 'Other review',
    ];

    $this->io()->title('SpotDeals Deal Discovery — Pending Queue Audit');
    $this->io()->definitionList(
      ['Pending candidates loaded' => (string) count($candidates)],
      ['Writes' => 'None'],
    );

    $summaryRows = [];
    foreach ($bucketCounts as $bucket => $count) {
      $summaryRows[] = [$labels[$bucket], (string) $count];
    }
    $this->io()->table(['Bucket', 'Count'], $summaryRows);

    $exampleRows = [];
    foreach ($examples as $bucket => $rows) {
      foreach ($rows as $row) {
        $exampleRows[] = [
          $labels[$bucket],
          (string) $row['id'],
          (string) $row['venue'],
          (string) $row['offer'],
          (string) $row['value'],
          (string) $row['score'],
          (string) $row['reason'],
        ];
      }
    }

    $this->io()->table(
      ['Bucket', 'ID', 'Venue', 'Offer', 'Value', 'Score', 'Classification reason'],
      $exampleRows,
    );

    $this->io()->success('Pending queue audit completed. No data was written.');
    return 0;
  }

  /**
   * Audits how the current confidence classifier would treat pending candidates.
   *
   * This command is strictly read-only. Discovery-time location confidence is
   * not persisted, so candidates whose stored classification explicitly records
   * a location-confidence failure are excluded. For the remaining candidates,
   * the current configured minimum is supplied only to exercise the other
   * classifier gates against the stored extraction evidence.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-classification-audit',
    aliases: ['sd:deal-discovery-classification-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-classification-audit',
    description: 'Shows which unreviewed pending candidates the current confidence classifier would newly auto-approve, without writing data.',
  )]
  public function classificationAudit(): int {
    $candidates = $this->storage->list('pending', 1000);
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredLocationConfidence = $config->get(
      'deal_discovery_auto_approve_location_confidence',
    );

    $this->io()->title('SpotDeals Deal Discovery — Classification Audit');
    $this->io()->definitionList(
      ['Pending candidates loaded' => (string) count($candidates)],
      ['Writes' => 'None'],
    );

    if ($configuredLocationConfidence === NULL) {
      $this->io()->error(
        'Automatic-approval minimum location confidence is not configured. No candidates were evaluated.',
      );
      return 1;
    }

    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $evaluated = 0;
    $newlyEligible = 0;
    $stillPending = 0;
    $skippedLocation = 0;
    $skippedReviewed = 0;
    $errors = 0;
    $eligibleRows = [];
    $pendingRows = [];

    foreach ($candidates as $candidate) {
      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        $skippedReviewed++;
        continue;
      }

      $storedReason = trim(
        (string) ($candidate['classification_reason'] ?? ''),
      );

      if (preg_match(
        '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
        $storedReason,
      ) === 1) {
        $skippedLocation++;
        continue;
      }

      $classifierCandidate = [
        'title' => (string) ($candidate['offer_title'] ?? ''),
        'value' => (string) ($candidate['offer_value'] ?? ''),
        'schedule' => (string) ($candidate['schedule'] ?? ''),
        'source_url' => (string) ($candidate['source_url'] ?? ''),
        'reason' => (string) ($candidate['reason'] ?? ''),
        'score' => (int) ($candidate['score'] ?? 0),
      ];

      try {
        $classification = $this->confidenceClassifier->classify(
          $classifierCandidate,
          $minimumLocationConfidence,
        );
      }
      catch (\Throwable $exception) {
        $errors++;
        $pendingRows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          (string) ($candidate['offer_value'] ?? ''),
          (string) ($candidate['score'] ?? 0),
          'ERROR: ' . $exception->getMessage(),
        ];
        continue;
      }

      $evaluated++;
      $row = [
        (string) ($candidate['id'] ?? 0),
        (string) ($candidate['venue_name'] ?? ''),
        (string) ($candidate['offer_title'] ?? ''),
        (string) ($candidate['offer_value'] ?? ''),
        (string) ($candidate['score'] ?? 0),
        implode('; ', array_map('strval', (array) ($classification['reasons'] ?? []))),
      ];

      if ((string) ($classification['status'] ?? '') === 'auto_approved') {
        $newlyEligible++;
        $eligibleRows[] = $row;
      }
      else {
        $stillPending++;
        $pendingRows[] = $row;
      }
    }

    $this->io()->definitionList(
      ['Evaluated against current classifier' => (string) $evaluated],
      ['Would newly auto-approve' => (string) $newlyEligible],
      ['Would remain pending' => (string) $stillPending],
      ['Skipped — original location confidence failed' => (string) $skippedLocation],
      ['Skipped — administratively reviewed' => (string) $skippedReviewed],
      ['Errors' => (string) $errors],
    );

    $this->io()->section('Would newly auto-approve');
    if ($eligibleRows === []) {
      $this->io()->text('None.');
    }
    else {
      $this->io()->table(
        ['ID', 'Venue', 'Offer', 'Value', 'Score', 'Current classifier result'],
        $eligibleRows,
      );
    }

    $this->io()->section('Would remain pending');
    if ($pendingRows === []) {
      $this->io()->text('None.');
    }
    else {
      $this->io()->table(
        ['ID', 'Venue', 'Offer', 'Value', 'Score', 'Current classifier result'],
        $pendingRows,
      );
    }

    if ($errors > 0) {
      $this->io()->warning('Classification audit completed with errors. No data was written.');
      return 1;
    }

    $this->io()->success('Classification audit completed. No data was written.');
    return 0;
  }

  /**
   * Reclassifies historical pending candidates through today's safety rules.
   *
   * Dry-run is the default. Because original discovery-time location confidence
   * is not stored, any candidate whose stored classification records a location
   * confidence failure is excluded. Administrative reviews are also excluded.
   * Candidates must pass both the current confidence classifier and the current
   * no-write publishing preview before they can be restored to auto-approved
   * status. Re-verified existing duplicates may be rejected on apply.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-pending-reclassify',
    aliases: ['sd:deal-discovery-pending-reclassify'],
  )]
  #[CLI\Option(
    name: 'apply',
    description: 'Apply safe status changes. Without this option the command is dry-run only.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-pending-reclassify',
    description: 'Preview safe historical pending reclassification without changing candidate data.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-pending-reclassify --apply',
    description: 'Restore currently eligible historical candidates to auto-approved status and reject re-verified duplicates.',
  )]
  public function pendingReclassify(
    array $options = ['apply' => FALSE],
  ): int {
    $apply = (bool) ($options['apply'] ?? FALSE);
    $candidates = $this->storage->list('pending', 1000);
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredLocationConfidence = $config->get(
      'deal_discovery_auto_approve_location_confidence',
    );

    $this->io()->title('SpotDeals Deal Discovery — Historical Pending Reclassification');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Pending candidates loaded' => (string) count($candidates)],
    );

    if ($configuredLocationConfidence === NULL) {
      $this->io()->error(
        'Automatic-approval minimum location confidence is not configured. No candidates were evaluated or changed.',
      );
      return 1;
    }

    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $evaluated = 0;
    $classifierEligible = 0;
    $stillPending = 0;
    $skippedLocation = 0;
    $skippedReviewed = 0;
    $ready = 0;
    $duplicates = 0;
    $publishingBlocked = 0;
    $changed = 0;
    $errors = 0;
    $rows = [];

    foreach ($candidates as $candidate) {
      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        $skippedReviewed++;
        continue;
      }

      $storedReason = trim(
        (string) ($candidate['classification_reason'] ?? ''),
      );
      if (preg_match(
        '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
        $storedReason,
      ) === 1) {
        $skippedLocation++;
        continue;
      }

      $classifierCandidate = [
        'title' => (string) ($candidate['offer_title'] ?? ''),
        'value' => (string) ($candidate['offer_value'] ?? ''),
        'schedule' => (string) ($candidate['schedule'] ?? ''),
        'source_url' => (string) ($candidate['source_url'] ?? ''),
        'reason' => (string) ($candidate['reason'] ?? ''),
        'score' => (int) ($candidate['score'] ?? 0),
      ];

      try {
        $classification = $this->confidenceClassifier->classify(
          $classifierCandidate,
          $minimumLocationConfidence,
        );
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          'CLASSIFIER ERROR',
          $exception->getMessage(),
        ];
        continue;
      }

      $evaluated++;
      if ((string) ($classification['status'] ?? '') !== 'auto_approved') {
        $stillPending++;
        continue;
      }

      $classifierEligible++;
      $previewCandidate = $candidate;
      $previewCandidate['status'] = 'auto_approved';

      try {
        $preview = $this->previewService->preview($previewCandidate);
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          'PREVIEW ERROR',
          $exception->getMessage(),
        ];
        continue;
      }

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $duplicateNid = !empty($deal['duplicate_found'])
        ? (int) ($deal['duplicate_nid'] ?? 0)
        : 0;

      if ($duplicateNid > 0) {
        $duplicates++;
        $action = 'WOULD REJECT DUPLICATE';
        if ($apply) {
          if ($this->storage->markRejectedAsDuplicate(
            (int) $candidate['id'],
            $duplicateNid,
          )) {
            $changed++;
            $action = 'REJECTED DUPLICATE';
          }
          else {
            $action = 'SKIPPED';
          }
        }

        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          $action,
          'Existing matching deal node ' . $duplicateNid . '.',
        ];
        continue;
      }

      if (!empty($preview['ready'])) {
        $ready++;
        $action = 'WOULD AUTO-APPROVE';
        if ($apply) {
          if ($this->storage->restoreAutoApprovalAfterHistoricalReclassification(
            (int) $candidate['id'],
          )) {
            $changed++;
            $action = 'AUTO-APPROVED';
          }
          else {
            $action = 'SKIPPED';
          }
        }

        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          $action,
          'Current classifier and publishing preview both pass.',
        ];
        continue;
      }

      $publishingBlocked++;
      $rows[] = [
        (string) ($candidate['id'] ?? 0),
        (string) ($candidate['venue_name'] ?? ''),
        (string) ($candidate['offer_title'] ?? ''),
        'PUBLISHING BLOCKED',
        $this->previewBlockingSummary($preview),
      ];
    }

    $this->io()->section('Classifier-eligible candidates');
    if ($rows === []) {
      $this->io()->text('None.');
    }
    else {
      $this->io()->table(
        ['ID', 'Venue', 'Offer', 'Action', 'Current result'],
        $rows,
      );
    }

    $this->io()->definitionList(
      ['Evaluated against current classifier' => (string) $evaluated],
      ['Classifier eligible' => (string) $classifierEligible],
      ['Still pending by classifier' => (string) $stillPending],
      ['Skipped — original location confidence failed' => (string) $skippedLocation],
      ['Skipped — administratively reviewed' => (string) $skippedReviewed],
      ['Publishing preview ready' => (string) $ready],
      ['Duplicates re-verified' => (string) $duplicates],
      ['Publishing preview blocked' => (string) $publishingBlocked],
      [$apply ? 'Records changed' : 'Would change' => (string) ($apply ? $changed : ($ready + $duplicates))],
      ['Errors' => (string) $errors],
    );

    if ($errors > 0) {
      $this->io()->warning('Historical pending reclassification completed with errors. Review the rows above.');
      return 1;
    }

    if ($apply) {
      $this->io()->success('Historical pending reclassification completed.');
    }
    else {
      $this->io()->success('Dry run completed. No data was written.');
    }

    return 0;
  }

  /**
   * Audits the extraction evidence behind every unreviewed pending candidate.
   *
   * This command is strictly read-only. It reruns the current classifier using
   * the same historical location-confidence safety rule as the classification
   * audit, then assigns each candidate to one primary diagnostic family. The
   * output intentionally includes the stored extraction reason and source URL
   * so extractor changes can be based on observed evidence instead of score
   * thresholds alone.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-pending-extraction-audit',
    aliases: ['sd:deal-discovery-pending-extraction-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-pending-extraction-audit',
    description: 'Groups pending candidates by extraction/classification failure family and prints their evidence without writing data.',
  )]
  public function pendingExtractionAudit(): int {
    $candidates = $this->storage->list('pending', 1000);
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredLocationConfidence = $config->get(
      'deal_discovery_auto_approve_location_confidence',
    );

    $this->io()->title('SpotDeals Deal Discovery — Pending Extraction Audit');
    $this->io()->definitionList(
      ['Pending candidates loaded' => (string) count($candidates)],
      ['Writes' => 'None'],
    );

    if ($configuredLocationConfidence === NULL) {
      $this->io()->error(
        'Automatic-approval minimum location confidence is not configured. No candidates were evaluated.',
      );
      return 1;
    }

    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $familyLabels = [
      'content_quality_or_title' => 'Content quality / title extraction',
      'temporal_validity' => 'Temporal validity / stale-date ambiguity',
      'multiple_offers_mixed' => 'Multiple offers mixed into one title',
      'weak_extraction_binding' => 'Weak extraction binding',
      'missing_required_content' => 'Missing required extracted content',
      'low_score_and_missing_schedule' => 'Low score + missing schedule',
      'missing_schedule_only' => 'Missing schedule only',
      'low_score_only' => 'Low score only',
      'original_location_safety' => 'Original location-confidence safety skip',
      'administratively_reviewed' => 'Administratively reviewed — excluded',
      'other_review' => 'Other classifier review',
      'error' => 'Runtime error',
    ];
    $familyCounts = array_fill_keys(array_keys($familyLabels), 0);
    $rowsByFamily = [];
    $evaluated = 0;
    $errors = 0;

    foreach ($candidates as $candidate) {
      $id = (int) ($candidate['id'] ?? 0);
      $venue = (string) ($candidate['venue_name'] ?? '');
      $title = (string) ($candidate['offer_title'] ?? '');
      $value = (string) ($candidate['offer_value'] ?? '');
      $schedule = (string) ($candidate['schedule'] ?? '');
      $sourceUrl = (string) ($candidate['source_url'] ?? '');
      $extractorReason = (string) ($candidate['reason'] ?? '');
      $score = (int) ($candidate['score'] ?? 0);
      $binding = $this->extractBindingFromReason($extractorReason);

      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        $family = 'administratively_reviewed';
        $familyCounts[$family]++;
        $rowsByFamily[$family][] = [
          (string) $id,
          $venue,
          $title,
          $value,
          $schedule,
          (string) $score,
          $binding,
          $extractorReason,
          (string) ($candidate['classification_reason'] ?? ''),
          $sourceUrl,
        ];
        continue;
      }

      $storedReason = trim(
        (string) ($candidate['classification_reason'] ?? ''),
      );
      if (preg_match(
        '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
        $storedReason,
      ) === 1) {
        $family = 'original_location_safety';
        $familyCounts[$family]++;
        $rowsByFamily[$family][] = [
          (string) $id,
          $venue,
          $title,
          $value,
          $schedule,
          (string) $score,
          $binding,
          $extractorReason,
          $storedReason,
          $sourceUrl,
        ];
        continue;
      }

      $classifierCandidate = [
        'title' => $title,
        'value' => $value,
        'schedule' => $schedule,
        'source_url' => $sourceUrl,
        'reason' => $extractorReason,
        'score' => $score,
      ];

      try {
        $classification = $this->confidenceClassifier->classify(
          $classifierCandidate,
          $minimumLocationConfidence,
        );
      }
      catch (\Throwable $exception) {
        $errors++;
        $family = 'error';
        $familyCounts[$family]++;
        $rowsByFamily[$family][] = [
          (string) $id,
          $venue,
          $title,
          $value,
          $schedule,
          (string) $score,
          $binding,
          $extractorReason,
          'ERROR: ' . $exception->getMessage(),
          $sourceUrl,
        ];
        continue;
      }

      $evaluated++;
      $classifierReasons = array_map(
        'strval',
        (array) ($classification['reasons'] ?? []),
      );
      $family = $this->pendingExtractionFailureFamily(
        $classifierReasons,
        $title,
        $schedule,
        $extractorReason,
      );
      $familyCounts[$family]++;
      $rowsByFamily[$family][] = [
        (string) $id,
        $venue,
        $title,
        $value,
        $schedule,
        (string) $score,
        $binding,
        $extractorReason,
        implode('; ', $classifierReasons),
        $sourceUrl,
      ];
    }

    $summaryRows = [];
    foreach ($familyLabels as $family => $label) {
      if ($familyCounts[$family] > 0) {
        $summaryRows[] = [$label, (string) $familyCounts[$family]];
      }
    }

    $this->io()->section('Primary failure families');
    $this->io()->table(['Family', 'Count'], $summaryRows);
    $this->io()->definitionList(
      ['Evaluated against current classifier' => (string) $evaluated],
      ['Errors' => (string) $errors],
    );

    foreach ($familyLabels as $family => $label) {
      $rows = $rowsByFamily[$family] ?? [];
      if ($rows === []) {
        continue;
      }

      $this->io()->section($label . ' (' . count($rows) . ')');
      $this->io()->table(
        ['ID', 'Venue', 'Offer', 'Value', 'Schedule', 'Score', 'Binding', 'Extractor reason', 'Current classifier reason', 'Source URL'],
        $rows,
      );
    }

    if ($errors > 0) {
      $this->io()->warning('Pending extraction audit completed with runtime errors. No data was written.');
      return 1;
    }

    $this->io()->success('Pending extraction audit completed. No data was written.');
    return 0;
  }


  /**
   * Diagnoses low-score pending candidates that also lack a schedule.
   *
   * This command is strictly read-only. Candidate selection uses the current
   * classifier, and every strong-evidence gate is reported by the classifier's
   * own diagnostic method so this command never mirrors private gate logic.
   * The purpose is to isolate the largest unresolved pending family without
   * weakening score or schedule requirements globally.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-missing-schedule-evidence-audit',
    aliases: ['sd:deal-discovery-missing-schedule-evidence-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-missing-schedule-evidence-audit',
    description: 'Shows the exact strong-evidence bypass gates failed by current low-score + missing-schedule pending candidates without writing data.',
  )]
  public function missingScheduleEvidenceAudit(): int {
    $candidates = $this->storage->list('pending', 1000);
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredLocationConfidence = $config->get(
      'deal_discovery_auto_approve_location_confidence',
    );

    $this->io()->title('SpotDeals Deal Discovery — Missing-Schedule Evidence Audit');
    $this->io()->definitionList(
      ['Pending candidates loaded' => (string) count($candidates)],
      ['Scope' => 'Current low-score + missing-schedule pending candidates'],
      ['Writes' => 'None'],
    );

    if ($configuredLocationConfidence === NULL) {
      $this->io()->error(
        'Automatic-approval minimum location confidence is not configured. No candidates were evaluated.',
      );
      return 1;
    }

    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $rows = [];
    $gateFailureCounts = [];
    $eligibleScope = 0;
    $strongBypassPasses = 0;
    $errors = 0;

    foreach ($candidates as $candidate) {
      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        continue;
      }

      $storedReason = trim(
        (string) ($candidate['classification_reason'] ?? ''),
      );
      if (preg_match(
        '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
        $storedReason,
      ) === 1) {
        continue;
      }

      $classifierCandidate = [
        'title' => (string) ($candidate['offer_title'] ?? ''),
        'value' => (string) ($candidate['offer_value'] ?? ''),
        'schedule' => (string) ($candidate['schedule'] ?? ''),
        'source_url' => (string) ($candidate['source_url'] ?? ''),
        'reason' => (string) ($candidate['reason'] ?? ''),
        'score' => (int) ($candidate['score'] ?? 0),
      ];

      try {
        $classification = $this->confidenceClassifier->classify(
          $classifierCandidate,
          $minimumLocationConfidence,
        );
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          (string) ($candidate['offer_value'] ?? ''),
          (string) ($candidate['schedule'] ?? ''),
          (string) ($candidate['score'] ?? 0),
          $this->extractBindingFromReason((string) ($candidate['reason'] ?? '')),
          'ERROR',
          $exception->getMessage(),
          (string) ($candidate['source_url'] ?? ''),
        ];
        continue;
      }

      $classifierReasons = array_map(
        'strval',
        (array) ($classification['reasons'] ?? []),
      );
      $joinedReasons = mb_strtolower(implode('; ', $classifierReasons));
      $hasLowScore = preg_match(
        '/score\s+\d+\s+is\s+below\s+configured\s+automatic-approval\s+score\s+\d+/u',
        $joinedReasons,
      ) === 1;
      $hasMissingSchedule = str_contains(
        $joinedReasons,
        'schedule or validity context is missing',
      );

      if (
        ($classification['status'] ?? '') !== 'pending'
        || !$hasLowScore
        || !$hasMissingSchedule
      ) {
        continue;
      }

      $eligibleScope++;

      try {
        $diagnostic = $this->confidenceClassifier
          ->diagnoseStrongExplicitOfferEvidence($classifierCandidate);
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          (string) ($candidate['offer_value'] ?? ''),
          (string) ($candidate['schedule'] ?? ''),
          (string) ($candidate['score'] ?? 0),
          $this->extractBindingFromReason((string) ($candidate['reason'] ?? '')),
          'ERROR',
          $exception->getMessage(),
          (string) ($candidate['source_url'] ?? ''),
        ];
        continue;
      }

      $strongBypass = !empty($diagnostic['strong_explicit_offer']);
      if ($strongBypass) {
        $strongBypassPasses++;
      }

      $failed = [];
      foreach ((array) ($diagnostic['gates'] ?? []) as $gate => $result) {
        if (!empty($result['passed'])) {
          continue;
        }
        $label = str_replace('_', ' ', (string) $gate);
        $detail = trim((string) ($result['detail'] ?? ''));
        $failed[] = $detail !== '' ? $label . ': ' . $detail : $label;
        $gateFailureCounts[$label] = ($gateFailureCounts[$label] ?? 0) + 1;
      }

      $rows[] = [
        (string) ($candidate['id'] ?? 0),
        (string) ($candidate['venue_name'] ?? ''),
        (string) ($candidate['offer_title'] ?? ''),
        (string) ($candidate['offer_value'] ?? ''),
        (string) ($candidate['schedule'] ?? ''),
        (string) ($candidate['score'] ?? 0),
        $this->extractBindingFromReason((string) ($candidate['reason'] ?? '')),
        $strongBypass ? 'PASS' : 'FAIL',
        $failed === [] ? 'none' : implode('; ', $failed),
        (string) ($candidate['source_url'] ?? ''),
      ];
    }

    arsort($gateFailureCounts);
    $failureSummaryRows = [];
    foreach ($gateFailureCounts as $gate => $count) {
      $failureSummaryRows[] = [$gate, (string) $count];
    }

    $this->io()->section('Failed strong-evidence gates');
    if ($failureSummaryRows === []) {
      $this->io()->text('None.');
    }
    else {
      $this->io()->table(['Gate', 'Candidates failing'], $failureSummaryRows);
    }

    $this->io()->section('Candidate diagnostics');
    $this->io()->table(
      ['ID', 'Venue', 'Offer', 'Value', 'Schedule', 'Score', 'Binding', 'Strong bypass', 'Exact failed gate(s)', 'Source URL'],
      $rows,
    );

    $this->io()->definitionList(
      ['Low-score + missing-schedule candidates diagnosed' => (string) $eligibleScope],
      ['Already pass current strong bypass' => (string) $strongBypassPasses],
      ['Errors' => (string) $errors],
    );

    if ($errors > 0) {
      $this->io()->warning('Missing-schedule evidence audit completed with runtime errors. No data was written.');
      return 1;
    }

    $this->io()->success('Missing-schedule evidence audit completed. No data was written.');
    return 0;
  }


  /**
   * Diagnoses why low-score-only pending candidates miss the strong bypass.
   *
   * This command is strictly read-only. Candidate selection uses the current
   * classifier, and every strong-evidence gate is reported by the classifier's
   * own diagnostic method so this command never mirrors private gate logic.
   */
  #[CLI\Command(
    name: 'spotdeals:deal-discovery-strong-evidence-audit',
    aliases: ['sd:deal-discovery-strong-evidence-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-strong-evidence-audit',
    description: 'Shows the exact strong-evidence bypass gates failed by current low-score-only pending candidates without writing data.',
  )]
  public function strongEvidenceAudit(): int {
    $candidates = $this->storage->list('pending', 1000);
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $configuredLocationConfidence = $config->get(
      'deal_discovery_auto_approve_location_confidence',
    );

    $this->io()->title('SpotDeals Deal Discovery — Strong-Evidence Gate Audit');
    $this->io()->definitionList(
      ['Pending candidates loaded' => (string) count($candidates)],
      ['Scope' => 'Current low-score-only pending candidates'],
      ['Writes' => 'None'],
    );

    if ($configuredLocationConfidence === NULL) {
      $this->io()->error(
        'Automatic-approval minimum location confidence is not configured. No candidates were evaluated.',
      );
      return 1;
    }

    $minimumLocationConfidence = (int) $configuredLocationConfidence;
    $rows = [];
    $gateFailureCounts = [];
    $eligibleScope = 0;
    $errors = 0;

    foreach ($candidates as $candidate) {
      if (
        (int) ($candidate['reviewed_by'] ?? 0) > 0
        || (int) ($candidate['reviewed_at'] ?? 0) > 0
      ) {
        continue;
      }

      $storedReason = trim(
        (string) ($candidate['classification_reason'] ?? ''),
      );
      if (preg_match(
        '/location confidence\s+\d+\s+is\s+below\s+configured\s+minimum\s+\d+/iu',
        $storedReason,
      ) === 1) {
        continue;
      }

      $classifierCandidate = [
        'title' => (string) ($candidate['offer_title'] ?? ''),
        'value' => (string) ($candidate['offer_value'] ?? ''),
        'schedule' => (string) ($candidate['schedule'] ?? ''),
        'source_url' => (string) ($candidate['source_url'] ?? ''),
        'reason' => (string) ($candidate['reason'] ?? ''),
        'score' => (int) ($candidate['score'] ?? 0),
      ];

      try {
        $classification = $this->confidenceClassifier->classify(
          $classifierCandidate,
          $minimumLocationConfidence,
        );
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          (string) ($candidate['offer_value'] ?? ''),
          (string) ($candidate['score'] ?? 0),
          $this->extractBindingFromReason((string) ($candidate['reason'] ?? '')),
          'ERROR',
          $exception->getMessage(),
        ];
        continue;
      }

      $classifierReasons = array_map(
        'strval',
        (array) ($classification['reasons'] ?? []),
      );
      $joinedReasons = mb_strtolower(implode('; ', $classifierReasons));
      $hasLowScore = preg_match(
        '/score\s+\d+\s+is\s+below\s+configured\s+automatic-approval\s+score\s+\d+/u',
        $joinedReasons,
      ) === 1;
      $hasMissingSchedule = str_contains(
        $joinedReasons,
        'schedule or validity context is missing',
      );

      if (
        ($classification['status'] ?? '') !== 'pending'
        || !$hasLowScore
        || $hasMissingSchedule
      ) {
        continue;
      }

      $eligibleScope++;

      try {
        $diagnostic = $this->confidenceClassifier
          ->diagnoseStrongExplicitOfferEvidence($classifierCandidate);
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) ($candidate['id'] ?? 0),
          (string) ($candidate['venue_name'] ?? ''),
          (string) ($candidate['offer_title'] ?? ''),
          (string) ($candidate['offer_value'] ?? ''),
          (string) ($candidate['score'] ?? 0),
          $this->extractBindingFromReason((string) ($candidate['reason'] ?? '')),
          'ERROR',
          $exception->getMessage(),
        ];
        continue;
      }

      $failed = [];
      foreach ((array) ($diagnostic['gates'] ?? []) as $gate => $result) {
        if (!empty($result['passed'])) {
          continue;
        }
        $label = str_replace('_', ' ', (string) $gate);
        $detail = trim((string) ($result['detail'] ?? ''));
        $failed[] = $detail !== '' ? $label . ': ' . $detail : $label;
        $gateFailureCounts[$label] = ($gateFailureCounts[$label] ?? 0) + 1;
      }

      $rows[] = [
        (string) ($candidate['id'] ?? 0),
        (string) ($candidate['venue_name'] ?? ''),
        (string) ($candidate['offer_title'] ?? ''),
        (string) ($candidate['offer_value'] ?? ''),
        (string) ($candidate['score'] ?? 0),
        $this->extractBindingFromReason((string) ($candidate['reason'] ?? '')),
        !empty($diagnostic['strong_explicit_offer']) ? 'PASS' : 'FAIL',
        $failed === [] ? 'none' : implode('; ', $failed),
      ];
    }

    arsort($gateFailureCounts);
    $failureSummaryRows = [];
    foreach ($gateFailureCounts as $gate => $count) {
      $failureSummaryRows[] = [$gate, (string) $count];
    }

    $this->io()->section('Failed strong-evidence gates');
    if ($failureSummaryRows === []) {
      $this->io()->text('None.');
    }
    else {
      $this->io()->table(['Gate', 'Candidates failing'], $failureSummaryRows);
    }

    $this->io()->section('Candidate diagnostics');
    $this->io()->table(
      ['ID', 'Venue', 'Offer', 'Value', 'Score', 'Binding', 'Strong bypass', 'Exact failed gate(s)'],
      $rows,
    );

    $this->io()->definitionList(
      ['Low-score-only candidates diagnosed' => (string) $eligibleScope],
      ['Errors' => (string) $errors],
    );

    if ($errors > 0) {
      $this->io()->warning('Strong-evidence gate audit completed with runtime errors. No data was written.');
      return 1;
    }

    $this->io()->success('Strong-evidence gate audit completed. No data was written.');
    return 0;
  }

  /**
   * Finds previously routed duplicate candidates and optionally rejects them.
   *
   * The command is dry-run by default. It only inspects pending candidates
   * already carrying a duplicate publishing-readiness reason, then re-verifies
   * each candidate through the current no-write publishing preview before any
   * status change is allowed.
   */

  #[CLI\Command(
    name: 'spotdeals:deal-discovery-duplicate-cleanup',
    aliases: ['sd:deal-discovery-duplicate-cleanup'],
  )]
  #[CLI\Option(
    name: 'apply',
    description: 'Reject verified duplicate candidates. Without this option the command is dry-run only.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-duplicate-cleanup',
    description: 'Preview pending duplicate cleanup without changing candidate data.',
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-discovery-duplicate-cleanup --apply',
    description: 'Reject pending candidates that are re-verified as existing deal duplicates.',
  )]
  public function duplicateCleanup(
    array $options = ['apply' => FALSE],
  ): int {
    $apply = (bool) ($options['apply'] ?? FALSE);
    $candidates = $this->storage->list('pending', 1000);

    $this->io()->title('SpotDeals Deal Discovery — Duplicate Cleanup');
    $this->io()->definitionList(
      ['Mode' => $apply ? 'APPLY' : 'DRY RUN'],
      ['Pending candidates loaded' => (string) count($candidates)],
    );

    $rows = [];
    $flagged = 0;
    $verified = 0;
    $changed = 0;
    $errors = 0;

    foreach ($candidates as $candidate) {
      $classificationReason = mb_strtolower(
        trim((string) ($candidate['classification_reason'] ?? '')),
      );
      if (!str_contains($classificationReason, 'duplicate deal already exists')) {
        continue;
      }

      $flagged++;
      $previewCandidate = $candidate;
      $previewCandidate['status'] = 'approved';

      try {
        $preview = $this->previewService->preview($previewCandidate);
      }
      catch (\Throwable $exception) {
        $errors++;
        $rows[] = [
          (string) $candidate['id'],
          (string) $candidate['venue_name'],
          (string) $candidate['offer_title'],
          'ERROR',
          '—',
          $exception->getMessage(),
        ];
        continue;
      }

      $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
      $duplicateNid = !empty($deal['duplicate_found'])
        ? (int) ($deal['duplicate_nid'] ?? 0)
        : 0;

      if ($duplicateNid <= 0) {
        $rows[] = [
          (string) $candidate['id'],
          (string) $candidate['venue_name'],
          (string) $candidate['offer_title'],
          'NOT VERIFIED',
          '—',
          'Current publishing preview no longer finds an existing duplicate.',
        ];
        continue;
      }

      $verified++;
      $action = 'WOULD REJECT';
      if ($apply) {
        if ($this->storage->markRejectedAsDuplicate(
          (int) $candidate['id'],
          $duplicateNid,
        )) {
          $changed++;
          $action = 'REJECTED';
        }
        else {
          $action = 'SKIPPED';
        }
      }

      $rows[] = [
        (string) $candidate['id'],
        (string) $candidate['venue_name'],
        (string) $candidate['offer_title'],
        $action,
        (string) $duplicateNid,
        'Existing matching deal re-verified by the current publishing preview.',
      ];
    }

    $this->io()->table(
      ['ID', 'Venue', 'Offer', 'Action', 'Duplicate NID', 'Reason'],
      $rows,
    );

    $this->io()->definitionList(
      ['Previously duplicate-flagged pending candidates' => (string) $flagged],
      ['Duplicates re-verified' => (string) $verified],
      [$apply ? 'Rejected' : 'Would reject' => (string) ($apply ? $changed : $verified)],
      ['Errors' => (string) $errors],
    );

    if ($errors > 0) {
      $this->io()->warning('Duplicate cleanup completed with errors. Review the rows above.');
      return 1;
    }

    if ($apply) {
      $this->io()->success('Verified duplicate cleanup completed.');
    }
    else {
      $this->io()->success('Dry run completed. No data was written.');
    }

    return 0;
  }

  #[CLI\Command(
    name: 'spotdeals:deal-content-quality-audit',
    aliases: ['sd:deal-content-quality-audit'],
  )]
  #[CLI\Usage(
    name: 'drush spotdeals:deal-content-quality-audit',
    description: 'Audits discovery candidates and published deal titles for deterministic content-quality problems.',
  )]
  public function contentQualityAudit(): int {
    $audit = $this->contentQualityAuditService->audit();
    $summary = $audit['summary'];

    $this->io()->title('SpotDeals Deal Discovery — Content Quality Audit');
    $this->io()->definitionList(
      ['Candidates checked' => (string) $summary['candidates_checked']],
      ['Published deals checked' => (string) $summary['published_deals_checked']],
      ['Candidate issues' => (string) $summary['candidate_issues']],
      ['Published content issues' => (string) $summary['published_content_issues']],
      ['Missing published nodes' => (string) $summary['missing_published_nodes']],
      ['Writes' => 'None'],
    );

    $rows = [];
    foreach ($audit['rows'] as $row) {
      $rows[] = [
        (string) $row['candidate_id'],
        (string) $row['deal_nid'],
        (string) $row['scope'],
        (string) $row['field'],
        (string) $row['current'],
        (string) $row['suggested'],
        (string) $row['issue'],
      ];
    }

    $this->io()->table(
      ['Candidate ID', 'Deal NID', 'Scope', 'Field', 'Current', 'Suggested', 'Issue'],
      $rows,
    );

    if ($summary['published_content_issues'] > 0 || $summary['missing_published_nodes'] > 0) {
      $this->io()->warning('Content-quality issues were found. No data was written.');
      return 2;
    }

    if ($summary['candidate_issues'] > 0) {
      $this->io()->note('Candidate extraction artifacts were found. Published content is currently clean. No data was written.');
      return 0;
    }

    $this->io()->success('Content-quality audit completed with no issues. No data was written.');
    return 0;
  }



  /**
   * Returns one mutually exclusive diagnostic family for a pending candidate.
   *
   * @param string[] $classifierReasons
   *   Current classifier reasons.
   */
  private function pendingExtractionFailureFamily(
    array $classifierReasons,
    string $title,
    string $schedule,
    string $extractorReason,
  ): string {
    $joined = mb_strtolower(implode('; ', $classifierReasons));

    if (
      str_contains($joined, 'content quality:')
      || str_contains($joined, 'content quality review:')
      || $this->looksLikeWeakPromotionalContainerTitle($title)
    ) {
      return 'content_quality_or_title';
    }

    if (str_contains($joined, 'validity review:')) {
      return 'temporal_validity';
    }

    if ($this->titleContainsMultipleDistinctOfferValues($title)) {
      return 'multiple_offers_mixed';
    }

    if ($this->extractBindingFromReason($extractorReason) === 'text_fallback') {
      return 'weak_extraction_binding';
    }

    if (
      str_contains($joined, 'source url is missing')
      || str_contains($joined, 'offer value is missing')
      || str_contains($joined, 'offer title is missing')
    ) {
      return 'missing_required_content';
    }

    $hasLowScore = preg_match(
      '/score\s+\d+\s+is\s+below\s+configured\s+automatic-approval\s+score\s+\d+/u',
      $joined,
    ) === 1;
    $hasMissingSchedule = str_contains(
      $joined,
      'schedule or validity context is missing',
    );

    if ($hasLowScore && $hasMissingSchedule) {
      return 'low_score_and_missing_schedule';
    }
    if ($hasMissingSchedule || trim($schedule) === '') {
      return 'missing_schedule_only';
    }
    if ($hasLowScore) {
      return 'low_score_only';
    }

    return 'other_review';
  }

  /**
   * Extracts the DOM-binding label persisted by the discovery extractor.
   */
  private function extractBindingFromReason(string $reason): string {
    if (preg_match('/(?:^|;\s*)binding=([a-z0-9_-]+)/i', $reason, $matches) !== 1) {
      return 'unknown';
    }

    return mb_strtolower((string) $matches[1]);
  }

  /**
   * Diagnostic mirror of the classifier's weak promotional container labels.
   */
  private function looksLikeWeakPromotionalContainerTitle(string $title): bool {
    $normalized = mb_strtolower(
      trim(preg_replace('/\s+/u', ' ', $title) ?? $title),
    );

    return preg_match(
      '/^(?:free|deals?|offers?|special\s+offers?|promotions?|discounts?|savings?|ways\s+to\s+save|programs?\s*(?:&|and)\s*special\s+offers?|free\s+days?\s*(?:&|and)\s*other\s+free\s+admission\s+offers?|opportunities\s+for\s+free\s+admission)$/iu',
      $normalized,
    ) === 1;
  }

  /**
   * Detects more than one distinct percentage/currency-off value in a title.
   */
  private function titleContainsMultipleDistinctOfferValues(string $title): bool {
    preg_match_all(
      '/\b\d{1,3}(?:\.\d+)?\s*%\s*off\b|[$£€]\s*\d+(?:\.\d{1,2})?\s*off\b/iu',
      $title,
      $matches,
    );

    $values = array_map(
      static fn(string $match): string => mb_strtolower(
        preg_replace('/\s+/u', '', $match) ?? $match,
      ),
      $matches[0] ?? [],
    );

    return count(array_unique($values)) > 1;
  }

  /**
   * Returns a concise explanation for a preview that remains blocked.
   *
   * @param array<string, mixed> $preview
   *   Publishing preview result.
   */
  private function previewBlockingSummary(array $preview): string {
    $reasons = [];

    foreach ((array) ($preview['errors'] ?? []) as $error) {
      $error = trim((string) $error);
      if ($error !== '') {
        $reasons[] = $error;
      }
    }

    $venue = is_array($preview['venue'] ?? NULL) ? $preview['venue'] : [];
    foreach ((array) ($venue['errors'] ?? []) as $error) {
      $error = trim((string) $error);
      if ($error !== '') {
        $reasons[] = $error;
      }
    }

    $deal = is_array($preview['deal'] ?? NULL) ? $preview['deal'] : [];
    foreach ((array) ($deal['blocking_fields'] ?? []) as $field => $message) {
      $message = trim((string) $message);
      if ($message !== '') {
        $reasons[] = (string) $field . ': ' . $message;
      }
    }

    if ($reasons === []) {
      $reasons[] = 'The current publishing preview is not ready.';
    }

    return implode('; ', array_values(array_unique($reasons)));
  }


}
