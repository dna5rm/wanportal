<?php
// targets_edit.php
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

    // print("Fetch target response: $response\n");
    // print("Fetch target status: $status\n");

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success' && isset($data['target'])) {
            $target = $data['target'];
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    print("API Response Status: $status\n");
    print("API Response: $response\n");

    curl_close($ch);

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success') {
            header('Location: /targets.php');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= $id ? 'Edit' : 'New' ?> Target</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3><?= $id ? 'Edit' : 'New' ?> Target</h3>
        </div>
        <div class="col text-end">
            <a href="/targets.php" class="btn btn-secondary">
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
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Save Target
                            </button>
                            <a href="/targets.php" class="btn btn-secondary">
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
    const ipv6Regex = /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;
    const hostnameRegex = /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
    
    if (this.value && !ipv4Regex.test(this.value) && !ipv6Regex.test(this.value) && !hostnameRegex.test(this.value)) {
        this.setCustomValidity('Please enter a valid IP address or hostname');
    } else {
        this.setCustomValidity('');
    }
});
</script>
</body>
</html>