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
   * Prevent duplicate search tracking.
   */
  function alreadyTracked(payload) {
    try {
      const storageKey = 'spotdeals_analytics.search.' + payload.page_path;

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

  Drupal.behaviors.spotdealsAnalytics = {
    attach(context) {

      /**
       * ========================
       * SEARCH EVENT (existing)
       * ========================
       */
      once('spotdeals-analytics-search', 'html', context).forEach(() => {
        const payload = getSearchPayload();

        if (!payload) {
          return;
        }

        if (alreadyTracked(payload)) {
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
       * DEAL CLICK EVENT (NEW)
       * ========================
       */
      once('spotdeals-analytics-deal-click', '.deal-title a', context).forEach((link) => {

        link.addEventListener('click', function () {

          const dealTitle = link.textContent.trim();

          // Try to find the venue title in the same result row.
          let venueTitle = '';
          const row = link.closest('.views-row');

          if (row) {
            const venueEl = row.querySelector('.venue-title');
            if (venueEl) {
              venueTitle = venueEl.textContent.trim();
            }
          }

          const params = new URLSearchParams(window.location.search);
          const searchTerm = params.get('search_clean') || params.get('search_deals') || '';

          sendEvent('deal_click', {
            deal_title: dealTitle,
            venue_name: venueTitle,
            search_term: searchTerm,
            page_location: window.location.href
          });

        });

      });

    }
  };

})(Drupal, once);
