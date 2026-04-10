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

    }
  };

})(Drupal, once);
