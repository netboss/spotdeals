(function (Drupal, once) {
  'use strict';

  const SPOTDEALS_ES_TRANSLATIONS = {
    'Find deal': 'Buscar oferta',
    'Find deals': 'Buscar ofertas',
    'Try again': 'Probar otra vez',
    'Reset': 'Restablecer',
    'Recommendation actions': 'Acciones de recomendación',
    'Finding another nearby pick…': 'Buscando otra opción cercana…',
    'No nearby pick could be found right now. Please try again.': 'No se pudo encontrar una opción cercana ahora mismo. Inténtalo otra vez.',
    'Sit back — we’ll pick a great deal nearby.': 'Relájate — encontraremos una gran oferta cerca de ti.'
  };

  function isSpanishPage() {
    const htmlLang = document.documentElement.getAttribute('lang') || '';
    return /^es(?:-|$)/i.test(htmlLang);
  }

  function applyTextArgs(text, args) {
    if (!args) {
      return text;
    }

    return Object.keys(args).reduce(function (value, key) {
      return value.replace(key, args[key]);
    }, text);
  }

  function t(text, args) {
    if (Drupal && typeof Drupal.t === 'function') {
      const translated = Drupal.t(text, args || {});

      if (translated !== text) {
        return translated;
      }
    }

    if (isSpanishPage() && SPOTDEALS_ES_TRANSLATIONS[text]) {
      return applyTextArgs(SPOTDEALS_ES_TRANSLATIONS[text], args);
    }

    return applyTextArgs(text, args);
  }

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

  const RETRY_LOADING_MIN_MS = 650;
  const SCROLL_STORAGE_KEY = 'spotdealsScrollToResults';
  const SCROLL_QUERY_PARAM = 'scroll_results';
  const BOTTOM_CONTROLS_CLASS = 'spotdeals-recommendation-bottom-actions';

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

  function extractExplicitLocation(value) {
    const rawValue = value || '';
    const zipMatch = rawValue.match(/\b(\d{5})(?:-\d{4})?\b/);

    if (zipMatch) {
      return {
        type: 'zip',
        value: zipMatch[1],
        raw: zipMatch[0]
      };
    }

    return null;
  }

  function hasExplicitLocation(value) {
    return !!extractExplicitLocation(value);
  }

  function removeExplicitLocationFromSearch(value, explicitLocation) {
    if (!explicitLocation || !explicitLocation.raw) {
      return cleanNearMe(value || '');
    }

    return cleanNearMe((value || '').replace(explicitLocation.raw, ' '));
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
    setHiddenValue(form, SCROLL_QUERY_PARAM, '');
    form.dataset.recommendationActive = '0';

    delete form.dataset.spotdealsNearMeResolved;
    delete form.dataset.spotdealsNearMePending;
    delete form.dataset.spotdealsRetryLoadingStartedAt;
  }

  function clearNearMeOnlyState(form) {
    setHiddenValue(form, 'search_clean', '');
    setHiddenValue(form, 'search_origin_mode', '');
    setHiddenValue(form, 'origin_lat', '');
    setHiddenValue(form, 'origin_lon', '');
    setHiddenValue(form, 'postal_code_exact', '');
    setHiddenValue(form, 'locality_exact', '');

    delete form.dataset.spotdealsNearMeResolved;
    delete form.dataset.spotdealsNearMePending;
    delete form.dataset.spotdealsRetryLoadingStartedAt;
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

  function getResultsWrapper(form) {
    const view = getRecommendationView(form);
    if (!view) {
      return null;
    }

    return view.querySelector('.spotdeals-finder__results');
  }

  function getScrollTarget(form) {
    return getResultsWrapper(form);
  }

  function setScrollToResultsPending(form) {
    try {
      sessionStorage.setItem(SCROLL_STORAGE_KEY, '1');
    }
    catch (e) {
      // Ignore storage failures.
    }

    if (form) {
      setHiddenValue(form, SCROLL_QUERY_PARAM, '1');
    }
  }

  function clearScrollFlagFromUrl() {
    try {
      const url = new URL(window.location.href);
      if (!url.searchParams.has(SCROLL_QUERY_PARAM)) {
        return;
      }

      url.searchParams.delete(SCROLL_QUERY_PARAM);
      window.history.replaceState({}, '', url.toString());
    }
    catch (e) {
      // Ignore history/url failures.
    }
  }

  function clearScrollToResultsPending(form) {
    try {
      sessionStorage.removeItem(SCROLL_STORAGE_KEY);
    }
    catch (e) {
      // Ignore storage failures.
    }

    if (form) {
      setHiddenValue(form, SCROLL_QUERY_PARAM, '');
    }

    clearScrollFlagFromUrl();
  }

  function shouldScrollToResultsOnLoad() {
    let storagePending = false;

    try {
      storagePending = sessionStorage.getItem(SCROLL_STORAGE_KEY) === '1';
    }
    catch (e) {
      storagePending = false;
    }

    try {
      const url = new URL(window.location.href);
      return storagePending || url.searchParams.get(SCROLL_QUERY_PARAM) === '1';
    }
    catch (e) {
      return storagePending;
    }
  }

  function scrollToResults(form) {
    const target = getScrollTarget(form);
    if (!target) {
      clearScrollToResultsPending(form);
      return;
    }

    const offset = 16;
    const rect = target.getBoundingClientRect();
    const top = window.pageYOffset + rect.top - offset;

    window.setTimeout(function () {
      window.scrollTo({
        top: Math.max(0, top),
        behavior: 'smooth'
      });
      clearScrollToResultsPending(form);
    }, 60);
  }

  function maybeScrollToResultsOnLoad(form, attempt) {
    const currentAttempt = typeof attempt === 'number' ? attempt : 0;

    if (!shouldScrollToResultsOnLoad()) {
      return;
    }

    const target = getScrollTarget(form);
    if (target) {
      scrollToResults(form);
      return;
    }

    if (currentAttempt >= 20) {
      clearScrollToResultsPending(form);
      return;
    }

    window.setTimeout(function () {
      maybeScrollToResultsOnLoad(form, currentAttempt + 1);
    }, 75);
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
        helper.textContent = t('Sit back — we’ll pick a great deal nearby.');
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
    let label = t('Find deals');

    if (recommendationMode) {
      if (isRecommendationActive(form)) {
        label = t('Try again');
      }
      else {
        label = t('Find deal');
      }
    }

    if (submit.tagName.toLowerCase() === 'input') {
      submit.value = label;
    }
    else {
      submit.textContent = label;
    }
  }


  function removeRecommendationBottomControls(form) {
    const view = getRecommendationView(form);
    if (!view) {
      return;
    }

    view.classList.remove('spotdeals-recommendation-bottom-actions-active');

    view.querySelectorAll('.' + BOTTOM_CONTROLS_CLASS).forEach(function (controls) {
      controls.remove();
    });
  }

  function buildRecommendationBottomButton(label, modifier) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = BOTTOM_CONTROLS_CLASS + '__button ' + BOTTOM_CONTROLS_CLASS + '__button--' + modifier;
    button.textContent = label;
    return button;
  }

  function submitRecommendationRetryFromBottom(form) {
    setHiddenValue(form, 'recommendation_action', 'retry');

    const submit = getPrimarySubmitButton(form);
    if (submit && typeof submit.click === 'function') {
      submit.click();
      return;
    }

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
      return;
    }

    form.submit();
  }

  function resetRecommendationFromBottom(form) {
    clearFreshSearchState(form);
    clearScrollToResultsPending(form);
    form.dataset.spotdealsLastSubmittedKeywords = '';
    stripActionQuery(form);

    const searchInput = getSearchInput(form);
    syncSearchInputUi(form, searchInput);
    updatePrimarySubmitLabel(form);
    removeRecommendationBottomControls(form);

    const reset = form.querySelector('input[type="reset"], button[type="reset"], a[href].button--secondary, a[href].form-submit');
    if (reset && typeof reset.click === 'function') {
      reset.click();
      return;
    }

    window.location.href = window.location.pathname;
  }

  function syncRecommendationBottomControls(form) {
    const view = getRecommendationView(form);
    const resultsWrapper = getResultsWrapper(form);

    if (!view || !resultsWrapper || !isRecommendationActive(form)) {
      removeRecommendationBottomControls(form);
      return;
    }

    let controls = resultsWrapper.querySelector('.' + BOTTOM_CONTROLS_CLASS);
    if (!controls) {
      controls = document.createElement('div');
      controls.className = BOTTOM_CONTROLS_CLASS;
      controls.setAttribute('aria-label', t('Recommendation actions'));

      const retryButton = buildRecommendationBottomButton(t('Try again'), 'primary');
      retryButton.addEventListener('click', function () {
        submitRecommendationRetryFromBottom(form);
      });

      const resetButton = buildRecommendationBottomButton(t('Reset'), 'secondary');
      resetButton.addEventListener('click', function () {
        resetRecommendationFromBottom(form);
      });

      controls.appendChild(retryButton);
      controls.appendChild(resetButton);
    }

    const content = resultsWrapper.querySelector('.view-content, .view-empty');
    if (content && content.nextSibling !== controls) {
      content.insertAdjacentElement('afterend', controls);
    }
    else if (!content && controls.parentNode !== resultsWrapper) {
      resultsWrapper.appendChild(controls);
    }

    view.classList.add('spotdeals-recommendation-bottom-actions-active');
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

  function buildRetryAjaxUrl() {
    return '/spotdeals-search-smart-location/recommendation/ajax';
  }

  function shouldUseRetryAjax(form) {
    if (!helpMeChooseEnabled(form)) {
      return false;
    }

    return getHiddenValue(form, 'recommendation_action') === 'retry';
  }

  function showRetryLoadingState(form) {
    const resultsWrapper = getResultsWrapper(form);
    if (!resultsWrapper) {
      return;
    }

    form.dataset.spotdealsRetryLoadingStartedAt = String(Date.now());

    resultsWrapper.innerHTML = ''
      + '<div class="spotdeals-recommendation-note spotdeals-recommendation-note--loading" aria-live="polite">'
      + '  <div class="spotdeals-recommendation-note__icon" aria-hidden="true"></div>'
      + '  <div class="spotdeals-recommendation-note__content">'
      + '    <span class="spotdeals-recommendation-note__line"><strong>' + t('Finding another nearby pick…') + '</strong></span>'
      + '  </div>'
      + '</div>';
  }

  function buildRetryAjaxPayload(form, submitter) {
    const formData = new FormData(form);
    formData.set('recommendation_action', 'retry');

    if (submitter && submitter.name && !formData.has(submitter.name)) {
      formData.append(submitter.name, submitter.value || '');
    }

    return formData;
  }

  function getRetryLoadingDelay(form) {
    const startedAt = parseInt(form.dataset.spotdealsRetryLoadingStartedAt || '0', 10);
    if (!startedAt) {
      return 0;
    }

    const elapsed = Date.now() - startedAt;
    return Math.max(0, RETRY_LOADING_MIN_MS - elapsed);
  }

  function clearRetryLoadingState(form) {
    delete form.dataset.spotdealsRetryLoadingStartedAt;
  }

  function extractViewHtmlFromResponseText(responseText) {
    if (!responseText || typeof responseText !== 'string') {
      return '';
    }

    const trimmed = responseText.trim();
    if (!trimmed) {
      return '';
    }

    if (trimmed.charAt(0) === '<') {
      return trimmed;
    }

    try {
      const parsed = JSON.parse(trimmed);
      if (parsed && typeof parsed.view_html === 'string') {
        return parsed.view_html;
      }
    }
    catch (e) {
      // Not JSON.
    }

    return '';
  }

  function extractRecommendationActiveFromResponseText(responseText) {
    if (!responseText || typeof responseText !== 'string') {
      return true;
    }

    const trimmed = responseText.trim();
    if (!trimmed || trimmed.charAt(0) === '<') {
      return true;
    }

    try {
      const parsed = JSON.parse(trimmed);
      if (parsed && typeof parsed.recommendation_active !== 'undefined') {
        return !!parsed.recommendation_active;
      }
    }
    catch (e) {
      // Ignore and default true.
    }

    return true;
  }

  function applyRetryAjaxHtml(form, viewHtml, recommendationActive) {
    const resultsWrapper = getResultsWrapper(form);
    if (!resultsWrapper) {
      return;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(viewHtml || '', 'text/html');

    const responseResultsWrapper = doc.querySelector('.spotdeals-finder__results');
    if (responseResultsWrapper) {
      resultsWrapper.innerHTML = responseResultsWrapper.innerHTML;
    }
    else {
      const responseView = doc.querySelector('.view-id-deals_search_solr');
      const fragments = [];

      if (responseView) {
        const selectors = [
          '.view-header',
          '.attachment-before',
          '.view-content',
          '.view-empty',
          '.pager',
          '.attachment-after',
          '.more-link',
          '.view-footer',
          '.feed-icons'
        ];

        selectors.forEach(function (selector) {
          responseView.querySelectorAll(selector).forEach(function (element) {
            fragments.push(element.outerHTML);
          });
        });
      }

      if (fragments.length > 0) {
        resultsWrapper.innerHTML = fragments.join('');
      }
      else if (viewHtml && viewHtml.trim().charAt(0) === '<') {
        resultsWrapper.innerHTML = viewHtml;
      }
      else {
        resultsWrapper.innerHTML = ''
          + '<div class="view-empty" data-result-count="0">'
          + '  <p>' + t('No nearby pick could be found right now. Please try again.') + '</p>'
          + '</div>';
      }
    }

    const view = getRecommendationView(form);
    const activeValue = recommendationActive ? '1' : '0';

    form.dataset.recommendationActive = activeValue;

    if (view) {
      view.dataset.recommendationActive = activeValue;
    }

    if (typeof Drupal.attachBehaviors === 'function') {
      Drupal.attachBehaviors(resultsWrapper);
    }

    updatePrimarySubmitLabel(form);
    syncRecommendationBottomControls(form);
    clearRetryLoadingState(form);
    scrollToResults(form);
  }

  function submitPreparedRetryAjax(form, submitter) {
    const payload = buildRetryAjaxPayload(form, submitter);
    const resultsWrapper = getResultsWrapper(form);

    if (!resultsWrapper) {
      submitPreparedForm(form, submitter);
      return;
    }

    fetch(buildRetryAjaxUrl(), {
      method: 'POST',
      body: payload,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json, text/html'
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Retry recommendation request failed.');
        }

        return response.text();
      })
      .then(function (responseText) {
        const viewHtml = extractViewHtmlFromResponseText(responseText);
        const recommendationActive = extractRecommendationActiveFromResponseText(responseText);

        if (!viewHtml) {
          throw new Error('Retry recommendation payload was invalid.');
        }

        const applyResponse = function () {
          form.dataset.spotdealsNearMeResolved = '1';
          delete form.dataset.spotdealsNearMePending;
          setHiddenValue(form, 'recommendation_action', 'retry');

          applyRetryAjaxHtml(form, viewHtml, recommendationActive);
        };

        const delay = getRetryLoadingDelay(form);
        if (delay > 0) {
          window.setTimeout(applyResponse, delay);
        }
        else {
          applyResponse();
        }
      })
      .catch(function () {
        clearRetryLoadingState(form);
        submitPreparedForm(form, submitter);
      });
  }

  Drupal.behaviors.spotdealsNearMe = {
    attach(context) {
      once('spotdeals-near-me', 'form.views-exposed-form', context).forEach((form) => {
        const searchInput = getSearchInput(form);

        if (!searchInput) {
          return;
        }

        ensureHidden(form, 'recommendation_action');
        ensureHidden(form, SCROLL_QUERY_PARAM);
        ensureHidden(form, 'search_raw');
        ensureHidden(form, 'search_clean');
        ensureHidden(form, 'search_origin_mode');
        ensureHidden(form, 'origin_lat');
        ensureHidden(form, 'origin_lon');
        ensureHidden(form, 'postal_code_exact');
        ensureHidden(form, 'locality_exact');
        ensureLastSubmittedKeywords(form, searchInput);
        syncSearchInputUi(form, searchInput);
        updatePrimarySubmitLabel(form);
        syncRecommendationBottomControls(form);
        maybeScrollToResultsOnLoad(form);

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
          clearRetryLoadingState(form);
          updatePrimarySubmitLabel(form);
          syncRecommendationBottomControls(form);
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

            clearRetryLoadingState(form);
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
            clearScrollToResultsPending(form);
            form.dataset.spotdealsLastSubmittedKeywords = '';
            stripActionQuery(form);
            syncSearchInputUi(form, searchInput);
            updatePrimarySubmitLabel(form);
            syncRecommendationBottomControls(form);
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
            clearScrollToResultsPending(form);
            form.dataset.spotdealsLastSubmittedKeywords = '';
            syncSearchInputUi(form, currentSearchInput);
            updatePrimarySubmitLabel(form);
            syncRecommendationBottomControls(form);
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
            setScrollToResultsPending(form);
            setHiddenValue(form, 'recommendation_action', '');
            rememberSubmittedKeywords(form, currentSearchInput);
            updatePrimarySubmitLabel(form);
            syncRecommendationBottomControls(form);
            return;
          }

          if (!recommendationMode) {
            setHiddenValue(form, 'recommendation_action', '');
            form.dataset.recommendationActive = '0';
          }
          else if (isRecommendationActive(form)) {
            setHiddenValue(form, 'recommendation_action', 'retry');
          }

          const useRetryAjax = shouldUseRetryAjax(form);
          const explicitLocation = extractExplicitLocation(rawValue);
          const explicitLocationSearch = !!explicitLocation;

          if (explicitLocationSearch && !useRetryAjax) {
            delete form.dataset.spotdealsNearMeResolved;
            delete form.dataset.spotdealsNearMePending;
            delete form.dataset.spotdealsRetryLoadingStartedAt;

            setHiddenValue(form, 'search_raw', rawValue);
            setHiddenValue(form, 'search_clean', removeExplicitLocationFromSearch(rawValue, explicitLocation));
            setHiddenValue(form, 'search_origin_mode', explicitLocation.type);
            setHiddenValue(form, 'origin_lat', '');
            setHiddenValue(form, 'origin_lon', '');
            setHiddenValue(form, 'postal_code_exact', explicitLocation.type === 'zip' ? explicitLocation.value : '');
            setHiddenValue(form, 'locality_exact', explicitLocation.type === 'city' ? explicitLocation.value : '');
            setHiddenValue(form, 'recommendation_action', '');

            form.dataset.recommendationActive = '0';

            setScrollToResultsPending(form);
            rememberSubmittedKeywords(form, currentSearchInput);
            updatePrimarySubmitLabel(form);
            return;
          }

          if (form.dataset.spotdealsNearMeResolved === '1' && !useRetryAjax) {
            delete form.dataset.spotdealsNearMeResolved;
            rememberSubmittedKeywords(form, currentSearchInput);
            return;
          }

          if (useRetryAjax) {
            delete form.dataset.spotdealsNearMeResolved;
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
          setHiddenValue(form, 'postal_code_exact', '');
          setHiddenValue(form, 'locality_exact', '');

          if (useRetryAjax) {
            showRetryLoadingState(form);
          }
          else {
            setScrollToResultsPending(form);
          }

          const finish = function () {
            rememberSubmittedKeywords(form, currentSearchInput);

            if (useRetryAjax) {
              submitPreparedRetryAjax(form, submitter);
              return;
            }

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
