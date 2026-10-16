<?php

namespace Drupal\spotdeals_conversations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
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
    $topic = trim((string) $request->query->get('q', ''));
    $city = trim((string) $request->query->get('city', ''));
    $page = max(0, min(10000, (int) $request->query->get('page', 0)));
    $limit = 20;

    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'conversation')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range($page * $limit, $limit + 1);

    if ($topic !== '') {
      $or = $query->orConditionGroup()
        ->condition('title', '%' . $topic . '%', 'LIKE')
        ->condition('field_conversation_body.value', '%' . $topic . '%', 'LIKE');
      $query->condition($or);
    }
    if ($city !== '') {
      $query->condition('field_address.locality', '%' . $city . '%', 'LIKE');
    }

    $ids = array_values($query->execute());
    $has_next = count($ids) > $limit;
    $ids = array_slice($ids, 0, $limit);
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($ids);

    $params = [];
    if ($topic !== '') {
      $params['q'] = $topic;
    }
    if ($city !== '') {
      $params['city'] = $city;
    }

    $html = '<div class="sd-conversations-wall"><div class="sd-conversations-intro"><p>Discover what locals are talking about. Search by topic and city, or explore the latest discussions.</p></div>';
    $html .= '<form class="sd-conversations-search" method="get" action="' .
      Html::escape(Url::fromRoute('spotdeals_conversations.wall')->toString()) . '">';
    $html .= '<div class="sd-conversations-field"><label for="sd-conversations-q">Topic</label><input id="sd-conversations-q" type="search" name="q" value="' . Html::escape($topic) . '" placeholder="Pizza, burgers, happy hour"></div>';
    $html .= '<div class="sd-conversations-field"><label for="sd-conversations-city">City</label><input id="sd-conversations-city" type="search" name="city" value="' . Html::escape($city) . '" placeholder="New Smyrna Beach, Orlando"></div>';
    $html .= '<button type="submit">Search conversations</button></form>';
    $html .= '<h2 class="sd-conversations-section-title">Latest conversations</h2>';

    $html .= '<div class="sd-conversations-list">';
    if (!$nodes) {
      $html .= '<p>No conversations found. Try another topic or city.</p>';
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
      $html .= '<h2><a href="' . Html::escape($url) . '">' . Html::escape($node->label()) . '</a></h2>';
      if ($summary !== '') {
        $html .= '<p>' . Html::escape($summary) . '</p>';
      }
      $html .= '<a class="sd-conversation-open use-ajax" data-dialog-type="modal" data-dialog-options="{&quot;width&quot;:850,&quot;dialogClass&quot;:&quot;sd-conversation-dialog&quot;}" href="' . Html::escape($url) . '">View conversation and replies →</a>';
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
      '#markup' => $html,
      '#attached' => ['library' => ['spotdeals_conversations/wall', 'core/drupal.dialog.ajax']],
      '#cache' => ['contexts' => ['url.query_args', 'user.permissions'], 'tags' => ['node_list'], 'max-age' => 0],
    ];
  }

}
