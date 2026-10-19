<?php

namespace Drupal\spotdeals_conversations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public, read-only local conversations timeline.
 */
final class ConversationsWallController extends ControllerBase {

  public function __construct(private readonly RequestStack $requestStack) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('request_stack'));
  }

  public function wall(): array {
    $request = $this->requestStack->getCurrentRequest();
    $search = trim((string) $request->query->get('q', ''));
    $page = max(0, min(10000, (int) $request->query->get('page', 0)));
    $limit = 20;
    $sort = (string) $request->query->get('sort', 'newest');
    $allowed_sorts = ['newest', 'oldest', 'comments', 'votes'];
    if (!in_array($sort, $allowed_sorts, TRUE)) {
      $sort = 'newest';
    }

    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'conversation')
      ->condition('status', 1)
      ->sort('created', $sort === 'oldest' ? 'ASC' : 'DESC')
      ->sort('nid', $sort === 'oldest' ? 'ASC' : 'DESC');

    if ($search !== '') {
      // Each word must match, but it may appear in any searchable field.
      // This makes "coffee orlando" and "orlando coffee" equivalent, even
      // when the words occur in different fields or in a different order.
      $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
      foreach (array_unique($terms) as $term) {
        $pattern = '%' . \Drupal::database()->escapeLike($term) . '%';
        $or = $query->orConditionGroup()
          ->condition('title', $pattern, 'LIKE')
          ->condition('field_conversation_body.value', $pattern, 'LIKE')
          ->condition('field_address.locality', $pattern, 'LIKE');
        $query->condition($or);
      }
    }

    if ($sort === 'comments' || $sort === 'votes') {
      // Access and search filters are applied by Drupal's entity query before
      // ranking. Only scalar counts are loaded; conversation nodes are loaded
      // for the current page below.
      $candidate_ids = array_values($query->execute());
      $counts = [];
      $database = \Drupal::database();
      foreach (array_chunk($candidate_ids, 500) as $chunk) {
        if ($sort === 'comments') {
          $stats = $database->select('comment_entity_statistics', 's')
            ->fields('s', ['entity_id', 'comment_count'])
            ->condition('entity_type', 'node')
            ->condition('field_name', 'field_conversation_replies')
            ->condition('entity_id', $chunk, 'IN')
            ->execute();
          foreach ($stats as $row) {
            $counts[(int) $row->entity_id] = (int) $row->comment_count;
          }
        }
        else {
          $totals = $database->select('spotdeals_conversation_vote_totals', 'v')
            ->fields('v', ['nid', 'upvotes'])
            ->condition('nid', $chunk, 'IN')
            ->execute();
          foreach ($totals as $row) {
            $counts[(int) $row->nid] = (int) $row->upvotes;
          }
        }
      }
      // The entity query is newest-first, providing a deterministic tie-break.
      usort($candidate_ids, static function ($a, $b) use ($counts): int {
        return ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0);
      });
      $ids = $candidate_ids;
    }
    else {
      // Date sorts are paginated in SQL, not in PHP.
      $query->range($page * $limit, $limit + 1);
      $ids = array_values($query->execute());
    }
    $has_next = count($ids) > ($sort === 'comments' || $sort === 'votes' ? ($page + 1) * $limit : $limit);
    $ids = array_slice($ids, $sort === 'comments' || $sort === 'votes' ? $page * $limit : 0, $limit);
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($ids);

    $params = [];
    if ($search !== '') {
      $params['q'] = $search;
    }
    if ($sort !== 'newest') {
      $params['sort'] = $sort;
    }

    $html = '<div class="sd-conversations-wall"><div class="sd-conversations-intro"><p>Discover what locals are talking about. Search by topic or city, or explore the latest discussions.</p></div>';
    $html .= '<form class="sd-conversations-search" method="get" action="' .
      Html::escape(Url::fromRoute('spotdeals_conversations.wall')->toString()) . '">';
    $html .= '<div class="sd-conversations-field"><label for="sd-conversations-q">Search topics or cities</label><input id="sd-conversations-q" type="search" name="q" value="' . Html::escape($search) . '" placeholder="Burgers, happy hour, Orlando"></div>';
    if ($sort !== 'newest') {
      $html .= '<input type="hidden" name="sort" value="' . Html::escape($sort) . '">';
    }
    $html .= '<button type="submit">Search conversations</button>';
    $html .= '<a class="sd-conversations-reset" href="' . Html::escape(Url::fromRoute('spotdeals_conversations.wall')->toString()) . '">Clear search</a></form>';
    $html .= '<div class="sd-conversations-results-heading">';
    $html .= '<h2 class="sd-conversations-section-title">' . ($search !== '' ? 'Search results' : 'Latest conversations') . '</h2>';
    $html .= '<form class="sd-conversations-sort" method="get" action="' . Html::escape(Url::fromRoute('spotdeals_conversations.wall')->toString()) . '">';
    if ($search !== '') {
      $html .= '<input type="hidden" name="q" value="' . Html::escape($search) . '">';
    }
    $html .= '<label for="sd-conversations-sort">Sort by</label><select id="sd-conversations-sort" name="sort">';
    foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'comments' => 'Most commented', 'votes' => 'Most voted'] as $value => $label) {
      $html .= '<option value="' . $value . '"' . ($sort === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $html .= '</select><button type="submit">Apply</button></form></div>';

    $html .= '<div class="sd-conversations-list">';
    if (!$nodes) {
      $html .= '<p>No conversations found. Try another search term.</p>';
    }
    foreach ($ids as $id) {
      if (!isset($nodes[$id])) {
        continue;
      }
      $node = $nodes[$id];
      $address = $node->get('field_address')->first();
      $location = $address ? array_filter([
        $address->get('locality')->getValue(),
        $address->get('administrative_area')->getValue(),
      ]) : [];
      $body = trim((string) ($node->get('field_conversation_body')->value ?? ''));
      $summary = mb_strimwidth(strip_tags($body), 0, 240, '…');
      $url = $node->toUrl()->toString();
      $html .= '<article class="sd-conversation-card">';
      $html .= '<div class="sd-conversation-location">' . Html::escape(implode(', ', $location)) . '</div>';
      $target = 'sd-activity-inline-' . (int) $id;
      $preview_url = Url::fromRoute('spotdeals_conversations.inline_preview', ['node' => $id])->toString();
      $html .= '<h2><button type="button" class="sd-conversation-trigger" data-sd-inline-conversation data-inline-url="' . Html::escape($preview_url) . '" aria-expanded="false" aria-controls="' . $target . '">' . Html::escape($node->label()) . '</button></h2>';
      $html .= '<noscript><a href="' . Html::escape($url) . '">Read conversation</a></noscript>';
      if ($summary !== '') {
        $html .= '<p>' . Html::escape($summary) . '</p>';
      }
      $html .= '<a class="sd-conversation-open" href="' . Html::escape($url) . '">View full conversation →</a>';
      $html .= '<div id="' . $target . '" class="sd-profile__activity-expand sd-conversation-panel" hidden role="region" aria-label="' . Html::escape($node->label()) . '"></div>';
      $html .= '</article>';
    }
    $html .= '</div>';

    $html .= '<nav class="sd-conversations-pages" aria-label="Conversations pages">';
    if ($page > 0) {
      $html .= '<a href="' . Html::escape(Url::fromRoute('spotdeals_conversations.wall', [], ['query' => $params + ['page' => $page - 1]])->toString()) . '">← Previous</a>';
    }
    if ($has_next) {
      $html .= '<a href="' . Html::escape(Url::fromRoute('spotdeals_conversations.wall', [], ['query' => $params + ['page' => $page + 1]])->toString()) . '">More conversations →</a>';
    }
    $html .= '</nav></div>';

    return [
      '#type' => 'markup',
      // All dynamic text and URLs above are HTML-escaped. Preserve the form,
      // input, article and data-* markup; Drupal filters these from raw #markup.
      '#markup' => Markup::create($html),
      '#attached' => ['library' => ['spotdeals_conversations/wall', 'spotdeals_conversations/activity_inline']],
      '#cache' => ['contexts' => ['url.query_args', 'user.permissions'], 'tags' => ['node_list'], 'max-age' => 0],
    ];
  }

}
