<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Checks whether a venue already exists in SpotDeals.
 */
final class VenueDuplicateChecker {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks for an exact title and address match.
   */
  public function exists(array $venue): bool {
    $title = trim((string) ($venue['title'] ?? ''));
    $address = is_array($venue['address'] ?? NULL)
      ? $venue['address']
      : [];

    $addressLine1 = trim((string) ($address['address_line1'] ?? ''));
    $city = trim((string) ($address['locality'] ?? ''));
    $state = trim((string) ($address['administrative_area'] ?? ''));
    $postalCode = trim((string) ($address['postal_code'] ?? ''));

    if ($title === '' || $addressLine1 === '') {
      return FALSE;
    }

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'venue')
      ->condition('title', $title)
      ->condition('field_address.address_line1', $addressLine1);

    if ($city !== '') {
      $query->condition('field_address.locality', $city);
    }

    if ($state !== '') {
      $query->condition('field_address.administrative_area', $state);
    }

    if ($postalCode !== '') {
      $query->condition('field_address.postal_code', $postalCode);
    }

    $query->range(0, 1);

    return $query->execute() !== [];
  }

}
