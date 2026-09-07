<?php
// lib/page.php
//
// Shared page-level partials for the wanportal webapp. The goal
// of this file is to remove the ~25 lines of boilerplate that every
// page was repeating (head, body open, navbar include, header row,
// footer include, body close) and replace them with a handful of
// function calls that read clearly at the call site.
//
// Usage at the top of a page:
//
//     require_once 'config.php';
//     require_once __DIR__ . '/page.php';
//     wanportal_session_start();
//
//     $show_inactive = wanportal_get_show_inactive();
//     // ... page logic ...
//
//     wanportal_render_head('Page Title', ['datatables' => true]);
//     wanportal_render_header_row('Page Title', [
//         ['url' => '/foo_edit.php', 'icon' => 'bi-plus-circle', 'label' => 'New Foo', 'variant' => 'primary'],
//     ]);
//     // ... page body ...
//     wanportal_render_page_end();
//
// Functions are documented individually below. All functions are
// safe to call multiple times in a single request — render_head
// uses a `defined()` guard so it won't double-emit; render_page_end
// is a no-op if render_head wasn't called.

if (!defined('WANPORTAL_PAGE_LIB_LOADED')) {
    define('WANPORTAL_PAGE_LIB_LOADED', true);

    /**
     * Read the per-user "Show Inactive" preference and keep it in sync.
     *
     * Reads $_GET['show_inactive'] when present (it takes priority,
     * because the wanportal_render_page_end() JS hook round-trips
     * toggle changes through the URL), otherwise falls back to
     * $_SESSION['show_inactive']. The resolved value is written back
     * to the session, so the next page navigation sees the same
     * choice the user just made.
     *
     * After the call, $_SESSION['show_inactive'] (the authoritative
     * value) and the returned bool are in sync. Page templates should
     * read the return value; wanportal_render_header_row() reads the
     * session to decide whether the toggle renders as checked, so
     * pages with the toggle must call this BEFORE the render call or
     * the checked state lags the session by one request.
     *
     * @return bool True when inactive rows should be shown.
     */
    function wanportal_get_show_inactive(): bool
    {
        $value = isset($_GET['show_inactive'])
            ? filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN)
            : ($_SESSION['show_inactive'] ?? false);
        $_SESSION['show_inactive'] = $value;
        return $value;
    }

    /**
     * Emit <!DOCTYPE>, <html>, <head> (meta + CDN CSS), the pre-paint
     * dark-mode restore script, <body> open, and the navbar include.
     * The page only renders its own body content after this call;
     * pair it with wanportal_render_page_end(). A second call in the
     * same request is a no-op (guarded by WANPORTAL_HEAD_RENDERED).
     *
     * $options keys (all optional; anything not listed here is
     * silently ignored, so passing extra keys is safe):
     *   datatables   bool    add the DataTables CSS to <head> (pages
     *                        with a tablePager table)
     *   select2      bool    add the Select2 CSS to <head> and define
     *                        WANPORTAL_NEEDS_SELECT2 so footer.php
     *                        conditionally loads its JS + init code
     *   leaflet      bool    add the Leaflet CSS to <head> and define
     *                        WANPORTAL_NEEDS_LEAFLET so footer.php
     *                        conditionally loads its JS
     *   prism        bool    add the Prism CSS (okaidia theme + line
     *                        numbers) to <head>
     *   head_extras  string  arbitrary HTML emitted inside <head>
     *                        after the standard meta tags and CSS and
     *                        before the pre-paint script. For
     *                        page-specific <meta> tags (e.g. the
     *                        auto-refresh tag on the dashboard),
     *                        <link> tags, or <script src> tags. The
     *                        string is emitted verbatim, so the
     *                        caller escapes user-controlled data.
     *
     * The CDN <script> tags for the matching libraries live in
     * footer.php — they get loaded on every page. The CSS lives in
     * <head> so it can block render to avoid a flash of unstyled
     * content. This split matches the original pages' behavior.
     *
     * Constants exposed for page bodies:
     *   WANPORTAL_SERVER_NAME  uppercase first hostname label (e.g.
     *                          "NETOPS" from "netops.crc1.net"),
     *                          stripped to [A-Za-z0-9-] so an
     *                          attacker-controlled SERVER_NAME (Host
     *                          header) can never carry HTML/JS
     *                          metacharacters into <title>; falls
     *                          back to "LOCALHOST" when stripping
     *                          empties the label.
     *   WANPORTAL_TITLE        htmlspecialchars'd $title, reused as
     *                          the header-row h3 fallback.
     *
     * The pre-paint dark-mode restore script (mirrored from the
     * runtime toggle in footer.php) keeps dark-mode users from seeing
     * a flash of light background before the footer script restores
     * their choice. Its localStorage key MUST stay in sync with
     * footer.php ('wanportal-theme'); if the two drift, the toggle
     * will fight the pre-paint restore.
     *
     * @param string $title   Used for <title> and as a fallback for
     *                        the header-row h3 when render_header_row
     *                        isn't given a different title.
     * @param array  $options Per-page feature flags, see description.
     * @return void
     * @global array $menuItems Navbar menu items from config.php's
     *                          top-level scope. PHP functions do not
     *                          inherit caller variables, so it is
     *                          pulled in here explicitly; the empty
     *                          fallback keeps the navbar include from
     *                          iterating over an undefined variable
     *                          when config.php was not loaded first.
     */
    function wanportal_render_head(string $title, array $options = []): void
    {
        // Guard against double-emit if a page calls render_head twice.
        if (defined('WANPORTAL_HEAD_RENDERED')) {
            return;
        }
        define('WANPORTAL_HEAD_RENDERED', true);

        // Restrict to hostname-label characters [A-Za-z0-9-] so the
        // value (also exposed as WANPORTAL_SERVER_NAME and echoed into
        // <title>) can never carry HTML/JS metacharacters from a
        // attacker-controlled SERVER_NAME (Host header). Fall back to
        // LOCALHOST if stripping empties the label.
        $server_name = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', explode('.', $_SERVER['SERVER_NAME'] ?? getenv('SERVER_NAME') ?? 'localhost')[0] ?? 'LOCALHOST'));
        if ($server_name === '' || $server_name === false) {
            $server_name = 'LOCALHOST';
        }

        // Expose the server name as a constant so page bodies can
        // use it without recomputing the same explode/strtoupper
        // pattern (index.php, server.php, latency.php previously
        // each had their own copy of this line).
        define('WANPORTAL_SERVER_NAME', $server_name);

        // Escape the title once; the same value goes into <title> and
        // is available to render_header_row via the WANPORTAL_TITLE
        // constant if the page didn't pass a different title there.
        define('WANPORTAL_TITLE', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en">' . "\n";
        echo '<head>' . "\n";
        echo '    <meta charset="UTF-8" />' . "\n";
        echo '    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />' . "\n";
        echo '    <meta http-equiv="Pragma" content="no-cache" />' . "\n";
        echo '    <meta http-equiv="Expires" content="0" />' . "\n";
        echo '    <title>' . htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8') . ' :: ' . WANPORTAL_TITLE . '</title>' . "\n";

        // Bootstrap 5.3.8 + Bootstrap Icons on every page.
        echo '    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" />' . "\n";
        echo '    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css">' . "\n";
        // Mark the icon font as loaded: navbar.php (included below)
        // re-emits the same <link> otherwise, because its own
        // NAVBAR_LOADED guard is never set by anything else.
        define('WANPORTAL_ICONS_LOADED', true);

        // DataTables CSS — only on pages that have a tablePager table.
        if (!empty($options['datatables'])) {
            echo '    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css">' . "\n";
        }

        // Select2 CSS — only on edit pages with searchable selects.
        if (!empty($options['select2'])) {
            define('WANPORTAL_NEEDS_SELECT2', true);
            echo '    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">' . "\n";
        }

        // Leaflet CSS — only on pages with maps.
        if (!empty($options['leaflet'])) {
            define('WANPORTAL_NEEDS_LEAFLET', true);
            echo '    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />' . "\n";
        }

        // Prism CSS — only on pages that display syntax-highlighted code.
        if (!empty($options['prism'])) {
            echo '    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism-okaidia.min.css" rel="stylesheet" />' . "\n";
            echo '    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />' . "\n";
        }

        // Cache-bust base.css with its mtime, matching footer.php's
        // ?v= treatment of local JS — without it browsers can serve a
        // stale copy after a deploy and the user sees broken styling.
        echo '    <link rel="stylesheet" href="/assets/base.css?v=' . filemtime(__DIR__ . '/../assets/base.css') . '">' . "\n";

        // Page-specific extras (custom <meta> tags, additional
        // <link> tags, etc.). The caller is responsible for
        // escaping any user-controlled content; the value is
        // inserted verbatim.
        if (!empty($options['head_extras'])) {
            echo '    ' . $options['head_extras'] . "\n";
        }

        // Pre-paint dark-mode restore. Mirrors the runtime toggle in
        // footer.php. The try/catch around localStorage is mandatory:
        // Safari private mode and some embedded WebViews throw on
        // localStorage.getItem, and the page would otherwise error
        // out before paint.
        echo '    <script>' . "\n";
        echo '        (function () {' . "\n";
        echo '            try {' . "\n";
        echo '                if (localStorage.getItem("wanportal-theme") === "dark") {' . "\n";
        echo '                    document.documentElement.setAttribute("data-bs-theme", "dark");' . "\n";
        echo '                }' . "\n";
        echo '            } catch (e) { /* localStorage may be disabled; default to light */ }' . "\n";
        echo '        })();' . "\n";
        echo '    </script>' . "\n";

        echo '</head>' . "\n";
        echo '<body>' . "\n";

        // The navbar partial handles CSRF token, Bootstrap Icons (it
        // re-checks to avoid double-load on pages that emit it in
        // <head>), logout form, and the menu rendering. It expects
        // the session to be active and the $menuItems variable to
        // be defined in config.php's scope.
        //
        // Pull $menuItems from the caller's scope (config.php's
        // top-level $menuItems) so the navbar can iterate over the
        // configured items. Inside this function the variable
        // would otherwise be undefined -- the if-isset check is
        // a CLI/curl safety net for cases where the navbar is
        // included without a parent that pre-loaded config.php.
        global $menuItems;
        if (!isset($menuItems)) {
            $menuItems = [];
        }
        require_once __DIR__ . '/../navbar.php';

        echo '<div class="container-fluid">' . "\n";
    }

    /**
     * Emit the standard page header: the h3 title on the left, the
     * btn-group on the right with Back | Home, then any page-specific
     * action buttons, then optionally the Show Inactive toggle.
     *
     * $title falls back to the WANPORTAL_TITLE constant set by
     * wanportal_render_head() when null.
     *
     * Each $actions entry is an associative array with:
     *   url      string  (optional) render the action as an <a href>
     *                    link (a navigation action)
     *   click    string  (optional) render the action as a <button
     *                    onclick> button, for JS-triggered actions
     *                    (confirm prompts, modal opens, etc.) without
     *                    a navigation
     *   label    string  required button text
     *   icon     string  optional Bootstrap Icons class, e.g. 'bi-pencil'
     *   variant  string  Bootstrap variant, e.g. 'primary', 'danger',
     *                    'warning'; default 'secondary'
     *   auth     bool    when true, render only for authenticated
     *                    users ($_SESSION['user'])
     *   admin    bool    when true, render only for admins
     *                    ($_SESSION['is_admin'])
     * url and click are mutually exclusive: an action with both
     * renders as <a> with the click handler ignored; an action with
     * neither is skipped rather than erroring. Back renders only when
     * a referer is present (deep links and hard refreshes skip the
     * button entirely); Home always renders.
     *
     * $options keys:
     *   show_inactive_toggle bool  render the "Inactive" form-switch
     *                        toggle inside the btn-group, placed LAST.
     *                        Its checked state reads from
     *                        $_SESSION['show_inactive'], so the page
     *                        must have called
     *                        wanportal_get_show_inactive() first or
     *                        the toggle lags the session by one
     *                        request.
     *   extra_buttons        string  raw HTML emitted inside the
     *                        btn-group AFTER the standard Back / Home /
     *                        page actions / optional show-inactive
     *                        toggle, for page-specific in-header
     *                        controls (e.g. monitor.php's "Legacy
     *                        Graph" switch). Emitted verbatim, so the
     *                        caller escapes user-controlled data.
     *
     * Convention: the title is h3, the actions are btn-sm inside a
     * btn-group — matching the layout the existing detail pages
     * established (agent.php, target.php, search.php).
     *
     * @param string|null $title   Page title; null falls back to
     *                             WANPORTAL_TITLE.
     * @param array       $actions Action button definitions, see
     *                             description.
     * @param array       $options Row options, see description.
     * @return void
     */
    function wanportal_render_header_row(?string $title = null, array $actions = [], array $options = []): void
    {
        $title = $title ?? (defined('WANPORTAL_TITLE') ? WANPORTAL_TITLE : '');
        $title_safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        echo '<div class="row mb-3">' . "\n";
        echo '    <div class="col">' . "\n";
        echo '        <h3>' . $title_safe . '</h3>' . "\n";
        echo '    </div>' . "\n";
        echo '    <div class="col text-end">' . "\n";
        echo '        <div class="d-flex justify-content-end align-items-center gap-2">' . "\n";
        echo '            <div class="btn-group" role="group">' . "\n";

        // Back button — only render if we have a referer to go back
        // to. Deep links and hard refreshes skip the button entirely
        // so we don't render an empty shell.
        if (isset($_SERVER['HTTP_REFERER'])) {
            echo '                <a href="' . htmlspecialchars($_SERVER['HTTP_REFERER'], ENT_QUOTES, 'UTF-8') . '" class="btn btn-secondary btn-sm">' . "\n";
            echo '                    <i class="bi bi-arrow-left"></i> Back' . "\n";
            echo '                </a>' . "\n";
        }

        // Home button — always rendered, goes to /index.php.
        echo '                <a href="/index.php" class="btn btn-secondary btn-sm">' . "\n";
        echo '                    <i class="bi bi-house-door"></i> Home' . "\n";
        echo '                </a>' . "\n";

        // Page-specific actions. Each action is gated on auth/admin
        // if those keys are set, and falls back to a sensible
        // default variant if the page didn't specify one. An action
        // with a 'url' key is emitted as an <a> (a navigation
        // button); an action with a 'click' key is emitted as a
        // <button> (a JS-triggered button). The two are mutually
        // exclusive: an action with both is rendered as an <a>
        // (with the click handler ignored), and an action with
        // neither is skipped.
        foreach ($actions as $action) {
            if (empty($action['url']) && empty($action['click'])) {
                continue; // skip malformed entries rather than error
            }
            if (!empty($action['auth']) && empty($_SESSION['user'])) {
                continue;
            }
            if (!empty($action['admin']) && empty($_SESSION['is_admin'])) {
                continue;
            }
            $variant = htmlspecialchars($action['variant'] ?? 'secondary', ENT_QUOTES, 'UTF-8');
            $label   = htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8');
            $icon    = isset($action['icon']) ? '<i class="' . htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') . '"></i> ' : '';

            if (!empty($action['url'])) {
                $url = htmlspecialchars($action['url'], ENT_QUOTES, 'UTF-8');
                echo '                <a href="' . $url . '" class="btn btn-' . $variant . ' btn-sm">' . "\n";
                echo '                    ' . $icon . $label . "\n";
                echo '                </a>' . "\n";
            } else {
                $click = htmlspecialchars($action['click'], ENT_QUOTES, 'UTF-8');
                echo '                <button type="button" onclick="' . $click . '" class="btn btn-' . $variant . ' btn-sm">' . "\n";
                echo '                    ' . $icon . $label . "\n";
                echo '                </button>' . "\n";
            }
        }

        // Show Inactive toggle. Rendered as a "fake button" inside
        // the btn-group so the visual rhythm is unbroken. The
        // matching JS hook (which round-trips the toggle state
        // through the URL on change) lives in render_page_end().
        if (!empty($options['show_inactive_toggle'])) {
            $checked = !empty($_SESSION['show_inactive']) ? 'checked' : '';
            echo '                <div class="btn btn-secondary btn-sm d-flex align-items-center" style="gap: 5px;">' . "\n";
            echo '                    <div class="form-check form-switch mb-0">' . "\n";
            echo '                        <input class="form-check-input" type="checkbox" id="showInactive" ' . $checked . '>' . "\n";
            echo '                        <label class="form-check-label" for="showInactive">' . "\n";
            echo '                            Inactive' . "\n";
            echo '                        </label>' . "\n";
            echo '                    </div>' . "\n";
            echo '                </div>' . "\n";
        }

        // Page-specific extra buttons. Useful for one-off in-header
        // controls that don't fit the action[] shape (custom
        // form-switches, dropdowns, raw HTML widgets, etc.).
        // The caller is responsible for escaping; the value is
        // emitted verbatim inside the btn-group.
        if (!empty($options['extra_buttons'])) {
            echo '                ' . $options['extra_buttons'] . "\n";
        }

        echo '            </div>' . "\n";
        echo '        </div>' . "\n";
        echo '    </div>' . "\n";
        echo '</div>' . "\n";
    }

    /**
     * Emit the "Statistics" card: a list-group of label/value rows
     * with a colored badge for each value. Used by agent.php,
     * target.php, monitor.php, and search.php; replaces ~30 lines of
     * copy-pasted list-group markup that existed in the four pages
     * with subtle drift (one had "Inactive Monitors", another had
     * "Total Results", etc.).
     *
     * Each $stats entry is a [label, value, variant] triple. variant
     * is one of 'success', 'warning', 'secondary', 'primary', 'info',
     * 'danger' (default 'secondary'), rendered as the dark-mode-aware
     * subtle trio: bg-{color}-subtle + text-{color}-emphasis +
     * border-{color}-subtle. Entries without both a label and a value
     * are skipped defensively. Pass an empty array to render just the
     * title (no items).
     *
     * Does NOT fit cards that mix stats with timestamp rows (e.g.
     * monitor.php's stats card) — keep those hand-rolled.
     *
     * @param string $title Card title (e.g. "Statistics", "Search
     *                      Statistics").
     * @param array  $stats List of [label, value, variant] triples.
     * @return void
     */
    function wanportal_render_stats_card(string $title, array $stats): void
    {
        echo '<div class="card mb-3">' . "\n";
        echo '    <div class="card-body">' . "\n";
        echo '        <h5 class="card-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h5>' . "\n";
        echo '        <ul class="list-group list-group-flush">' . "\n";
        foreach ($stats as $stat) {
            // Defensive: skip entries that don't have at least a label
            // and a value. The variant defaults to secondary.
            if (!isset($stat[0], $stat[1])) {
                continue;
            }
            $label   = htmlspecialchars((string) $stat[0], ENT_QUOTES, 'UTF-8');
            $value   = htmlspecialchars((string) $stat[1], ENT_QUOTES, 'UTF-8');
            $variant = htmlspecialchars((string) ($stat[2] ?? 'secondary'), ENT_QUOTES, 'UTF-8');
            echo '            <li class="list-group-item d-flex justify-content-between align-items-center">' . "\n";
            echo '                ' . $label . "\n";
            echo '                <span class="badge bg-' . $variant . '-subtle text-' . $variant . '-emphasis border border-' . $variant . '-subtle rounded-pill">' . "\n";
            echo '                    ' . $value . "\n";
            echo '                </span>' . "\n";
            echo '            </li>' . "\n";
        }
        echo '        </ul>' . "\n";
        echo '    </div>' . "\n";
        echo '</div>' . "\n";
    }

    /**
     * Close the page: the closing </div> for the container, the
     * footer include, the showInactive JS hook, and the closing
     * </body></html>.
     *
     * Safe to call even when wanportal_render_head() wasn't called:
     * the container close and footer include are skipped unless
     * WANPORTAL_HEAD_RENDERED is defined, which lets pages opt out of
     * render_head (e.g. login.php, which has its own pre-paint
     * script) and still call render_page_end for the body close.
     *
     * The showInactive hook round-trips the toggle's state through
     * the URL on every change. That way $_SESSION['show_inactive']
     * is updated on the server side, and the next page navigation
     * (e.g. agent.php -> target.php) sees the same value the user
     * just set. It preserves all other query params (e.g. ?id=...,
     * ?q=...) and the hash fragment, and reads/writes nothing if the
     * toggle isn't on the page (document.getElementById returns
     * null).
     *
     * @return void
     */
    function wanportal_render_page_end(): void
    {
        // Close the container-fluid div that render_head opened.
        // If render_head wasn't called, emit nothing for the
        // container close — the page is responsible for its own
        // layout in that case.
        if (defined('WANPORTAL_HEAD_RENDERED')) {
            echo '</div>' . "\n";
            require_once __DIR__ . '/../footer.php';
        }

        // The showInactive hook is harmless to emit on pages that
        // don't have the toggle (the early-return on null handles
        // it), so we always include it. This keeps the helper
        // truly drop-in.
        echo '<script>' . "\n";
        echo '    // Persist the "Show Inactive" toggle across page navigations.' . "\n";
        echo '    // The PHP session keeps the value once it is set, so we just' . "\n";
        echo '    // round-trip the new value through the URL on every change.' . "\n";
        echo '    // Preserves all other query params (e.g. ?id=..., ?q=...)' . "\n";
        echo '    // and the hash fragment. Listing pages (agents/targets/' . "\n";
        echo '    // monitors) have their own client-side filter via' . "\n";
        echo '    // listings.js and do not need this hook.' . "\n";
        echo '    window.pageSpecificScripts = function () {' . "\n";
        echo '        var toggle = document.getElementById("showInactive");' . "\n";
        echo '        if (!toggle) { return; }' . "\n";
        echo '        toggle.addEventListener("change", function () {' . "\n";
        echo '            var url = new URL(window.location.href);' . "\n";
        echo '            url.searchParams.set("show_inactive", toggle.checked ? "true" : "false");' . "\n";
        echo '            window.location.assign(url.toString());' . "\n";
        echo '        });' . "\n";
        echo '    };' . "\n";
        echo '</script>' . "\n";

        echo '</body>' . "\n";
        echo '</html>' . "\n";
    }
}
