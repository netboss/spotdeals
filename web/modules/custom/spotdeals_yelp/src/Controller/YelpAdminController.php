<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\spotdeals_yelp\Service\YelpVenueSyncManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin pages for SpotDeals Yelp.
 */
final class YelpAdminController extends ControllerBase {

  /**
   * Constructs the admin controller.
   */
  public function __construct(
    private readonly YelpVenueSyncManager $syncManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('spotdeals_yelp.venue_sync_manager'));
  }

  /**
   * Builds a needs-review table.
   */
  public function review(): array {
    $rows = [];
    foreach ($this->syncManager->getNeedsReviewVenues(100) as $venue) {
      $addressField = $venue->get('field_address');
      $address = !$addressField->isEmpty() ? $addressField->first() : NULL;
      $rows[] = [
        'title' => $venue->toLink()->toString(),
        'address' => trim(implode(', ', array_filter([
          $address?->address_line1 ?? '',
          $address?->locality ?? '',
          $address?->administrative_area ?? '',
          $address?->postal_code ?? '',
        ]))),
        'phone' => (string) ($venue->get('field_phone')->value ?? ''),
        'status' => (string) ($venue->get('field_yelp_match_status')->value ?? ''),
        'yelp_business_id' => (string) ($venue->get('field_yelp_business_id')->value ?? ''),
      ];
    }

    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('These venues still need manual Yelp review before they should be trusted as matched.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Venue'),
          $this->t('Address'),
          $this->t('Phone'),
          $this->t('Status'),
          $this->t('Yelp business ID'),
        ],
        '#rows' => array_map(static fn(array $row): array => array_values($row), $rows),
        '#empty' => $this->t('No venues currently need Yelp review.'),
      ],
    ];
  }

  /**
   * Builds a status summary page.
   */
  public function status(): array {
    $counts = $this->syncManager->getStatusCounts();
    $rows = [];
    foreach ($counts as $label => $value) {
      $rows[] = [$label, (string) $value];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Count')],
        '#rows' => $rows,
      ],
    ];
  }

}
