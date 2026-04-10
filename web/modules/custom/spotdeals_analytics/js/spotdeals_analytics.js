(function (Drupal, once) {
  'use strict';

  /**
   * Get search payload from URL.
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

    const searchTerm = searchClean || searchDeals;

    if (!searchTerm) {
      return null;
    }

    return {
      event_name: 'search',
      search_term: searchTerm,
      search_raw: searchRaw || searchDeals,
      search_origin_mode: searchOriginMode,
      postal_code_exact: postalCodeExact,
      locality_exact: localityExact,
      page: page,
      page_location: window.location.href,
      page_path: window.location.pathname + window.location.search
    };
  }

  /**
   * Send GA event safely.
   */
  function sendEvent(eventName, data) {
    if (typeof window.gtag !== 'function') {
      return;
    }

    window.gtag('event', eventName, data);
  }

  /**
   * Prevent duplicate tracking for the same URL in the same tab.
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
   * Get the active search term from the current URL.
   */
  function getCurrentSearchTerm() {
    const params = new URLSearchParams(window.location.search);
    return (params.get('search_clean') || params.get('search_deals') || '').trim();
  }

  /**
   * Get the nearest views row for a clicked element.
   */
  function getResultRow(element) {
    return element.closest('.views-row');
  }

  /**
   * Extract venue title from a result row.
   */
  function getVenueTitleFromRow(row) {
    if (!row) {
      return '';
    }

    const venueEl = row.querySelector('.venue-title');
    return venueEl ? venueEl.textContent.trim() : '';
  }

  /**
   * Extract deal title from a result row.
   */
  function getDealTitleFromRow(row) {
    if (!row) {
      return '';
    }

    const dealLink = row.querySelector('.deal-title a');
    return dealLink ? dealLink.textContent.trim() : '';
  }

  /**
   * Extract venue id from a claim link href.
   */
  function getVenueIdFromClaimHref(href) {
    if (!href) {
      return '';
    }

    try {
      const url = new URL(href, window.location.origin);
      return (url.searchParams.get('venue') || '').trim();
    }
    catch (e) {
      return '';
    }
  }

  /**
   * Extract venue id from the current URL.
   */
  function getVenueIdFromCurrentUrl() {
    const params = new URLSearchParams(window.location.search);
    return (params.get('venue') || '').trim();
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
    const destination = params.get('destination') || '';

    return destination.indexOf('/create/claim') !== -1;
  }

  /**
   * Get claim destination from the login page.
   */
  function getClaimDestination() {
    const params = new URLSearchParams(window.location.search);
    return (params.get('destination') || '').trim();
  }

  /**
   * Check whether the current page is the upgrade page.
   */
  function isUpgradePage() {
    return window.location.pathname === '/account/upgrade';
  }

  /**
   * Check whether the current page is the upgrade success page.
   */
  function isUpgradeSuccessPage() {
    return window.location.pathname === '/account/upgrade/success';
  }

  Drupal.behaviors.spotdealsAnalytics = {
    attach(context) {

      /**
       * ========================
       * SEARCH EVENT
       * ========================
       */
      once('spotdeals-analytics-search', 'html', context).forEach(() => {
        const payload = getSearchPayload();

        if (!payload) {
          return;
        }

        if (alreadyTracked('search.' + payload.page_path)) {
          return;
        }

        sendEvent('search', {
          search_term: payload.search_term,
          search_raw: payload.search_raw,
          search_origin_mode: payload.search_origin_mode,
          postal_code_exact: payload.postal_code_exact,
          locality_exact: payload.locality_exact,
          page_number: payload.page,
          page_location: payload.page_location,
          page_path: payload.page_path
        });
      });

      /**
       * ========================
       * ZERO RESULTS EVENT
       * ========================
       */
      once('spotdeals-analytics-zero-results', 'html', context).forEach(() => {
        const payload = getSearchPayload();

        if (!payload) {
          return;
        }

        const dealLinks = document.querySelectorAll('.deal-title a');
        const hasResults = dealLinks.length > 0;

        if (hasResults) {
          return;
        }

        if (alreadyTracked('zero_results.' + payload.page_path)) {
          return;
        }

        sendEvent('zero_results', {
          search_term: payload.search_term,
          search_raw: payload.search_raw,
          search_origin_mode: payload.search_origin_mode,
          postal_code_exact: payload.postal_code_exact,
          locality_exact: payload.locality_exact,
          page_number: payload.page,
          page_location: payload.page_location,
          page_path: payload.page_path
        });
      });

      /**
       * ========================
       * DEAL CLICK EVENT
       * ========================
       */
      once('spotdeals-analytics-deal-click', '.deal-title a', context).forEach((link) => {
        link.addEventListener('click', function () {
          const row = getResultRow(link);
          const dealTitle = link.textContent.trim();
          const venueTitle = getVenueTitleFromRow(row);
          const searchTerm = getCurrentSearchTerm();

          sendEvent('deal_click', {
            deal_title: dealTitle,
            venue_name: venueTitle,
            search_term: searchTerm,
            page_location: window.location.href
          });
        });
      });

      /**
       * ========================
       * VENUE CLICK EVENT
       * ========================
       */
      once('spotdeals-analytics-venue-click', '.venue-title a', context).forEach((link) => {
        link.addEventListener('click', function () {
          const row = getResultRow(link);
          const venueTitle = link.textContent.trim();
          const dealTitle = getDealTitleFromRow(row);
          const searchTerm = getCurrentSearchTerm();

          sendEvent('venue_click', {
            venue_name: venueTitle,
            deal_title: dealTitle,
            search_term: searchTerm,
            page_location: window.location.href
          });
        });
      });

      /**
       * ========================
       * CLAIM LISTING CLICK EVENT
       * ========================
       */
      once('spotdeals-analytics-claim-click', 'a[href*="/create/claim"]', context).forEach((link) => {
        link.addEventListener('click', function () {
          const row = getResultRow(link);
          const venueTitle = getVenueTitleFromRow(row);
          const dealTitle = getDealTitleFromRow(row);
          const searchTerm = getCurrentSearchTerm();
          const venueId = getVenueIdFromClaimHref(link.href);

          sendEvent('claim_listing_click', {
            venue_name: venueTitle,
            venue_id: venueId,
            deal_title: dealTitle,
            search_term: searchTerm,
            page_location: window.location.href,
            claim_url: link.href
          });
        });
      });

      /**
       * ========================
       * CLAIM FORM VIEW EVENT
       * ========================
       */
      once('spotdeals-analytics-claim-form-view', 'html', context).forEach(() => {
        if (!isClaimFormPage()) {
          return;
        }

        const venueId = getVenueIdFromCurrentUrl();
        const trackKey = 'claim_form_view.' + window.location.pathname + window.location.search;

        if (alreadyTracked(trackKey)) {
          return;
        }

        sendEvent('claim_form_view', {
          venue_id: venueId,
          page_location: window.location.href,
          page_path: window.location.pathname + window.location.search
        });
      });

      /**
       * ========================
       * LOGIN REQUIRED FOR CLAIM EVENT
       * ========================
       */
      once('spotdeals-analytics-login-required-claim', 'html', context).forEach(() => {
        if (!isClaimLoginPage()) {
          return;
        }

        const destination = getClaimDestination();
        const trackKey = 'login_required_for_claim.' + window.location.pathname + window.location.search;

        if (alreadyTracked(trackKey)) {
          return;
        }

        sendEvent('login_required_for_claim', {
          destination: destination,
          page_location: window.location.href,
          page_path: window.location.pathname + window.location.search
        });
      });

      /**
       * ========================
       * UPGRADE PAGE VIEW EVENT
       * ========================
       */
      once('spotdeals-analytics-upgrade-page-view', 'html', context).forEach(() => {
        if (!isUpgradePage()) {
          return;
        }

        const trackKey = 'upgrade_click.' + window.location.pathname + window.location.search;

        if (alreadyTracked(trackKey)) {
          return;
        }

        sendEvent('upgrade_click', {
          upgrade_path: window.location.pathname,
          page_location: window.location.href,
          page_path: window.location.pathname + window.location.search
        });
      });

      /**
       * ========================
       * UPGRADE CLICK EVENT
       * ========================
       */
      once('spotdeals-analytics-upgrade-click', '.upgrade-pro-link', context).forEach((link) => {
        link.addEventListener('click', function () {
          sendEvent('upgrade_click', {
            upgrade_path: window.location.pathname,
            page_location: window.location.href,
            page_path: window.location.pathname + window.location.search,
            target_url: link.href
          });
        });
      });

      /**
       * ========================
       * UPGRADE SUCCESS EVENT
       * ========================
       */
      once('spotdeals-analytics-upgrade-success', 'html', context).forEach(() => {
        if (!isUpgradeSuccessPage()) {
          return;
        }

        const trackKey = 'upgrade_success.' + window.location.pathname + window.location.search;

        if (alreadyTracked(trackKey)) {
          return;
        }

        sendEvent('upgrade_success', {
          success_path: window.location.pathname,
          page_location: window.location.href,
          page_path: window.location.pathname + window.location.search
        });
      });

      /**
       * ========================
       * UPGRADE MONTHLY PLAN CLICK EVENT
       * ========================
       */
      once('spotdeals-analytics-upgrade-monthly-plan-click', '.spotdeals-billing-upgrade-page__monthly--link', context).forEach((link) => {
        link.addEventListener('click', function () {
          sendEvent('upgrade_monthly_plan_click', {
            plan_period: 'monthly',
            upgrade_path: window.location.pathname,
            page_location: window.location.href,
            page_path: window.location.pathname + window.location.search,
            target_url: link.href
          });
        });
      });

      /**
       * ========================
       * UPGRADE YEARLY PLAN CLICK EVENT
       * ========================
       */
      once('spotdeals-analytics-upgrade-yearly-plan-click', '.spotdeals-billing-upgrade-page__yearly--link', context).forEach((link) => {
        link.addEventListener('click', function () {
          sendEvent('upgrade_yearly_plan_click', {
            plan_period: 'yearly',
            upgrade_path: window.location.pathname,
            page_location: window.location.href,
            page_path: window.location.pathname + window.location.search,
            target_url: link.href
          });
        });
      });

      /**
       * ========================
       * CLAIM SUBMIT EVENT
       * ========================
       */
      once('spotdeals-analytics-claim-submit', '.node-claim-form', context).forEach((form) => {
        form.addEventListener('submit', function () {

          const params = new URLSearchParams(window.location.search);
          const venueId = params.get('venue') || '';

          sendEvent('claim_submit', {
            venue_id: venueId,
            page_location: window.location.href,
            page_path: window.location.pathname + window.location.search
          });

        });
      });

      /**
       * ========================
       * CLAIM SUBMIT SUCCESS EVENT
       * ========================
       */
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
        const trackKey = 'claim_submit_success.' + pagePath + '.' + successMessage;

        if (alreadyTracked(trackKey)) {
          return;
        }

        sendEvent('claim_submit_success', {
          success_message: successMessage,
          page_location: window.location.href,
          page_path: window.location.pathname + window.location.search
        });
      });

    }
  };

})(Drupal, once);
