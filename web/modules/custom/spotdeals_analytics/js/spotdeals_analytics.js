(function (Drupal, once) {
  'use strict';

  /**
   * Returns a normalized search payload from the current URL.
   *
   * @returns {Object|null}
   *   A payload object or null if this is not a searchable page state.
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

    // Only fire on meaningful searches.
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
   * Sends the GA4 search event if gtag is available.
   *
   * @param {Object} payload
   *   The event payload.
   */
  function sendSearchEvent(payload) {
    if (typeof window.gtag !== 'function') {
      return;
    }

    window.gtag('event', payload.event_name, {
      search_term: payload.search_term,
      search_raw: payload.search_raw,
      search_origin_mode: payload.search_origin_mode,
      postal_code_exact: payload.postal_code_exact,
      locality_exact: payload.locality_exact,
      page_number: payload.page,
      page_location: payload.page_location,
      page_path: payload.page_path
    });
  }

  /**
   * Prevents duplicate firing for the same URL in the same tab/session.
   *
   * @param {Object} payload
   *   The event payload.
   *
   * @returns {boolean}
   *   TRUE if the event has already been fired for this URL.
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
      // If sessionStorage is unavailable, fail open and allow tracking.
      return false;
    }
  }

  Drupal.behaviors.spotdealsAnalytics = {
    attach(context) {
      once('spotdeals-analytics-search', 'html', context).forEach(() => {
        const payload = getSearchPayload();

        if (!payload) {
          return;
        }

        if (alreadyTracked(payload)) {
          return;
        }

        sendSearchEvent(payload);
      });
    }
  };

})(Drupal, once);
