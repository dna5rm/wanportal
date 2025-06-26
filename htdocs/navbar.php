<?php
// Start session only if it hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    header('Location: /login.php');
    exit;
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Add Bootstrap Icons in head if not already loaded
if (!defined('NAVBAR_LOADED')): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?php define('NAVBAR_LOADED', true);
endif;

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar navbar-expand navbar-dark mb-4" style="background-color: #102444; box-shadow: 0 3px 10px #000000;">
    <div class="container-fluid">
        <!-- Brand and toggler -->
        <a class="navbar-brand d-flex align-items-center" href="/">
            <img src="/assets/logo_white_trsprnt.png" class="d-inline-block align-top me-2" style="width: 122px;" alt="Logo" />
            <!-- <span><?= htmlspecialchars(strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING')) ?></span> -->
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navigation items -->
        <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
            <ul class="navbar-nav">
                <?php foreach ($menuItems as $name => $item): ?>
                    <?php
                    // Skip if authentication required and user not logged in
                    if (isset($item['auth']) && $item['auth'] && !isset($_SESSION['user'])) continue;
                    
                    // For regular menu items, skip if admin required and user is not admin
                    if (isset($item['admin']) && $item['admin'] && !isset($_SESSION['is_admin'])) continue;
                    
                    // For dropdowns, check if there are any visible items before showing the dropdown
                    if (isset($item['type']) && $item['type'] === 'dropdown') {
                        $hasVisibleItems = false;
                        foreach ($item['items'] as $dropdownItem) {
                            if ((!isset($dropdownItem['auth']) || !$dropdownItem['auth'] || isset($_SESSION['user'])) &&
                                (!isset($dropdownItem['admin']) || !$dropdownItem['admin'] || isset($_SESSION['is_admin']))) {
                                $hasVisibleItems = true;
                                break;
                            }
                        }
                        if (!$hasVisibleItems) continue;
                    }
                    ?>
                    
                    <?php if (isset($item['type']) && $item['type'] === 'dropdown'): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" 
                               data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if (isset($item['icon'])): ?>
                                    <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($name) ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark">
                                <?php foreach ($item['items'] as $dropdownName => $dropdownItem): ?>
                                    <?php
                                    // Skip if not authenticated
                                    if (isset($dropdownItem['auth']) && $dropdownItem['auth'] && !isset($_SESSION['user'])) {
                                        continue;
                                    }
                                    
                                    // Skip if admin required but user is not admin
                                    if (isset($dropdownItem['admin']) && $dropdownItem['admin'] && empty($_SESSION['is_admin'])) {
                                        continue;
                                    }
                                    ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= htmlspecialchars($dropdownItem['url']) ?>">
                                            <?php if (isset($dropdownItem['icon'])): ?>
                                                <i class="<?= htmlspecialchars($dropdownItem['icon']) ?>"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($dropdownName) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === basename($item['url']) ? 'active' : '' ?>"
                               href="<?= htmlspecialchars($item['url']) ?>"
                               <?php if ($current_page === basename($item['url'])): ?>
                                   aria-current="page"
                               <?php endif; ?>>
                                <?php if (isset($item['icon'])): ?>
                                    <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($name) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>

            <!-- User section -->
            <?php if (isset($_SESSION['user'])): ?>
                <ul class="navbar-nav ms-3">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" 
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i>
                            <?= htmlspecialchars($_SESSION['user']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark" aria-labelledby="userDropdown">
                            <li>
                                <form method="post" action="/index.php" class="dropdown-item-text">
                                    <input type="hidden" name="csrf_token" 
                                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>" />
                                    <button name="logout" class="btn btn-danger w-100" type="submit">
                                        <i class="bi bi-box-arrow-right"></i> Logout
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            <?php else: ?>
                <!-- Login button for unauthenticated users -->
                <ul class="navbar-nav ms-3">
                    <li class="nav-item">
                        <a href="/login.php" class="btn btn-outline-light">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </a>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>
