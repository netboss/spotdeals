(function (Drupal, drupalSettings, once) {
  'use strict';

  const analyticsSettings = drupalSettings.spotdealsAnalytics || {};

  /**
   * Return whether SpotDeals analytics is enabled for this visitor.
   */
  function analyticsEnabled() {
    return analyticsSettings.enabled === true;
  }

  /**
   * Get the current language code without exposing personal data.
   */
  function getLanguage() {
    const html = document.documentElement;
    return html ? (html.getAttribute('lang') || '').trim() : '';
  }

  /**
   * Get a normalized page type for event context.
   */
  function getPageType() {
    const body = document.body;

    if (!body) {
      return 'other';
    }

    if (body.classList.contains('node--type-deal')) {
      return 'deal';
    }

    if (body.classList.contains('node--type-venue')) {
      return 'venue';
    }

    if (document.querySelector('.spotdeals-finder')) {
      return 'search';
    }

    if (document.querySelector('.spotdeals-home-feed')) {
      return 'home';
    }

    if (window.location.pathname.indexOf('/suggest') !== -1) {
      return 'suggestion';
    }

    if (window.location.pathname.indexOf('/account/upgrade') === 0) {
      return 'upgrade';
    }

    return 'other';
  }

  /**
   * Get common, non-identifying event context.
   */
  function getCommonContext() {
    return {
      user_type: analyticsSettings.userType || 'anonymous',
      page_type: getPageType(),
      language: getLanguage(),
      page_location: window.location.href,
      page_path: window.location.pathname + window.location.search
    };
  }

  /**
   * Send a GA event safely.
   */
  function sendEvent(eventName, data) {
    if (!analyticsEnabled() || typeof window.gtag !== 'function') {
      return;
    }

    const payload = Object.assign({}, getCommonContext(), data || {});

    // Make local Firefox testing readable without affecting production.
    if (/\.ddev\.site$/i.test(window.location.hostname)) {
      console.info('[SpotDeals Analytics]', eventName, payload);
    }

    window.gtag('event', eventName, payload);
  }

  /**
   * Prevent duplicate page-level tracking in the same tab.
   */
  function alreadyTracked(key) {
    try {
      const storageKey = 'spotdeals_analytics.' + key;

      if (window.sessionStorage.getItem(storageKey)) {
        return true;
      }

      window.sessionStorage.setItem(storageKey, '1');
      return false;
    }
    catch (e) {
      return false;
    }
  }

  /**
   * Get search payload from the URL.
   */
  function getSearchPayload() {
    const params = new URLSearchParams(window.location.search);
    const searchDeals = (params.get('search_deals') || '').trim();
    const searchClean = (params.get('search_clean') || '').trim();
    const searchRaw = (params.get('search_raw') || '').trim();
    const searchOriginMode = (params.get('search_origin_mode') || '').trim();
    const postalCodeExact = (params.get('postal_code_exact') || '').trim();
    const localityExact = (params.get('locality_exact') || '').trim();
    const page = (params.get('page') || '').trim();
    const recommendationAction = (params.get('recommendation_action') || '').trim();
    const recommendationCuisines = (params.get('recommendation_cuisines') || '').trim();
    const searchTerm = searchClean || searchDeals;

    if (!searchTerm) {
      return null;
    }

    return {
      search_term: searchTerm,
      search_raw: searchRaw || searchDeals,
      search_origin_mode: searchOriginMode,
      postal_code_exact: postalCodeExact,
      locality_exact: localityExact,
      page_number: page,
      recommendation_action: recommendationAction,
      recommendation_cuisines: recommendationCuisines
    };
  }

  /**
   * Get the active search term from the current URL.
   */
  function getCurrentSearchTerm() {
    const payload = getSearchPayload();
    return payload ? payload.search_term : '';
  }

  /**
   * Get the nearest result/card wrapper.
   */
  function getResultContainer(element) {
    if (!element || !element.closest) {
      return null;
    }

    return element.closest('.views-row, .spotdeals-deal-card, .spotdeals-hybrid-venue-card, .spotdeals-home-feed__card');
  }

  /**
   * Read visible text from the first matching selector.
   */
  function getText(container, selectors) {
    if (!container) {
      return '';
    }

    for (let i = 0; i < selectors.length; i++) {
      const element = container.querySelector(selectors[i]);
      if (element && element.textContent && element.textContent.trim()) {
        return element.textContent.trim();
      }
    }

    return '';
  }

  /**
   * Extract venue title from a result/card.
   */
  function getVenueTitle(container) {
    return getText(container, [
      '[data-spotdeals-venue-title]',
      '.venue-title',
      '.spotdeals-deal-card__venue-title',
      '.spotdeals-hybrid-venue-card__title'
    ]);
  }

  /**
   * Extract deal title from a result/card.
   */
  function getDealTitle(container) {
    return getText(container, [
      '.deal-title a',
      '.deal-title',
      '.spotdeals-deal-card__deal-title'
    ]);
  }

  /**
   * Get the current page title as fallback entity context.
   */
  function getCurrentPageTitle() {
    const selectors = ['h1.page-title', '.page-title', 'h1'];

    for (let i = 0; i < selectors.length; i++) {
      const element = document.querySelector(selectors[i]);
      if (element && element.textContent && element.textContent.trim()) {
        return element.textContent.trim();
      }
    }

    return '';
  }

  /**
   * Get venue/deal context for an interaction.
   */
  function getEntityContext(element) {
    const container = getResultContainer(element);
    let venueTitle = getVenueTitle(container);
    let dealTitle = getDealTitle(container);
    const pageType = getPageType();
    const pageTitle = getCurrentPageTitle();

    if (!container && pageTitle) {
      if (pageType === 'venue') {
        venueTitle = pageTitle;
      }
      else if (pageType === 'deal') {
        dealTitle = pageTitle;
      }
    }

    return {
      venue_name: venueTitle,
      deal_title: dealTitle
    };
  }

  /**
   * Get the visible label for a clicked control.
   */
  function getActionLabel(element) {
    if (!element) {
      return '';
    }

    return (element.textContent || element.value || element.getAttribute('aria-label') || '').trim();
  }

  /**
   * Infer the placement/section containing an interaction.
   */
  function getSection(element) {
    if (!element || !element.closest) {
      return 'other';
    }

    if (element.closest('.spotdeals-hybrid-venues')) {
      return 'nearby_venues';
    }
    if (element.closest('.spotdeals-trending-near-you, .block-spotdeals-search-smart-location-trending-near-you')) {
      return 'trending_near_you';
    }
    if (element.closest('.spotdeals-popular-searches, .block-spotdeals-search-insights')) {
      return 'popular_searches';
    }
    if (element.closest('.spotdeals-home-feed')) {
      return 'home_discovery';
    }
    if (element.closest('.spotdeals-deal-detail__primary-cta')) {
      return 'deal_primary_actions';
    }
    if (element.closest('.spotdeals-share-this')) {
      return 'share_this';
    }
    if (element.closest('.spotdeals-deal-feedback, .spotdeals-vote')) {
      return 'voting';
    }
    if (element.closest('.spotdeals-finder__suggestion-cta--intro')) {
      return 'search_intro';
    }
    if (element.closest('.spotdeals-finder__suggestion-cta--after-results')) {
      return 'after_results';
    }
    if (element.closest('.spotdeals-finder__suggestion-cta--empty')) {
      return 'zero_results';
    }
    if (element.closest('.spotdeals-finder__cards')) {
      return 'search_results';
    }
    if (element.closest('.spotdeals-finder__filters')) {
      return 'search_filters';
    }

    return 'other';
  }

  /**
   * Extract a numeric entity ID from a URL query parameter.
   */
  function getQueryId(href, parameter) {
    if (!href) {
      return '';
    }

    try {
      const url = new URL(href, window.location.origin);
      return (url.searchParams.get(parameter) || '').trim();
    }
    catch (e) {
      return '';
    }
  }

  /**
   * Check whether the current page is the claim form.
   */
  function isClaimFormPage() {
    return window.location.pathname === '/create/claim';
  }

  /**
   * Check whether the current page is the login page for claim flow.
   */
  function isClaimLoginPage() {
    if (window.location.pathname !== '/user/login') {
      return false;
    }

    const params = new URLSearchParams(window.location.search);
    return (params.get('destination') || '').indexOf('/create/claim') !== -1;
  }

  /**
   * Infer CTA type from label text.
   */
  function getCtaType(label) {
    const normalized = (label || '').trim().toLowerCase();

    if (normalized.indexOf('reserv') !== -1) {
      return 'reservation';
    }
    if (normalized.indexOf('book') !== -1) {
      return 'booking';
    }
    if (normalized.indexOf('delivery') !== -1) {
      return 'delivery';
    }
    if (normalized.indexOf('order') !== -1) {
      return 'order';
    }
    if (normalized.indexOf('ticket') !== -1) {
      return 'ticket';
    }
    if (normalized.indexOf('waitlist') !== -1) {
      return 'waitlist';
    }
    if (normalized.indexOf('direction') !== -1 || normalized.indexOf('maps') !== -1) {
      return 'directions';
    }
    if (normalized.indexOf('venue') !== -1) {
      return 'view_venue';
    }
    if (normalized.indexOf('claim') !== -1) {
      return 'claim';
    }

    return 'other';
  }

  /**
   * Track all delegated click interactions, including AJAX-created content.
   */
  function trackClick(event) {
    const control = event.target.closest('a, button, summary, input[type="submit"], input[type="reset"]');

    if (!control) {
      return;
    }

    const label = getActionLabel(control);
    const entityContext = getEntityContext(control);
    const section = getSection(control);
    const searchTerm = getCurrentSearchTerm();
    const href = control.href || '';
    const baseData = {
      action_label: label,
      section: section,
      venue_name: entityContext.venue_name,
      deal_title: entityContext.deal_title,
      search_term: searchTerm,
      target_url: href
    };

    if (control.matches('.deal-title a, .spotdeals-deal-card__deal-title a')) {
      sendEvent('deal_click', baseData);
      return;
    }

    if (control.matches('.venue-title a, .spotdeals-deal-card__venue-title a, .spotdeals-deal-detail__venue a, .spotdeals-deal-sidebar-venue a') ||
      (control.matches('.spotdeals-deal-card__claim-cta a') && /^view venue$/i.test(label))) {
      sendEvent('venue_click', baseData);
      return;
    }

    if (control.matches('.spotdeals-directions-link, .field--name-field-directions, .spotdeals-hybrid-venue-card__address-link, .spotdeals-hybrid-venue-card__action--maps') ||
      /(?:google\.[^/]+\/maps|maps\.google\.)/i.test(href)) {
      sendEvent('directions_click', Object.assign({}, baseData, {
        venue_name: entityContext.venue_name || getVenueTitle(getResultContainer(control)),
        destination_type: 'google_maps'
      }));
      return;
    }

    if (control.matches('.spotdeals-hybrid-venue-card__action--primary')) {
      const actionType = control.textContent.toLowerCase().indexOf('website') !== -1 ? 'visit_website' : 'view_venue';
      sendEvent('nearby_venue_click', Object.assign({}, baseData, {nearby_action: actionType}));
      return;
    }

    if (control.matches('.spotdeals-hybrid-venue-card__action--secondary')) {
      sendEvent('suggestion_click', Object.assign({}, baseData, {suggestion_type: 'deal_for_nearby_venue'}));
      return;
    }

    if (control.matches('.spotdeals-hybrid-venue-card__action--report')) {
      sendEvent('venue_report_click', baseData);
      return;
    }

    if (control.matches('.spotdeals-finder__suggestion-cta-button, .spotdeals-suggest-cta__link') || control.closest('.spotdeals-deal-card__suggest-cta')) {
      sendEvent('suggestion_click', Object.assign({}, baseData, {suggestion_type: 'venue_or_deal'}));
      return;
    }

    if (control.matches('.spotdeals-share-this__summary')) {
      sendEvent('share_expand', baseData);
      return;
    }

    if (control.matches('.spotdeals-share-this__link')) {
      sendEvent('share_click', Object.assign({}, baseData, {
        share_method: label.toLowerCase().replace(/\s+/g, '_')
      }));
      return;
    }

    if (control.matches('[data-vote-field], .spotdeals-vote__button')) {
      sendEvent('vote_click', Object.assign({}, baseData, {
        vote_field: control.getAttribute('data-vote-field') || '',
        vote_value: control.getAttribute('data-vote-value') || '',
        vote_scope: (control.closest('[data-vote-scope]') || {}).dataset ? control.closest('[data-vote-scope]').dataset.voteScope || '' : ''
      }));
      return;
    }

    if (control.matches('.spotdeals-recommendation-bottom-actions__button--primary')) {
      sendEvent('recommendation_click', Object.assign({}, baseData, {recommendation_action: 'retry'}));
      return;
    }

    if (control.matches('.spotdeals-recommendation-bottom-actions__button--secondary')) {
      sendEvent('recommendation_click', Object.assign({}, baseData, {recommendation_action: 'reset'}));
      return;
    }

    if (control.matches('.spotdeals-help-me-choose-trigger')) {
      sendEvent('recommendation_click', Object.assign({}, baseData, {recommendation_action: 'start'}));
      return;
    }

    if (control.matches('.spotdeals-home-feed [data-search]')) {
      sendEvent('home_discovery_click', Object.assign({}, baseData, {
        discovery_term: control.getAttribute('data-search') || '',
        recommendation_cuisines: control.getAttribute('data-recommendation-cuisines') || ''
      }));
      return;
    }

    if (control.matches('.spotdeals-popular-search-link')) {
      sendEvent('popular_search_click', Object.assign({}, baseData, {
        popular_search_term: label
      }));
      return;
    }

    if (control.matches('.spotdeals-trending-near-you-link')) {
      sendEvent('trending_deal_click', baseData);
      return;
    }

    if (control.matches('a[href*="/create/claim"]')) {
      sendEvent('claim_listing_click', Object.assign({}, baseData, {
        venue_id: getQueryId(href, 'venue'),
        claim_url: href
      }));
      return;
    }

    if (control.closest('.field--name-field-menu-url')) {
      sendEvent('menu_click', Object.assign({}, baseData, {menu_label: label}));
      return;
    }

    if (control.closest('.field--name-field-cta') || control.closest('.spotdeals-deal-detail__primary-cta')) {
      const ctaType = getCtaType(label);
      sendEvent(ctaType === 'directions' ? 'directions_click' : 'cta_click', Object.assign({}, baseData, {
        cta_label: label,
        cta_type: ctaType
      }));
      return;
    }


    if (control.matches('.pager a')) {
      sendEvent('pager_click', Object.assign({}, baseData, {
        pager_label: label
      }));
      return;
    }

    if (control.matches('.spotdeals-finder__filters input[type="submit"], .spotdeals-finder__filters button[type="submit"]')) {
      sendEvent('search_filter_submit', baseData);
      return;
    }

    if (control.matches('.region--header a, header a, .site-footer a, footer a')) {
      sendEvent('navigation_click', Object.assign({}, baseData, {
        navigation_area: control.closest('footer, .site-footer') ? 'footer' : 'header'
      }));
      return;
    }

    if (control.matches('input[type="reset"], button[type="reset"]') || /reset/i.test(label)) {
      if (control.closest('.spotdeals-finder__filters')) {
        sendEvent('search_reset_click', baseData);
      }
    }
  }

  Drupal.behaviors.spotdealsAnalytics = {
    attach(context) {
      if (!analyticsEnabled()) {
        return;
      }

      once('spotdeals-analytics-global-clicks', 'html', context).forEach(() => {
        document.addEventListener('click', trackClick);
      });

      once('spotdeals-analytics-search', 'html', context).forEach(() => {
        const payload = getSearchPayload();

        if (!payload || alreadyTracked('search.' + window.location.pathname + window.location.search)) {
          return;
        }

        sendEvent('search', payload);
      });

      once('spotdeals-analytics-zero-results', 'html', context).forEach(() => {
        const payload = getSearchPayload();

        if (!payload) {
          return;
        }

        const resultCountElement = document.querySelector('[data-result-count]');
        const resultCount = resultCountElement ? parseInt(resultCountElement.getAttribute('data-result-count') || '0', 10) : null;
        const hasResults = resultCount === null
          ? document.querySelectorAll('.deal-title a, .spotdeals-deal-card__deal-title a').length > 0
          : resultCount > 0;

        if (hasResults || alreadyTracked('zero_results.' + window.location.pathname + window.location.search)) {
          return;
        }

        sendEvent('zero_results', payload);
      });

      once('spotdeals-analytics-claim-form-view', 'html', context).forEach(() => {
        if (!isClaimFormPage()) {
          return;
        }

        const key = 'claim_form_view.' + window.location.pathname + window.location.search;
        if (!alreadyTracked(key)) {
          sendEvent('claim_form_view', {
            venue_id: getQueryId(window.location.href, 'venue')
          });
        }
      });

      once('spotdeals-analytics-login-required-claim', 'html', context).forEach(() => {
        if (!isClaimLoginPage()) {
          return;
        }

        const key = 'login_required_for_claim.' + window.location.pathname + window.location.search;
        if (!alreadyTracked(key)) {
          const params = new URLSearchParams(window.location.search);
          sendEvent('login_required_for_claim', {
            destination: (params.get('destination') || '').trim()
          });
        }
      });

      once('spotdeals-analytics-upgrade-page-view', 'html', context).forEach(() => {
        if (window.location.pathname !== '/account/upgrade') {
          return;
        }

        const key = 'upgrade_click.' + window.location.pathname + window.location.search;
        if (!alreadyTracked(key)) {
          sendEvent('upgrade_click', {upgrade_path: window.location.pathname});
        }
      });

      once('spotdeals-analytics-upgrade-click', '.upgrade-pro-link', context).forEach((link) => {
        link.addEventListener('click', function () {
          sendEvent('upgrade_click', {
            upgrade_path: window.location.pathname,
            target_url: link.href
          });
        });
      });

      once('spotdeals-analytics-upgrade-success', 'html', context).forEach(() => {
        if (window.location.pathname !== '/account/upgrade/success') {
          return;
        }

        const key = 'upgrade_success.' + window.location.pathname + window.location.search;
        if (!alreadyTracked(key)) {
          sendEvent('upgrade_success', {success_path: window.location.pathname});
        }
      });

      once('spotdeals-analytics-upgrade-monthly-plan-click', '.spotdeals-billing-upgrade-page__monthly--link', context).forEach((link) => {
        link.addEventListener('click', function () {
          sendEvent('upgrade_monthly_plan_click', {
            plan_period: 'monthly',
            upgrade_path: window.location.pathname,
            target_url: link.href
          });
        });
      });

      once('spotdeals-analytics-upgrade-yearly-plan-click', '.spotdeals-billing-upgrade-page__yearly--link', context).forEach((link) => {
        link.addEventListener('click', function () {
          sendEvent('upgrade_yearly_plan_click', {
            plan_period: 'yearly',
            upgrade_path: window.location.pathname,
            target_url: link.href
          });
        });
      });

      once('spotdeals-analytics-claim-submit', '.node-claim-form', context).forEach((form) => {
        form.addEventListener('submit', function () {
          sendEvent('claim_submit', {
            venue_id: getQueryId(window.location.href, 'venue')
          });
        });
      });

      once('spotdeals-analytics-claim-submit-success', 'html', context).forEach(() => {
        const pagePath = window.location.pathname;
        const isDealsLandingPage = pagePath === '/' || pagePath === '/deals';

        if (!isDealsLandingPage) {
          return;
        }

        const pageText = document.body.textContent || '';
        const claimSuccessMatch = pageText.match(/Claim\s+.+\s+has been created\./i);

        if (!claimSuccessMatch) {
          return;
        }

        const successMessage = claimSuccessMatch[0];
        const key = 'claim_submit_success.' + pagePath + '.' + successMessage;

        if (!alreadyTracked(key)) {
          sendEvent('claim_submit_success', {success_message: successMessage});
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
