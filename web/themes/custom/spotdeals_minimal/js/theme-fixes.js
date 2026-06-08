(function (Drupal, once) {
  'use strict';

  function isRecommendationMode(context) {
    const checkbox = context.querySelector('.spotdeals-finder__filters input[name="help_me_choose"]');

    if (checkbox) {
      return checkbox.checked;
    }

    const url = new URL(window.location.href);
    return url.searchParams.has('help_me_choose') && url.searchParams.get('help_me_choose') !== '0';
  }

  function getCurrentLanguageId() {
    const htmlLang = (document.documentElement.getAttribute('lang') || '').toLowerCase();

    if (htmlLang) {
      return htmlLang.split('-')[0];
    }

    if (typeof drupalSettings !== 'undefined' && drupalSettings.path && drupalSettings.path.currentLanguage) {
      return drupalSettings.path.currentLanguage;
    }

    return 'en';
  }

  function getResultSummaryText(start, end, total) {
    if (getCurrentLanguageId() === 'es') {
      return 'Mostrando ' + start + ' - ' + end + ' de ' + total;
    }

    return 'Displaying ' + start + ' - ' + end + ' of ' + total;
  }

  function getRenderedRowCount(resultsWrapper) {
    const rows = resultsWrapper.querySelectorAll('.views-row');

    if (rows.length > 0) {
      return rows.length;
    }

    const unformattedItems = resultsWrapper.querySelectorAll('.view-content > *');

    if (unformattedItems.length > 0) {
      return unformattedItems.length;
    }

    return 0;
  }

  function findSummaryElement(resultsWrapper) {
    const header = resultsWrapper.querySelector('.view-header');

    if (!header) {
      return null;
    }

    const summaryPattern = /(?:Displaying\s+\d+\s*-\s*\d+\s+of\s+\d+|Mostrando\s+\d+\s*-\s*\d+\s+de\s+\d+)/i;

    if (summaryPattern.test(header.textContent || '')) {
      return header;
    }

    const matchingChild = Array.from(header.querySelectorAll('*')).find(function (element) {
      return summaryPattern.test(element.textContent || '');
    });

    return matchingChild || header;
  }

  function ensureRecommendationSummary(resultsWrapper) {
    if (!isRecommendationMode(document)) {
      return;
    }

    const rowCount = getRenderedRowCount(resultsWrapper);

    if (rowCount !== 1) {
      return;
    }

    const summaryText = getResultSummaryText(1, 1, 1);
    const summaryElement = findSummaryElement(resultsWrapper);

    if (summaryElement) {
      summaryElement.textContent = summaryText;
      return;
    }

    const header = document.createElement('div');
    header.className = 'view-header';
    header.textContent = summaryText;
    resultsWrapper.insertBefore(header, resultsWrapper.firstChild);
  }

  function findExternalVoteElements(row, card) {
    const selectors = [
      '.views-field-spotdeals-vote',
      '.views-field-spotdeals-vote-summary',
      '.views-field-field-spotdeals-vote',
      '.views-field-field-spotdeals-vote-summary',
      '.spotdeals-vote',
      '.spotdeals-vote-widget',
      '.spotdeals-review-summary',
      '.spotdeals-vote-summary',
      '[class*="spotdeals-vote"]',
      '[class*="vote-summary"]'
    ];

    return selectors
      .flatMap(function (selector) {
        return Array.from(row.querySelectorAll(selector));
      })
      .filter(function (element, index, elements) {
        return elements.indexOf(element) === index && !card.contains(element);
      })
      .filter(function (element) {
        const text = (element.textContent || '').trim();
        return text !== '' || element.children.length > 0;
      });
  }

  function ensureCardVoteTarget(card, type) {
    const attribute = type === 'deal' ? 'data-spotdeals-card-deal-votes' : 'data-spotdeals-card-venue-votes';
    const className = type === 'deal' ? 'spotdeals-deal-card__deal-votes' : 'spotdeals-deal-card__venue-votes';
    let target = card.querySelector('[' + attribute + ']');

    if (target) {
      return target;
    }

    target = document.createElement('div');
    target.className = className;
    target.setAttribute(attribute, '');

    if (type === 'deal') {
      const deal = card.querySelector('.spotdeals-deal-card__deal');

      if (deal) {
        deal.appendChild(target);
        return target;
      }
    }

    const main = card.querySelector('.spotdeals-deal-card__main');

    if (main) {
      main.appendChild(target);
    }

    return target;
  }

  function voteElementType(element) {
    const text = (element.textContent || '').toLowerCase();
    const classes = (element.getAttribute('class') || '').toLowerCase();
    const combined = text + ' ' + classes;

    if (combined.includes('was it worth') || combined.includes('deal')) {
      return 'deal';
    }

    if (combined.includes('worth visiting') || combined.includes('venue') || combined.includes('visit')) {
      return 'venue';
    }

    return 'venue';
  }

  function moveVotesIntoDealCards(context) {
    once('spotdeals-card-vote-placement-v22', '.spotdeals-finder__cards > .views-row', context).forEach(function (row) {
      const card = row.querySelector('.spotdeals-deal-card');

      if (!card) {
        return;
      }

      const externalVotes = findExternalVoteElements(row, card);

      externalVotes.forEach(function (voteElement) {
        const type = voteElementType(voteElement);
        const target = ensureCardVoteTarget(card, type);

        target.appendChild(voteElement);
      });
    });
  }

  function findInternalVenueVoteElements(card) {
    const target = card.querySelector("[data-spotdeals-card-venue-votes]");
    const main = card.querySelector(".spotdeals-deal-card__main");

    if (!target || !main || normalizeWhitespace(target.textContent || "")) {
      return [];
    }

    const selectors = [
      ".views-field-spotdeals-vote",
      ".views-field-spotdeals-vote-summary",
      ".views-field-field-spotdeals-vote",
      ".views-field-field-spotdeals-vote-summary",
      ".spotdeals-vote",
      ".spotdeals-vote-widget",
      ".spotdeals-review-summary",
      ".spotdeals-vote-summary",
      "[class*=\"spotdeals-vote\"]",
      "[class*=\"vote-summary\"]"
    ];

    return selectors
      .flatMap(function (selector) {
        return Array.from(main.querySelectorAll(selector));
      })
      .filter(function (element, index, elements) {
        return elements.indexOf(element) === index;
      })
      .filter(function (element) {
        return !target.contains(element) && !element.closest(".spotdeals-deal-card__deal-panel");
      })
      .filter(function (element) {
        const text = normalizeWhitespace(element.textContent || "");
        return /\d{1,3}\s*%|no\s+votes?/i.test(text);
      });
  }

  function moveInternalVenueVotesIntoTarget(context) {
    once("spotdeals-card-internal-venue-vote-placement-v25", ".spotdeals-deal-card", context).forEach(function (card) {
      const target = ensureCardVoteTarget(card, "venue");

      findInternalVenueVoteElements(card).forEach(function (voteElement) {
        target.appendChild(voteElement);
      });

      if (!normalizeWhitespace(target.textContent || "")) {
        const main = card.querySelector(".spotdeals-deal-card__main");
        const mainText = normalizeWhitespace(main ? main.textContent : "");
        const compactVote = mainText.match(/\(?\d+\)?\s*\d{1,3}\s*%|\d{1,3}\s*%\s*\(?\d+\)?/);

        if (compactVote) {
          const fallback = document.createElement("span");
          fallback.className = "spotdeals-vote-summary-source-hidden";
          fallback.textContent = compactVote[0];
          target.appendChild(fallback);
        }
      }
    });
  }

  function voteQuestionLabels(type) {
    if (type === 'deal') {
      return ['Worth it?', 'Go back?'];
    }

    return ['Worth visiting?', 'Go back?'];
  }

  function voteQuestionAliases(type) {
    if (type === 'deal') {
      return [
        ['Worth it?', 'Was it worth it?'],
        ['Go back?', 'Would you go back?']
      ];
    }

    return [
      ['Worth visiting?'],
      ['Go back?', 'Would go back?', 'Would you go back?']
    ];
  }

  function normalizeWhitespace(value) {
    return (value || '').replace(/\s+/g, ' ').trim();
  }


  function translateFrontendLabel(label) {
    const spanishLabels = {
      'View nearby picks': 'Ver opciones cercanas',
      'View popular searches': 'Ver búsquedas populares',
      'View trending deals': 'Ver ofertas populares',
      'View more deals': 'Ver más ofertas'
    };

    if (getCurrentLanguageId() === 'es' && spanishLabels[label]) {
      return spanishLabels[label];
    }

    if (Drupal.t) {
      return Drupal.t(label);
    }

    return label;
  }

  function isDealsDiscoveryPage() {
    return document.body.classList.contains('path-frontpage') || document.body.classList.contains('path-deals');
  }

  function getDirectBlockTitle(block) {
    return Array.from(block.children).find(function (child) {
      return child.matches('h1, h2, h3, h4, h5, h6, .block-title, .block__title');
    }) || null;
  }

  function getMobileDiscoverySummaryText(block, title) {
    const titleText = normalizeWhitespace(title ? title.textContent : '');

    if (block.classList.contains('block-spotdeals-search-insights') || /popular searches/i.test(titleText)) {
      return translateFrontendLabel('View popular searches');
    }

    if (block.classList.contains('block-spotdeals-search-smart-location-trending-near-you') || /trending deals|trending near you/i.test(titleText)) {
      return translateFrontendLabel('View trending deals');
    }

    return titleText ? titleText : translateFrontendLabel('View more deals');
  }

  function enableMobileDiscoveryAccordion(block) {
    if (block.dataset.spotdealsMobileAccordion === '1') {
      return;
    }

    const title = getDirectBlockTitle(block);
    const titleText = getMobileDiscoverySummaryText(block, title);
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    const content = document.createElement('div');

    details.className = 'spotdeals-mobile-discovery-accordion';
    summary.className = 'spotdeals-mobile-discovery-accordion__summary';
    content.className = 'spotdeals-mobile-discovery-accordion__content';
    summary.textContent = titleText;

    Array.from(block.childNodes).forEach(function (node) {
      if (node !== title) {
        content.appendChild(node);
      }
    });

    details.appendChild(summary);
    details.appendChild(content);
    block.appendChild(details);
    block.dataset.spotdealsMobileAccordion = '1';
  }

  function disableMobileDiscoveryAccordion(block) {
    if (block.dataset.spotdealsMobileAccordion !== '1') {
      return;
    }

    const details = Array.from(block.children).find(function (child) {
      return child.classList.contains('spotdeals-mobile-discovery-accordion');
    });

    if (!details) {
      delete block.dataset.spotdealsMobileAccordion;
      return;
    }

    const content = details.querySelector('.spotdeals-mobile-discovery-accordion__content');
    if (content) {
      while (content.firstChild) {
        block.insertBefore(content.firstChild, details);
      }
    }

    details.remove();
    delete block.dataset.spotdealsMobileAccordion;
  }


  function getSeoLandingSectionTitle(section) {
    return Array.from(section.children).find(function (child) {
      return child.classList.contains('spotdeals-seo-section-title');
    }) || null;
  }

  function enableSeoLandingMobileAccordion(section) {
    if (section.dataset.spotdealsSeoMobileAccordion === '1') {
      return;
    }

    const title = getSeoLandingSectionTitle(section);
    const titleText = normalizeWhitespace(title ? title.textContent : '');

    if (!titleText) {
      return;
    }

    const details = document.createElement('details');
    const summary = document.createElement('summary');
    const content = document.createElement('div');

    details.className = 'spotdeals-mobile-discovery-accordion spotdeals-seo-left-rail-accordion';
    summary.className = 'spotdeals-mobile-discovery-accordion__summary';
    content.className = 'spotdeals-mobile-discovery-accordion__content';
    summary.textContent = titleText;

    while (section.firstChild) {
      content.appendChild(section.firstChild);
    }

    details.appendChild(summary);
    details.appendChild(content);
    section.appendChild(details);
    section.dataset.spotdealsSeoMobileAccordion = '1';
  }

  function disableSeoLandingMobileAccordion(section) {
    if (section.dataset.spotdealsSeoMobileAccordion !== '1') {
      return;
    }

    const details = Array.from(section.children).find(function (child) {
      return child.classList.contains('spotdeals-seo-left-rail-accordion');
    });

    if (!details) {
      delete section.dataset.spotdealsSeoMobileAccordion;
      return;
    }

    const content = details.querySelector('.spotdeals-mobile-discovery-accordion__content');
    if (content) {
      while (content.firstChild) {
        section.insertBefore(content.firstChild, details);
      }
    }

    details.remove();
    delete section.dataset.spotdealsSeoMobileAccordion;
  }

  function moveSeoLandingMobileAccordions() {
    const isMobile = window.matchMedia('(max-width: 640px)').matches;
    const sections = document.querySelectorAll('.spotdeals-seo-landing__left-rail .spotdeals-seo-related, .spotdeals-seo-landing__left-rail .spotdeals-seo-nearby');

    sections.forEach(function (section) {
      if (isMobile) {
        enableSeoLandingMobileAccordion(section);
        return;
      }

      disableSeoLandingMobileAccordion(section);
    });
  }

  function moveMobileDiscoveryBlocks() {
    if (!isDealsDiscoveryPage()) {
      return;
    }

    const sidebar = document.querySelector('.spotdeals-sidebar-right');
    const filters = document.querySelector('.spotdeals-finder__filters');

    if (!sidebar || !filters) {
      return;
    }

    if (!sidebar._spotdealsOriginalPlaceholder) {
      sidebar._spotdealsOriginalPlaceholder = document.createComment('spotdeals-sidebar-right-original-position');
      sidebar.parentNode.insertBefore(sidebar._spotdealsOriginalPlaceholder, sidebar);
    }

    const isMobile = window.matchMedia('(max-width: 1023px)').matches;
    const discoveryBlocks = sidebar.querySelectorAll('.block-spotdeals-search-insights, .block-spotdeals-search-smart-location-trending-near-you');

    if (isMobile) {
      sidebar.classList.add('spotdeals-mobile-discovery-blocks');
      filters.insertAdjacentElement('afterend', sidebar);
      discoveryBlocks.forEach(enableMobileDiscoveryAccordion);
      return;
    }

    discoveryBlocks.forEach(disableMobileDiscoveryAccordion);
    sidebar.classList.remove('spotdeals-mobile-discovery-blocks');

    const placeholder = sidebar._spotdealsOriginalPlaceholder;
    if (placeholder && placeholder.parentNode && sidebar.previousSibling !== placeholder) {
      placeholder.parentNode.insertBefore(sidebar, placeholder.nextSibling);
    }
  }

  function attachMobileDiscoveryResizeHandler(context) {
    once('spotdeals-mobile-discovery-resize-v1', 'body', context).forEach(function () {
      let resizeTimer = null;
      window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
          moveMobileDiscoveryBlocks();
          moveSeoLandingMobileAccordions();
        }, 150);
      });
    });
  }

  function findFirstLabelMatch(lowerText, labels, startOffset) {
    let match = null;

    labels.forEach(function (label) {
      const index = lowerText.indexOf(label.toLowerCase(), startOffset || 0);

      if (index !== -1 && (!match || index < match.index)) {
        match = {
          index: index,
          label: label
        };
      }
    });

    return match;
  }

  function extractVoteMetric(sourceText, label, aliases, nextAliases) {
    const text = normalizeWhitespace(sourceText);
    const lower = text.toLowerCase();
    const labelAliases = aliases && aliases.length ? aliases : [label];
    const startMatch = findFirstLabelMatch(lower, labelAliases, 0);

    if (!startMatch) {
      return {
        label: label,
        percent: '',
        count: '',
        hasVotes: false
      };
    }

    let end = text.length;

    if (nextAliases && nextAliases.length) {
      const nextMatch = findFirstLabelMatch(lower, nextAliases, startMatch.index + startMatch.label.length);
      if (nextMatch) {
        end = nextMatch.index;
      }
    }

    const segment = text.slice(startMatch.index, end);
    const percentMatch = segment.match(/(\d{1,3})\s*%/);
    const countMatch = segment.match(/\((\d+)\)/) || segment.match(/(\d+)\s+(?:vote|votes|review|reviews)\b/i);
    const noVotes = /no\s+votes?/i.test(segment);

    return {
      label: label,
      percent: percentMatch ? percentMatch[1] + '%' : '',
      count: countMatch ? '(' + countMatch[1] + ')' : '',
      hasVotes: Boolean(percentMatch) && !noVotes
    };
  }

  function extractCompactVoteMetrics(sourceText, labels) {
    const text = normalizeWhitespace(sourceText);
    const matches = [];
    const percentPattern = /(\d{1,3})\s*%/g;
    let match;

    while ((match = percentPattern.exec(text)) !== null) {
      const before = text.slice(Math.max(0, match.index - 24), match.index);
      const after = text.slice(match.index + match[0].length, match.index + match[0].length + 24);
      const countBefore = before.match(/\((\d+)\)\s*$/);
      const countAfter = after.match(/^\s*\((\d+)\)/);
      const count = countAfter || countBefore;

      matches.push({
        label: labels[matches.length] || labels[labels.length - 1],
        percent: match[1] + "%",
        count: count ? "(" + count[1] + ")" : "",
        hasVotes: true
      });
    }

    return labels.map(function (label, index) {
      if (matches[index]) {
        matches[index].label = label;
        return matches[index];
      }

      return {
        label: label,
        percent: "",
        count: "",
        hasVotes: false
      };
    });
  }

  function createVoteSummaryDisplay(metrics, type) {
    const summary = document.createElement('div');
    summary.className = 'spotdeals-vote-summary spotdeals-vote-summary--display spotdeals-vote-summary--' + type;

    metrics.forEach(function (metric) {
      const item = document.createElement('div');
      item.className = 'spotdeals-vote-summary__item';

      const label = document.createElement('div');
      label.className = 'spotdeals-vote-summary__label';
      label.textContent = metric.label;
      item.appendChild(label);

      if (metric.hasVotes) {
        const value = document.createElement('div');
        value.className = 'spotdeals-vote-summary__value';

        const icon = document.createElement('span');
        icon.className = 'spotdeals-vote-summary__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '👍';
        value.appendChild(icon);

        const percent = document.createElement('span');
        percent.className = 'spotdeals-vote-summary__percent';
        percent.textContent = metric.percent;
        value.appendChild(percent);

        if (metric.count) {
          const count = document.createElement('span');
          count.className = 'spotdeals-vote-summary__count';
          count.textContent = metric.count;
          value.appendChild(count);
        }

        item.appendChild(value);
      }
      else {
        const noVotes = document.createElement('div');
        noVotes.className = 'spotdeals-vote-summary__no-votes';
        noVotes.textContent = 'No votes';
        item.appendChild(noVotes);
      }

      summary.appendChild(item);
    });

    return summary;
  }

  function normalizeVoteSummaryTarget(target, type) {
    if (!target || target.querySelector('.spotdeals-vote-summary--display')) {
      return;
    }

    const sourceText = normalizeWhitespace(target.textContent || '');
    const labels = voteQuestionLabels(type);
    const aliases = voteQuestionAliases(type);
    const lowerSourceText = sourceText.toLowerCase();
    const includesExpectedLabels = aliases.some(function (aliasGroup) {
      return aliasGroup.some(function (alias) {
        return lowerSourceText.includes(alias.toLowerCase());
      });
    });

    const metrics = sourceText
      ? (includesExpectedLabels
        ? labels.map(function (label, index) {
          return extractVoteMetric(sourceText, label, aliases[index], aliases[index + 1]);
        })
        : extractCompactVoteMetrics(sourceText, labels))
      : labels.map(function (label) {
        return {
          label: label,
          percent: '',
          count: '',
          hasVotes: false
        };
      });

    Array.from(target.children).forEach(function (child) {
      child.classList.add('spotdeals-vote-summary-source-hidden');
      child.setAttribute('aria-hidden', 'true');
    });

    target.appendChild(createVoteSummaryDisplay(metrics, type));
  }

  function syncMobileVenueVoteSummary(card) {
    const venueTarget = card.querySelector('[data-spotdeals-card-venue-votes]');
    const mobileTarget = card.querySelector('[data-spotdeals-card-mobile-venue-votes]');

    if (!venueTarget || !mobileTarget) {
      return;
    }

    const displaySummary = venueTarget.querySelector('.spotdeals-vote-summary--display');

    if (!displaySummary) {
      return;
    }

    mobileTarget.innerHTML = '';
    mobileTarget.appendChild(displaySummary.cloneNode(true));
  }

  function normalizeCardVoteSummaries(context) {
    once('spotdeals-card-vote-summary-display-v22', '.spotdeals-deal-card', context).forEach(function (card) {
      normalizeVoteSummaryTarget(card.querySelector('[data-spotdeals-card-venue-votes]'), 'venue');
      normalizeVoteSummaryTarget(card.querySelector('[data-spotdeals-card-deal-votes]'), 'deal');
      syncMobileVenueVoteSummary(card);
    });
  }

  function applyVoteStateClasses(context) {
    const voteGroups = context.querySelectorAll('.spotdeals-vote__group-count');

    voteGroups.forEach(function (group) {
      const text = normalizeWhitespace(group.textContent || '');
      const lowerText = text.toLowerCase();

      group.classList.remove('is-positive', 'is-negative');

      // "No votes" is a neutral empty state. It must not inherit the negative
      // styling used for real thumbs-down vote percentages.
      if (!text || lowerText.indexOf('no votes') !== -1) {
        return;
      }

      // Prefer the explicit icon rendered by the vote builders. This avoids
      // confusing the "No" button label with a negative result summary.
      if (text.indexOf('👎') !== -1) {
        group.classList.add('is-negative');
        return;
      }

      if (text.indexOf('👍') !== -1) {
        group.classList.add('is-positive');
      }
    });
  }

  function getBackToTopButton() {
    let button = document.querySelector('.spotdeals-back-to-top');

    if (button) {
      return button;
    }

    button = document.createElement('button');
    button.type = 'button';
    button.className = 'spotdeals-back-to-top';
    button.setAttribute('aria-label', 'Back to top');
    button.setAttribute('title', 'Back to top');
    button.innerHTML = '<span class="spotdeals-back-to-top__icon" aria-hidden="true">↑</span>';
    document.body.appendChild(button);

    button.addEventListener('click', function () {
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    });

    return button;
  }

  function hasStickyRecommendationActions() {
    return Boolean(document.querySelector('.view-id-deals_search_solr.view-display-id-page_1.spotdeals-recommendation-bottom-actions-active .spotdeals-recommendation-bottom-actions'));
  }

  function syncBackToTopState(button) {
    const shouldShow = window.scrollY > 420;

    button.classList.toggle('is-visible', shouldShow);
    document.body.classList.toggle('spotdeals-has-sticky-recommendation-actions', hasStickyRecommendationActions());
  }

  function attachBackToTopButton(context) {
    once('spotdeals-back-to-top-v1', 'body', context).forEach(function () {
      const button = getBackToTopButton();
      let ticking = false;

      const requestSync = function () {
        if (ticking) {
          return;
        }

        ticking = true;
        window.requestAnimationFrame(function () {
          syncBackToTopState(button);
          ticking = false;
        });
      };

      syncBackToTopState(button);
      window.addEventListener('scroll', requestSync, { passive: true });
      window.addEventListener('resize', requestSync);
    });
  }

  function submitHomepageRecommendation(searchValue) {
    const form = document.querySelector('.spotdeals-finder__filters form');

    if (!form) {
      return;
    }

    const searchInput = form.querySelector('input[name="search_deals"], input[name="search_api_fulltext"]');
    const helpMeChooseCheckbox = form.querySelector('input[name="help_me_choose"]');
    const recommendationAction = form.querySelector('input[name="recommendation_action"]');
    const scrollResults = form.querySelector('input[name="scroll_results"]');
    const submitButton = form.querySelector('input[type="submit"]');

    if (!searchInput || !submitButton) {
      return;
    }

    searchInput.value = searchValue || '';

    if (helpMeChooseCheckbox) {
      helpMeChooseCheckbox.checked = true;
      helpMeChooseCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (recommendationAction) {
      recommendationAction.value = '';
    }

    if (scrollResults) {
      scrollResults.value = '1';
    }

    submitButton.click();
  }

  function clearHomepageRecommendationActiveStates() {
    document.querySelectorAll('.spotdeals-home-feed [data-search].is-active').forEach(function (item) {
      item.classList.remove('is-active');
      item.removeAttribute('aria-pressed');
    });
  }

  function getHomepageCurrentSearchValue() {
    const params = new URLSearchParams(window.location.search);
    const urlSearchValue = params.get('search_deals') || params.get('search_api_fulltext') || params.get('search_clean');

    if (urlSearchValue !== null) {
      return urlSearchValue.trim();
    }

    const form = document.querySelector('.spotdeals-finder__filters form');
    const searchInput = form ? form.querySelector('input[name="search_deals"], input[name="search_api_fulltext"]') : null;

    return searchInput ? searchInput.value.trim() : '';
  }

  function syncHomepageRecommendationActiveState() {
    const currentSearchValue = getHomepageCurrentSearchValue();
    const form = document.querySelector('.spotdeals-finder__filters form');
    const helpMeChoose = form ? form.querySelector('input[name="help_me_choose"]') : null;

    clearHomepageRecommendationActiveStates();

    if (!helpMeChoose || !helpMeChoose.checked) {
      return;
    }

    const triggers = document.querySelectorAll('.spotdeals-home-feed [data-search]');
    let activeItem = null;

    triggers.forEach(function (trigger) {
      if (activeItem) {
        return;
      }

      if ((trigger.getAttribute('data-search') || '').trim() === currentSearchValue) {
        activeItem = trigger;
      }
    });

    if (activeItem) {
      activeItem.classList.add('is-active');
      activeItem.setAttribute('aria-pressed', 'true');
    }
  }

  function attachHomepageRecommendationActions(context) {
    once('spotdeals-home-recommendation-actions', '.spotdeals-home-feed [data-search]', context).forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        clearHomepageRecommendationActiveStates();
        trigger.classList.add('is-active');
        trigger.setAttribute('aria-pressed', 'true');

        submitHomepageRecommendation(trigger.getAttribute('data-search') || '');
      });
    });

    syncHomepageRecommendationActiveState();
  }

  function attachHomepageFeedMobileAccordion(context) {
    once('spotdeals-home-feed-mobile-accordion', '.spotdeals-home-feed__mobile-toggle', context).forEach(function (toggle) {
      const label = toggle.querySelector('span:first-child');

      if (label) {
        label.textContent = translateFrontendLabel('View nearby picks');
      }

      toggle.addEventListener('click', function () {
        const feed = toggle.closest('.spotdeals-home-feed');

        if (!feed) {
          return;
        }

        const isOpen = feed.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    });
  }



  function getSeoLandingFilterSearchInput(form) {
    return form.querySelector('input[name="search_deals_by_city"]');
  }

  function ensureSeoLandingHiddenInput(form, name) {
    let input = form.querySelector('input[name="' + name + '"]');

    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }

    return input;
  }

  function getActiveSeoLandingLocalPick() {
    return document.querySelector('.spotdeals-seo-local-pick.is-active');
  }

  function hasActiveSeoLandingLocalPick() {
    return Boolean(getActiveSeoLandingLocalPick());
  }

  function syncSeoLandingLocalPickFilterVisibility() {
    document.querySelectorAll('.spotdeals-finder__filters.is-covered-by-local-pick').forEach(function (filterWrapper) {
      filterWrapper.classList.remove('is-covered-by-local-pick');
    });

    const localPick = getActiveSeoLandingLocalPick();

    if (!localPick) {
      document.body.classList.remove('spotdeals-has-active-local-pick');
      return;
    }

    const resultsColumn = localPick.closest('.spotdeals-seo-landing__results-column') || document;
    const filterWrapper = resultsColumn.querySelector('.spotdeals-finder__filters');

    if (filterWrapper) {
      filterWrapper.classList.add('is-covered-by-local-pick');
    }

    document.body.classList.add('spotdeals-has-active-local-pick');
  }

  function getSeoLandingResultsTarget(form) {
    const filterWrapper = form.closest('.spotdeals-finder__filters');
    const localPick = getActiveSeoLandingLocalPick();

    // Local recommendation requests render a sticky "Your local pick" control.
    // In that state, the regular filter form is hidden and the pick control
    // becomes the scroll anchor. Regular filter searches keep the results-view
    // anchor so the normal search experience is unchanged.
    if (localPick && filterWrapper) {
      return localPick;
    }

    if (filterWrapper && filterWrapper.nextElementSibling && filterWrapper.nextElementSibling.classList.contains('spotdeals-seo-results-view')) {
      return filterWrapper.nextElementSibling;
    }

    return document.querySelector('.spotdeals-seo-results-view');
  }

  function clearSeoLandingScrollUrlFlag() {
    try {
      const url = new URL(window.location.href);

      if (!url.searchParams.has('scroll_results')) {
        return;
      }

      url.searchParams.delete('scroll_results');
      window.history.replaceState({}, '', url.toString());
    }
    catch (e) {
      // Ignore history/url failures.
    }
  }

  function clearSeoLandingScrollFlag(form) {
    const scrollInput = ensureSeoLandingHiddenInput(form, 'scroll_results');
    scrollInput.value = '';
    clearSeoLandingScrollUrlFlag();
  }

  function scrollSeoLandingResultsIntoView(form) {
    const target = getSeoLandingResultsTarget(form);

    if (!target) {
      clearSeoLandingScrollFlag(form);
      return;
    }

    const offset = 16;
    const rect = target.getBoundingClientRect();
    const top = window.pageYOffset + rect.top - offset;

    window.setTimeout(function () {
      window.scrollTo({
        top: Math.max(0, top),
        behavior: 'smooth'
      });
      clearSeoLandingScrollFlag(form);
    }, 60);
  }
  function getSeoLandingStandaloneResultsTarget() {
    const localPick = getActiveSeoLandingLocalPick();

    if (localPick) {
      return localPick;
    }

    return document.querySelector('.spotdeals-seo-results-view[data-recommendation-active="1"]') || document.querySelector('.spotdeals-seo-results-view');
  }

  function scrollSeoLandingStandaloneResultsIntoView() {
    const target = getSeoLandingStandaloneResultsTarget();

    if (!target) {
      clearSeoLandingScrollUrlFlag();
      return;
    }

    const offset = 16;
    const rect = target.getBoundingClientRect();
    const top = window.pageYOffset + rect.top - offset;

    window.setTimeout(function () {
      window.scrollTo({
        top: Math.max(0, top),
        behavior: 'smooth'
      });
      clearSeoLandingScrollUrlFlag();
    }, 60);
  }

  function attachSeoLandingScrollFallback(context) {
    once('spotdeals-seo-landing-scroll-fallback', 'body', context).forEach(function () {
      if (!shouldScrollSeoLandingResultsOnLoad()) {
        return;
      }

      window.setTimeout(function () {
        if (!shouldScrollSeoLandingResultsOnLoad()) {
          return;
        }

        if (document.querySelector('form.spotdeals-seo-filter-form')) {
          return;
        }

        scrollSeoLandingStandaloneResultsIntoView();
      }, 120);
    });
  }


  function shouldScrollSeoLandingResultsOnLoad() {
    try {
      const url = new URL(window.location.href);
      return url.searchParams.get('scroll_results') === '1';
    }
    catch (e) {
      return false;
    }
  }

  function attachSeoLandingFilterEnhancements(context) {
    once('spotdeals-seo-landing-filter-enhancements', 'form.views-exposed-form', context).forEach(function (form) {
      const searchInput = getSeoLandingFilterSearchInput(form);

      if (!searchInput) {
        return;
      }

      const scrollInput = ensureSeoLandingHiddenInput(form, 'scroll_results');
      let lastClickedSubmitter = null;

      form.classList.add('spotdeals-seo-filter-form');
      document.body.classList.add('spotdeals-has-seo-filter-actions');

      form.addEventListener('click', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
          return;
        }

        const button = target.closest('input[type="submit"], button[type="submit"]');

        if (button) {
          lastClickedSubmitter = button;
        }
      }, true);

      form.addEventListener('submit', function (event) {
        const submitter = event.submitter || lastClickedSubmitter;
        const label = submitter ? ((submitter.getAttribute('value') || submitter.textContent || '').trim()) : '';
        const isReset = /reset/i.test(label) || (submitter && /reset/i.test(submitter.getAttribute('name') || ''));

        scrollInput.value = isReset ? '' : '1';
      });

      if (shouldScrollSeoLandingResultsOnLoad()) {
        scrollSeoLandingResultsIntoView(form);
      }
    });
  }

  function getRegularSearchFilterWrapper() {
    const wrappers = document.querySelectorAll('.spotdeals-finder__filters');

    for (let i = 0; i < wrappers.length; i += 1) {
      const wrapper = wrappers[i];
      const form = wrapper.querySelector('form.views-exposed-form, form');

      if (!form) {
        continue;
      }

      if (wrapper.querySelector('.spotdeals-recommendation-bottom-actions')) {
        continue;
      }

      if (form.querySelector('input[name="search_deals"], input[name="search_api_fulltext"], input[name="search_deals_by_city"]')) {
        return wrapper;
      }
    }

    return null;
  }

  function syncMobileStickySearchFormState() {
    const wrapper = getRegularSearchFilterWrapper();
    const hasRecommendationActions = hasStickyRecommendationActions();

    document.body.classList.toggle('spotdeals-has-mobile-search-form', Boolean(wrapper) && !hasRecommendationActions);

    document.querySelectorAll('.spotdeals-mobile-sticky-search-form').forEach(function (item) {
      if (item !== wrapper) {
        item.classList.remove('spotdeals-mobile-sticky-search-form');
      }
    });

    if (wrapper && !hasRecommendationActions) {
      wrapper.classList.add('spotdeals-mobile-sticky-search-form');
    }
    else if (wrapper) {
      wrapper.classList.remove('spotdeals-mobile-sticky-search-form');
    }
  }

  function attachMobileStickySearchForm(context) {
    once('spotdeals-mobile-sticky-search-form-v1', 'body', context).forEach(function () {
      let ticking = false;

      const requestSync = function () {
        if (ticking) {
          return;
        }

        ticking = true;
        window.requestAnimationFrame(function () {
          syncMobileStickySearchFormState();
          ticking = false;
        });
      };

      syncMobileStickySearchFormState();
      window.addEventListener('resize', requestSync);
      window.addEventListener('orientationchange', requestSync);
    });

    syncMobileStickySearchFormState();
  }

  Drupal.behaviors.spotdealsThemeFixes = {
    attach: function (context) {
      once('spotdeals-theme-fixes', '.spotdeals-finder__results', context).forEach(function (resultsWrapper) {
        ensureRecommendationSummary(resultsWrapper);
      });

      moveMobileDiscoveryBlocks();
      moveSeoLandingMobileAccordions();
      syncSeoLandingLocalPickFilterVisibility();
      attachMobileDiscoveryResizeHandler(context);
      attachBackToTopButton(context);
      attachHomepageRecommendationActions(context);
      attachHomepageFeedMobileAccordion(context);
      attachSeoLandingFilterEnhancements(context);
      attachSeoLandingScrollFallback(context);
      attachMobileStickySearchForm(context);
      moveVotesIntoDealCards(context);
      moveInternalVenueVotesIntoTarget(context);
      applyVoteStateClasses(context);
      // Keep the real Drupal vote widgets interactive in /deals result cards.
      // v31 previously converted them into display-only summaries here, which
      // hid the actual buttons and prevented in-place voting.
      // normalizeCardVoteSummaries(context);
    }
  };
})(Drupal, once);
