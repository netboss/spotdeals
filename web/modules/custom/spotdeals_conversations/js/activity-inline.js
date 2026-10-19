/** Compact conversation previews with independently expandable replies. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.spotdealsActivityInline = {
    attach(context) {
      once('spotdeals-inline-activity', '[data-sd-inline-conversation]', context).forEach((trigger) => {
        const panel = document.getElementById(trigger.getAttribute('aria-controls'));
        if (!panel) return;
        let pending = false;
        let expanded = false;
        panel.hidden = false;
        panel.classList.add('sd-inline-preview--collapsed');
        trigger.setAttribute('aria-expanded', 'false');
        const load = async () => {
          if (panel.dataset.loaded === 'true' || pending) return;
          pending = true;
          const url = new URL(trigger.dataset.inlineUrl, window.location.href);
          url.searchParams.set('target', panel.id);
          try {
            await Drupal.ajax({ url: url.toString(), progress: { type: 'throbber' } }).execute();
            panel.dataset.loaded = 'true';
          }
          catch (error) {
            panel.textContent = Drupal.t('Could not load conversation preview.');
          }
          finally { pending = false; }
        };
        const setExpanded = (open) => {
          expanded = open;
          panel.classList.toggle('sd-inline-preview--collapsed', !open);
          trigger.setAttribute('aria-expanded', String(open));
          const count = panel.querySelector('[data-sd-toggle-replies]');
          if (count) count.setAttribute('aria-expanded', String(open));
        };
        const toggle = async () => {
          await load();
          if (panel.dataset.loaded !== 'true') return;
          setExpanded(!expanded);
        };
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          toggle();
        });
        panel.addEventListener('click', (event) => {
          // Form fields, vote controls, links, and Load more keep their own behavior.
          if (event.target.closest('button:not([data-sd-toggle-replies]), input, textarea, select, a, form, [data-sd-inline-load-more], [data-sd-thread-more], [data-sd-reply-to], [data-sd-cancel-reply], [data-sd-reply-view]')) return;
          if (event.target.closest('[data-sd-toggle-replies]')) event.preventDefault();
          toggle();
        });
        panel.addEventListener('keydown', (event) => {
          if (event.target.matches('[data-sd-toggle-replies]') && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            toggle();
          }
        });
        // Load previews only as they approach the viewport, not all at once.
        if ('IntersectionObserver' in window) {
          const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
              observer.disconnect();
              load();
            }
          }, { rootMargin: '250px' });
          observer.observe(panel);
        }
        else { load(); }
      });
    }
  };
})(Drupal, once);

/** Progressive reply pagination, with no page navigation. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.spotdealsInlineReplyPagination = {
    attach(context) {
      once('sd-inline-load-more', '[data-sd-inline-load-more]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const panel = document.getElementById(button.dataset.target);
          if (!panel || button.disabled) return;
          button.disabled = true;
          Drupal.ajax({
            url: button.dataset.inlineUrl + '&target=' + encodeURIComponent(panel.id),
            element: button,
            progress: { type: 'throbber' },
          }).execute().catch(() => { button.disabled = false; });
        });
      });
    }
  };
})(Drupal, once);


/** One submission per click, with an explicit success response and refresh. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.spotdealsInlineReplySubmit = {
    attach(context) {
      once('sd-inline-reply-submit', '[data-sd-inline-reply-form]', context).forEach((form) => {
        // Enter posts; Shift+Enter inserts a newline. Ignore IME composition.
        form.querySelectorAll('textarea').forEach((textarea) => {
          textarea.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
            event.preventDefault();
            if (form.dataset.submitting === 'true' || !textarea.value.trim()) return;
            // Use the existing submit handler, including its duplicate-submit guard.
            if (typeof form.requestSubmit === 'function') {
              form.requestSubmit();
            }
            else {
              const submit = form.querySelector('[type="submit"]');
              if (submit) submit.click();
            }
          });
        });
        // Preserve the form's original location when replying to a comment.
        // Move the existing form, never clone it (Drupal form state and AJAX
        // handlers must remain attached to the same DOM element).
        const home = document.createComment('Inline reply form home');
        form.parentNode.insertBefore(home, form);
        const restoreForm = () => {
          if (home.parentNode) home.parentNode.insertBefore(form, home.nextSibling);
        };
        // Reply to any comment while keeping one shared, working form.
        const panel = document.getElementById(form.dataset.target);
        if (panel) {
          panel.addEventListener('click', (event) => {
            const reply = event.target.closest('[data-sd-reply-to]');
            const cancel = event.target.closest('[data-sd-cancel-reply]');
            if (!reply && !cancel) return;
            event.preventDefault();
            event.stopPropagation();
            const parent = form.querySelector('[name="parent_id"]');
            if (!parent) return;
            if (form.dataset.submitting === 'true') return;
            parent.value = reply ? reply.dataset.sdReplyTo : '0';
            // Every newly selected reply starts with a blank editor. Do not
            // carry the previous comment's text into another reply.
            form.querySelectorAll('textarea').forEach((textarea) => {
              textarea.value = '';
            });
            if (reply) {
              const comment = reply.closest('.sd-inline-conversation__reply[data-comment-id]');
              if (!comment) return;
              // Keep the parent visible, with the editor before child replies.
              const children = Array.from(comment.children).find((child) => child.classList?.contains('sd-inline-thread-children'));
              comment.insertBefore(form, children || null);
            }
            else {
              restoreForm();
            }
            let context = form.querySelector('.sd-inline-reply-context');
            if (!context) {
              context = document.createElement('div');
              context.className = 'sd-inline-reply-context';
              form.prepend(context);
            }
            context.replaceChildren();
            if (reply) {
              const label = document.createElement('span');
              label.textContent = Drupal.t('Replying to @name', { '@name': reply.dataset.replyAuthor || '' });
              const reset = document.createElement('button');
              reset.type = 'button'; reset.dataset.sdCancelReply = '';
              reset.textContent = Drupal.t('Cancel');
              context.append(label, ' ', reset);
              // The editor is now beside the selected comment: no long scroll.
              form.querySelector('textarea')?.focus({ preventScroll: true });
            }
          });
        }
        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          if (form.dataset.submitting === 'true') return;
          const target = form.dataset.target;
          const panel = document.getElementById(target);
          if (!panel || !/^sd-activity-inline-\d+$/.test(target)) return;
          const button = form.querySelector('[type="submit"]');
          let message = form.querySelector('.sd-inline-reply-message');
          if (!message) {
            message = document.createElement('p');
            message.className = 'sd-inline-reply-message';
            message.setAttribute('role', 'status');
            form.appendChild(message);
          }
          form.dataset.submitting = 'true';
          if (button) button.disabled = true;
          message.textContent = Drupal.t('Posting reply…');
          let saved = false;
          try {
            const result = await fetch(form.action, {
              method: 'POST',
              body: new FormData(form),
              credentials: 'same-origin',
              headers: { 'Accept': 'application/json' }
            });
            const data = await result.json();
            if (!result.ok || !data.saved) throw new Error(data.error || Drupal.t('Could not post reply.'));
            saved = true;
            // Clear immediately after the server confirms the comment was
            // saved, even if the subsequent AJAX refresh fails.
            form.querySelectorAll('textarea').forEach((textarea) => {
              textarea.value = '';
            });
            message.textContent = Drupal.t('Reply saved. Refreshing conversation…');
            const url = new URL(form.dataset.previewUrl, window.location.href);
            url.searchParams.set('target', target);
            // Refresh the newest page only; preserve Load more pagination.
            url.searchParams.set('visible', '10');
            await Drupal.ajax({ url: url.toString(), progress: { type: 'throbber' } }).execute();
            panel.dataset.loaded = 'true';
            // Reset parent targeting after a successful save. Nested replies
            // become visible by reopening their thread; no duplicate submission.
            const refreshed = panel.querySelector('[data-sd-inline-reply-form]');
            if (refreshed) {
              const parent = refreshed.querySelector('[name="parent_id"]');
              if (parent) parent.value = '0';
              const context = refreshed.querySelector('.sd-inline-reply-context');
              if (context) context.remove();
            }
          }
          catch (error) {
            message.textContent = saved
              ? Drupal.t('Reply saved. Reopen this conversation to see it.')
              : error.message;
          }
          finally {
            form.dataset.submitting = 'false';
            if (button) button.disabled = false;
          }
        });
      });
    }
  };
})(Drupal, once);

/** AJAX voting for the original conversation only. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.spotdealsConversationVoting = {
    attach(context) {
      once('sd-conversation-voting', '[data-sd-conversation-votes]', context).forEach((container) => {
        let busy = false;
        container.querySelectorAll('[data-vote-value]').forEach((button) => {
          button.addEventListener('click', async () => {
            if (busy) return;
            busy = true;
            const buttons = container.querySelectorAll('[data-vote-value]');
            buttons.forEach((item) => { item.disabled = true; });
            try {
              const tokenResponse = await fetch(Drupal.url('session/token'), { credentials: 'same-origin' });
              if (!tokenResponse.ok) throw new Error(Drupal.t('Unable to authorize vote.'));
              const token = await tokenResponse.text();
              const response = await fetch(container.dataset.voteUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({ vote: Number(button.dataset.voteValue) })
              });
              if (!response.ok) throw new Error(Drupal.t('Could not save vote.'));
              const state = await response.json();
              buttons.forEach((item) => {
                const up = item.dataset.voteValue === '1';
                item.textContent = (up ? '👍 ' + state.up : '👎 ' + state.down);
                item.setAttribute('aria-pressed', String(state.mine === (up ? 1 : -1)));
              });
            }
            catch (error) { window.alert(error.message); }
            finally { busy = false; buttons.forEach((item) => { item.disabled = false; }); }
          });
        });
      });
    }
  };
})(Drupal, once);

/** Paginate each reply's immediate children independently. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.spotdealsThreadPagination = {
    attach(context) {
      once('sd-thread-more', '[data-sd-thread-more]', context).forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (button.disabled) return;
          button.disabled = true;
          const url = new URL(button.dataset.inlineUrl, window.location.href);
          url.searchParams.set('target', button.dataset.target);
          Drupal.ajax({ url: url.toString(), element: button, progress: { type: 'throbber' } })
            .execute().catch(() => { button.disabled = false; });
        });
      });
    }
  };
})(Drupal, once);


/** Compact reply-view dropdown; progressive all-comments expansion. */
(function (Drupal, once) {
  'use strict';
  const selected = new Map();
  const active = new Set();
  const BATCH_REQUESTS = 10; // At most ten pages per action, never an unbounded download.

  async function expandPages(panel, control) {
    if (active.has(panel.id)) return;
    active.add(panel.id);
    const status = control.querySelector('[data-sd-view-status]');
    try {
      let pages = 0;
      while (pages < BATCH_REQUESTS && selected.get(panel.id) === 'all' && panel.isConnected) {
        // First expand the oldest top-level pages, then the nested threads.
        const button = panel.querySelector('.sd-inline-conversation__load-more:not(:disabled), .sd-inline-thread-more:not(:disabled)');
        if (!button) break;
        const url = new URL(button.dataset.inlineUrl, window.location.href);
        url.searchParams.set('target', panel.id);
        button.disabled = true;
        try {
          await Drupal.ajax({url: url.toString(), progress: {type: 'throbber'}}).execute();
        }
        catch (error) {
          button.disabled = false;
          if (status) status.textContent = Drupal.t('Could not load more replies. Try again.');
          return;
        }
        pages++;
      }
      if (status && selected.get(panel.id) === 'all') {
        const remaining = panel.querySelector('.sd-inline-conversation__load-more, .sd-inline-thread-more');
        status.textContent = remaining ? Drupal.t('More replies available. Select Continue loading.') : '';
        const resume = control.querySelector('[data-sd-view-resume]');
        if (resume) resume.hidden = !remaining;
      }
    }
    finally { active.delete(panel.id); }
  }

  Drupal.behaviors.spotdealsReplyView = {
    attach(context) {
      once('sd-reply-view', '[data-sd-reply-view]', context).forEach((control) => {
        const panel = control.closest('[id^="sd-activity-inline-"]');
        if (!panel) return;
        const toggle = control.querySelector('[data-sd-view-toggle]');
        const choices = control.querySelector('[data-sd-view-choices]');
        const resume = document.createElement('button');
        resume.type = 'button'; resume.dataset.sdViewResume = '';
        resume.className = 'sd-inline-reply-view__resume';
        resume.textContent = Drupal.t('Continue loading');
        resume.hidden = true;
        control.appendChild(resume);
        const apply = (value) => {
          selected.set(panel.id, value);
          toggle.textContent = (value === 'all' ? Drupal.t('All comments') : Drupal.t('Newest')) + ' ⌄';
          control.querySelectorAll('input[type="radio"]').forEach((radio) => { radio.checked = radio.value === value; });
          choices.hidden = true;
          toggle.setAttribute('aria-expanded', 'false');
          if (value === 'all') {
            expandPages(panel, control);
          }
          else {
            resume.hidden = true;
            control.querySelector('[data-sd-view-status]').textContent = '';
            // "All comments" appends pages and nested descendants to the DOM.
            // Selecting "Newest" must restore the server's original ten-reply
            // page, not just relabel the dropdown. Reuse the existing preview
            // endpoint, preserving the outer conversation's expanded state.
            const trigger = Array.from(document.querySelectorAll('[data-sd-inline-conversation]'))
              .find((item) => item.getAttribute('aria-controls') === panel.id);
            if (trigger && trigger.dataset.inlineUrl) {
              const url = new URL(trigger.dataset.inlineUrl, window.location.href);
              url.searchParams.set('target', panel.id);
              url.searchParams.set('visible', '10');
              Drupal.ajax({ url: url.toString(), progress: { type: 'throbber' } })
                .execute().catch(() => {
                  const status = panel.querySelector('[data-sd-view-status]');
                  if (status) status.textContent = Drupal.t('Could not restore newest replies. Try again.');
                });
            }
          }
        };
        toggle.addEventListener('click', (event) => {
          event.preventDefault(); event.stopPropagation();
          choices.hidden = !choices.hidden;
          toggle.setAttribute('aria-expanded', String(!choices.hidden));
        });
        control.addEventListener('change', (event) => {
          if (event.target.matches('input[type="radio"]')) apply(event.target.value);
        });
        resume.addEventListener('click', (event) => {
          event.stopPropagation(); resume.hidden = true; expandPages(panel, control);
        });
        // Initial rendering is already the newest page from PHP. Do not
        // issue another AJAX request when Drupal attaches this behavior.
        const initial = selected.get(panel.id) || 'newest';
        toggle.textContent = (initial === 'all' ? Drupal.t('All comments') : Drupal.t('Newest')) + ' ⌄';
        control.querySelectorAll('input[type="radio"]').forEach((radio) => { radio.checked = radio.value === initial; });
        if (initial === 'all') expandPages(panel, control);
      });
    }
  };
})(Drupal, once);

