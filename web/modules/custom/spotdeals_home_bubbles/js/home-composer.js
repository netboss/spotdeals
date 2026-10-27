/** The homepage composer is rendered directly inside the Recent Conversations block. */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.spotdealsHomeComposer = {
    attach(context) {
      once('spotdeals-home-composer', '[data-sd-home-composer]', context).forEach((composer) => {
        composer.hidden = false;
        // Lift the sidebar stacking contexts while the composer is open.
        // A high z-index on the fixed panel alone cannot escape an ancestor stacking context.
        const panel = composer.querySelector('.sd-home-conversation-create__panel');
        if (panel) {
          const raised = new Map();
          const syncPanelLayer = () => {
            const open = !panel.hidden;
            if (open && raised.size === 0) {
              let ancestor = composer.parentElement;
              while (ancestor && ancestor !== document.body) {
                raised.set(ancestor, {
                  position: ancestor.style.position,
                  zIndex: ancestor.style.zIndex
                });
                if (window.getComputedStyle(ancestor).position === 'static') {
                  ancestor.style.position = 'relative';
                }
                ancestor.style.zIndex = '2000000';
                ancestor = ancestor.parentElement;
              }
            }
            else if (!open && raised.size) {
              raised.forEach((original, ancestor) => {
                ancestor.style.position = original.position;
                ancestor.style.zIndex = original.zIndex;
              });
              raised.clear();
            }
          };
          new MutationObserver(syncPanelLayer).observe(panel, {
            attributes: true,
            attributeFilter: ['hidden']
          });
          syncPanelLayer();
        }
        const form = composer.querySelector('form.sd-profile-create-form');
        const title = form?.querySelector('input[name="title"]');
        title?.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' || event.isComposing || event.repeat) return;
          const publish = form.querySelector('input[type="submit"], button[type="submit"]');
          if (!publish || publish.disabled) return;
          event.preventDefault();
          publish.click();
        });
      });
      once('spotdeals-recent-toggle', '[data-sd-recent-toggle]', context).forEach((toggle) => {
        const block = toggle.closest('.sd-recent-conversations');
        const list = block?.querySelector('.sd-recent-conversations__list');
        if (!list) return;
        toggle.addEventListener('click', () => {
          const expanded = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!expanded));
          block.classList.toggle('sd-recent-conversations--expanded', !expanded);
        });
      });
    }
  };
})(Drupal, once);
