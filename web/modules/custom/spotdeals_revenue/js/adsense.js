(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.spotdealsAdSense = {
    attach: function (context) {
      once('spotdeals-adsense', 'ins.adsbygoogle', context).forEach(function () {
        try {
          (window.adsbygoogle = window.adsbygoogle || []).push({});
        }
        catch (error) {
          // AdSense may be blocked by browser privacy tools or unavailable while
          // the site is still under review. Leave the slot empty in that case.
        }
      });
    }
  };
})(Drupal, once);
