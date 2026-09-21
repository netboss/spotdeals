(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.spotdealsAdSense = {
    attach: function (context) {
      once('spotdeals-adsense', 'ins.adsbygoogle', context).forEach(function (ad) {
        var container = ad.closest('.spotdeals-promoted-slot');

        var updateVisibility = function () {
          if (!container) {
            return;
          }

          container.classList.toggle(
            'spotdeals-promoted-slot--filled',
            ad.getAttribute('data-ad-status') === 'filled'
          );
        };

        updateVisibility();

        var observer = new MutationObserver(function () {
          updateVisibility();

          if (ad.hasAttribute('data-ad-status')) {
            observer.disconnect();
          }
        });

        observer.observe(ad, {
          attributes: true,
          attributeFilter: ['data-ad-status']
        });

        try {
          (window.adsbygoogle = window.adsbygoogle || []).push({});
        }
        catch (error) {
          observer.disconnect();
          updateVisibility();
        }
      });
    }
  };
})(Drupal, once);
