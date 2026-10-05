<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Database\Connection;

/**
 * Provides human-readable SpotDeals venue locations for administrative tools.
 */
final class DealDiscoveryLocationResolver {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Returns distinct venue locations keyed by an internal serialized value.
   *
   * @return array<string, string>
   *   Select options keyed by an internal location token.
   */
  public function options(): array {
    $options = [];
    foreach ($this->locations() as $token => $location) {
      $options[$token] = $location['label'];
    }

    return $options;
  }

  /**
   * Returns distinct structured venue locations keyed by internal token.
   *
   * @return array<string, array{city: string, state: string, country: string, label: string}>
   *   Structured locations in the same order as the administrative selector.
   */
  public function locations(): array {
    $query = $this->database->select('node__field_address', 'a');
    $query->innerJoin(
      'node_field_data',
      'n',
      'n.nid = a.entity_id AND n.vid = a.revision_id',
    );
    $query->fields('a', [
      'field_address_locality',
      'field_address_administrative_area',
      'field_address_country_code',
    ]);
    $query->condition('n.type', 'venue');
    $query->condition('n.default_langcode', 1);
    $query->condition('a.deleted', 0);
    $query->isNotNull('a.field_address_locality');
    $query->condition('a.field_address_locality', '', '<>');
    $query->distinct();

    $locations = [];
    foreach ($query->execute() as $record) {
      $city = trim((string) $record->field_address_locality);
      $state = strtoupper(trim((string) $record->field_address_administrative_area));
      $country = strtoupper(trim((string) $record->field_address_country_code));

      if ($city === '') {
        continue;
      }

      $location = [
        'city' => $city,
        'state' => $state,
        'country' => $country !== '' ? $country : 'US',
      ];
      $token = $this->encode($location);
      if ($token === '') {
        continue;
      }

      $label = $city;
      if ($state !== '') {
        $label .= ', ' . $state;
      }

      $locations[$token] = $location + ['label' => $label];
    }

    uasort($locations, static fn (array $a, array $b): int => strnatcasecmp(
      $a['label'],
      $b['label'],
    ));

    return $locations;
  }

  /**
   * Decodes one internal location token.
   *
   * @return array{city: string, state: string, country: string}|null
   */
  public function decode(string $token): ?array {
    $decoded = base64_decode(strtr($token, '-_', '+/'), TRUE);
    if ($decoded === FALSE) {
      return NULL;
    }

    $data = json_decode($decoded, TRUE);
    if (!is_array($data)) {
      return NULL;
    }

    $city = trim((string) ($data['city'] ?? ''));
    $state = strtoupper(trim((string) ($data['state'] ?? '')));
    $country = strtoupper(trim((string) ($data['country'] ?? 'US')));

    if ($city === '') {
      return NULL;
    }

    return [
      'city' => $city,
      'state' => $state,
      'country' => $country !== '' ? $country : 'US',
    ];
  }

  /**
   * Encodes one location without exposing technical identifiers to the admin.
   */
  private function encode(array $location): string {
    $json = json_encode($location, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
      return '';
    }

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
  }

}
