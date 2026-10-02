<?php

declare(strict_types=1);

namespace Drupal\spotdeals_import\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Normalizes SpotDeals tag lists that use comma and/or pipe delimiters.
 *
 * Historical SpotDeals venue CSV data uses pipes while newer rows use commas.
 * This plugin accepts both formats, trims whitespace, removes empty values, and
 * de-duplicates tag names case-insensitively while preserving first spelling.
 *
 * @MigrateProcessPlugin(
 *   id = "spotdeals_normalize_tags"
 * )
 */
final class NormalizeTags extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform(
    mixed $value,
    MigrateExecutableInterface $migrate_executable,
    Row $row,
    $destination_property,
  ): array {
    if (is_array($value)) {
      $value = implode('|', array_map('strval', $value));
    }

    $value = trim((string) $value);
    if ($value === '') {
      return [];
    }

    $parts = preg_split('/[|,]/u', $value) ?: [];
    $normalized = [];
    $seen = [];

    foreach ($parts as $part) {
      $name = trim($part);
      if ($name === '') {
        continue;
      }

      $key = mb_strtolower($name, 'UTF-8');
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = TRUE;
      $normalized[] = $name;
    }

    return $normalized;
  }

}
