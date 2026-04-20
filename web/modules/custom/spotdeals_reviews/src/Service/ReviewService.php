<?php

declare(strict_types=1);

namespace Drupal\spotdeals_reviews\Service;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles SpotDeals review storage and aggregation.
 */
final class ReviewService {

  /**
   * CSRF token seed for review submissions.
   */
  private const CSRF_TOKEN_SEED = 'spotdeals_reviews.submit';

  /**
   * Per-request stats cache.
   *
   * @var array<string,array<string,int|float|string>>
   */
  private array $statsCache = [];

  /**
   * Per-request user review cache.
   *
   * @var array<string,array<string,int|bool|null>|null>
   */
  private array $userReviewCache = [];

  /**
   * Constructs the review service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns a CSRF token for the review endpoint.
   */
  public function getCsrfToken(): string {
    return $this->csrfToken->get(self::CSRF_TOKEN_SEED);
  }

  /**
   * Validates a submitted CSRF token.
   */
  public function isValidCsrfToken(?string $token): bool {
    if (!is_string($token) || $token === '') {
      return FALSE;
    }

    return $this->csrfToken->validate($token, self::CSRF_TOKEN_SEED);
  }

  /**
   * Returns the current review context for a deal or venue page.
   *
   * @return array<string,int>
   *   Context ids containing venue_id and deal_id.
   */
  public function getContextForNode(NodeInterface $node): array {
    $dealId = $node->bundle() === 'deal' ? (int) $node->id() : 0;
    $venueId = 0;

    if ($node->bundle() === 'venue') {
      $venueId = (int) $node->id();
    }
    elseif ($node->hasField('field_venue') && !$node->get('field_venue')->isEmpty()) {
      $venueId = (int) $node->get('field_venue')->target_id;
    }

    return [
      'venue_id' => $venueId,
      'deal_id' => $dealId,
    ];
  }

