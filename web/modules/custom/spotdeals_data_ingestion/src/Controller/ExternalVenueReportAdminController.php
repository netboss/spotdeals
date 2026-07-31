<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\spotdeals_data_ingestion\Service\ExternalVenueReportStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExternalVenueReportAdminController extends ControllerBase {

  public function __construct(private readonly ExternalVenueReportStorage $storage, private readonly DateFormatterInterface $dateFormatter) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('spotdeals_data_ingestion.external_venue_report_storage'), $container->get('date.formatter'));
  }

  public function listing(Request $request): array {
    $status = (string) $request->query->get('status', 'pending');
    $allowed = ['pending', 'closed', 'invalid', 'excluded', 'restored', 'dismissed', 'all'];
    if (!in_array($status, $allowed, TRUE)) {
      $status = 'pending';
    }
    $rows = [];
    foreach ($this->storage->list($status) as $report) {
      $rows[] = [
        $report['id'],
        ['data' => ['#markup' => '<strong>' . htmlspecialchars($report['venue_name']) . '</strong><br><small>' . htmlspecialchars($report['venue_address']) . '</small>']],
        $report['reason'],
        $report['status'],
        $this->dateFormatter->format((int) $report['created'], 'short'),
        ['data' => ['#type' => 'link', '#title' => $this->t('Review'), '#url' => Url::fromRoute('spotdeals_data_ingestion.external_venue_report_review', ['report_id' => $report['id']])]],
      ];
    }
    return [
      'filters' => ['#type' => 'container', 'links' => ['#theme' => 'links', '#links' => array_combine($allowed, array_map(fn($item) => ['title' => ucfirst($item), 'url' => Url::fromRoute('spotdeals_data_ingestion.external_venue_reports', [], ['query' => ['status' => $item]])], $allowed))]],
      'table' => ['#type' => 'table', '#header' => [$this->t('ID'), $this->t('Venue'), $this->t('Reason'), $this->t('Status'), $this->t('Submitted'), $this->t('Operations')], '#rows' => $rows, '#empty' => $this->t('No external venue reports found.')],
    ];
  }
}
