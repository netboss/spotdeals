(function (Drupal, drupalSettings, once) {
  'use strict';

  function getSettings() {
    return drupalSettings.spotdealsSearchSmartLocationActivity || {};
  }

  function postActivity(payload) {
    const settings = getSettings();
    const endpoint = settings.endpoint || '/spotdeals-search-smart-location/activity/log';

    if (!payload || !payload.deal_nid || !payload.action) {
      return;
    }

    try {
      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload),
        keepalive: true
      }).catch(function () {});
    }
    catch (e) {}
  }

  function getDealContext(element) {
    const settings = getSettings();
    let dealNid = parseInt(settings.dealNid || 0, 10);
    let venueNid = parseInt(settings.venueNid || 0, 10);

    if (element && element.closest) {
      const wrapper = element.closest('[data-deal-nid], [data-spotdeals-vote]');
      if (wrapper) {
        dealNid = parseInt(wrapper.getAttribute('data-deal-nid') || wrapper.getAttribute('data-node-id') || dealNid || 0, 10);
        venueNid = parseInt(wrapper.getAttribute('data-venue-nid') || venueNid || 0, 10);
      }
    }

    if (Number.isNaN(dealNid)) {
      dealNid = 0;
    }
    if (Number.isNaN(venueNid)) {
      venueNid = 0;
    }

    return {
      deal_nid: dealNid,
      venue_nid: venueNid
    };
  }

  function classifyLink(link) {
    if (!link || !link.closest) {
      return 'link';
    }

    if (link.closest('.field--name-field-cta')) {
      return 'cta';
    }

    if (link.closest('.field--name-field-menu-url')) {
      return 'menu';
    }

    if (link.closest('.field--name-field-website')) {
      return 'website';
    }

    if (link.classList.contains('spotdeals-trending-near-you-link')) {
      return 'trending_near_you';
    }

    return 'link';
  }

  Drupal.behaviors.spotdealsSearchSmartLocationActivity = {
    attach: function (context) {
      once('spotdeals-deal-page-view', 'body', context).forEach(function () {
        const settings = getSettings();
        const dealNid = parseInt(settings.dealNid || 0, 10);
        if (!dealNid || Number.isNaN(dealNid)) {
          return;
        }

        postActivity({
          deal_nid: dealNid,
          venue_nid: parseInt(settings.venueNid || 0, 10) || 0,
          action: 'view',
          source: settings.source || 'deal_page'
        });
      });

      once('spotdeals-deal-click-activity', 'a', context).forEach(function (link) {
        link.addEventListener('click', function () {
          const contextData = getDealContext(link);
          if (!contextData.deal_nid) {
            return;
          }

          postActivity({
            deal_nid: contextData.deal_nid,
            venue_nid: contextData.venue_nid,
            action: 'click',
            source: classifyLink(link)
          });
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
