/** Homepage conversation placements; appearance varies independently of content. */
(function (Drupal, once) {
  'use strict';

  const shapes = ['cloud', 'blob', 'ticket', 'rounded', 'comic'];
  const palettes = ['lemon', 'rose', 'sky', 'mint', 'lavender', 'peach'];
  const lettering = ['friendly', 'bold', 'editorial'];
  const textures = ['dots', 'stripes', 'paper', 'plain'];
  let appearancePool = [];
  function nextAppearance() {
    if (!appearancePool.length) {
      const shuffled = (values) => [...values].sort(() => Math.random() - 0.5);
      const colors = shuffled(palettes);
      const forms = shuffled(shapes);
      appearancePool = colors.map((color, i) => [forms[i % forms.length], color, pick(lettering), pick(textures)]);
    }
    return appearancePool.pop();
  }
  const pick = (list) => list[Math.floor(Math.random() * list.length)];
  const searchKeys = ['city', 'location', 'search_raw', 'search_deals',
    'search_api_fulltext', 'search_deals_by_city', 'search_origin_mode',
    'origin_lat', 'origin_lon'];
  const exposedFormSelector = 'form[data-drupal-selector="views-exposed-form-deals-search-solr-page-1"]';

  let lastQuery = null;
  let lastItems = [];
  let requestId = 0;
  let refreshTimer;
  let submittedParams = null;
  let dismissed = new Set();
  let highestBubbleZIndex = 1001;

  function bringBubbleToFront(bubble) {
    highestBubbleZIndex += 1;
    bubble.style.zIndex = String(highestBubbleZIndex);
  }

  function makeBubble(item, slot) {
    const wrapper = document.createElement('section');
    const appearance = nextAppearance();
    wrapper.className = 'sd-home-bubble sd-home-bubble--' + slot +
      appearance.map((variant) => ' sd-home-bubble--' + variant).join('');
    wrapper.setAttribute('aria-label', Drupal.t('Local conversation'));

    // Bring a bubble forward on any pointer interaction, including accordion
    // clicks/taps. Capture phase also covers nested buttons and links.
    wrapper.addEventListener('pointerdown', () => {
      bringBubbleToFront(wrapper);
    }, true);

    // Keyboard navigation should reveal the focused bubble as well.
    wrapper.addEventListener('focusin', () => {
      bringBubbleToFront(wrapper);
    });

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'sd-home-bubble__close';
    close.setAttribute('aria-label', Drupal.t('Dismiss conversation'));
    close.textContent = '×';
    close.addEventListener('click', () => {
      dismissed.add(item.id);
      wrapper.remove();
    });

    const kicker = document.createElement('span');
    kicker.className = 'sd-home-bubble__kicker';
    kicker.textContent = item.city || Drupal.t('Local conversation');

    const target = 'sd-activity-inline-' + item.id;
    const detailsId = 'sd-home-bubble-details-' + item.id;
    const heading = document.createElement('button');
    heading.type = 'button';
    heading.className = 'sd-home-bubble__heading';
    heading.setAttribute('aria-controls', detailsId);
    heading.setAttribute('aria-expanded', 'false');

    const title = document.createElement('span');
    title.className = 'sd-home-bubble__title';
    title.textContent = item.title;
    const chevron = document.createElement('span');
    chevron.className = 'sd-home-bubble__chevron';
    chevron.setAttribute('aria-hidden', 'true');
    chevron.textContent = '⌄';
    heading.append(title, chevron);

    const teaser = document.createElement('p');
    teaser.className = 'sd-home-bubble__teaser';
    teaser.textContent = item.summary || '';

    const details = document.createElement('div');
    details.id = detailsId;
    details.className = 'sd-home-bubble__details';
    details.hidden = true;

    const summary = document.createElement('p');
    summary.className = 'sd-home-bubble__summary';
    summary.textContent = item.summary || '';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'sd-home-bubble__question';
    trigger.dataset.sdInlineConversation = '';
    trigger.dataset.inlineUrl = item.preview;
    trigger.setAttribute('aria-controls', target);
    trigger.setAttribute('aria-expanded', 'false');
    trigger.textContent = Drupal.t('Open conversation preview');

    const link = document.createElement('a');
    link.href = item.url;
    link.className = 'sd-home-bubble__full';
    link.textContent = Drupal.t('View conversation');

    const panel = document.createElement('div');
    panel.id = target;
    panel.hidden = true;
    panel.className = 'sd-profile__activity-expand sd-conversation-panel sd-home-bubble__panel';
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', item.title);

    if (item.summary) details.appendChild(summary);
    details.append(trigger, link, panel);

    function fitExpandedBubble() {
      const rect = wrapper.getBoundingClientRect();
      const top = Math.max(0, Math.min(rect.top, window.innerHeight - rect.height));
      const left = Math.max(0, Math.min(rect.left, window.innerWidth - rect.width));
      wrapper.style.top = top + 'px';
      wrapper.style.left = left + 'px';
    }

    heading.addEventListener('click', () => {
      const expanded = heading.getAttribute('aria-expanded') !== 'true';
      heading.setAttribute('aria-expanded', String(expanded));
      details.hidden = !expanded;
      wrapper.classList.toggle('sd-home-bubble--expanded', expanded);
      fitExpandedBubble();
    });

    const hint = document.createElement('span');
    hint.className = 'sd-home-bubble__drag-hint';
    hint.setAttribute('role', 'tooltip');
    hint.textContent = Drupal.t('Drag me around');
    kicker.appendChild(hint);

    let hintTimer;
    function showTouchHint() {
      if (!window.matchMedia('(pointer: coarse)').matches) return;
      wrapper.classList.add('sd-home-bubble--show-hint');
      window.clearTimeout(hintTimer);
      hintTimer = window.setTimeout(() => wrapper.classList.remove('sd-home-bubble--show-hint'), 1600);
    }
    kicker.addEventListener('pointerup', (event) => {
      if (event.pointerType === 'touch') showTouchHint();
    });

    // A stable drag handle keeps pointer capture alive for the whole gesture.
    kicker.classList.add('sd-home-bubble__drag-handle');
    kicker.setAttribute('title', Drupal.t('Drag me around'));
    kicker.setAttribute('aria-label', Drupal.t('Drag conversation bubble to move it'));
    kicker.addEventListener('pointerdown', (event) => {
      if (!event.isPrimary || event.pointerType === 'touch' || (event.pointerType === 'mouse' && event.button !== 0)) return;
      event.preventDefault();
      const rect = wrapper.getBoundingClientRect();
      const pointerId = event.pointerId;
      const grabX = event.clientX - rect.left;
      const grabY = event.clientY - rect.top;
      wrapper.classList.add('sd-home-bubble--dragged');
      bringBubbleToFront(wrapper);
      wrapper.style.left = rect.left + 'px';
      wrapper.style.top = rect.top + 'px';
      wrapper.style.right = 'auto';
      wrapper.style.bottom = 'auto';
      wrapper.style.width = rect.width + 'px';
      kicker.setPointerCapture(pointerId);

      const move = (e) => {
        if (e.pointerId !== pointerId) return;
        e.preventDefault();
        const maxX = Math.max(0, window.innerWidth - wrapper.offsetWidth);
        const maxY = Math.max(0, window.innerHeight - wrapper.offsetHeight);
        wrapper.style.left = Math.max(0, Math.min(maxX, e.clientX - grabX)) + 'px';
        wrapper.style.top = Math.max(0, Math.min(maxY, e.clientY - grabY)) + 'px';
      };
      const finish = (e) => {
        if (e.pointerId !== pointerId) return;
        kicker.removeEventListener('pointermove', move);
        kicker.removeEventListener('pointerup', finish);
        kicker.removeEventListener('pointercancel', finish);
        if (kicker.hasPointerCapture(pointerId)) kicker.releasePointerCapture(pointerId);
      };
      kicker.addEventListener('pointermove', move);
      kicker.addEventListener('pointerup', finish);
      kicker.addEventListener('pointercancel', finish);
    });

    // On touchscreens, the entire non-interactive bubble surface is a drag
    // target. Desktop mouse dragging remains exclusively on the kicker.
    wrapper.addEventListener('pointerdown', (event) => {
      if (event.pointerType !== 'touch' || !event.isPrimary) return;
      if (event.target.closest('button, a, input, textarea, select, [contenteditable]')) return;

      event.preventDefault();
      const rect = wrapper.getBoundingClientRect();
      const pointerId = event.pointerId;
      const grabX = event.clientX - rect.left;
      const grabY = event.clientY - rect.top;
      wrapper.classList.add('sd-home-bubble--dragged');
      bringBubbleToFront(wrapper);
      wrapper.style.left = rect.left + 'px';
      wrapper.style.top = rect.top + 'px';
      wrapper.style.right = 'auto';
      wrapper.style.bottom = 'auto';
      wrapper.style.width = rect.width + 'px';
      wrapper.setPointerCapture(pointerId);

      const move = (e) => {
        if (e.pointerId !== pointerId) return;
        e.preventDefault();
        const maxX = Math.max(0, window.innerWidth - wrapper.offsetWidth);
        const maxY = Math.max(0, window.innerHeight - wrapper.offsetHeight);
        wrapper.style.left = Math.max(0, Math.min(maxX, e.clientX - grabX)) + 'px';
        wrapper.style.top = Math.max(0, Math.min(maxY, e.clientY - grabY)) + 'px';
      };
      const finish = (e) => {
        if (e.pointerId !== pointerId) return;
        wrapper.removeEventListener('pointermove', move);
        wrapper.removeEventListener('pointerup', finish);
        wrapper.removeEventListener('pointercancel', finish);
        if (wrapper.hasPointerCapture(pointerId)) wrapper.releasePointerCapture(pointerId);
      };
      wrapper.addEventListener('pointermove', move);
      wrapper.addEventListener('pointerup', finish);
      wrapper.addEventListener('pointercancel', finish);
    });

    // Suppress iOS/Android long-press menus only for the mobile drag surface.
    wrapper.addEventListener('contextmenu', (event) => {
      if (window.matchMedia('(pointer: coarse)').matches &&
          !event.target.closest('button, a, input, textarea, select, [contenteditable]')) {
        event.preventDefault();
      }
    });

    wrapper.append(close, kicker, heading);
    if (item.summary) wrapper.appendChild(teaser);
    wrapper.appendChild(details);
    return wrapper;
  }

  function currentQuery() {
    const params = new URLSearchParams(window.location.search);
    // A submitted AJAX search can precede the browser URL update.
    if (submittedParams !== null) {
      searchKeys.forEach((key) => {
        if (submittedParams.has(key)) params.set(key, submittedParams.get(key));
        else params.delete(key);
      });
    }
    const query = new URLSearchParams();
    searchKeys.forEach((key) => {
      if (params.has(key)) query.set(key, params.get(key));
    });
    return query.toString();
  }

  function placements() {
    return [
      ['hero', document.querySelector('.spotdeals-home-feed__hero'),
        (bubble, target) => target.appendChild(bubble)],
      ['sidebar', document.querySelector('.spotdeals-sidebar-right'),
        (bubble, target) => target.appendChild(bubble)],
      ['results', document.querySelector('.spotdeals-finder__cards') || document.querySelector('.spotdeals-finder__results'),
        (bubble, target) => {
          const rows = Array.from(target.children).filter((child) => !child.classList.contains('sd-home-bubble'));
          if (rows.length > 2) rows[2].after(bubble);
          else target.appendChild(bubble);
        }],
    ];
  }

  function render(items) {
    const slots = placements();
    if (!document.querySelector('.sd-home-bubble')) appearancePool = [];
    const available = items.filter((item) => !dismissed.has(item.id));
    // Rebuild only if a slot is missing or the data actually changed.
    // Keeping existing nodes preserves expanded conversations and reply forms.
    let index = 0;
    slots.forEach(([slot, target, insert]) => {
      if (!target || index >= available.length) return;
      const item = available[index++];
      const existing = document.querySelector('.sd-home-bubble--' + slot);
      if (existing && existing.dataset.conversationId === String(item.id)) return;
      if (existing) existing.remove();
      const bubble = makeBubble(item, slot);
      bubble.dataset.conversationId = String(item.id);
      insert(bubble, target);
      // Establish viewport coordinates before any pointer interaction. The
      // bubble never changes DOM parent while a drag is in progress.
      const rect = bubble.getBoundingClientRect();
      document.body.appendChild(bubble);
      bubble.classList.add('sd-home-bubble--viewport');
      bubble.style.left = rect.left + 'px';
      bubble.style.top = rect.top + 'px';
      bubble.style.right = 'auto';
      bubble.style.bottom = 'auto';
      bubble.style.width = rect.width + 'px';
      Drupal.attachBehaviors(bubble);
    });
  }

  async function refresh() {
    const query = currentQuery();
    if (query === lastQuery) {
      render(lastItems);
      return;
    }
    lastQuery = query;
    const id = ++requestId;
    const url = new URL(Drupal.url('spotdeals/home-conversation-bubbles'), window.location.href);
    url.search = query;
    try {
      const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Conversation feed unavailable');
      const payload = await response.json();
      if (id !== requestId) return;
      lastItems = Array.isArray(payload.items) ? payload.items : [];
      dismissed = new Set();
      document.querySelectorAll('.sd-home-bubble').forEach((bubble) => bubble.remove());
      render(lastItems);
    }
    catch (error) {
      if (id === requestId) lastQuery = null;
    }
  }

  function scheduleRefresh() {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refresh, 120);
  }

  Drupal.behaviors.spotdealsHomeBubbles = {
    attach(context) {
      once('sd-home-bubbles-init', 'body.path-frontpage', context).forEach(() => {
        ['pushState', 'replaceState'].forEach((method) => {
          const original = window.history[method];
          window.history[method] = function (...args) {
            const result = original.apply(this, args);
            // The URL becomes authoritative once browser history changes.
            submittedParams = null;
            scheduleRefresh();
            return result;
          };
        });
        window.addEventListener('popstate', () => {
          submittedParams = null;
          scheduleRefresh();
        });
        document.addEventListener('submit', (event) => {
          const form = event.target;
          if (!form.matches(exposedFormSelector)) return;
          submittedParams = new URLSearchParams(new FormData(form));
          scheduleRefresh();
        }, true);
        if (window.jQuery) {
          window.jQuery(document).ajaxComplete((event, xhr, settings) => {
            // Conversation preview/voting AJAX must not refresh the bubble feed.
            const url = String(settings && settings.url || '');
            if (url.includes('/conversations/') || url.includes('/spotdeals/home-conversation-bubbles')) return;
            scheduleRefresh();
          });
        }
        refresh();
      });
    },
  };
})(Drupal, once);
