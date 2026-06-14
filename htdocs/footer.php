<!-- Footer Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Local helpers: CSRF-protected API calls + DataTables init
     for listing pages. Listings auto-initialize on DOMContentLoaded
     via the data-page-length / data-order attributes on the table.
     The ?v= query string is the file's mtime, so the URL changes
     automatically every time the file is edited. Without this,
     browsers can serve a cached stale copy after a deploy and the
     user sees confusing behavior that a hard refresh "fixes" --
     e.g. after a JS change, the dropdown preference might look
     site-broken until a hard reload, which is hard to debug. -->
<script src="/assets/js/proxy.js?v=<?= filemtime(__DIR__ . '/assets/js/proxy.js') ?>"></script>
<script src="/assets/js/listings.js?v=<?= filemtime(__DIR__ . '/assets/js/listings.js') ?>"></script>

<script>
    // Select2 init for the searchable <select> elements used on
    // monitor/credential edit pages.
    $(document).ready(function() {
        $('.searchable-select').each(function() {
            $(this).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: $(this).find('option:first').text(),
                allowClear: false,
                selectionCssClass: 'form-select',
            });
        });
    });

    // Toast notification helper. Pages call showToast(message, type)
    // where type is one of 'success', 'danger', 'warning', 'info'.
    // The container is appended lazily on first use so listing
    // pages that never toast don't carry the markup overhead.
    window.showToast = function (message, type) {
        type = type || 'info';
        var container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }
        var id = 'toast-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        var html = ''
            + '<div id="' + id + '" class="toast align-items-center text-bg-' + type
            + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">'
            + '  <div class="d-flex">'
            + '    <div class="toast-body">' + message + '</div>'
            + '    <button type="button" class="btn-close btn-close-white me-2 m-auto"'
            + '            data-bs-dismiss="toast" aria-label="Close"></button>'
            + '  </div>'
            + '</div>';
        container.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(id);
        var t = new bootstrap.Toast(el, { delay: 4000 });
        t.show();
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    };

    // Dark mode toggle. Bootstrap 5.3+ has a built-in dark theme
    // activated by the data-bs-theme="dark" attribute on <html>.
    // We persist the user's choice to localStorage so it survives
    // page navigations, and we add a small floating button bottom-
    // right to toggle it. The icon flips between a moon (light
    // mode is active, click to go dark) and a sun (dark mode is
    // active, click to go light).
    (function () {
        var KEY = 'wanportal-theme';
        var html = document.documentElement;

        // Restore persisted choice. We do this on script-execute
        // (not on DOMContentLoaded) so the attribute is set before
        // the browser paints, preventing a "flash of light theme"
        // for dark-mode users.
        try {
            if (localStorage.getItem(KEY) === 'dark') {
                html.setAttribute('data-bs-theme', 'dark');
            }
        } catch (e) { /* localStorage may be disabled; default to light */ }

        // Build the toggle button lazily (only after DOM is ready).
        function buildToggle() {
            if (document.getElementById('darkModeToggle')) { return; }
            var btn = document.createElement('button');
            btn.id = 'darkModeToggle';
            btn.type = 'button';
            btn.className = 'btn btn-outline-secondary btn-sm position-fixed';
            btn.style.cssText = 'bottom: 1rem; right: 1rem; z-index: 1080;';
            btn.setAttribute('aria-label', 'Toggle dark mode');
            btn.title = 'Toggle dark mode';
            function refreshIcon() {
                var isDark = html.getAttribute('data-bs-theme') === 'dark';
                btn.innerHTML = isDark
                    ? '<i class="bi bi-sun"></i>'
                    : '<i class="bi bi-moon-stars"></i>';
            }
            btn.addEventListener('click', function () {
                var isDark = html.getAttribute('data-bs-theme') === 'dark';
                if (isDark) {
                    html.removeAttribute('data-bs-theme');
                    try { localStorage.setItem(KEY, 'light'); } catch (e) {}
                } else {
                    html.setAttribute('data-bs-theme', 'dark');
                    try { localStorage.setItem(KEY, 'dark'); } catch (e) {}
                }
                refreshIcon();
            });
            refreshIcon();
            document.body.appendChild(btn);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', buildToggle);
        } else {
            buildToggle();
        }
    })();

    // Page-level hook: each *edit.php that wants to surface a toast
    // on redirect (e.g. "?saved=1") defines wanportalPageOnLoad().
    // That gives the listing page a chance to fire showToast()
    // after the redirect target finishes loading.
    window.wanportalPageOnLoad = window.wanportalPageOnLoad || function () {};

    // Wait for the document to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
            html: true,
            placement: 'auto'
        }));

        // Initialize all dropdowns
        const dropdownElementList = document.querySelectorAll('.dropdown-toggle');
        const dropdownList = [...dropdownElementList].map(dropdownToggleEl => new bootstrap.Dropdown(dropdownToggleEl));

        // Fire page-specific onload (e.g. toasts from ?saved=1 query)
        try { window.wanportalPageOnLoad(); } catch (e) { /* noop */ }

        // Page-specific hook: pages can define pageSpecificScripts()
        // for any init that has to run after DataTables/proxy are
        // wired up.
        if (typeof pageSpecificScripts === 'function') {
            pageSpecificScripts();
        }
    });
</script>

<?php if (isset($additional_scripts)): ?>
    <?php foreach ($additional_scripts as $script): ?>
        <script src="<?= htmlspecialchars($script) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>