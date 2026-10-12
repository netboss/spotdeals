<?php

declare(strict_types=1);

namespace Drupal\Tests\spotdeals_data_ingestion\Unit\Service;

use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryGeographicCatalog;
use Drupal\spotdeals_data_ingestion\Service\DealDiscoveryLocationResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/src/Service/DealDiscoveryGeographicCatalog.php';
require_once dirname(__DIR__, 4) . '/src/Service/DealDiscoveryLocationResolver.php';

/**
 * Guards location token compatibility and geographic deduplication rules.
 *
 * These tests intentionally do not connect to Drupal's database.
 */
#[Group('spotdeals_data_ingestion')]
final class DealDiscoveryLocationResolverTest extends TestCase {

  private function resolver(): DealDiscoveryLocationResolver {
    return (new \ReflectionClass(DealDiscoveryLocationResolver::class))
      ->newInstanceWithoutConstructor();
  }

  private function invokePrivate(string $method, array $arguments): mixed {
    return (new \ReflectionMethod(DealDiscoveryLocationResolver::class, $method))
      ->invokeArgs($this->resolver(), $arguments);
  }

  public function testAllCatalogLocationsHaveStableUniqueRoundTripTokens(): void {
    $tokens = [];
    $identities = [];
    foreach (DealDiscoveryGeographicCatalog::locations() as $location) {
      $token = $this->invokePrivate('encode', [$location]);
      self::assertNotSame('', $token);
      self::assertArrayNotHasKey($token, $tokens, 'Repeated location token');
      $tokens[$token] = TRUE;
      self::assertSame($location, $this->resolver()->decode($token));

      $identity = $this->invokePrivate('locationIdentity', [$location]);
      self::assertArrayNotHasKey($identity, $identities, 'Repeated normalized location');
      $identities[$identity] = TRUE;
    }
    self::assertCount(388, $tokens);
  }

  #[DataProvider('equivalentCityNames')]
  public function testEquivalentCityNamesHaveMatchingIdentity(string $first, string $second, string $state): void {
    $a = ['city' => $first, 'state' => $state, 'country' => 'US'];
    $b = ['city' => $second, 'state' => $state, 'country' => 'US'];
    self::assertSame(
      $this->invokePrivate('locationIdentity', [$a]),
      $this->invokePrivate('locationIdentity', [$b]),
    );
  }

  public static function equivalentCityNames(): iterable {
    yield 'Saint Augustine' => ['Saint Augustine', 'St. Augustine', 'FL'];
    yield 'Saint Cloud' => ['Saint Cloud', 'St Cloud', 'FL'];
    yield 'Champions Gate' => ['Champions Gate', 'ChampionsGate', 'FL'];
    yield 'Saint Paul' => ['Saint Paul', 'St. Paul', 'MN'];
    yield 'Saint Louis' => ['Saint Louis', 'St. Louis', 'MO'];
    yield 'case and punctuation' => ['NEW YORK', 'New-York', 'NY'];
  }

  public function testDistinctStatesAndCountriesDoNotCollapse(): void {
    $base = ['city' => 'Springfield', 'state' => 'IL', 'country' => 'US'];
    $otherState = ['city' => 'Springfield', 'state' => 'MA', 'country' => 'US'];
    $otherCountry = ['city' => 'Springfield', 'state' => 'IL', 'country' => 'CA'];
    self::assertNotSame(
      $this->invokePrivate('locationIdentity', [$base]),
      $this->invokePrivate('locationIdentity', [$otherState]),
    );
    self::assertNotSame(
      $this->invokePrivate('locationIdentity', [$base]),
      $this->invokePrivate('locationIdentity', [$otherCountry]),
    );
  }

  public function testHistoricalTokenRemainsUnchanged(): void {
    $location = ['city' => 'Orlando', 'state' => 'FL', 'country' => 'US'];
    $expected = rtrim(strtr(base64_encode('{"city":"Orlando","state":"FL","country":"US"}'), '+/', '-_'), '=');
    self::assertSame($expected, $this->invokePrivate('encode', [$location]));
    self::assertSame($location, $this->resolver()->decode($expected));
  }

  public function testMalformedTokensAreRejected(): void {
    self::assertNull($this->resolver()->decode('not-valid-token!'));
    self::assertNull($this->resolver()->decode(rtrim(strtr(base64_encode('{}'), '+/', '-_'), '=')));
  }

}
