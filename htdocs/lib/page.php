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

    // ----------------------------------------------------------------
    // show_inactive: read/write the per-user session preference and
    // expose the value as a local variable. Seven pages had this
    // exact 5-line block copy-pasted; consolidating it here removes
    // the chance for the copies to drift.
    //
    // Usage:
    //     $show_inactive = wanportal_get_show_inactive();
    //
    // After this call, both $_SESSION['show_inactive'] (the
    // authoritative value) and $show_inactive (a convenience local)
    // are updated and in sync. The local is what page templates
    // should read; the session is what wanportal_render_header_row
    // reads to decide whether to render the toggle as checked.
    // ----------------------------------------------------------------
    function wanportal_get_show_inactive(): bool
    {
        $value = isset($_GET['show_inactive'])
            ? filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN)
            : ($_SESSION['show_inactive'] ?? false);
        $_SESSION['show_inactive'] = $value;
        return $value;
    }

    // ----------------------------------------------------------------
    // render_head: emit <!DOCTYPE>, <html>, <head>, and the opening
    // of <body> + the navbar include. The page only needs to render
    // its own body content after this call.
    //
    // Parameters:
    //   $title   string   Used for the <title> tag and as a fallback
    //                    for the header-row h3 if render_header_row
    //                    isn't given a different title.
    //   $options array    Per-page feature flags. Recognized keys:
    //                       'datatables'   -> adds DataTables 1.13.11
    //                                         CSS to <head>
    //                       'select2'      -> adds Select2 4.1.0-rc.0
    //                                         CSS to <head>
    //                       'leaflet'      -> adds Leaflet 1.9.4 CSS
    //                                         to <head>
    //                       'prism'        -> adds Prism 1.24.1 CSS
    //                                         (okaidia theme + line
    //                                         numbers) to <head>
    //                       'head_extras'  -> arbitrary HTML to emit
    //                                         inside <head> after
    //                                         the standard meta tags
    //                                         and CSS, before the
    //                                         pre-paint dark-mode
    //                                         script. Used for
    //                                         page-specific meta
    //                                         tags (e.g. the
    //                                         <meta http-equiv=
    //                                         "refresh"> on the
    //                                         dashboard) or
    //                                         page-specific <link>
    //                                         tags. The string is
    //                                         passed through
    //                                         unmodified, so the
    //                                         caller is responsible
    //                                         for escaping anything
    //                                         user-controlled.
    //                     Anything not listed here is silently
    //                     ignored, so passing extra keys is safe.
    //
    // The CDN <script> tags for the matching libraries live in
    // footer.php — they get loaded on every page. The CSS lives in
    // <head> so it can block render to avoid a flash of unstyled
    // content. This split matches the original pages' behavior.
    //
    // The pre-paint dark-mode restore script (mirrored from the
    // runtime toggle in footer.php) lives here so dark-mode users
    // don't see a flash of light background before the footer
    // script restores their choice. The localStorage key MUST
    // match the one in footer.php ('wanportal-theme'); if they
    // drift, the toggle will fight the pre-paint restore.
    // ----------------------------------------------------------------
    function wanportal_render_head(string $title, array $options = []): void
    {
        // Guard against double-emit if a page calls render_head twice.
        if (defined('WANPORTAL_HEAD_RENDERED')) {
            return;
        }
        define('WANPORTAL_HEAD_RENDERED', true);

        $server_name = strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING');

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
        echo '    <title>' . $server_name . ' :: ' . WANPORTAL_TITLE . '</title>' . "\n";

        // Bootstrap 5.3.8 + Bootstrap Icons on every page.
        echo '    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" />' . "\n";
        echo '    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css">' . "\n";

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

        echo '    <link rel="stylesheet" href="/assets/base.css">' . "\n";

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

    // ----------------------------------------------------------------
    // render_header_row: emit the standard page header — the h3
    // title on the left, the btn-group on the right with Back |
    // Home, then any page-specific action buttons, then optionally
    // the Show Inactive toggle.
    //
    // Parameters:
    //   $title    string  Page title. If not given, falls back to
    //                     the WANPORTAL_TITLE constant set by
    //                     render_head.
    //                     Each action is an associative array
    //                     with:
    //                       'url'     string  (optional)  renders
    //                                        the action as an
    //                                        <a href> link
    //                       'click'   string  (optional)  renders
    //                                        the action as a
    //                                        <button onclick>
    //                                        button. Useful for
    //                                        actions that trigger
    //                                        JS (confirm prompts,
    //                                        modal opens, etc.)
    //                                        without a navigation.
    //                       'label'   string  required
    //                       'icon'    string  optional, e.g. 'bi-pencil'
    //                       'variant' string  Bootstrap variant,
    //                                        e.g. 'primary', 'danger',
    //                                        'warning'. Default 'secondary'.
    //                       'auth'    bool    if true, only render for
    //                                        authenticated users.
    //                       'admin'   bool    if true, only render for
    //                                        admins.
    //                     url and click are mutually exclusive; an
    //                     action with both renders as <a> with the
    //                     click handler ignored. An action with
    //                     neither is silently skipped.
    //                     Back and Home are always rendered (the
    //                     Back button is itself conditional on a
    //                     referer, matching the original pages).
    //   $options  array   Per-row options. Recognized keys:
    //                       'show_inactive_toggle'  bool  if true,
    //                                            render the
    //                                            "Inactive" form-
    //                                            switch toggle
    //                                            inside the btn-
    //                                            group, reading
    //                                            its state from
    //                                            $_SESSION.
    //                                            The page must
    //                                            have called
    //                                            wanportal_get_show_inactive
    //                                            first so the
    //                                            session value is
    //                                            in sync.
    //                       'extra_buttons'  string  raw HTML to
    //                                            emit inside the
    //                                            btn-group AFTER
    //                                            the standard
    //                                            Back / Home /
    //                                            page actions /
    //                                            optional show-
    //                                            inactive toggle.
    //                                            Used for page-
    //                                            specific
    //                                            in-header
    //                                            controls (e.g.
    //                                            monitor.php's
    //                                            "Legacy Graph"
    //                                            switch). The
    //                                            string is passed
    //                                            through
    //                                            unmodified, so
    //                                            the caller is
    //                                            responsible for
    //                                            escaping.
    //
    // Convention: the title is h3, the actions are btn-sm inside a
    // btn-group, and the Show Inactive toggle (if requested) is
    // placed LAST in the group. This matches the layout the
    // existing detail pages established (agent.php, target.php,
    // search.php) and the wanportal skill documents in its
    // header-row convention.
    // ----------------------------------------------------------------
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

    // ----------------------------------------------------------------
    // render_stats_card: emit the "Statistics" card with a list of
    // label/value pairs and a colored badge for the value. Used by
    // agent.php, target.php, monitor.php, and search.php.
    //
    // Parameters:
    //   $title   string   Card title (e.g. "Statistics", "Search Statistics")
    //   $stats   array    List of [label, value, variant] triples.
    //                     variant is one of 'success', 'warning',
    //                     'secondary', 'primary', 'info', 'danger'.
    //                     Pass an empty array to render just the
    //                     title (no items).
    //
    // This replaces ~30 lines of copy-pasted list-group markup that
    // existed in the four pages with subtle drift (one had "Inactive
    // Monitors", another had "Total Results", etc.).
    // ----------------------------------------------------------------
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

    // ----------------------------------------------------------------
    // render_page_end: emit the closing </div> for the container,
    // the footer include, the showInactive JS hook, and the closing
    // </body></html>. Safe to call even if render_head wasn't called
    // (it falls back to a no-op), which lets pages opt out of
    // render_head (e.g. login.php, which has its own pre-paint
    // script) and still call render_page_end for the body close.
    //
    // The showInactive hook round-trips the toggle's state through
    // the URL on every change. That way $_SESSION['show_inactive']
    // is updated on the server side, and the next page navigation
    // (e.g. agent.php -> target.php) sees the same value the user
    // just set. The hook reads/writes nothing if the toggle isn't
    // on the page (i.e. document.getElementById returns null).
    // ----------------------------------------------------------------
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
