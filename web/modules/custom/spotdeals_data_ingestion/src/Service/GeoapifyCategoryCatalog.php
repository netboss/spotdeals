<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Maintains a cached catalog of Geoapify Places API categories.
 *
 * The provider catalog is discovered from the official Geoapify Places
 * documentation and cached in Drupal state. Category keys are therefore
 * provider-managed data rather than application-code mappings.
 */
final class GeoapifyCategoryCatalog {

  private const STATE_KEY = 'spotdeals_data_ingestion.geoapify_category_catalog';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns the cached catalog, refreshing it when stale if requested.
   *
   * @return array<int, string>
   *   Geoapify Places category keys.
   */
  public function categories(bool $refreshIfStale = TRUE): array {
    $cached = $this->cachedPayload();
    if (
      $cached !== NULL
      && (!$refreshIfStale || !$this->isStale((int) $cached['fetched_at']))
    ) {
      return $cached['categories'];
    }

    if ($refreshIfStale) {
      try {
        return $this->refresh();
      }
      catch (\Throwable $exception) {
        if ($cached !== NULL) {
          $this->logger->warning(
            'Unable to refresh the Geoapify category catalog; using the cached copy instead: {message}',
            ['message' => $exception->getMessage()],
          );
          return $cached['categories'];
        }

        throw $exception;
      }
    }

    return $cached['categories'] ?? [];
  }

  /**
   * Downloads and caches the current provider category catalog.
   *
   * @return array<int, string>
   *   Geoapify Places category keys.
   */
  public function refresh(): array {
    $config = $this->configFactory->get('spotdeals_data_ingestion.settings');
    $url = trim((string) $config->get('category_catalog_url'));
    if ($url === '') {
      throw new \RuntimeException('No Geoapify category catalog URL is configured.');
    }

    $timeout = max(1, (int) ($config->get('request_timeout') ?? 30));
    $response = $this->httpClient->request('GET', $url, [
      'timeout' => $timeout,
      'headers' => [
        'Accept' => 'text/html,application/xhtml+xml',
        'User-Agent' => 'SpotDeals Geoapify category catalog sync',
      ],
    ]);

    $html = (string) $response->getBody();
    $categories = $this->extractCategories($html);
    $minimumSize = max(1, (int) ($config->get('category_catalog_minimum_size') ?? 100));

    if (count($categories) < $minimumSize) {
      throw new \RuntimeException(sprintf(
        'Geoapify category catalog refresh returned only %d category keys; expected at least %d.',
        count($categories),
        $minimumSize,
      ));
    }

    $this->state->set(self::STATE_KEY, [
      'source_url' => $url,
      'fetched_at' => time(),
      'categories' => $categories,
    ]);

    return $categories;
  }

  /**
   * Returns information about the cached provider catalog.
   *
   * @return array{
   *   source_url: string,
   *   fetched_at: int,
   *   count: int,
   *   stale: bool
   * }
   */
  public function metadata(): array {
    $cached = $this->cachedPayload();

    return [
      'source_url' => (string) ($cached['source_url'] ?? ''),
      'fetched_at' => (int) ($cached['fetched_at'] ?? 0),
      'count' => count($cached['categories'] ?? []),
      'stale' => $cached === NULL || $this->isStale((int) $cached['fetched_at']),
    ];
  }

  /**
   * Extracts category keys from the official Places documentation.
   *
   * The parser is intentionally scoped to the "Supported categories" section
   * and stops at "Supported conditions" so API parameters and examples from
   * the rest of the page are not mistaken for category keys.
   *
   * @return array<int, string>
   */
  private function extractCategories(string $html): array {
    if ($html === '' || !class_exists(\DOMDocument::class)) {
      throw new \RuntimeException('Unable to parse the Geoapify category catalog document.');
    }

    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    if (!$loaded) {
      throw new \RuntimeException('Unable to parse the Geoapify category catalog HTML.');
    }

    $xpath = new \DOMXPath($document);
    $headings = $xpath->query(
      '//h2[contains(translate(normalize-space(string(.)), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "supported categories")]',
    );
    $heading = $headings !== FALSE ? $headings->item(0) : NULL;

    if (!$heading instanceof \DOMElement) {
      throw new \RuntimeException('The Geoapify "Supported categories" section was not found.');
    }

    $categories = [];
    for ($node = $heading->nextSibling; $node !== NULL; $node = $node->nextSibling) {
      if (
        $node instanceof \DOMElement
        && strtolower($node->tagName) === 'h2'
        && str_contains(strtolower(trim($node->textContent)), 'supported conditions')
      ) {
        break;
      }

      if (!$node instanceof \DOMElement) {
        continue;
      }

      foreach ($node->getElementsByTagName('code') as $code) {
        $value = trim($code->textContent);
        if ($this->isCategoryKey($value)) {
          $categories[$value] = TRUE;
        }
      }
    }

    $keys = array_keys($categories);
    sort($keys, SORT_STRING);
    return $keys;
  }

  private function isCategoryKey(string $value): bool {
    return preg_match(
      '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/D',
      $value,
    ) === 1;
  }

  /**
   * @return array{
   *   source_url: string,
   *   fetched_at: int,
   *   categories: array<int, string>
   * }|null
   */
  private function cachedPayload(): ?array {
    $value = $this->state->get(self::STATE_KEY);
    if (!is_array($value) || !is_array($value['categories'] ?? NULL)) {
      return NULL;
    }

    $categories = array_values(array_unique(array_filter(array_map(
      static fn (mixed $category): string => trim((string) $category),
      $value['categories'],
    ))));

    if ($categories === []) {
      return NULL;
    }

    return [
      'source_url' => trim((string) ($value['source_url'] ?? '')),
      'fetched_at' => (int) ($value['fetched_at'] ?? 0),
      'categories' => $categories,
    ];
  }

  private function isStale(int $fetchedAt): bool {
    if ($fetchedAt <= 0) {
      return TRUE;
    }

    $maxAge = max(
      3600,
      (int) ($this->configFactory
        ->get('spotdeals_data_ingestion.settings')
        ->get('category_catalog_max_age') ?? 604800),
    );

    return $fetchedAt + $maxAge < time();
  }

}
