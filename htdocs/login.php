<?php
session_start();
require_once 'config.php';

// Check if user already logged in
if (isset($_SESSION['user'])) {
    // user is logged in, continue
    return;
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_errno) {
        die("DB connection failed: " . $mysqli->connect_error);
    }

    $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE username=?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($pw_hash_db);
    $found = $stmt->fetch();
    $stmt->close();
    $mysqli->close();

    if ($found && hash('sha256', $password) === $pw_hash_db) {
        $_SESSION['user'] = $username;
        // Now user is logged in
        // Continue executing the parent page
    } else {
        // Login failed, show form again
        $error = "Invalid username or password";
    }
}

// If user is not logged in, show login form
if (!isset($_SESSION['user'])) { ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow" style="min-width:320px;max-width:340px;">
        <div class="card-body">
            <h4 class="mb-4 text-center">Login</h4>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger py-1"><?=$error?></div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input name="username" class="form-control" autofocus required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input name="password" type="password" class="form-control" required>
                </div>
                <button class="btn btn-primary w-100" type="submit">Login</button>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php exit; } ?>