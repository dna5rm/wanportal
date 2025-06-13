<?php
// Start session only if it hasn't been started already to avoid warnings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$menuItems = [
    'Home' => '/',
    'phpinfo' => '/phpinfo.php',
    // Add more menu items here as needed
];

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}
?>

<nav class="navbar navbar-expand navbar-dark mb-4" style="background-color: #000080; box-shadow: 0 3px 10px #000000;">
    <div class="container-fluid">
        <!-- Brand and toggler stay on the left -->
        <a class="navbar-brand" href="/">
            <!-- <img src="/assets/kinetic.svg" width="30" height="30" class="d-inline-block align-top" alt="" /> -->
            NetPing
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Collapse div with right-aligned menu items -->
        <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
            <ul class="navbar-nav">
                <?php foreach ($menuItems as $name => $url): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == $url ? 'active' : ''; ?>" href="<?php echo $url; ?>"><?php echo htmlspecialchars($name); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Conditionally display the Logout button -->
        <?php if (isset($_SESSION['user'])): ?>
            <form method="post" class="ms-auto">
                <button name="logout" class="btn btn-danger btn-sm" type="submit">Logout</button>
            </form>
        <?php endif; ?>
    </div>
</nav>