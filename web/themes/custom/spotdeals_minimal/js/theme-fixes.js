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


  Drupal.behaviors.spotdealsThemeFixes = {
    attach: function (context) {
      once('spotdeals-theme-fixes', '.spotdeals-finder__results', context).forEach(function (resultsWrapper) {
        ensureRecommendationSummary(resultsWrapper);
      });

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
