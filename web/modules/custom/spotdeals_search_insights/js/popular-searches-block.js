(function (Drupal, once) {
  'use strict';

  function setParam(url, name, value) {
    url.searchParams.set(name, value);
  }

  function buildNearMeUrl(link) {
    const href = link.getAttribute('href') || '/deals';
    const keyword = (link.dataset.keyword || '').trim();
    const url = new URL(href, window.location.origin);

    if (keyword === '') {
      return url;
    }

    setParam(url, 'search_deals', keyword);
    setParam(url, 'search_raw', keyword);
    setParam(url, 'search_clean', keyword);
    setParam(url, 'search_origin_mode', 'browser');
    url.searchParams.delete('page');

    return url;
  }

  function redirectTo(url) {
    window.location.href = url.toString();
  }

  Drupal.behaviors.spotdealsPopularSearchesNearMe = {
    attach(context) {
      once('spotdeals-popular-searches-near-me', '.spotdeals-popular-search-link', context).forEach((link) => {
        link.addEventListener('click', function (event) {
          event.preventDefault();

          const url = buildNearMeUrl(link);

          if (!navigator.geolocation) {
            redirectTo(url);
            return;
          }

          navigator.geolocation.getCurrentPosition(
            function (position) {
              setParam(url, 'origin_lat', String(position.coords.latitude));
              setParam(url, 'origin_lon', String(position.coords.longitude));
              redirectTo(url);
            },
            function () {
              redirectTo(url);
            },
            {
              enableHighAccuracy: true,
              timeout: 8000,
              maximumAge: 300000,
            }
          );
        });
      });
    },
  };
})(Drupal, once);
