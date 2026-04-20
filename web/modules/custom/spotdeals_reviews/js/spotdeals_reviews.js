(function (Drupal, once) {
  'use strict';

  function setMessage(widget, text, isError) {
    const message = widget.querySelector('[data-review-message]');
    if (!message) {
      return;
    }

    message.textContent = text || '';
    message.classList.toggle('is-error', !!isError);
    message.classList.toggle('is-success', !!text && !isError);
  }

  function updateStats(widget, stats) {
    const map = {
      '[data-review-total]': stats.total_reviews,
      '[data-worth-it-percent]': stats.worth_it_percent,
      '[data-worth-it-yes]': stats.worth_it_yes,
      '[data-worth-it-answered]': stats.worth_it_answered,
      '[data-would-go-again-percent]': stats.would_go_again_percent,
      '[data-would-go-again-yes]': stats.would_go_again_yes,
      '[data-would-go-again-answered]': stats.would_go_again_answered,
    };

    Object.keys(map).forEach(function (selector) {
      const el = widget.querySelector(selector);
      if (el && map[selector] !== undefined && map[selector] !== null) {
        el.textContent = String(map[selector]);
      }
    });
  }

  function updateActiveButtons(widget, userReview) {
    ['worth_it', 'would_go_again'].forEach(function (field) {
      const value = userReview && typeof userReview[field] === 'boolean' ? (userReview[field] ? '1' : '0') : '';
      widget.setAttribute('data-' + field.replace(/_/g, '-'), value);

      widget.querySelectorAll('[data-review-field="' + field + '"]').forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-review-value') === value);
      });
    });

    const wouldGoAgainQuestion = widget.querySelector('[data-would-go-again-question]');
    if (wouldGoAgainQuestion) {
      const hasWorthIt = userReview && typeof userReview.worth_it === 'boolean';
      wouldGoAgainQuestion.classList.toggle('is-hidden', !hasWorthIt);
    }
  }

  async function parseJsonResponse(response) {
    const raw = await response.text();

    if (!raw) {
      throw new Error('Empty response received from the server.');
    }

    try {
      return JSON.parse(raw);
    }
    catch (error) {
      throw new Error('Server returned a non-JSON response. Check watchdog/PHP errors for the review submit route.');
    }
  }

  async function submitReview(widget, button) {
    const submitUrl = widget.getAttribute('data-submit-url');
    const csrfToken = widget.getAttribute('data-csrf-token');
    const isAuthenticated = widget.getAttribute('data-is-authenticated') === '1';
    const loginUrl = widget.getAttribute('data-login-url');

    if (!isAuthenticated) {
      window.location.href = loginUrl;
      return;
    }

    const payload = {
      venue_id: parseInt(widget.getAttribute('data-venue-id') || '0', 10),
      deal_id: parseInt(widget.getAttribute('data-deal-id') || '0', 10),
      field: button.getAttribute('data-review-field'),
      value: button.getAttribute('data-review-value') === '1',
      csrf_token: csrfToken,
    };

    widget.classList.add('is-loading');
    setMessage(widget, 'Saving your feedback...', false);

    try {
      const response = await fetch(submitUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      });

      const data = await parseJsonResponse(response);

      if (!response.ok || data.status !== 'ok') {
        throw new Error(data.message || 'Unable to save your review right now.');
      }

      updateStats(widget, data.stats || {});
      updateActiveButtons(widget, data.user_review || {});
      setMessage(widget, 'Thanks — your feedback was saved.', false);
    }
    catch (error) {
      setMessage(widget, error.message || 'Unable to save your review right now.', true);
    }
    finally {
      widget.classList.remove('is-loading');
    }
  }

  Drupal.behaviors.spotdealsReviews = {
    attach: function (context) {
      once('spotdeals-reviews-widget', '[data-spotdeals-review-widget]', context).forEach(function (widget) {
        widget.querySelectorAll('[data-review-field]').forEach(function (button) {
          button.addEventListener('click', function () {
            submitReview(widget, button);
          });
        });
      });
    }
  };
})(Drupal, once);
