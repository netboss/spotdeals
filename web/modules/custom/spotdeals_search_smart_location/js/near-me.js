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

  function helpMeChooseEnabled(form) {
    const checkbox = form.querySelector('input[name="help_me_choose"]');
    return !!(checkbox && checkbox.checked);
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

  function getHiddenValue(form, name) {
    return ensureHidden(form, name).value || '';
  }

  function getRecommendationView(form) {
    if (form) {
      const closestView = form.closest('.view-id-deals_search_solr');
      if (closestView) {
        return closestView;
      }
    }

    return document.querySelector('.view-id-deals_search_solr.view-display-id-page_1')
      || document.querySelector('.view-id-deals_search_solr');
  }

  function getRecommendationRows(form) {
    const view = getRecommendationView(form);
    if (!view) {
      return [];
    }

    return Array.from(view.querySelectorAll('.views-row'));
  }

  function isRecommendationActive(form) {
    const formState = form.dataset.recommendationActive || '';
    const view = getRecommendationView(form);
    const viewState = view ? (view.dataset.recommendationActive || '') : '';

    if (formState === '1' || viewState === '1') {
      return true;
    }

    if (!helpMeChooseEnabled(form)) {
      return false;
    }

    return getRecommendationRows(form).length === 1;
  }

  function clearFreshSearchState(form) {
    FRESH_SEARCH_PARAMS.forEach(function (name) {
      setHiddenValue(form, name, '');
    });

    setHiddenValue(form, 'recommendation_action', '');
    form.dataset.recommendationActive = '0';

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

  function getPrimarySubmitButton(form) {
    const buttons = form.querySelectorAll('input[type="submit"], button[type="submit"]');
    for (let i = 0; i < buttons.length; i++) {
      if (!isResetButton(buttons[i])) {
        return buttons[i];
      }
    }
    return null;
  }

  function getRecommendationHelper(form, searchInput) {
    if (!searchInput || !searchInput.parentNode) {
      return null;
    }

    let helper = form.querySelector('.spotdeals-help-me-choose-helper');
    if (helper) {
      return helper;
    }

    helper = document.createElement('div');
    helper.className = 'spotdeals-help-me-choose-helper';
    helper.style.marginTop = '6px';
    helper.style.fontSize = '0.875rem';
    helper.style.lineHeight = '1.4';
    helper.style.color = '#6b7280';
    helper.style.display = 'none';
    searchInput.parentNode.appendChild(helper);

    return helper;
  }

  function syncSearchInputUi(form, searchInput) {
    if (!searchInput) {
      return;
    }

    const recommendationMode = helpMeChooseEnabled(form);
    const helper = getRecommendationHelper(form, searchInput);

    searchInput.readOnly = recommendationMode;
    searchInput.setAttribute('aria-disabled', recommendationMode ? 'true' : 'false');

    if (recommendationMode) {
      searchInput.classList.add('spotdeals-readonly-recommendation-mode');
      searchInput.style.backgroundColor = '#f3f4f6';
      searchInput.style.color = '#6b7280';
      searchInput.style.borderColor = '#d1d5db';
      searchInput.style.cursor = 'not-allowed';
      searchInput.style.opacity = '1';
      searchInput.style.caretColor = 'transparent';
      searchInput.setAttribute('tabindex', '-1');
      searchInput.blur();

      if (helper) {
        helper.textContent = 'Sit back — we’ll pick a great deal nearby.';
        helper.style.display = '';
      }
    }
    else {
      searchInput.classList.remove('spotdeals-readonly-recommendation-mode');
      searchInput.style.backgroundColor = '';
      searchInput.style.color = '';
      searchInput.style.borderColor = '';
      searchInput.style.cursor = '';
      searchInput.style.opacity = '';
      searchInput.style.caretColor = '';
      searchInput.removeAttribute('tabindex');

      if (helper) {
        helper.textContent = '';
        helper.style.display = 'none';
      }
    }
  }

  function updatePrimarySubmitLabel(form) {
    const submit = getPrimarySubmitButton(form);
    if (!submit) {
      return;
    }

    const recommendationMode = helpMeChooseEnabled(form);
    let label = 'Find deals';

    if (recommendationMode) {
      if (isRecommendationActive(form)) {
        label = 'Try again';
      }
      else {
        label = 'Find deal';
      }
    }

    if (submit.tagName.toLowerCase() === 'input') {
      submit.value = label;
    }
    else {
      submit.textContent = label;
    }
  }

  function suppressReadonlyRecommendationInteraction(event, form, searchInput) {
    if (!searchInput || event.target !== searchInput) {
      return;
    }

    if (!helpMeChooseEnabled(form)) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    searchInput.blur();
  }

  Drupal.behaviors.spotdealsNearMe = {
    attach(context) {
      once('spotdeals-near-me', 'form.views-exposed-form', context).forEach((form) => {
        const searchInput = getSearchInput(form);

        if (!searchInput) {
          return;
        }

        ensureHidden(form, 'recommendation_action');
        ensureLastSubmittedKeywords(form, searchInput);
        syncSearchInputUi(form, searchInput);
        updatePrimarySubmitLabel(form);

        let lastClickedSubmitter = null;

        searchInput.addEventListener('mousedown', function (event) {
          suppressReadonlyRecommendationInteraction(event, form, searchInput);
        }, true);

        searchInput.addEventListener('click', function (event) {
          suppressReadonlyRecommendationInteraction(event, form, searchInput);
        }, true);

        searchInput.addEventListener('focus', function (event) {
          suppressReadonlyRecommendationInteraction(event, form, searchInput);
        }, true);

        searchInput.addEventListener('input', function () {
          delete form.dataset.spotdealsNearMeResolved;
          delete form.dataset.spotdealsNearMePending;
          setHiddenValue(form, 'recommendation_action', '');
          form.dataset.recommendationActive = '0';
          updatePrimarySubmitLabel(form);
        });

        const helpMeChooseCheckbox = form.querySelector('input[name="help_me_choose"]');
        if (helpMeChooseCheckbox) {
          helpMeChooseCheckbox.addEventListener('change', function () {
            delete form.dataset.spotdealsNearMeResolved;
            delete form.dataset.spotdealsNearMePending;
            setHiddenValue(form, 'recommendation_action', '');

            if (!helpMeChooseCheckbox.checked) {
              form.dataset.recommendationActive = '0';
            }

            syncSearchInputUi(form, searchInput);
            updatePrimarySubmitLabel(form);
          });
        }

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
            syncSearchInputUi(form, searchInput);
            updatePrimarySubmitLabel(form);
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
            syncSearchInputUi(form, currentSearchInput);
            updatePrimarySubmitLabel(form);
            return;
          }

          const rawValue = (currentSearchInput.value || '').trim();
          const recommendationMode = helpMeChooseEnabled(form);
          const hasKeywordChange = keywordsChanged(form, currentSearchInput);

          if (hasKeywordChange) {
            clearFreshSearchState(form);
          }

          setHiddenValue(form, 'search_raw', rawValue);

          if (rawValue === '' && !recommendationMode) {
            clearNearMeOnlyState(form);
            setHiddenValue(form, 'recommendation_action', '');
            rememberSubmittedKeywords(form, currentSearchInput);
            updatePrimarySubmitLabel(form);
            return;
          }

          if (!recommendationMode) {
            setHiddenValue(form, 'recommendation_action', '');
            form.dataset.recommendationActive = '0';
          }
          else if (isRecommendationActive(form) && getHiddenValue(form, 'recommendation_action') === '') {
            setHiddenValue(form, 'recommendation_action', 'retry');
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
