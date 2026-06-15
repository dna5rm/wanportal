<?php
// agents_edit.php
session_start();
require_once 'check_session.php';
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';


// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Edit existing agent (if id) or create a blank one.
$id = $_GET['id'] ?? null;
$error = '';
$success = '';
$agent = [
    'name' => '',
    'address' => '',
    'description' => '',
    'password' => '',
    'is_active' => true
];

if ($id) {
    $ch = curl_init("http://localhost/cgi-bin/api/agent/$id");
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
        if ($data['status'] === 'success' && isset($data['agent'])) {
            $agent = $data['agent'];
        }
    }
}

// Form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wanportal_csrf_valid()) {
        $error = 'Invalid CSRF token. Please reload the page and try again.';
    } else {
        $agentData = [
            'name' => $_POST['name'] ?? '',
            'address' => $_POST['address'] ?? '',
            'description' => $_POST['description'] ?? '',
            'is_active' => isset($_POST['is_active'])
        ];

        if (!empty($_POST['password'])) {
            $agentData['password'] = $_POST['password'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $_SESSION['token'],
                "Content-Type: application/json"
            ]
        ]);

        if ($id) {
            curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/agent/$id");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        } else {
            curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/agent");
            curl_setopt($ch, CURLOPT_POST, true);
        }

        $postData = json_encode($agentData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($status === 200) {
            $data = json_decode($response, true);
            if ($data['status'] === 'success') {
                header('Location: /agents.php?saved=1');
                exit;
            } else {
                $error = $data['message'] ?? 'Unknown error occurred';
            }
        } else {
            $error = "Failed to save agent (Status: $status, Response: $response)";
        }

        // On error, keep the submitted data so the form re-renders with what the user typed.
        if ($error) {
            $agent = $agentData;
        }

    }
}
wanportal_render_head(($id ? 'Edit' : 'New') . ' Agent');
wanportal_render_header_row(($id ? 'Edit' : 'New') . ' Agent', [
    [
        'url'     => '/agents.php',
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
                            <label for="name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   value="<?= htmlspecialchars($agent['name']) ?>"
                                   <?= ($id && $agent['name'] === 'LOCAL') ? 'readonly' : '' ?>
                                   required>
                            <div class="invalid-feedback">
                                Name is required
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label">Address *</label>
                            <input type="text" class="form-control" id="address" name="address" 
                                   value="<?= htmlspecialchars($agent['address']) ?>"
                                   required>
                            <div class="form-text">
                                IPv4 or IPv6 address
                            </div>
                            <div class="invalid-feedback">
                                Valid IP address is required
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= htmlspecialchars($agent['description']) ?>">
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
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                       <?= $agent['is_active'] ? 'checked' : '' ?>
                                       <?= ($id && $agent['name'] === 'LOCAL') ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-save"></i> Save Agent
                            </button>
                            <a href="/agents.php" class="btn btn-secondary btn-sm">
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

// Bootstrap needs-validation handler.
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

// IP address validation: IPv4 or IPv6 only (no hostnames -- the
// agent needs a routable address, not a DNS name).
document.getElementById('address').addEventListener('input', function() {
    const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/;
    const ipv6Regex = /^(?:(?:[0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|(?:[0-9A-Fa-f]{1,4}:){1,7}:|(?:[0-9A-Fa-f]{1,4}:){1,6}:[0-9A-Fa-f]{1,4}|(?:[0-9A-Fa-f]{1,4}:){1,5}(?::[0-9A-Fa-f]{1,4}){1,2}|(?:[0-9A-Fa-f]{1,4}:){1,4}(?::[0-9A-Fa-f]{1,4}){1,3}|(?:[0-9A-Fa-f]{1,4}:){1,3}(?::[0-9A-Fa-f]{1,4}){1,4}|(?:[0-9A-Fa-f]{1,4}:){1,2}(?::[0-9A-Fa-f]{1,4}){1,5}|[0-9A-Fa-f]{1,4}:(?:(?::[0-9A-Fa-f]{1,4}){1,6})|:(?:(?::[0-9A-Fa-f]{1,4}){1,7}|:)|fe80:(?::[0-9A-Fa-f]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(?:ffff(?::0{1,4}){0,1}:){0,1}(?:(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])|(?:[0-9A-Fa-f]{1,4}:){1,4}:(?:(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(?:25[0-5]|(?:2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/;
    
    if (this.value && !ipv4Regex.test(this.value) && !ipv6Regex.test(this.value)) {
        this.setCustomValidity('Please enter a valid IP address');
    } else {
        this.setCustomValidity('');
    }
});
</script>
</body>
</html>