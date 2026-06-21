<?php
// user_edit.php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
require_once 'check_session.php';


// Check authentication and admin status
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Check if user is admin
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /');  // Redirect to home page
    exit;
}

// Initialize variables
$id = $_GET['id'] ?? null;
$error = '';
$success = '';
$user = [
    'username' => '',
    'full_name' => '',
    'email' => '',
    'is_admin' => false,
    'is_active' => true
];

// If editing, fetch existing user
if ($id) {
    $ch = curl_init("http://localhost/cgi-bin/api/users/$id");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $_SESSION['token'],
            "Content-Type: application/json"
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Debug output

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success' && isset($data['user'])) {
            $user = $data['user'];
            // Convert boolean fields from integers if necessary
            $user['is_admin'] = (bool)$user['is_admin'];
            $user['is_active'] = (bool)$user['is_active'];
        } else {
            $error = $data['message'] ?? 'Failed to fetch user data';
        }
    } else {
        $error = 'Failed to fetch user data (Status: ' . $status . ')';
    }
}

if ($id) {
    error_log("User ID: " . $id);
    error_log("API Status: " . $status);
    error_log("Raw Response: " . $response);
    error_log("Decoded User Data: " . print_r($user, true));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wanportal_csrf_valid()) {
        $error = 'Invalid CSRF token. Please reload the page and try again.';
    } else {
        // Collect form data
        $userData = [
            'username' => $_POST['username'] ?? '',
            'full_name' => $_POST['full_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'is_admin' => isset($_POST['is_admin']),
            'is_active' => isset($_POST['is_active'])
        ];

        // Add password if provided
        if (!empty($_POST['password'])) {
            $userData['password'] = $_POST['password'];
        }

        // Validate required fields
        if (empty($userData['username'])) {
            $error = 'Username is required.';
        } else {
            // Make API request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $_SESSION['token'],
                    "Content-Type: application/json"
                ]
            ]);

            if ($id) {
                // Update existing user
                curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/users/$id");
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            } else {
                // Create new user
                curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/users");
                curl_setopt($ch, CURLOPT_POST, true);
            
                // Password required for new users
                if (empty($userData['password'])) {
                    $error = 'Password is required for new users.';
                }
            }

            if (!$error) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userData));
            
                $response = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($status === 200) {
                    $data = json_decode($response, true);
                    if ($data['status'] === 'success') {
                        header('Location: /users.php?saved=1');
                        exit;
                    } else {
                        $error = $data['message'] ?? 'Unknown error occurred';
                    }
                } else {
                    $error = 'Failed to save user';
                }
            }
        }

        // If there was an error, keep the submitted data
        if ($error) {
            $user = $userData;
        }

    }
}
wanportal_render_head(($id ? 'Edit' : 'New') . ' User');
wanportal_render_header_row(($id ? 'Edit' : 'New') . ' User', [
    [
        'url'     => '/users.php',
        'icon'    => 'bi bi-arrow-left',
        'label'   => 'Back',
        'variant' => 'secondary',
    ],
]);
?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                        <div class="mb-3">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?= htmlspecialchars($user['username']) ?>"
                                   <?= ($id && $user['username'] === 'admin') ? 'readonly' : '' ?>
                                   required>
                            <div class="invalid-feedback">
                                Username is required
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">
                                <?= $id ? 'Password (leave blank to keep current)' : 'Password *' ?>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password"
                                       <?= $id ? '' : 'required' ?>>
                                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="togglePassword('password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">
                                Password must be at least 8 characters and contain letters and numbers
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   value="<?= htmlspecialchars($user['full_name']) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars($user['email']) ?>">
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" 
                                       <?= $user['is_admin'] ? 'checked' : '' ?>
                                       <?= ($id && $user['username'] === 'admin') ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="is_admin">
                                    Administrator
                                </label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                       <?= $user['is_active'] ? 'checked' : '' ?>
                                       <?= ($id && $user['username'] === 'admin') ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-save"></i> Save User
                            </button>
                            <a href="/users.php" class="btn btn-secondary btn-sm">
                                <i class="bi bi-x"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($id): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">User Information</h5>
                    <dl class="row">
                        <dt class="col-sm-4">Created</dt>
                        <dd class="col-sm-8">
                            <?= $user['created_at'] ? date('Y-m-d H:i', strtotime($user['created_at'])) : 'N/A' ?>
                            <?= $user['created_by'] ? ' by ' . htmlspecialchars($user['created_by']) : '' ?>
                        </dd>

                        <dt class="col-sm-4">Last Updated</dt>
                        <dd class="col-sm-8">
                            <?= $user['updated_at'] ? date('Y-m-d H:i', strtotime($user['updated_at'])) : 'Never' ?>
                            <?= $user['updated_by'] ? ' by ' . htmlspecialchars($user['updated_by']) : '' ?>
                        </dd>

                        <dt class="col-sm-4">Last Login</dt>
                        <dd class="col-sm-8">
                            <?= $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never' ?>
                        </dd>

                        <?php if (isset($user['failed_attempts']) && $user['failed_attempts'] > 0): ?>
                            <dt class="col-sm-4">Failed Attempts</dt>
                            <dd class="col-sm-8">
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?= $user['failed_attempts'] ?></span>
                            </dd>
                        <?php endif; ?>

                        <?php if (isset($user['locked_until']) && $user['locked_until']): ?>
                            <dt class="col-sm-4">Locked Until</dt>
                            <dd class="col-sm-8">
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                    <?= date('Y-m-d H:i', strtotime($user['locked_until'])) ?>
                                </span>
                            </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php wanportal_render_page_end(); ?>

<script>
// togglePassword and needs-validation are now in footer.php.
</script>