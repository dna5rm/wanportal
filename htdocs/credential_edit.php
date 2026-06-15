<?php
// credential_edit.php
session_start();
require_once 'check_session.php';
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';


// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}
// Initialize variables
$id = $_GET['id'] ?? null;
$error = '';
$success = '';
$cred = [
    'name' => '',
    'type' => '',
    'site' => '',
    'username' => '',
    'password' => '',
    'url' => '',
    'owner' => '',
    'comment' => '',
    'sensitivity' => 'MEDIUM',
    'metadata' => '',
    'expiry_date' => '',
    'is_active' => true
];

// If editing, fetch existing credential
if ($id) {
    $ch = curl_init("http://localhost/cgi-bin/api/credentials/$id");
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

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success' && isset($data['credential'])) {
            $cred = $data['credential'];
            // Format metadata for display if it exists
            if ($cred['metadata']) {
                $cred['metadata'] = json_encode(json_decode($cred['metadata']), JSON_PRETTY_PRINT);
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (closes the cross-site form-submission
    // gap). The token is rendered as a hidden input by the
    // template below; this verifies the posted value
    // matches the session token.
    if (!wanportal_csrf_valid()) {
        $error = 'Invalid CSRF token. Please reload the page and try again.';
    } else {
        // Collect form data
        $cred = [
            'name' => $_POST['name'] ?? '',
            'type' => $_POST['type'] ?? '',
            'site' => $_POST['site'] ?? '',
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? '',
            'url' => $_POST['url'] ?? '',
            'owner' => $_POST['owner'] ?? '',
            'comment' => $_POST['comment'] ?? '',
            'sensitivity' => $_POST['sensitivity'] ?? 'MEDIUM',
            'metadata' => $_POST['metadata'] ?? '',
            'expiry_date' => $_POST['expiry_date'] ?? null,
            'is_active' => isset($_POST['is_active'])
        ];

        // Validate required fields
        if (empty($cred['name']) || empty($cred['type'])) {
            $error = 'Name and Type are required fields.';
        } else {
            // Validate metadata JSON if provided
            if (!empty($cred['metadata'])) {
                json_decode($cred['metadata']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $error = 'Invalid JSON in metadata field.';
                }
            }

            if (!$error) {
                // Prepare data for API
                $apiData = array_filter($cred, function($value) {
                    return $value !== '';
                });

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
                    // Update existing credential
                    curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/credentials/$id");
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                } else {
                    // Create new credential
                    curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/credentials");
                    curl_setopt($ch, CURLOPT_POST, true);
                }

                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
            
                $response = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($status === 200) {
                    $data = json_decode($response, true);
                    if ($data['status'] === 'success') {
                        header('Location: /credentials.php?saved=1');
                        exit;
                    } else {
                        $error = $data['message'] ?? 'Unknown error occurred';
                    }
                } else {
                    $error = 'Failed to save credential';
                }
            }
        }

    }
}
wanportal_render_head(($id ? 'Edit' : 'New') . ' Credential');
wanportal_render_header_row(($id ? 'Edit' : 'New') . ' Credential', [
    [
        'url'     => '/credentials.php',
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

    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Basic Information</h5>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Name *</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= htmlspecialchars($cred['name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="type" class="form-label">Type *</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="">Select Type...</option>
                            <?php
                            $types = ['ACCOUNT', 'CERTIFICATE', 'API', 'PSK', 'CODE'];
                            foreach ($types as $type):
                            ?>
                                <option value="<?= $type ?>" <?= $cred['type'] === $type ? 'selected' : '' ?>>
                                    <?= $type ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="site" class="form-label">Site</label>
                        <input type="text" class="form-control" id="site" name="site" 
                               value="<?= htmlspecialchars($cred['site']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?= htmlspecialchars($cred['username']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password/Key</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" 
                                   value="<?= htmlspecialchars($cred['password']) ?>">
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="togglePassword('password')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="url" class="form-label">URL</label>
                        <input type="url" class="form-control" id="url" name="url" 
                               value="<?= htmlspecialchars($cred['url']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Additional Information</h5>

                    <div class="mb-3">
                        <label for="owner" class="form-label">Owner</label>
                        <input type="text" class="form-control" id="owner" name="owner" 
                               value="<?= htmlspecialchars($cred['owner']) ?>">
                    </div>

                    <div class="mb-3">
                        <label for="sensitivity" class="form-label">Sensitivity</label>
                        <select class="form-select" id="sensitivity" name="sensitivity">
                            <?php
                            $levels = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
                            foreach ($levels as $level):
                            ?>
                                <option value="<?= $level ?>" <?= $cred['sensitivity'] === $level ? 'selected' : '' ?>>
                                    <?= $level ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="expiry_date" class="form-label">Expiry Date</label>
                        <input type="datetime-local" class="form-control" id="expiry_date" name="expiry_date" 
                               value="<?= $cred['expiry_date'] ? date('Y-m-d\TH:i', strtotime($cred['expiry_date'])) : '' ?>">
                    </div>

                    <div class="mb-3">
                        <label for="comment" class="form-label">Comment</label>
                        <textarea class="form-control" id="comment" name="comment" rows="3"><?= htmlspecialchars($cred['comment']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="metadata" class="form-label">Metadata (JSON)</label>
                        <textarea class="form-control font-monospace" id="metadata" name="metadata" rows="5"><?= htmlspecialchars($cred['metadata']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   <?= $cred['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 text-center mb-3">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-save"></i> Save Credential
            </button>
            <a href="/credentials.php" class="btn btn-secondary btn-sm">
                <i class="bi bi-x"></i> Cancel
            </a>
        </div>
    </form>
</div>

<?php wanportal_render_page_end(); ?>

<script>
// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling;
    if (field.type === 'password') {
        field.type = 'text';
        button.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        field.type = 'password';
        button.innerHTML = '<i class="bi bi-eye"></i>';
    }
}

// Format JSON in metadata field
document.getElementById('metadata').addEventListener('blur', function() {
    try {
        const json = JSON.parse(this.value);
        this.value = JSON.stringify(json, null, 2);
        this.classList.remove('is-invalid');
    } catch (e) {
        if (this.value.trim() !== '') {
            this.classList.add('is-invalid');
        }
    }
});

// Dynamic form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const metadata = document.getElementById('metadata');
    if (metadata.value.trim() !== '') {
        try {
            JSON.parse(metadata.value);
        } catch (e) {
            e.preventDefault();
            alert('Invalid JSON in metadata field');
            metadata.focus();
        }
    }
});
</script>