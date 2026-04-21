(function (Drupal, once) {
  'use strict';

  function getWrapper(element) {
    return element.closest('[data-spotdeals-vote]');
  }

  function setMessage(wrapper, message, isError) {
    const messageEl = wrapper.querySelector('.spotdeals-vote__message');
    if (!messageEl) {
      return;
    }

    messageEl.textContent = message || '';
    messageEl.classList.toggle('is-error', !!isError);
  }

  function setPending(wrapper, isPending) {
    wrapper.classList.toggle('is-pending', !!isPending);
    wrapper.querySelectorAll('.spotdeals-vote__button').forEach(function (button) {
      button.disabled = !!isPending;
      button.setAttribute('aria-disabled', isPending ? 'true' : 'false');
    });
  }

  function updateButtons(wrapper, userVote) {
    wrapper.querySelectorAll('.spotdeals-vote__button').forEach(function (button) {
      const field = button.getAttribute('data-vote-field');
      const value = parseInt(button.getAttribute('data-vote-value') || '', 10);
      const selected = userVote && userVote[field] !== null && parseInt(userVote[field], 10) === value;

      button.classList.toggle('is-selected', !!selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  }

  function updateSummary(wrapper, aggregate) {
    const worthItVotes = parseInt(aggregate.worth_it_yes || 0, 10) + parseInt(aggregate.worth_it_no || 0, 10);
    const wouldGoAgainVotes = parseInt(aggregate.would_go_again_yes || 0, 10) + parseInt(aggregate.would_go_again_no || 0, 10);
    const totalVoters = parseInt(aggregate.total_voters || 0, 10);

    const worthItCount = wrapper.querySelector('[data-vote-group-count="worth_it"]');
    const wouldGoAgainCount = wrapper.querySelector('[data-vote-group-count="would_go_again"]');
    const count = wrapper.querySelector('[data-vote-summary="count"]');

    if (worthItCount) {
      worthItCount.textContent = `${worthItVotes} vote${worthItVotes === 1 ? '' : 's'}`;
    }

    if (wouldGoAgainCount) {
      wouldGoAgainCount.textContent = `${wouldGoAgainVotes} vote${wouldGoAgainVotes === 1 ? '' : 's'}`;
    }

    if (count) {
      count.textContent = `${totalVoters} vote${totalVoters === 1 ? '' : 's'}`;
    }
  }

  function syncWrappers(dealNid, payload) {
    document.querySelectorAll(`[data-spotdeals-vote][data-deal-nid="${dealNid}"]`).forEach(function (wrapper) {
      const userVote = payload.user_vote || {};

      wrapper.setAttribute(
        'data-current-worth-it',
        userVote.worth_it !== null && userVote.worth_it !== undefined ? String(userVote.worth_it) : ''
      );
      wrapper.setAttribute(
        'data-current-would-go-again',
        userVote.would_go_again !== null && userVote.would_go_again !== undefined ? String(userVote.would_go_again) : ''
      );

      updateButtons(wrapper, userVote);
      updateSummary(wrapper, payload.aggregate || {});
      setMessage(wrapper, 'Vote saved.', false);
      setPending(wrapper, false);
    });
  }

  function submitVote(button) {
    const wrapper = getWrapper(button);
    if (!wrapper) {
      return;
    }

    if (wrapper.getAttribute('data-authenticated') !== '1') {
      const loginUrl = wrapper.getAttribute('data-login-url');
      if (loginUrl) {
        window.location.href = loginUrl;
      }
      return;
    }

    const payload = {
      deal_nid: parseInt(button.getAttribute('data-deal-nid') || wrapper.getAttribute('data-deal-nid') || '0', 10),
      venue_nid: parseInt(button.getAttribute('data-venue-nid') || wrapper.getAttribute('data-venue-nid') || '0', 10),
      field: button.getAttribute('data-vote-field') || '',
      value: parseInt(button.getAttribute('data-vote-value') || '', 10),
      source: 'recommendation_card'
    };

    if (!payload.deal_nid || !payload.venue_nid || !payload.field || Number.isNaN(payload.value)) {
      setMessage(wrapper, 'Vote data is incomplete.', true);
      return;
    }

    setPending(wrapper, true);
    setMessage(wrapper, '', false);

    fetch('/spotdeals/vote', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.ok) {
          throw new Error(result.data && result.data.message ? result.data.message : 'Unable to save vote.');
        }

        syncWrappers(String(payload.deal_nid), result.data);
      })
      .catch(function (error) {
        setPending(wrapper, false);
        setMessage(wrapper, error.message || 'Unable to save vote.', true);
      });
  }

  Drupal.behaviors.spotdealsVote = {
    attach(context) {
      once('spotdeals-vote-wrapper', '[data-spotdeals-vote]', context).forEach(function (wrapper) {
        updateButtons(wrapper, {
          worth_it: wrapper.getAttribute('data-current-worth-it') !== ''
            ? parseInt(wrapper.getAttribute('data-current-worth-it'), 10)
            : null,
          would_go_again: wrapper.getAttribute('data-current-would-go-again') !== ''
            ? parseInt(wrapper.getAttribute('data-current-would-go-again'), 10)
            : null
        });
      });

      once('spotdeals-vote', '.spotdeals-vote__button', context).forEach(function (button) {
        button.addEventListener('click', function () {
          submitVote(button);
        });
      });
    }
  };
})(Drupal, once);
