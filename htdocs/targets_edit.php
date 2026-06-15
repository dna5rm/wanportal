<?php
// targets_edit.php
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
$target = [
    'address' => '',
    'description' => '',
    'is_active' => true
];

// If editing, fetch existing target
if ($id) {
    $ch = curl_init("http://localhost/cgi-bin/api/target/$id");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $_SESSION['token']
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);


    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success' && isset($data['target'])) {
            $target = $data['target'];
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
        print("Form submitted: " . print_r($_POST, true) . "\n");

        // Collect form data
        $targetData = [
            'address' => $_POST['address'] ?? '',
            'description' => $_POST['description'] ?? '',
            'is_active' => isset($_POST['is_active'])
        ];

        print("Target data to send: " . json_encode($targetData) . "\n");

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
            // Update existing target
            curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/target/$id");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        } else {
            // Create new target
            curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/target");
            curl_setopt($ch, CURLOPT_POST, true);
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($targetData));
    
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Debug output

        curl_close($ch);

        if ($status === 200) {
            $data = json_decode($response, true);
            if ($data['status'] === 'success') {
                header('Location: /targets.php?saved=1');
                exit;
            } else {
                $error = $data['message'] ?? 'Unknown error occurred';
            }
        } else {
            $error = "Failed to save target (Status: $status, Response: $response)";
        }

        // If there was an error, keep the submitted data
        if ($error) {
            $target = $targetData;
        }

    }
}
wanportal_render_head(($id ? 'Edit' : 'New') . ' Target');
wanportal_render_header_row(($id ? 'Edit' : 'New') . ' Target', [
    [
        'url'     => '/targets.php',
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
                            <label for="address" class="form-label">Address *</label>
                            <input type="text" class="form-control" id="address" name="address" 
                                   value="<?= htmlspecialchars($target['address']) ?>"
                                   required>
                            <div class="form-text">
                                IPv4, IPv6 address, or hostname
                            </div>
                            <div class="invalid-feedback">
                                Valid address is required
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= htmlspecialchars($target['description']) ?>">
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                       <?= $target['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-save"></i> Save Target
                            </button>
                            <a href="/targets.php" class="btn btn-secondary btn-sm">
                                <i class="bi bi-x"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php wanportal_render_page_end(); ?>

<script>
// Form validation
(function () {
    'use strict'
    var forms = document.querySelectorAll('.needs-validation')
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})()

// Address validation
document.getElementById('address').addEventListener('input', function() {
    const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/;
    const ipv6Regex = /^(?:(?:[0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|(?:[0-9A-Fa-f]{1,4}:){1,7}:|(?:[0-9A-Fa-f]{1,4}:){1,6}:[0-9A-Fa-f]{1,4}|(?:[0-9A-Fa-f]{1,4}:){1,5}(?::[0-9A-Fa-f]{1,4}){1,2}|(?:[0-9A-Fa-f]{1,4}:){1,4}(?::[0-9A-Fa-f]{1,4}){1,3}|(?:[0-9A-Fa-f]{1,4}:){1,3}(?::[0-9A-Fa-f]{1,4}){1,4}|(?:[0-9A-Fa-f]{1,4}:){1,2}(?::[0-9A-Fa-f]{1,4}){1,5}|[0-9A-Fa-f]{1,4}:(?:(?::[0-9A-Fa-f]{1,4}){1,6})|:(?:(?::[0-9A-Fa-f]{1,4}){1,7}|:)|fe80:(?::[0-9A-Fa-f]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(?:ffff(?::0{1,4}){0,1}:){0,1}(?:(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])|(?:[0-9A-Fa-f]{1,4}:){1,4}:(?:(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9])\.){3,3}(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/;
    const hostnameRegex = /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;

    if (this.value && !ipv4Regex.test(this.value) && !ipv6Regex.test(this.value) && !hostnameRegex.test(this.value)) {
        this.setCustomValidity('Please enter a valid IP address or hostname');
    } else {
        this.setCustomValidity('');
    }
});
</script>