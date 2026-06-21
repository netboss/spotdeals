<?php

declare(strict_types=1);

namespace Drupal\spotdeals_yelp\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Wraps the Yelp API.
 */
final class YelpApiClient {

  private const BASE_URI = 'https://api.yelp.com/v3/';

  /**
   * Constructs the API client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns business details for a Yelp business.
   */
  public function getBusinessDetails(string $businessId): array {
    return $this->request('businesses/' . rawurlencode($businessId));
  }

  /**
   * Returns review excerpts for a Yelp business.
   */
  public function getBusinessReviews(string $businessId, ?string $locale = NULL): array {
    $query = [];
    if (!empty($locale)) {
      $query['locale'] = $locale;
    }

    return $this->request('businesses/' . rawurlencode($businessId) . '/reviews', $query);
  }

  /**
   * Searches Yelp by normalized phone.
   */
  public function searchByPhone(string $phone): array {
    return $this->request('businesses/search/phone', ['phone' => $phone]);
  }

  /**
   * Runs a Yelp business match.
   */
  public function matchBusiness(array $payload): array {
    return $this->request('businesses/matches', array_filter($payload, static fn($value): bool => $value !== NULL && $value !== ''));
  }

  /**
   * Performs a GET request against Yelp.
   */
  private function request(string $path, array $query = []): array {
    $settings = $this->configFactory->get('spotdeals_yelp.settings');
    $apiKey = trim((string) $settings->get('api_key'));
    if ($apiKey === '') {
      throw new \RuntimeException('Missing Yelp API key. Set spotdeals_yelp.settings.api_key or override it in settings.php.');
    }

    $timeout = max(1, (int) $settings->get('request_timeout'));

    try {
      $response = $this->httpClient->request('GET', self::BASE_URI . ltrim($path, '/'), [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Accept' => 'application/json',
        ],
        'query' => $query,
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error('Yelp request failed for @path: @message', [
        '@path' => $path,
        '@message' => $exception->getMessage(),
      ]);
      throw new \RuntimeException('Yelp request failed: ' . $exception->getMessage(), 0, $exception);
    }

    $statusCode = $response->getStatusCode();
    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);

    if ($statusCode < 200 || $statusCode >= 300) {
      $message = is_array($decoded) && isset($decoded['error']['description'])
        ? (string) $decoded['error']['description']
        : 'Unexpected Yelp API error.';

      $this->logger->warning('Yelp API returned @status for @path: @message', [
        '@status' => $statusCode,
        '@path' => $path,
        '@message' => $message,
      ]);

      throw new \RuntimeException(sprintf('Yelp API returned %d for %s: %s', $statusCode, $path, $message));
    }

    if (!is_array($decoded)) {
      throw new \RuntimeException('Unexpected non-JSON Yelp API response.');
    }

    return $decoded;
  }

}
