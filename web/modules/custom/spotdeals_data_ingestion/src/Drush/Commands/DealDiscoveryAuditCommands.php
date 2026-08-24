<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Drush\Commands;

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
      ['Not approved' => (string) $summary['not_eligible']],
      ['Published' => (string) $summary['published']],
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

}
