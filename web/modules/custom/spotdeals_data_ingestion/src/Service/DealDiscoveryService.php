<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Discovers review-only deal candidates from official venue websites.
 */
final class DealDiscoveryService {

  private const PROMOTION_TERMS = [
    'deal',
    'deals',
    'discount',
    'discounts',
    'offer',
    'offers',
    'promo',
    'promos',
    'promotion',
    'promotions',
    'special',
    'specials',
    'coupon',
    'coupons',
    'save',
    'savings',
    'sale',
    'happy hour',
    'free admission',
    'free entry',
  ];

  /**
   * Terms that make same-domain pages worth inspecting for consumer offers.
   *
   * These intentionally include common museum/attraction pages such as
   * tickets, admission, and membership. A page can be crawled because of one
   * of these terms without automatically qualifying as a deal.
   */
  private const DISCOVERY_TERMS = [
    'deal',
    'deals',
    'discount',
    'discounts',
    'offer',
    'offers',
    'promo',
    'promos',
    'promotion',
    'promotions',
    'special',
    'specials',
    'coupon',
    'coupons',
    'save',
    'savings',
    'sale',
    'ticket',
    'tickets',
    'admission',
    'membership',
    'memberships',
    'pricing',
    'prices',
    'passes',
    'visit',
  ];

  private const SCHEDULE_PATTERN = '/\b(mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|daily|weekday|weekdays|weekend|weekends|monthly|every\s+(?:first|second|third|fourth|last)|am|pm|through|until|valid|expires?|expiration)\b/i';

  private const PERMANENT_BENEFIT_PATTERNS = [
    '/\bfree\s+parking\b/i',
    '/\bno\s+parking\s+fee\b/i',
    '/\bno\s+gate\s+fee\b/i',
    '/\bfree\s+wi-?fi\b/i',
    '/\bfree\s+wifi\b/i',
    '/\bcomplimentary\s+parking\b/i',
  ];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Researches one mapped Geoapify venue without writing anything.
   *
   * @param array<string, mixed> $venue
   *   A mapped venue record.
   * @param int $maxPages
   *   Maximum number of same-domain pages to inspect, including homepage.
   *
   * @return array<string, mixed>
   *   Review-only discovery result.
   */
  public function discover(array $venue, int $maxPages = 5): array {
    $website = $this->normalizeWebsite((string) ($venue['website'] ?? ''));
    if ($website === '') {
      return $this->emptyResult('no_website');
    }

    $maxPages = max(1, min(10, $maxPages));
    $home = $this->fetchPage($website);
    if ($home === NULL) {
      return $this->emptyResult('website_unreachable', $website);
    }

    $canonicalWebsite = $home['url'];
    $host = $this->normalizedHost($canonicalWebsite);
    if ($host === '') {
      return $this->emptyResult('invalid_website', $canonicalWebsite);
    }

    $pages = [$canonicalWebsite => $home];
    foreach ($this->discoverPromotionLinks($home['html'], $canonicalWebsite, $host) as $url) {
      if (count($pages) >= $maxPages) {
        break;
      }

      if (isset($pages[$url])) {
        continue;
      }

      $page = $this->fetchPage($url);
      if ($page === NULL || $this->normalizedHost($page['url']) !== $host) {
        continue;
      }

      $pages[$page['url']] = $page;
    }

    $matches = [];
    foreach ($pages as $url => $page) {
      foreach ($this->extractDealCandidates($page['html'], $page['text'], $url) as $candidate) {
        $candidate['source_url'] = $url;
        $key = $this->candidateKey($candidate);

        if (isset($matches[$key]) && !$this->preferCandidate($candidate, $matches[$key])) {
          continue;
        }

        $matches[$key] = $candidate;
      }
    }

    $matches = array_values($matches);
    usort($matches, static function (array $a, array $b): int {
      return $b['score'] <=> $a['score'];
    });

    $matches = array_slice($matches, 0, 5);
    $locationConfidence = $this->locationConfidence($venue, $pages);
    $bestScore = $matches[0]['score'] ?? 0;

    $recommendation = 'SKIP';
    if ($matches !== [] && $locationConfidence >= 1 && $bestScore >= 5) {
      $recommendation = 'REVIEW';
    }
    elseif ($matches !== []) {
      $recommendation = 'MANUAL_CHECK';
    }

    return [
      'status' => 'completed',
      'website' => $canonicalWebsite,
      'pages_checked' => count($pages),
      'location_confidence' => $locationConfidence,
      'deal_candidates' => $matches,
      'recommendation' => $recommendation,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyResult(string $status, string $website = ''): array {
    return [
      'status' => $status,
      'website' => $website,
      'pages_checked' => 0,
      'location_confidence' => 0,
      'deal_candidates' => [],
      'recommendation' => 'SKIP',
    ];
  }

  /**
   * @return array{url: string, html: string, text: string}|null
   */
  private function fetchPage(string $url): ?array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => [
          'max' => 5,
          'strict' => TRUE,
          'referer' => TRUE,
          'track_redirects' => TRUE,
        ],
        'headers' => [
          'Accept' => 'text/html,application/xhtml+xml',
          'User-Agent' => 'SpotDealsDealDiscovery/0.2 (+https://spotdeals.app)',
        ],
        'http_errors' => FALSE,
        'timeout' => 15,
        'connect_timeout' => 8,
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->notice(
        'Deal discovery could not fetch {url}: {message}',
        ['url' => $url, 'message' => $exception->getMessage()],
      );
      return NULL;
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 400) {
      return NULL;
    }

    $contentType = strtolower($response->getHeaderLine('Content-Type'));
    if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
      return NULL;
    }

