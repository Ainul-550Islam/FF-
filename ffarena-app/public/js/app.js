/* ==========================================================================
   FF Arena shared UI behaviours (Phase 17)
   --------------------------------------------------------------------------
   Loaded with `defer`. Vanilla JS only — no framework. Progressive
   enhancement: everything here is driven by data-* attributes already
   rendered server-side, so pages remain fully usable without JavaScript.

   Behaviours:
     1. Accessible mobile navigation (aria-expanded, Escape to close, focus
        return, click-outside to close).
     2. Unread-notification badge polling — visibility-aware (pauses in
        hidden tabs), resilient (no retry storm, keeps last known count).
   ========================================================================== */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ------------------------------------------------------------------
       1. Mobile navigation toggle
       ------------------------------------------------------------------ */
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.getElementById('site-nav');

    if (toggle && nav) {
        var closeMenu = function () {
            nav.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        };

        var openMenu = function () {
            nav.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
        };

        toggle.addEventListener('click', function () {
            if (nav.classList.contains('is-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        // Escape closes the menu and returns focus to the toggle.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                closeMenu();
                toggle.focus();
            }
        });

        // Clicking/tapping outside the open menu closes it.
        document.addEventListener('click', function (event) {
            if (nav.classList.contains('is-open')
                && !nav.contains(event.target)
                && !toggle.contains(event.target)) {
                closeMenu();
            }
        });

        // Collapse the menu automatically when the viewport grows past the
        // breakpoint so an open mobile menu never obscures desktop nav.
        var mq = window.matchMedia('(min-width: 901px)');
        if (mq.addEventListener) {
            mq.addEventListener('change', function (e) {
                if (e.matches) { closeMenu(); }
            });
        } else if (mq.addListener) {
            mq.addListener(function (e) { if (e.matches) { closeMenu(); } });
        }
    }

    /* ------------------------------------------------------------------
       2. Unread notification badge (visibility-aware polling)
       ------------------------------------------------------------------ */
    var badgeHost = document.querySelector('[data-unread-url]');
    var badge = document.getElementById('unread-badge');

    if (badgeHost && badge) {
        var url = badgeHost.getAttribute('data-unread-url');
        if (url) {
            var interval = 10000;
            var timer = null;

            var render = function (n) {
                badge.textContent = n > 99 ? '99+' : String(n);
                badge.hidden = n === 0;
                badge.setAttribute('aria-label',
                    n === 1 ? '1 unread notification' : n + ' unread notifications');
            };

            var poll = function () {
                // Never poll a hidden tab; resume immediately on visibility.
                if (document.hidden) {
                    schedule();
                    return;
                }
                fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then(function (response) {
                        if (!response.ok) { throw new Error('http ' + response.status); }
                        return response.json();
                    })
                    .then(function (data) {
                        var n = parseInt(data && data.unread, 10);
                        render(Number.isFinite(n) ? n : 0);
                    })
                    .catch(function () {
                        /* Network hiccup — keep the last known count. */
                    });
                schedule();
            };

            var schedule = function () {
                clearTimeout(timer);
                timer = setTimeout(poll, interval);
            };

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    clearTimeout(timer);
                    poll();
                }
            });

            schedule();
        }
    }

    /* ------------------------------------------------------------------
       3. Reduced-motion: cancel any transform/scroll animations the CSS
          might apply (the stylesheet already disables them, this is a
          belt-and-braces guard for any future inline styles).
       ------------------------------------------------------------------ */
    if (reduceMotion && 'scrollBehavior' in document.documentElement.style) {
        document.documentElement.style.scrollBehavior = 'auto';
    }
})();
