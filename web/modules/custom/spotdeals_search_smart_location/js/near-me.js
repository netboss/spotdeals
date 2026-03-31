(function (Drupal, once) {
  'use strict';

  const FRESH_SEARCH_PARAMS = [
    'search_raw',
    'search_clean',
    'search_origin_mode',
    'origin_lat',
    'origin_lon',
    'postal_code_exact',
    'locality_exact',
    'page'
  ];

  function ensureHidden(form, name) {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    return input;
  }

  function getSearchInput(form) {
    return form.querySelector('input[name="search_deals"], input[name="search_api_fulltext"]');
  }

  function normalizeSearchValue(value) {
    return (value || '')
      .toLowerCase()
      .replace(/\s+/g, ' ')
      .trim();
  }

  function cleanNearMe(value) {
    return value.replace(/\bnear\s+me\b/ig, ' ').replace(/\s+/g, ' ').trim();
  }

  function isResetButton(button) {
    if (!button) {
      return false;
    }

    const name = button.getAttribute('name') || '';
    const value = button.getAttribute('value') || '';
    const text = (button.textContent || '').trim();

    return /reset/i.test(name) || /reset/i.test(value) || /reset/i.test(text);
  }

  function stripActionQuery(form) {
    const action = form.getAttribute('action') || window.location.pathname;
    const cleanAction = action.split('?')[0];
    form.setAttribute('action', cleanAction);
  }

  function setHiddenValue(form, name, value) {
    ensureHidden(form, name).value = value;
  }

  function clearFreshSearchState(form) {
    FRESH_SEARCH_PARAMS.forEach(function (name) {
      setHiddenValue(form, name, '');
    });

    delete form.dataset.spotdealsNearMeResolved;
    delete form.dataset.spotdealsNearMePending;
  }

  function clearNearMeOnlyState(form) {
    setHiddenValue(form, 'search_clean', '');
    setHiddenValue(form, 'search_origin_mode', '');
    setHiddenValue(form, 'origin_lat', '');
    setHiddenValue(form, 'origin_lon', '');

    delete form.dataset.spotdealsNearMeResolved;
    delete form.dataset.spotdealsNearMePending;
  }

  function getInitialSubmittedValue(form, searchInput) {
    const url = new URL(window.location.href);

    const candidates = [
      url.searchParams.get('search_raw'),
      url.searchParams.get('search_deals'),
      url.searchParams.get('search_api_fulltext'),
      searchInput ? searchInput.defaultValue : '',
      searchInput ? searchInput.value : ''
    ];

    for (let i = 0; i < candidates.length; i++) {
      const value = (candidates[i] || '').trim();
      if (value !== '') {
        return value;
      }
    }

    return '';
  }

  function ensureLastSubmittedKeywords(form, searchInput) {
    if (typeof form.dataset.spotdealsLastSubmittedKeywords !== 'undefined') {
      return;
    }

    form.dataset.spotdealsLastSubmittedKeywords = normalizeSearchValue(
      getInitialSubmittedValue(form, searchInput)
    );
  }

  function keywordsChanged(form, searchInput) {
    ensureLastSubmittedKeywords(form, searchInput);

    const currentValue = normalizeSearchValue(searchInput ? searchInput.value : '');
    const lastSubmittedValue = form.dataset.spotdealsLastSubmittedKeywords || '';

    return currentValue !== lastSubmittedValue;
  }

  function rememberSubmittedKeywords(form, searchInput) {
    form.dataset.spotdealsLastSubmittedKeywords = normalizeSearchValue(
      searchInput ? searchInput.value : ''
    );
  }

  function submitPreparedForm(form, submitter) {
    form.dataset.spotdealsNearMeResolved = '1';
    delete form.dataset.spotdealsNearMePending;

    window.setTimeout(function () {
      if (submitter && typeof submitter.click === 'function') {
        submitter.click();
        return;
      }

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
      }

      const temp = document.createElement('input');
      temp.type = 'submit';
      temp.style.display = 'none';
      form.appendChild(temp);
      temp.click();
      form.removeChild(temp);
    }, 0);
  }

  Drupal.behaviors.spotdealsNearMe = {
    attach(context) {
      once('spotdeals-near-me', 'form.views-exposed-form', context).forEach((form) => {
        const searchInput = getSearchInput(form);

        if (!searchInput) {
          return;
        }

        ensureLastSubmittedKeywords(form, searchInput);

        let lastClickedSubmitter = null;

        searchInput.addEventListener('input', function () {
          delete form.dataset.spotdealsNearMeResolved;
          delete form.dataset.spotdealsNearMePending;
        });

        form.addEventListener('click', function (event) {
          const target = event.target;
          if (!(target instanceof Element)) {
            return;
          }

          const button = target.closest('input[type="submit"], button[type="submit"]');
          if (!button) {
            return;
          }

          lastClickedSubmitter = button;

          if (isResetButton(button)) {
            clearFreshSearchState(form);
            form.dataset.spotdealsLastSubmittedKeywords = '';
            stripActionQuery(form);
          }
        }, true);

        form.addEventListener('submit', function (event) {
          const submitter = event.submitter || lastClickedSubmitter;
          const currentSearchInput = getSearchInput(form);

          if (!currentSearchInput) {
            return;
          }

          stripActionQuery(form);

          if (isResetButton(submitter)) {
            clearFreshSearchState(form);
            form.dataset.spotdealsLastSubmittedKeywords = '';
            return;
          }

          const rawValue = (currentSearchInput.value || '').trim();
          const hasKeywordChange = keywordsChanged(form, currentSearchInput);

          if (hasKeywordChange) {
            clearFreshSearchState(form);
          }

          setHiddenValue(form, 'search_raw', rawValue);

          if (!/\bnear\s+me\b/i.test(rawValue)) {
            clearNearMeOnlyState(form);
            rememberSubmittedKeywords(form, currentSearchInput);
            return;
          }

          if (form.dataset.spotdealsNearMeResolved === '1') {
            delete form.dataset.spotdealsNearMeResolved;
            rememberSubmittedKeywords(form, currentSearchInput);
            return;
          }

          if (form.dataset.spotdealsNearMePending === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
          }

          event.preventDefault();
          event.stopImmediatePropagation();

          form.dataset.spotdealsNearMePending = '1';

          setHiddenValue(form, 'search_origin_mode', 'browser');
          setHiddenValue(form, 'search_raw', rawValue);
          setHiddenValue(form, 'search_clean', cleanNearMe(rawValue));
          setHiddenValue(form, 'origin_lat', '');
          setHiddenValue(form, 'origin_lon', '');

          const finish = function () {
            rememberSubmittedKeywords(form, currentSearchInput);
            submitPreparedForm(form, submitter);
          };

          if (!navigator.geolocation) {
            finish();
            return;
          }

          navigator.geolocation.getCurrentPosition(
            function (position) {
              setHiddenValue(form, 'origin_lat', String(position.coords.latitude));
              setHiddenValue(form, 'origin_lon', String(position.coords.longitude));
              finish();
            },
            function () {
              finish();
            },
            {
              enableHighAccuracy: true,
              timeout: 8000,
              maximumAge: 300000
            }
          );
        }, true);
      });
    }
  };
})(Drupal, once);
