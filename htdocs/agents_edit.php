<?php
// agents_edit.php
session_start();
require_once 'config.php';

// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Initialize variables
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

// If editing, fetch existing agent
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug output to console
    print("Form submitted: " . print_r($_POST, true) . "\n");

    // Collect form data
    $agentData = [
        'name' => $_POST['name'] ?? '',
        'address' => $_POST['address'] ?? '',
        'description' => $_POST['description'] ?? '',
        'is_active' => isset($_POST['is_active'])
    ];

    // Add password if provided
    if (!empty($_POST['password'])) {
        $agentData['password'] = $_POST['password'];
    }

    // Debug output
    print("Agent data to send: " . json_encode($agentData) . "\n");

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
        // Update existing agent
        curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/agent/$id");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    } else {
        // Create new agent
        curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/agent");
        curl_setopt($ch, CURLOPT_POST, true);
    }

    $postData = json_encode($agentData);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Debug output
    print("API Response Status: $status\n");
    print("API Response: $response\n");

    curl_close($ch);

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success') {
            header('Location: /agents.php');
            exit;
        } else {
            $error = $data['message'] ?? 'Unknown error occurred';
        }
    } else {
        $error = "Failed to save agent (Status: $status, Response: $response)";
    }

    // If there was an error, keep the submitted data
    if ($error) {
        $agent = $agentData;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= $id ? 'Edit' : 'New' ?> Agent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3><?= $id ? 'Edit' : 'New' ?> Agent</h3>
        </div>
        <div class="col text-end">
            <a href="/agents.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

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
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
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
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Save Agent
                            </button>
                            <a href="/agents.php" class="btn btn-secondary">
                                <i class="bi bi-x"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
// Password visibility toggle
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

// IP address validation
document.getElementById('address').addEventListener('input', function() {
    const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/;
    const ipv6Regex = /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;
    
    if (this.value && !ipv4Regex.test(this.value) && !ipv6Regex.test(this.value)) {
        this.setCustomValidity('Please enter a valid IP address');
    } else {
        this.setCustomValidity('');
    }
});
</script>
</body>
</html>