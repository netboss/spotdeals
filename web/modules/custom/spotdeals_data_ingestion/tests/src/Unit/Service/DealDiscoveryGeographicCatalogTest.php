<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryGeographicCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/src/Service/DealDiscoveryGeographicCatalog.php';

/**
 * Protects the independent geographic discovery catalog from regressions.
 */
#[Group('spotdeals_data_ingestion')]
final class DealDiscoveryGeographicCatalogTest extends TestCase {

  public function testLocationsAreWellFormedAndUnique(): void {
    $locations = DealDiscoveryGeographicCatalog::locations();
    self::assertCount(388, $locations);

    $identities = [];
    $states = [];
    foreach ($locations as $location) {
      self::assertSame(['city', 'state', 'country'], array_keys($location));
      self::assertNotSame('', trim($location['city']));
      self::assertSame($location['city'], trim($location['city']));
      self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $location['state']);
      self::assertSame('US', $location['country']);

      $states[$location['state']] = TRUE;
      $identity = $location['country'] . ':' . $location['state'] . ':'
        . strtolower(preg_replace('/[^a-z0-9]/i', '', $location['city']));
      self::assertArrayNotHasKey($identity, $identities, 'Duplicate geographic target: ' . $identity);
      $identities[$identity] = TRUE;
    }

    self::assertCount(34, $states);
    foreach (['AL', 'CT', 'DC', 'DE', 'FL', 'GA', 'IA', 'IL', 'IN', 'KS', 'KY', 'MA', 'MD', 'ME', 'MI', 'MN', 'MO', 'MS', 'NC', 'ND', 'NE', 'NH', 'NJ', 'NY', 'OH', 'PA', 'RI', 'SC', 'SD', 'TN', 'VA', 'VT', 'WI', 'WV'] as $state) {
      self::assertArrayHasKey($state, $states);
    }
  }

  public function testRepresentativeNewMarketsRemainAvailable(): void {
    $locations = DealDiscoveryGeographicCatalog::locations();
    foreach ([
      ['Chicago', 'IL'],
      ['Detroit', 'MI'],
      ['New York', 'NY'],
      ['Philadelphia', 'PA'],
      ['Minneapolis', 'MN'],
      ['Cleveland', 'OH'],
    ] as [$city, $state]) {
      self::assertContains(
        ['city' => $city, 'state' => $state, 'country' => 'US'],
        $locations,
        "Missing geographic target: $city, $state",
      );
    }
  }

}
