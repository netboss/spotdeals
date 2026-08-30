<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryContentQualityAuditService;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryPublishAuditService;
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

}
