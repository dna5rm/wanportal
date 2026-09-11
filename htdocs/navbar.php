<?php
// session_start() is invoked by the caller (via wanportal_session_start()
// from config.php), so by the time we get here the session is already
// active. We don't call session_start() again — it's a no-op in PHP
// anyway, but skipping it is cleaner.

// Operator chrome (brand logo + extra menu entries) is shared with the
// SPA through htdocs/config.json; lib/site_config.php parses it and
// never throws — a broken config just leaves the built-in chrome up.
require_once __DIR__ . '/lib/site_config.php';

// Handle logout
if (isset($_POST['logout'])) {
    // Verify CSRF token if set
    if (isset($_POST['csrf_token']) && isset($_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        // Clear all session variables
        $_SESSION = array();
        
        // Destroy the session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time()-3600, '/');
        }
        
        // Destroy the session
        session_destroy();
    }
    
    // Redirect to login page
    header('Location: /classic/login.php');
    exit;
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Expose the CSRF token to client-side JavaScript so that
// proxy.php can be called from fetch() with the X-CSRF-Token
// header. The CSRF token is designed to be readable by the
// browser, unlike the session JWT, so echoing it here is safe.
?>
<meta name="csrf-token" content="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<?php

// Fallback load of the Bootstrap Icons stylesheet for an include
// that bypasses the partial: wanportal_render_head() already emits
// the stylesheet and defines WANPORTAL_ICONS_LOADED, so every page
// built on the partial has the font in <head> and this branch stays
// a no-op there. The bar itself renders text-only links (SPA
// parity); page bodies are what still use the glyphs.
if (!defined('NAVBAR_LOADED') && !defined('WANPORTAL_ICONS_LOADED')): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css">
    <?php define('NAVBAR_LOADED', true);
endif;

// Operator chrome config: brand logo + extra menu entries, shared with
// the SPA (htdocs/config.json). wanportal_site_config() never throws;
// a broken config just leaves the built-in chrome in place.
$site = wanportal_site_config();
$wa_logo = (string) ($site['logo'] ?? '');
$wa_menu = (array) ($site['menu'] ?? []);

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Shared escaper for everything echoed below.
$wa_esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Account-menu doors: the classic listing pages surface in the user
// dropdown (the SPA account menu), not as a separate Admin dropdown in
// the bar. config.php's $menuItems is the source; both the old Admin
// dropdown wrapper and the slimmed flat shape are accepted. Entries
// are auth-gated as a set (the dropdown only exists signed in) and the
// Users door keeps its admin gate, exactly like the SPA account menu.
$wa_accountItems = [];
$wa_accountSource = (isset($menuItems) && is_array($menuItems)) ? $menuItems : [];
if (isset($wa_accountSource['Admin']['items']) && is_array($wa_accountSource['Admin']['items'])) {
    $wa_accountSource = $wa_accountSource['Admin']['items'];
}
foreach ($wa_accountSource as $wa_label => $wa_entry) {
    if (!is_array($wa_entry)
        || !isset($wa_entry['url']) || !is_string($wa_entry['url'])
        || $wa_entry['url'] === ''
        || strpos($wa_entry['url'], '/classic/') !== 0) {
        continue;
    }
    $wa_accountItems[] = [
        'label' => (string) $wa_label,
        'url'   => $wa_entry['url'],
        'icon'  => (string) ($wa_entry['icon'] ?? ''),
        'admin' => !empty($wa_entry['admin']),
    ];
}

// Menu tree renderer, driven by the operator's config.json menu. An
// item with children becomes a Bootstrap dropdown; `to` becomes an SPA
// hash link ("/#" . to); `href` renders as given; a label-only entry
// renders as inert text (SPA NavMenu parity). Entries that only point
// back at the classic console are skipped — we are already there.
$wa_render_items = null;
$wa_render_items = function (array $items, bool $nested, int $depth = 0) use (&$wa_render_items, $wa_esc): void {
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $label = (isset($it['label']) && is_string($it['label'])) ? trim($it['label']) : '';
        if ($label === '') {
            continue; // entries without a label are ignored (SPA parity)
        }
        $to   = (isset($it['to']) && is_string($it['to']) && trim($it['to']) !== '') ? trim($it['to']) : '';
        $href = (isset($it['href']) && is_string($it['href']) && trim($it['href']) !== '') ? trim($it['href']) : '';
        $kids = (isset($it['children']) && is_array($it['children'])) ? $it['children'] : [];

        if ($href === '/classic' || $href === '/classic/'
            || $to === '/classic' || $to === '/classic/'
            || strcasecmp($label, 'Classic console') === 0) {
            continue;
        }

        if ($kids !== []) {
            if ($nested) {
                // Bootstrap 5 has no nested-dropdown styles in the base
                // build, so deeper levels flatten into a group label
                // plus indented items: every entry stays reachable.
                echo '<li><span class="dropdown-item-text fw-semibold small">' . $wa_esc($label) . '</span></li>' . "\n";
                $wa_render_items($kids, true, $depth + 1);
            } else {
                echo '<li class="nav-item dropdown">' . "\n";
                echo '    <a class="nav-link dropdown-toggle" href="#" role="button"'
                    . ' data-bs-toggle="dropdown" aria-expanded="false">' . $wa_esc($label) . '</a>' . "\n";
                echo '    <ul class="dropdown-menu dropdown-menu-dark">' . "\n";
                $wa_render_items($kids, true, $depth + 1);
                echo '    </ul>' . "\n";
                echo '</li>' . "\n";
            }
            continue;
        }

        // Route resolution: SPA hash routes get "/#" . to; hrefs pass
        // through untouched.
        $wa_url = null;
        if ($to !== '') {
            $wa_url = '/#' . $to;
        } elseif ($href !== '') {
            $wa_url = $href;
        }

        if ($wa_url === null) {
            // Label-only entry: inert text, never a dead link.
            if ($nested) {
                echo '<li><span class="dropdown-item-text">' . $wa_esc($label) . '</span></li>' . "\n";
            } else {
                echo '<li class="nav-item"><span class="nav-link disabled">' . $wa_esc($label) . '</span></li>' . "\n";
            }
            continue;
        }

        if ($nested) {
            $wa_pad = ' style="padding-left: ' . (string) (0.5 + 0.75 * $depth) . 'rem"';
            echo '<li><a class="dropdown-item"' . $wa_pad . ' href="' . $wa_esc($wa_url) . '">' . $wa_esc($label) . '</a></li>' . "\n";
        } else {
            echo '<li class="nav-item"><a class="nav-link" href="' . $wa_esc($wa_url) . '">' . $wa_esc($label) . '</a></li>' . "\n";
        }
    }
};
?>
<nav class="navbar navbar-expand navbar-dark mb-4" style="background-color: #102444; border-bottom: 0;">
    <div class="container-fluid">
        <!-- Brand: the operator logo takes the text brand's slot, the
             same /assets/logo.png file the SPA shell uses; without it
             the text brand stays, as in the SPA. -->
        <a class="navbar-brand d-flex align-items-center" href="/classic/">
        <?php if ($wa_logo !== ''): ?>
            <img src="<?= $wa_esc($wa_logo) ?>" class="d-inline-block align-top me-2" style="height: 35px; width: auto;" alt="wanportal" />
        <?php else: ?>
            <span class="fw-semibold">wanportal</span>
        <?php endif; ?>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navigation items -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <!-- Public pages, matching the SPA's public set -->
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>"
                       href="/classic/"
                       <?php if ($current_page === 'index.php'): ?>aria-current="page"<?php endif; ?>>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'latency.php' ? 'active' : '' ?>"
                       href="/classic/latency.php"
                       <?php if ($current_page === 'latency.php'): ?>aria-current="page"<?php endif; ?>>
                        Latency
                    </a>
                </li>

                <?php $wa_render_items($wa_menu, false); ?>

                <!-- The door back to the new UI -->
                <li class="nav-item">
                    <a class="nav-link" href="/">New UI</a>
                </li>
            </ul>

            <!-- Right cluster: the API door sits left of the account
                 chip, as in the SPA's bar (swagger lives at /#/api). -->
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/#/api">API</a>
                </li>
            </ul>

            <!-- User section -->
            <?php if (isset($_SESSION['user'])): ?>
                <?php
                // The admin gate (Users) applies before rendering, the
                // way the SPA account menu hides Users for non-admins.
                $wa_visibleLinks = [];
                foreach ($wa_accountItems as $wa_link) {
                    if ($wa_link['admin'] && empty($_SESSION['is_admin'])) {
                        continue;
                    }
                    $wa_visibleLinks[] = $wa_link;
                }
                ?>
                <ul class="navbar-nav ms-2">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" 
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <?= $wa_esc((string) $_SESSION['user']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark" aria-labelledby="userDropdown">
                            <?php foreach ($wa_visibleLinks as $wa_link): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= $wa_esc($wa_link['url']) ?>">
                                        <?= $wa_esc($wa_link['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="/classic/index.php">
                                    <input type="hidden" name="csrf_token"
                                        value="<?= $wa_esc((string) ($_SESSION['csrf_token'] ?? '')) ?>" />
                                    <button name="logout" class="dropdown-item text-danger" type="submit">
                                        Log out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            <?php else: ?>
                <!-- Login button for unauthenticated users -->
                <ul class="navbar-nav ms-2">
                    <li class="nav-item">
                        <a href="/classic/login.php" class="btn btn-outline-light">
                            Log in
                        </a>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>