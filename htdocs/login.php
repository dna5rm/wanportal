<?php
require_once 'config.php';
wanportal_session_start();
// Check if user already logged in
if (isset($_SESSION['user'])) {
    header('Location: /index.php');
    exit;
}

// Initialize variables
$error = '';
$username = '';

// Handle login POST with CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid session, please try again";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Basic input validation
        if (empty($username) || empty($password)) {
            $error = "Username and password are required";
        } else {
            // Call the API login endpoint
            $ch = curl_init('http://localhost/cgi-bin/api/login');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'username' => $username,
                    'password' => $password
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ]);

            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status === 200) {
                $data = json_decode($response, true);
                if ($data['status'] === 'success' && isset($data['token'])) {
                    // Decode the JWT token to get the claims
                    $tokenParts = explode('.', $data['token']);
                    $payload = json_decode(base64_decode($tokenParts[1]), true);
                    
                    error_log("Login payload: " . print_r($payload, true));  // Debug output
                    
                    $_SESSION['user'] = $username;
                    $_SESSION['token'] = $data['token'];
                    $_SESSION['is_admin'] = $payload['is_admin'] ?? false;
                    $_SESSION['last_activity'] = time();
                    
                    error_log("Session after login: " . print_r($_SESSION, true));  // Debug output
                    
                    header('Location: /index.php');
                    exit;
                }
            }
            $error = "Invalid username or password";
            sleep(1);
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/assets/base.css">
    <script>
        // Apply the persisted dark-mode choice *before* paint so dark-mode
        // users don't see a flash of the light background. The full toggle
        // and persistence lives in footer.php; this just mirrors its
        // restore-from-localStorage step synchronously in <head>.
        (function () {
            try {
                if (localStorage.getItem('wanportal-theme') === 'dark') {
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                }
            } catch (e) { /* localStorage may be disabled; default to light */ }
        })();
    </script>
    <style>
        .login-container {
            min-height: calc(100vh - 72px); /* Account for navbar height */
            /* Use Bootstrap's --bs-body-bg so the page area matches the
               body color and blends with the navbar in both themes.
               The previous --bs-tertiary-bg created a hard 1px line at
               the navbar boundary (#f8f9fa in light, #2b3035 in dark
               against the navy navbar) that read as a separator even
               after we removed the box-shadow. */
            background-color: var(--bs-body-bg);
        }
        .login-card {
            min-width: 320px;
            max-width: 340px;
        }
        .login-card .card-body {
            padding: 2rem;
        }
        .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(14, 118, 168, 0.25);
            border-color: #0e76a8;
        }
        .btn-primary {
            background-color: #0e76a8;
            border-color: #0e76a8;
        }
        .btn-primary:hover {
            background-color: #0c6590;
            border-color: #0c6590;
        }
        /* Dark-mode: soften the card shadow and use the same darker
           btn-primary shade that the rest of the app uses. */
        [data-bs-theme="dark"] .login-card {
            --bs-card-bg: var(--bs-body-bg);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5) !important;
        }
        [data-bs-theme="dark"] .btn-primary {
            background-color: #0c6590;
            border-color: #0c6590;
        }
        [data-bs-theme="dark"] .btn-primary:hover {
            background-color: #0a5378;
            border-color: #0a5378;
        }
        .alert {
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="login-container d-flex align-items-center justify-content-center">
        <div class="login-card card shadow">
            <div class="card-body">
                <h4 class="mb-4 text-center">Login</h4>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2">
                        <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>" />
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               value="<?= htmlspecialchars($username) ?>"
                               required 
                               autofocus />
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               required />
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right"></i> Login
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
</body>
</html>
<?php 
// Clear sensitive data
$password = null;
$mysqli->close();
?>