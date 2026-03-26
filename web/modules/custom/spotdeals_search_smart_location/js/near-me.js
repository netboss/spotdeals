(function (Drupal, once) {
  'use strict';

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

  function clearNearMeState(form) {
    ensureHidden(form, 'search_raw').value = '';
    ensureHidden(form, 'search_clean').value = '';
    ensureHidden(form, 'search_origin_mode').value = '';
    ensureHidden(form, 'origin_lat').value = '';
    ensureHidden(form, 'origin_lon').value = '';
    delete form.dataset.spotdealsNearMeResolved;
    delete form.dataset.spotdealsNearMePending;
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
        form.addEventListener('click', function (event) {
          const target = event.target;
          if (!(target instanceof Element)) {
            return;
          }

          const button = target.closest('input[type="submit"], button[type="submit"]');
          if (!button) {
            return;
          }

          if (isResetButton(button)) {
            clearNearMeState(form);
            return;
          }

          if (form.dataset.spotdealsNearMeResolved === '1') {
            delete form.dataset.spotdealsNearMeResolved;
            return;
          }

          if (form.dataset.spotdealsNearMePending === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
          }

          const searchInput = getSearchInput(form);
          if (!searchInput) {
            return;
          }

          const originalValue = (searchInput.value || '').trim();
          if (!/\bnear\s+me\b/i.test(originalValue)) {
            ensureHidden(form, 'search_clean').value = '';
            return;
          }

          event.preventDefault();
          event.stopImmediatePropagation();

          form.dataset.spotdealsNearMePending = '1';

          ensureHidden(form, 'search_origin_mode').value = 'browser';
          ensureHidden(form, 'search_raw').value = originalValue;
          ensureHidden(form, 'search_clean').value = cleanNearMe(originalValue);
          ensureHidden(form, 'origin_lat').value = '';
          ensureHidden(form, 'origin_lon').value = '';

          const finish = function () {
            submitPreparedForm(form, button);
          };

          if (!navigator.geolocation) {
            finish();
            return;
          }

          navigator.geolocation.getCurrentPosition(
            function (position) {
              ensureHidden(form, 'origin_lat').value = String(position.coords.latitude);
              ensureHidden(form, 'origin_lon').value = String(position.coords.longitude);
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
