// Help offcanvas panel — page-aware help content loaded via HTMX.
//
// Wiring:
//  - #helpToggleBtn in the navbar carries data-help-page="<route_name>".
//  - On click we set hx-get on the panel body and trigger an htmx request,
//    then open the offcanvas. HelpController returns rendered Markdown.
//  - Closing happens on the X button, backdrop click, or Escape key.
//
// Why HTMX (not Turbo): ORMed already ships HTMX everywhere. Loading the
// panel content via fetch + innerHTML would bypass htmx process hooks,
// which is fine for static HTML but adds an inconsistent pattern.

(function () {
    'use strict';

    const panel = document.getElementById('help-offcanvas');
    const body = document.getElementById('help-panel-body');
    const btn = document.getElementById('helpToggleBtn');
    if (!panel || !body || !btn) {
        return;
    }

    const backdrop = document.querySelector('.help-offcanvas-backdrop');
    let currentPage = null;

    function open(page) {
        if (page && page !== currentPage) {
            body.innerHTML = '<p><small>Loading…</small></p>';
            const url = '/help/' + encodeURIComponent(page || '_default');
            if (window.htmx) {
                htmx.ajax('GET', url, { target: '#help-panel-body', swap: 'innerHTML' });
            } else {
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) { body.innerHTML = html; });
            }
            currentPage = page;
        }
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        if (backdrop) backdrop.classList.add('is-open');
        document.body.classList.add('help-open');
    }

    function close() {
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        if (backdrop) backdrop.classList.remove('is-open');
        document.body.classList.remove('help-open');
    }

    btn.addEventListener('click', function () {
        const page = btn.dataset.helpPage || '_default';
        if (panel.classList.contains('is-open')) {
            close();
        } else {
            open(page);
        }
    });

    document.querySelectorAll('[data-help-close]').forEach(function (el) {
        el.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open')) {
            close();
        }
    });

    // Internal cross-links: <a href="/help/foo"> inside the help panel
    // should swap the panel's content rather than navigate the whole
    // page. Authors can also link to other help pages without thinking
    // about the JS wiring — markdown like [foo](/help/foo) just works.
    body.addEventListener('click', function (e) {
        const link = e.target.closest('a[href^="/help/"]');
        if (!link) return;
        e.preventDefault();
        const page = link.getAttribute('href').replace(/^\/help\//, '');
        // Reset currentPage so open() refetches even if same name.
        currentPage = null;
        open(page);
    });
}());