/** Share the canonical conversation URL; never the current profile URL. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.spotdealsConversationShare = {
    attach(context) {
      once('sd-conversation-share', '[data-sd-share-conversation]', context).forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          const url = button.dataset.shareUrl;
          if (!url) return;
          const title = button.dataset.shareTitle || Drupal.t('SpotDeals conversation');
          if (navigator.share) {
            try {
              await navigator.share({ title, url });
              return;
            }
            catch (error) {
              // Cancellation is not an error and should not open a second dialog.
              if (error.name === 'AbortError') return;
            }
          }
          try {
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
              throw new Error('Clipboard API unavailable');
            }
            await navigator.clipboard.writeText(url);
            const original = button.textContent;
            button.textContent = Drupal.t('Link copied!');
            window.setTimeout(() => { button.textContent = original; }, 2000);
          }
          catch (error) {
            // Works on local HTTP development sites without secure clipboard access.
            window.prompt(Drupal.t('Copy conversation link:'), url);
          }
        });
      });
    }
  };
})(Drupal, once);

/** Profile composer and newly published conversation focus. */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.sdProfileConversationCreate = {
    attach(context) {
      once('sd-profile-create', '[data-sd-create-toggle]', context).forEach((button) => {
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) return;
        const close = () => { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); };
        button.addEventListener('click', () => {
          panel.hidden = !panel.hidden;
          button.setAttribute('aria-expanded', String(!panel.hidden));
          if (!panel.hidden) panel.querySelector('input[name="title"]')?.focus();
        });
        panel.addEventListener('click', (event) => {
          if (event.target.closest('[data-sd-create-cancel]')) { event.preventDefault(); close(); }
        });
      });
      // Bind to the actual composer textarea on every Drupal AJAX rebuild.
      // Enter publishes; Shift+Enter inserts a newline.
      once('sd-profile-enter-submit', 'form.sd-profile-create-form textarea[name="body"]', context).forEach((textarea) => {
        textarea.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
          const form = textarea.form;
          const publish = form?.querySelector('input[type="submit"], button[type="submit"]');
          if (!publish || publish.disabled) return;
          event.preventDefault();
          publish.click();
        });
      });
      once('sd-new-conversation', '.sd-profile__activity--new', context).forEach((item) => {
        const panel = document.getElementById('sd-profile-create-form');
        const button = document.querySelector('[data-sd-create-toggle]');
        if (panel) {
          panel.hidden = true;
          // Drupal AJAX can rebuild the form with submitted values still in the
          // DOM. Clear only after confirmed publication, not after errors.
          const form = panel.querySelector('form');
          if (form) {
            form.querySelectorAll('input[type="text"], textarea').forEach((field) => { field.value = ''; });
            form.querySelectorAll('select').forEach((field) => { field.selectedIndex = 0; });
          }
        }
        if (button) button.setAttribute('aria-expanded', 'false');
        document.querySelector('[data-sd-activity-empty]')?.remove();
        item.setAttribute('tabindex', '-1');
        item.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
        item.focus({ preventScroll: true });
        window.setTimeout(() => item.classList.remove('sd-profile__activity--new'), 5000);
      });
    }
  };
})(Drupal, once);