    $html = (string) $response->getBody();
    if ($html === '') {
      return NULL;
    }

    $finalUrl = $url;
    $history = $response->getHeader('X-Guzzle-Redirect-History');
    if ($history !== []) {
      $last = end($history);
      if (is_string($last) && $last !== '') {
        $finalUrl = $last;
      }
    }

    return [
      'url' => $finalUrl,
      'html' => $html,
      'text' => $this->htmlToText($html),
    ];
  }

  /**
   * @return array<int, string>
   */
  private function discoverPromotionLinks(string $html, string $baseUrl, string $host): array {
    if (!class_exists(\DOMDocument::class)) {
      return [];
    }

    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
      return [];
    }

    $ranked = [];
    foreach ($dom->getElementsByTagName('a') as $link) {
      $href = trim((string) $link->getAttribute('href'));
      $label = trim((string) $link->textContent);
      if ($href === '') {
        continue;
      }

      $url = $this->resolveUrl($baseUrl, $href);
      if ($url === '' || $this->normalizedHost($url) !== $host) {
        continue;
      }

      $haystack = mb_strtolower($label . ' ' . $url);
      $score = 0;

      foreach (self::DISCOVERY_TERMS as $term) {
        if (!str_contains($haystack, $term)) {
          continue;
        }

        $urlNeedle = str_replace(' ', '-', $term);
        $score += str_contains(mb_strtolower($url), $urlNeedle) ? 3 : 2;
      }

      // Give direct promotion language priority over generic ticket/visit pages.
      foreach (self::PROMOTION_TERMS as $term) {
        if (str_contains($haystack, $term)) {
          $score += 2;
        }
      }

      if ($score > 0) {
        $ranked[$url] = max($ranked[$url] ?? 0, $score);
      }
    }

    arsort($ranked);
    return array_keys($ranked);
  }

  /**
   * Extracts deal candidates while keeping offer text bound to its own block.
   *
   * Structured HTML blocks are preferred so a discount value is not borrowed
   * from an unrelated neighboring promotion. Plain-text extraction is retained
   * only as a conservative fallback when the page cannot be parsed into blocks.
   *
   * @return array<int, array<string, mixed>>
   */
  private function extractDealCandidates(string $html, string $text, string $sourceUrl = ''): array {
    $blocks = $this->extractStructuredOfferBlocks($html);
    if ($blocks === []) {
      $blocks = $this->extractFallbackTextBlocks($text);
    }

    $results = [];
    foreach ($blocks as $block) {
      foreach ($this->candidatesFromBlock($block, $sourceUrl) as $candidate) {
        $equivalentKey = $this->findEquivalentCandidateKey($candidate, $results);
        $key = $equivalentKey ?? $this->candidateKey($candidate);
        if (isset($results[$key]) && !$this->preferCandidate($candidate, $results[$key])) {
          continue;
        }

        $results[$key] = $candidate;
      }
    }

    return array_values($results);
  }

  /**
   * Builds logical offer blocks from headings, cards, list items, and sections.
   *
   * @return array<int, array{title: string, text: string, source: string}>
   */
  private function extractStructuredOfferBlocks(string $html): array {
    if (!class_exists(\DOMDocument::class)) {
      return [];
    }

    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
      return [];
    }

    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//script|//style|//noscript|//svg|//template') ?: [] as $node) {
      $node->parentNode?->removeChild($node);
    }

    $blocks = [];
    $seen = [];

    // List items and common card/article containers tend to represent one
    // promotion each and therefore provide the strongest offer/value binding.
    $queries = [
      '//li',
      '//article',
      '//section',
      '//div[contains(concat(" ", normalize-space(@class), " "), " card ")]',
      '//div[contains(concat(" ", normalize-space(@class), " "), " offer ")]',
      '//div[contains(concat(" ", normalize-space(@class), " "), " deal ")]',
      '//div[contains(concat(" ", normalize-space(@class), " "), " promo ")]',
      '//div[contains(concat(" ", normalize-space(@class), " "), " special ")]',
      '//div[contains(concat(" ", normalize-space(@class), " "), " discount ")]',
    ];

    foreach ($queries as $query) {
      foreach ($xpath->query($query) ?: [] as $node) {
        if (!$node instanceof \DOMElement) {
          continue;
        }

        $block = $this->domNodeToOfferBlock($node, $xpath);
        if ($block === NULL) {
          continue;
        }

        $key = mb_strtolower($block['text']);
        if (isset($seen[$key])) {
          continue;
        }

        $seen[$key] = TRUE;
        $blocks[] = $block;
      }
    }

    // Headings often label the promotion while the immediately following
    // siblings contain its price or conditions. Stop at the next heading so
    // unrelated offers cannot bleed into the same candidate.
    foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
      if (!$heading instanceof \DOMElement) {
        continue;
      }

      $parts = [$this->domElementText($heading)];
      $sibling = $heading->nextSibling;
      $collected = 0;
      while ($sibling !== NULL && $collected < 4) {
        if ($sibling instanceof \DOMElement) {
          if (preg_match('/^h[1-6]$/i', $sibling->tagName) === 1) {
            break;
          }

          // A wrapper containing another heading usually starts a different
          // offer/tier. Do not absorb that wrapper into this heading's block.
          if ($this->elementContainsHeading($sibling)) {
            break;
          }
        }

        $siblingText = $sibling instanceof \DOMElement
          ? $this->domElementText($sibling)
          : $this->cleanText((string) $sibling->textContent);
        if ($siblingText !== '') {
          // Large sibling wrappers are likely to contain multiple independent
          // offers even when their internal markup does not use headings.
          if (mb_strlen($siblingText) > 500) {
            break;
          }

          $parts[] = $siblingText;
          $collected++;
        }
        $sibling = $sibling->nextSibling;
      }

      $blockText = $this->cleanText(implode(' ', $parts));
      if (!$this->isUsefulOfferBlock($blockText)) {
        continue;
      }

      $key = mb_strtolower($blockText);
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = TRUE;
      $blocks[] = [
        'title' => $this->domElementText($heading),
        'text' => $blockText,
        'source' => 'heading',
      ];
    }

    return $blocks;
  }

  /**
   * @return array{title: string, text: string, source: string}|null
   */
  private function domNodeToOfferBlock(\DOMElement $node, \DOMXPath $xpath): ?array {
    $text = $this->domElementText($node);
    if (!$this->isUsefulOfferBlock($text)) {
      return NULL;
    }

    // Avoid giant layout wrappers. They defeat structural binding and are more
    // likely to contain several independent promotions.
    if (mb_strlen($text) > 900) {
      return NULL;
    }

    $title = '';
    $headingNodes = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $node);
    $heading = $headingNodes !== FALSE ? $headingNodes->item(0) : NULL;
    if ($heading !== NULL) {
      $title = $this->domElementText($heading);
    }

    if ($title === '') {
      $strongNodes = $xpath->query('.//strong|.//b', $node);
      $strong = $strongNodes !== FALSE ? $strongNodes->item(0) : NULL;
      if ($strong !== NULL) {
        $title = $this->domElementText($strong);
      }
    }

    return [
      'title' => $title,
      'text' => $text,
      'source' => strtolower($node->tagName),
    ];
  }

  /**
   * Extracts DOM text while preserving boundaries between nested elements.
   */
  private function domElementText(\DOMElement $element): string {
    $parts = [];

    foreach ($element->childNodes as $child) {
      if ($child instanceof \DOMText) {
        $part = $this->cleanText((string) $child->nodeValue);
        if ($part !== '') {
          $parts[] = $part;
        }
        continue;
      }

      if ($child instanceof \DOMElement) {
        $part = $this->domElementText($child);
        if ($part !== '') {
          $parts[] = $part;
        }
      }
    }

    return $this->cleanText(implode(' ', $parts));
  }

  private function elementContainsHeading(\DOMElement $element): bool {
    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tagName) {
      if ($element->getElementsByTagName($tagName)->length > 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function isUsefulOfferBlock(string $text): bool {
    $length = mb_strlen($text);
    return $length >= 12 && $length <= 900;
  }

  /**
   * Conservative fallback for malformed/non-structural pages.
   *
   * Unlike V2, this does not join adjacent sentences. Each sentence/line must
   * contain both its promotion language and its own value.
   *
   * @return array<int, array{title: string, text: string, source: string}>
   */
  private function extractFallbackTextBlocks(string $text): array {
    $segments = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];
    $blocks = [];

    foreach ($segments as $segment) {
      $segment = $this->cleanText($segment);
      if (!$this->isUsefulOfferBlock($segment)) {
        continue;
      }

      $blocks[] = [
        'title' => '',
        'text' => $segment,
        'source' => 'text_fallback',
      ];
    }

    return $blocks;
  }

  /**
   * @param array{title: string, text: string, source: string} $block
   *
   * @return array<string, mixed>|null
   */
  private function candidatesFromBlock(array $block, string $sourceUrl = ''): array {
    $text = $this->cleanText($block['text']);
    $lower = mb_strtolower($text);

    $promotionScore = 0;
    $matchedPromotionTerms = [];
    foreach (self::PROMOTION_TERMS as $term) {
      if (str_contains($lower, $term)) {
        $promotionScore++;
        $matchedPromotionTerms[] = $term;
      }
    }

    if ($promotionScore === 0) {
      return [];
    }

    $matchedValues = $this->extractValues($text);
    if ($matchedValues === []) {
      return [];
    }

    $schedule = $this->extractSchedule($text, $matchedValues);
    $scheduleScore = $schedule !== '' ? 1 : 0;
    $comparisonScore = preg_match('/\b(?:regularly|regular price|reg\.?|normally|was)\b/i', $text) === 1 ? 1 : 0;
    $bindingScore = $block['source'] === 'text_fallback' ? 0 : 1;
    $score = min(10, $promotionScore + 3 + $scheduleScore + $comparisonScore + $bindingScore);
    $snippet = mb_substr($text, 0, 420);
    $titleSource = $block['title'] !== '' ? $block['title'] : $text;

    $candidates = [];
    foreach ($matchedValues as $matchedValue) {
      if ($this->looksLikePermanentBenefit($text, $matchedValue)) {
        continue;
      }

      if ($this->looksLikeStandingMembershipBenefit($block, $matchedValue, $sourceUrl)) {
        continue;
      }

      if ($this->hasUnboundMembershipHeadingValue($block, $matchedValue, $sourceUrl)) {
        continue;
      }

      if (!$this->valueHasOfferContext($text, $matchedValue)) {
        continue;
      }

      $title = $this->sanitizeOfferTitle($this->deriveTitle($titleSource, $matchedValue));
      $reason = sprintf(
        'promotion=%s; value=%s; binding=%s%s',
        implode(',', array_unique($matchedPromotionTerms)),
        $matchedValue,
        $block['source'],
        $schedule !== '' ? '; schedule=' . $schedule : '',
      );

      $candidates[] = [
        'title' => $title,
        'value' => $matchedValue,
        'schedule' => $schedule,
        'snippet' => $snippet,
        'score' => $score,
        'reason' => $reason,
      ];
    }

    return $candidates;
  }

  private function valueHasOfferContext(string $text, string $value): bool {
    if (str_contains($value, ' vs ')) {
      return preg_match('/\b(?:regularly|regular price|reg\.?|normally|was)\b/i', $text) === 1;
    }

    $position = mb_stripos($text, $value);
    if ($position === FALSE) {
      return FALSE;
    }

    $start = max(0, $position - 180);
    $context = mb_strtolower(mb_substr($text, $start, mb_strlen($value) + 360));

    foreach (self::PROMOTION_TERMS as $term) {
      if (str_contains($context, $term)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts every distinct deal value that is bound to one offer block.
   *
   * @return string[]
   */
  private function extractValues(string $text): array {
    $patterns = [
      '/\b\d{1,3}%\s+off\b/i',
      '/\b(?:save|savings?)\s+(?:up\s+to\s+)?(?:[$£€]\s*\d+(?:\.\d{1,2})?|\d{1,3}(?:\.\d{1,2})?\s*%)(?![\p{L}\p{N}_])/iu',
      '/(?<![\p{L}\p{N}_])[$£€]\s*\d+(?:\.\d{1,2})?\s+off\b/iu',
      '/\bbuy\s+\d+[^.]{0,80}\bget\s+\d+\s+free\b/i',
      '/\bbuy\s+one[^.]{0,80}\bget\s+one\s+free\b/i',
      '/\bfree\s+(?:admission|entry|ticket|tickets|game|games|service|services|item|items)\b/i',
      '/\b(?:only|now|special(?:ly)? priced? at)\s+[$£€]\s*\d+(?:\.\d{1,2})?\b/iu',
    ];

    $values = [];
    foreach ($patterns as $pattern) {
      if (preg_match_all($pattern, $text, $matches) < 1) {
        continue;
      }

      foreach ($matches[0] as $match) {
        $value = $this->cleanText($match);
        $key = $this->normalizeCandidateValue($value);
        if ($key !== '' && !isset($values[$key])) {
          $values[$key] = $value;
        }
      }
    }

    if ($values !== []) {
      return array_values($values);
    }

    // A sale that gives both current and regular prices is still structured
    // enough to review even if it never says "save $X" explicitly.
    if (
      preg_match('/[$£€]\s*\d+(?:\.\d{1,2})?/u', $text, $current) === 1
      && preg_match('/\b(?:regularly|regular price|reg\.?|normally|was)\b[^$£€]{0,50}([$£€]\s*\d+(?:\.\d{1,2})?)/iu', $text, $regular) === 1
    ) {
      return [$this->cleanText($current[0]) . ' vs ' . $this->cleanText($regular[1])];
    }

    return [];
  }

  /**
   * @param string[] $values
   */
  private function extractSchedule(string $text, array $values = []): string {
    $scheduleText = $text;
    foreach ($values as $value) {
      $scheduleText = str_ireplace($value, ' ', $scheduleText);
    }
    $scheduleText = $this->cleanText($scheduleText);

    if (preg_match(self::SCHEDULE_PATTERN, $scheduleText, $matches) !== 1) {
      return '';
    }

    $match = $matches[0];
    $position = mb_stripos($scheduleText, $match);
    if ($position === FALSE) {
      return $match;
    }

    $start = max(0, $position - 60);
    return $this->cleanText(mb_substr($scheduleText, $start, 180));
  }

  private function deriveTitle(string $segment, string $value): string {
    $title = $this->cleanText($segment);
    if (mb_strlen($title) > 120) {
      $title = mb_substr($title, 0, 117) . '...';
    }

    if ($title === '') {
      return $value;
    }

    return $title;
  }

  /**
   * Rejects values that leaked into a membership heading from sibling content.
   *
   * Membership pages often render a tier heading followed by a shared benefits
   * wrapper. If the extracted value is not present in the heading itself, the
   * heading is not strong enough evidence that the value belongs to that tier.
   *
   * @param array{title: string, text: string, source: string} $block
   */
  private function hasUnboundMembershipHeadingValue(array $block, string $value, string $sourceUrl): bool {
    if ($block['source'] !== 'heading' || !str_contains(mb_strtolower($sourceUrl), 'membership')) {
      return FALSE;
    }

    $title = $this->cleanText($block['title']);
    if ($title === '') {
      return TRUE;
    }

    if (mb_stripos($title, $value) !== FALSE) {
      return FALSE;
    }

    // A heading that explicitly states its own promotion remains eligible even
    // when the numeric value is rendered in an immediately associated element.
    return preg_match(
      '/\\b(?:sale|promo(?:tion)?|special offer|limited(?:-time)?|save|now\\b|offer)\\b/i',
      $title,
    ) !== 1;
  }

  /**
   * Rejects standing membership-plan savings while retaining actual promotions.
   *
   * @param array{title: string, text: string, source: string} $block
   */
  private function looksLikeStandingMembershipBenefit(array $block, string $value, string $sourceUrl): bool {
    $text = $this->cleanText($block['text']);
    $title = $this->cleanText($block['title']);
    $lowerUrl = mb_strtolower($sourceUrl);
    $membershipContext = preg_match('/\bmemberships?\b/i', $text) === 1
      || str_contains($lowerUrl, 'membership');

    if (!$membershipContext) {
      return FALSE;
    }

    // Promotional status must be bound to the offer title or to the value
    // itself. Generic "sale", "special", or "offer" copy elsewhere in a
    // membership section must not promote every standing tier on the page.
    $boundPromotionText = $this->cleanText($title . ' ' . $value);
    $explicitPromotion = preg_match(
      '/\b(?:sale|promo(?:tion)?|special offer|limited(?:-time)?|now\s+\d{1,3}\s*%\s+off|\d{1,3}\s*%\s+off|[$£€]\s*\d+(?:\.\d{1,2})?\s+off)\b/iu',
      $boundPromotionText,
    ) === 1;

    if ($explicitPromotion) {
      return FALSE;
    }

    // "Save X%" / "Save $X" on a membership page describes the standing
    // economics of a plan unless the title/value itself identifies a promotion.
    if (preg_match('/\b(?:save|savings?)\b/i', $value) === 1) {
      return TRUE;
    }

    // Membership pages frequently enumerate permanent benefits with deal-like
    // language. Those are not discoverable local promotions for this feature.
    if (
      preg_match('/\b(?:free admission|free entry|guest passes?|member discounts?|discounts? on|member benefits?|membership benefits?)\b/i', $text) === 1
      || preg_match('/^\s*(?:student|senior|adult|individual|family|dual|patron|supporter|member)\b/i', $title) === 1
    ) {
      return TRUE;
    }

    // A bare discount/free-entry value on a membership URL is presumed to be
    // a standing membership benefit unless its own title/value says otherwise.
    return preg_match('/(?:%\s+off|free\s+(?:admission|entry))/i', $value) === 1;
  }

  private function looksLikePermanentBenefit(string $text, string $value): bool {
    foreach (self::PERMANENT_BENEFIT_PATTERNS as $pattern) {
      if (preg_match($pattern, $text) === 1) {
        return TRUE;
      }
    }

    // "Free admission" can be a real recurring deal for museums, but wording
    // such as "walk right in" or "no gate fee" generally describes the venue's
    // standing business model rather than a promotion.
    if (
      str_contains(mb_strtolower($value), 'free admission')
      && preg_match('/\b(?:walk right in|no gate fee|always free|free to enter)\b/i', $text) === 1
    ) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Finds a stronger/weaker representation of the same value on one page.
   *
   * @param array<string, mixed> $candidate
   * @param array<string, array<string, mixed>> $existingCandidates
   */
  private function findEquivalentCandidateKey(array $candidate, array $existingCandidates): ?string {
    $value = $this->normalizeCandidateValue((string) ($candidate['value'] ?? ''));
    $title = $this->normalizeCandidateTitle((string) ($candidate['title'] ?? ''));
    $snippet = $this->normalizeCandidateTitle((string) ($candidate['snippet'] ?? ''));

    foreach ($existingCandidates as $key => $existing) {
      if ($value === '' || $value !== $this->normalizeCandidateValue((string) ($existing['value'] ?? ''))) {
        continue;
      }

      $existingTitle = $this->normalizeCandidateTitle((string) ($existing['title'] ?? ''));
      $existingSnippet = $this->normalizeCandidateTitle((string) ($existing['snippet'] ?? ''));

      if (
        ($title !== '' && str_contains($existingSnippet, $title))
        || ($existingTitle !== '' && str_contains($snippet, $existingTitle))
      ) {
        return $key;
      }
    }

    return NULL;
  }

  /**
   * @param array<string, mixed> $candidate
   */
  private function candidateKey(array $candidate): string {
    $title = $this->normalizeCandidateTitle((string) ($candidate['title'] ?? ''));
    $value = $this->normalizeCandidateValue((string) ($candidate['value'] ?? ''));

    if ($value !== '') {
      return $value . '|' . mb_substr($title, 0, 100);
    }

    return mb_strtolower($this->cleanText((string) ($candidate['snippet'] ?? '')));
  }

  /**
   * Normalizes equivalent deal values for semantic deduplication.
   */
  private function normalizeCandidateValue(string $value): string {
    $value = mb_strtolower($this->cleanText($value));
    $value = preg_replace('/\bfree\s+entry\b/u', 'free admission', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
  }

  /**
   * Normalizes harmless title variations without collapsing distinct programs.
   */
  private function normalizeCandidateTitle(string $title): string {
    $title = mb_strtolower($this->cleanText($title));
    $title = preg_replace('/[[:punct:]]+/u', ' ', $title) ?? $title;
    $title = preg_replace('/\bfree\s+entry\b/u', 'free admission', $title) ?? $title;
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

    return trim($title);
  }

  /**
   * Chooses the stronger representation when two candidates normalize alike.
   *
   * @param array<string, mixed> $candidate
   * @param array<string, mixed> $existing
   */
  private function preferCandidate(array $candidate, array $existing): bool {
    $candidateQuality = $this->candidateQuality($candidate);
    $existingQuality = $this->candidateQuality($existing);

    if ($candidateQuality !== $existingQuality) {
      return $candidateQuality > $existingQuality;
    }

    return (int) ($candidate['score'] ?? 0) > (int) ($existing['score'] ?? 0);
  }

  /**
   * Scores representation quality only; it does not change deal qualification.
   *
   * @param array<string, mixed> $candidate
   */
  private function candidateQuality(array $candidate): int {
    $quality = (int) ($candidate['score'] ?? 0) * 10;

    if ($this->cleanText((string) ($candidate['schedule'] ?? '')) !== '') {
      $quality += 6;
    }

    $reason = (string) ($candidate['reason'] ?? '');
    if (str_contains($reason, 'binding=li') || str_contains($reason, 'binding=article')) {
      $quality += 4;
    }
    elseif (str_contains($reason, 'binding=heading')) {
      $quality += 2;
    }

    $title = $this->cleanText((string) ($candidate['title'] ?? ''));
    if ($title !== '' && !str_ends_with($title, '...')) {
      $quality += 2;
    }

    return $quality;
  }

  /**
   * Scores whether the retrieved official-site content identifies this location.
   *
   * 0 = no useful location evidence, 1 = city/name evidence, 2 = address evidence.
   *
   * @param array<string, mixed> $venue
   * @param array<string, array{url: string, html: string, text: string}> $pages
   */
  private function locationConfidence(array $venue, array $pages): int {
    $address = is_array($venue['address'] ?? NULL) ? $venue['address'] : [];
    $city = mb_strtolower(trim((string) ($address['locality'] ?? '')));
    $street = mb_strtolower(trim((string) ($address['address_line1'] ?? '')));
    $sourceTitle = mb_strtolower(trim((string) ($venue['source_title'] ?? $venue['title'] ?? '')));

    $allText = mb_strtolower(implode("\n", array_column($pages, 'text')));
    $cityMatched = $city !== '' && str_contains($allText, $city);
    $nameMatched = $sourceTitle !== '' && str_contains($allText, $sourceTitle);

    if ($street !== '') {
      $streetNumber = '';
      if (preg_match('/^\s*(\d+)/', $street, $matches) === 1) {
        $streetNumber = $matches[1];
      }

      $streetWords = preg_replace('/^\s*\d+\s*/', '', $street) ?? $street;
      $streetWords = trim((string) preg_replace('/\b(street|st|road|rd|avenue|ave|boulevard|blvd|drive|dr|way|lane|ln|court|ct|parkway|pkwy)\b\.?/i', '', $streetWords));

      $streetMatched = $streetWords !== '' && str_contains($allText, $streetWords);
      $numberMatched = $streetNumber === '' || str_contains($allText, $streetNumber);

      if ($streetMatched && $numberMatched) {
        return 2;
      }
    }

    return ($cityMatched || $nameMatched) ? 1 : 0;
  }

  private function htmlToText(string $html): string {
    $html = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $this->cleanText($text);
  }

  private function sanitizeOfferTitle(string $title): string {
    $title = preg_replace('/[\p{So}\x{200D}\x{FE0F}]+/u', ' ', $title) ?? $title;
    return $this->cleanText($title);
  }

  private function cleanText(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  private function normalizeWebsite(string $website): string {
    $website = trim($website);
    if ($website === '') {
      return '';
    }

    if (!preg_match('#^https?://#i', $website)) {
      $website = 'https://' . ltrim($website, '/');
    }

    return filter_var($website, FILTER_VALIDATE_URL) !== FALSE ? $website : '';
  }

  private function normalizedHost(string $url): string {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $host) ?? $host;
  }

  private function resolveUrl(string $baseUrl, string $href): string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto:|tel:|javascript:)#i', $href)) {
      return '';
    }

    if (preg_match('#^https?://#i', $href)) {
      return $href;
    }

    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
      return '';
    }

    if (str_starts_with($href, '//')) {
      return $base['scheme'] . ':' . $href;
    }

    if (str_starts_with($href, '/')) {
      return $base['scheme'] . '://' . $base['host'] . $href;
    }

    $path = (string) ($base['path'] ?? '/');
    $directory = str_ends_with($path, '/') ? $path : dirname($path) . '/';
    return $base['scheme'] . '://' . $base['host'] . $directory . $href;
  }

}
