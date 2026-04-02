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

    const summaryPattern = /Displaying\s+\d+\s*-\s*\d+\s+of\s+\d+/i;

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

    const summaryText = 'Displaying 1 - 1 of 1';
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

  Drupal.behaviors.spotdealsThemeFixes = {
    attach: function (context) {
      once('spotdeals-theme-fixes', '.spotdeals-finder__results', context).forEach(function (resultsWrapper) {
        ensureRecommendationSummary(resultsWrapper);
      });
    }
  };
})(Drupal, once);