  /**
   * Stores or updates a single review answer.
   *
   * @return array<string,mixed>
   *   Submission result.
   */
  public function submitReview(int $userId, int $venueId, int $dealId, string $field, bool $value): array {
    if ($userId <= 0) {
      throw new \InvalidArgumentException('A logged-in user is required to submit reviews.');
    }

    if (!in_array($field, ['worth_it', 'would_go_again'], TRUE)) {
      throw new \InvalidArgumentException('Unsupported review field.');
    }

    $context = $this->normalizeContext($venueId, $dealId);
    if ($context['venue_id'] <= 0 && $context['deal_id'] <= 0) {
      throw new \InvalidArgumentException('A venue or deal context is required.');
    }

    $review = $this->loadExistingReview($userId, $context['venue_id'], $context['deal_id']);
    $isNew = !$review;

    if (!$review) {
      $review = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'review',
        'status' => 1,
        'title' => $this->buildReviewTitle($userId, $context['venue_id'], $context['deal_id']),
        'uid' => $userId,
      ]);
    }

    if (!$review instanceof NodeInterface) {
      throw new \RuntimeException('Unable to create review entity.');
    }

    if ($review->hasField('field_user')) {
      $review->set('field_user', ['target_id' => $userId]);
    }

    if ($context['venue_id'] > 0 && $review->hasField('field_review_venue')) {
      $review->set('field_review_venue', ['target_id' => $context['venue_id']]);
    }

    if ($context['deal_id'] > 0 && $review->hasField('field_review_deal')) {
      $review->set('field_review_deal', ['target_id' => $context['deal_id']]);
    }

    $fieldName = $field === 'worth_it' ? 'field_worth_it' : 'field_would_go_again';
    if ($review->hasField($fieldName)) {
      $review->set($fieldName, $value ? 1 : 0);
    }

    $review->save();
    $this->resetCachedContext($context['venue_id'], $context['deal_id'], $userId);
    $this->invalidateContextCacheTags($context['venue_id'], $context['deal_id']);

    $this->logger->notice('SpotDeals review saved for user @uid, venue @venue, deal @deal, field @field.', [
      '@uid' => $userId,
      '@venue' => $context['venue_id'],
      '@deal' => $context['deal_id'],
      '@field' => $field,
    ]);

    return [
      'created' => $isNew,
      'review_id' => (int) $review->id(),
      'stats' => $this->getStatsForContext($context['venue_id'], $context['deal_id']),
      'user_review' => $this->getUserReviewForContext($userId, $context['venue_id'], $context['deal_id']),
    ];
  }

  /**
   * Returns aggregate review stats for one context.
   *
   * @return array<string,int|float>
   *   Aggregate values for display.
   */
  public function getStatsForContext(int $venueId, int $dealId = 0): array {
    $context = $this->normalizeContext($venueId, $dealId);
    $cacheKey = $this->buildContextCacheKey($context['venue_id'], $context['deal_id']);
    if (isset($this->statsCache[$cacheKey])) {
      return $this->statsCache[$cacheKey];
    }

    $reviews = $this->loadReviews($context['venue_id'], $context['deal_id']);

    $total = count($reviews);
    $worthItYes = 0;
    $worthItAnswered = 0;
    $goAgainYes = 0;
    $goAgainAnswered = 0;
    $ratingTotal = 0;
    $ratingCount = 0;

    foreach ($reviews as $review) {
      if (!$review instanceof NodeInterface) {
        continue;
      }

      if ($review->hasField('field_worth_it') && !$review->get('field_worth_it')->isEmpty()) {
        $worthItAnswered++;
        $worthItYes += ((int) $review->get('field_worth_it')->value === 1) ? 1 : 0;
      }

      if ($review->hasField('field_would_go_again') && !$review->get('field_would_go_again')->isEmpty()) {
        $goAgainAnswered++;
        $goAgainYes += ((int) $review->get('field_would_go_again')->value === 1) ? 1 : 0;
      }

      if ($review->hasField('field_rating') && !$review->get('field_rating')->isEmpty()) {
        $ratingCount++;
        $ratingTotal += (int) $review->get('field_rating')->value;
      }
    }

    $this->statsCache[$cacheKey] = [
      'total_reviews' => $total,
      'worth_it_yes' => $worthItYes,
      'worth_it_answered' => $worthItAnswered,
      'worth_it_percent' => $worthItAnswered > 0 ? (int) round(($worthItYes / $worthItAnswered) * 100) : 0,
      'would_go_again_yes' => $goAgainYes,
      'would_go_again_answered' => $goAgainAnswered,
      'would_go_again_percent' => $goAgainAnswered > 0 ? (int) round(($goAgainYes / $goAgainAnswered) * 100) : 0,
      'average_rating' => $ratingCount > 0 ? round($ratingTotal / $ratingCount, 1) : 0,
      'rating_count' => $ratingCount,
    ];

    return $this->statsCache[$cacheKey];
  }

  /**
   * Builds compact summary data for cards and result lists.
   *
   * @return array<string,int|float|string>
   *   Summary values ready for theming.
   */
  public function buildSummaryData(int $venueId, int $dealId = 0, string $contextType = 'deal'): array {
    $stats = $this->getStatsForContext($venueId, $dealId);
    $totalReviews = (int) ($stats['total_reviews'] ?? 0);
    $worthItPercent = (int) ($stats['worth_it_percent'] ?? 0);

    return [
      'total_reviews' => $totalReviews,
      'worth_it_percent' => $worthItPercent,
      'summary_text' => $totalReviews > 0
        ? sprintf('👍 %d%% worth it (%d)', $worthItPercent, $totalReviews)
        : '',
      'context_type' => $contextType,
    ] + $stats;
  }

  /**
   * Returns the current user's review for one context.
   *
   * @return array<string,int|bool|null>
   *   Current user review state.
   */
  public function getUserReviewForContext(int $userId, int $venueId, int $dealId = 0): ?array {
    $context = $this->normalizeContext($venueId, $dealId);
    $cacheKey = $this->buildUserContextCacheKey($userId, $context['venue_id'], $context['deal_id']);
    if (array_key_exists($cacheKey, $this->userReviewCache)) {
      return $this->userReviewCache[$cacheKey];
    }

    $review = $this->loadExistingReview($userId, $context['venue_id'], $context['deal_id']);
    if (!$review instanceof NodeInterface) {
      $this->userReviewCache[$cacheKey] = NULL;
      return NULL;
    }

    $this->userReviewCache[$cacheKey] = [
      'review_id' => (int) $review->id(),
      'worth_it' => $review->hasField('field_worth_it') && !$review->get('field_worth_it')->isEmpty()
        ? ((int) $review->get('field_worth_it')->value === 1)
        : NULL,
      'would_go_again' => $review->hasField('field_would_go_again') && !$review->get('field_would_go_again')->isEmpty()
        ? ((int) $review->get('field_would_go_again')->value === 1)
        : NULL,
      'rating' => $review->hasField('field_rating') && !$review->get('field_rating')->isEmpty()
        ? (int) $review->get('field_rating')->value
        : NULL,
      'comment' => $review->hasField('field_comment') && !$review->get('field_comment')->isEmpty()
        ? (string) $review->get('field_comment')->value
        : '',
    ];

    return $this->userReviewCache[$cacheKey];
  }

  /**
   * Builds initial widget data for one node.
   *
   * @return array<string,mixed>
   *   Widget values ready for theming.
   */
  public function buildWidgetData(NodeInterface $node): array {
    $context = $this->getContextForNode($node);
    $userId = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : 0;
    $request = $this->requestStack->getCurrentRequest();

    return [
      'node_id' => (int) $node->id(),
      'node_type' => $node->bundle(),
      'venue_id' => $context['venue_id'],
      'deal_id' => $context['deal_id'],
      'stats' => $this->getStatsForContext($context['venue_id'], $context['deal_id']),
      'user_review' => $userId > 0 ? $this->getUserReviewForContext($userId, $context['venue_id'], $context['deal_id']) : NULL,
      'is_authenticated' => $this->currentUser->isAuthenticated(),
      'login_url' => Url::fromRoute('user.login', [], [
        'query' => ['destination' => $request ? $request->getRequestUri() : '/'],
      ])->toString(),
      'csrf_token' => $this->getCsrfToken(),
    ];
  }

  /**
   * Returns normalized ids for one review context.
   *
   * @return array<string,int>
   *   Normalized ids.
   */
  private function normalizeContext(int $venueId, int $dealId): array {
    $dealId = max(0, $dealId);
    $venueId = max(0, $venueId);

    if ($dealId > 0 && $venueId <= 0) {
      $deal = $this->entityTypeManager->getStorage('node')->load($dealId);
      if ($deal instanceof NodeInterface && $deal->bundle() === 'deal' && $deal->hasField('field_venue') && !$deal->get('field_venue')->isEmpty()) {
        $venueId = (int) $deal->get('field_venue')->target_id;
      }
    }

    return [
      'venue_id' => $venueId,
      'deal_id' => $dealId,
    ];
  }

  /**
   * Loads a user's existing review for one context.
   */
  private function loadExistingReview(int $userId, int $venueId, int $dealId = 0): ?NodeInterface {
    $context = $this->normalizeContext($venueId, $dealId);
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'review')
      ->condition('status', 1)
      ->condition('field_user', $userId)
      ->accessCheck(FALSE)
      ->sort('nid', 'DESC')
      ->range(0, 1);

    if ($context['deal_id'] > 0) {
      $query->condition('field_review_deal', $context['deal_id']);
    }
    else {
      $query->notExists('field_review_deal');
    }

    if ($context['venue_id'] > 0) {
      $query->condition('field_review_venue', $context['venue_id']);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }

    $review = $this->entityTypeManager->getStorage('node')->load((int) reset($ids));
    return $review instanceof NodeInterface ? $review : NULL;
  }

  /**
   * Loads all reviews for one context.
   *
   * @return array<int,\Drupal\node\NodeInterface>
   *   Loaded review nodes.
   */
  private function loadReviews(int $venueId, int $dealId = 0): array {
    $context = $this->normalizeContext($venueId, $dealId);
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'review')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    if ($context['deal_id'] > 0) {
      $query->condition('field_review_deal', $context['deal_id']);
    }
    elseif ($context['venue_id'] > 0) {
      $query->condition('field_review_venue', $context['venue_id']);
      $query->notExists('field_review_deal');
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $loaded = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    return array_values(array_filter($loaded, static fn ($entity): bool => $entity instanceof NodeInterface));
  }

  /**
   * Builds a stable review title.
   */
  private function buildReviewTitle(int $userId, int $venueId, int $dealId): string {
    if ($dealId > 0) {
      return sprintf('Deal review u%d-d%d', $userId, $dealId);
    }

    return sprintf('Venue review u%d-v%d', $userId, $venueId);
  }

  /**
   * Builds a stable cache key for one review context.
   */
  private function buildContextCacheKey(int $venueId, int $dealId): string {
    return sprintf('v%d:d%d', $venueId, $dealId);
  }

  /**
   * Builds a stable cache key for a user and one review context.
   */
  private function buildUserContextCacheKey(int $userId, int $venueId, int $dealId): string {
    return sprintf('u%d:v%d:d%d', $userId, $venueId, $dealId);
  }

  /**
   * Clears per-request caches after a review write.
   */
  private function resetCachedContext(int $venueId, int $dealId, int $userId): void {
    unset($this->statsCache[$this->buildContextCacheKey($venueId, $dealId)]);
    unset($this->userReviewCache[$this->buildUserContextCacheKey($userId, $venueId, $dealId)]);
  }

  /**
   * Invalidates render cache tags for the reviewed deal/venue context.
   */
  private function invalidateContextCacheTags(int $venueId, int $dealId): void {
    $tags = ['node_list', 'search_api_list:deals_solr'];

    if ($dealId > 0) {
      $tags[] = 'node:' . $dealId;
    }

    if ($venueId > 0) {
      $tags[] = 'node:' . $venueId;
    }

    $this->cacheTagsInvalidator->invalidateTags(array_unique($tags));
  }

}
