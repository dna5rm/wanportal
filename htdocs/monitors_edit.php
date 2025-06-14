<?php
// monitors_edit.php
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
$monitor = [
    'description' => '',
    'agent_id' => '',
    'target_id' => '',
    'protocol' => 'ICMP',
    'port' => '',
    'dscp' => 'BE',
    'pollcount' => 5,
    'pollinterval' => 60,
    'is_active' => true
];

// Fetch available agents
$ch = curl_init("http://localhost/cgi-bin/api/agents");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);
$response = curl_exec($ch);
$agents = [];
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $agents = $data['agents'];
    }
}
curl_close($ch);

// Fetch available targets
$ch = curl_init("http://localhost/cgi-bin/api/targets");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);
$response = curl_exec($ch);
$targets = [];
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $targets = $data['targets'];
    }
}
curl_close($ch);

// Define valid options
$protocols = ['ICMP', 'ICMPV6', 'TCP'];
$dscpValues = [
    'BE', 'EF', 
    'CS0', 'CS1', 'CS2', 'CS3', 'CS4', 'CS5', 'CS6', 'CS7',
    'AF11', 'AF12', 'AF13', 'AF21', 'AF22', 'AF23',
    'AF31', 'AF32', 'AF33', 'AF41', 'AF42', 'AF43'
];

// If editing, fetch existing monitor
if ($id) {
    $ch = curl_init("http://localhost/cgi-bin/api/monitor/$id");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $_SESSION['token']
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // print("Fetch monitor response: $response\n");
    // print("Fetch monitor status: $status\n");

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success' && isset($data['monitor'])) {
            $monitor = $data['monitor'];
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    print("Form submitted: " . print_r($_POST, true) . "\n");

    // Collect form data
    $monitorData = [
        'description' => $_POST['description'] ?? '',
        'agent_id' => $_POST['agent_id'] ?? '',
        'target_id' => $_POST['target_id'] ?? '',
        'protocol' => $_POST['protocol'] ?? 'ICMP',
        'dscp' => $_POST['dscp'] ?? 'BE',
        'is_active' => isset($_POST['is_active'])
    ];

    // Add port if protocol is TCP
    if ($_POST['protocol'] === 'TCP' && isset($_POST['port'])) {
        $monitorData['port'] = intval($_POST['port']);
    }

    // Add polling parameters only for new monitors
    if (!$id) {
        $monitorData['pollcount'] = intval($_POST['pollcount'] ?? 5);
        $monitorData['pollinterval'] = intval($_POST['pollinterval'] ?? 60);
    }

    print("Monitor data to send: " . json_encode($monitorData) . "\n");

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
        // Update existing monitor
        curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/monitor/$id");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    } else {
        // Create new monitor
        curl_setopt($ch, CURLOPT_URL, "http://localhost/cgi-bin/api/monitor");
        curl_setopt($ch, CURLOPT_POST, true);
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($monitorData));
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    print("API Response Status: $status\n");
    print("API Response: $response\n");

    curl_close($ch);

    if ($status === 200) {
        $data = json_decode($response, true);
        if ($data['status'] === 'success') {
            header('Location: /monitors.php');
            exit;
        } else {
            $error = $data['message'] ?? 'Unknown error occurred';
        }
    } else {
        $error = "Failed to save monitor (Status: $status, Response: $response)";
    }

    // If there was an error, keep the submitted data
    if ($error) {
        $monitor = $monitorData;
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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= $id ? 'Edit' : 'New' ?> Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3><?= $id ? 'Edit' : 'New' ?> Monitor</h3>
        </div>
        <div class="col text-end">
            <a href="/monitors.php" class="btn btn-secondary">
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
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= htmlspecialchars($monitor['description']) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="agent_id" class="form-label">Agent *</label>
                            <select class="form-select" id="agent_id" name="agent_id" required>
                                <option value="">Select Agent...</option>
                                <?php foreach ($agents as $agent): ?>
                                    <?php if ($agent['is_active']): ?>
                                        <option value="<?= htmlspecialchars($agent['id']) ?>"
                                                <?= $monitor['agent_id'] === $agent['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($agent['name']) ?>
                                            (<?= htmlspecialchars($agent['address']) ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                                Please select an agent
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="target_id" class="form-label">Target *</label>
                            <select class="form-select" id="target_id" name="target_id" required>
                                <option value="">Select Target...</option>
                                <?php foreach ($targets as $target): ?>
                                    <?php if ($target['is_active']): ?>
                                        <option value="<?= htmlspecialchars($target['id']) ?>"
                                                <?= $monitor['target_id'] === $target['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($target['address']) ?>
                                            <?= $target['description'] ? ' (' . htmlspecialchars($target['description']) . ')' : '' ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                                Please select a target
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="protocol" class="form-label">Protocol *</label>
                            <select class="form-select" id="protocol" name="protocol" required>
                                <?php foreach ($protocols as $protocol): ?>
                                    <option value="<?= htmlspecialchars($protocol) ?>"
                                            <?= $monitor['protocol'] === $protocol ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($protocol) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="portGroup" style="display: none;">
                            <label for="port" class="form-label">Port *</label>
                            <input type="number" class="form-control" id="port" name="port" 
                                   value="<?= htmlspecialchars($monitor['port']) ?>"
                                   min="1" max="65535">
                            <div class="invalid-feedback">
                                Port must be between 1 and 65535
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="dscp" class="form-label">DSCP</label>
                            <select class="form-select" id="dscp" name="dscp">
                                <?php foreach ($dscpValues as $dscp): ?>
                                    <option value="<?= htmlspecialchars($dscp) ?>"
                                            <?= $monitor['dscp'] === $dscp ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dscp) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!$id): // Only show for new monitors ?>
                            <div class="mb-3">
                                <label for="pollcount" class="form-label">Poll Count</label>
                                <input type="number" class="form-control" id="pollcount" name="pollcount" 
                                       value="<?= htmlspecialchars($monitor['pollcount']) ?>"
                                       min="1" max="100">
                                <div class="form-text">
                                    Number of polls per interval (1-100)
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="pollinterval" class="form-label">Poll Interval (seconds)</label>
                                <input type="number" class="form-control" id="pollinterval" name="pollinterval" 
                                       value="<?= htmlspecialchars($monitor['pollinterval']) ?>"
                                       min="10" max="3600">
                                <div class="form-text">
                                    Time between polls in seconds (10-3600)
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                       <?= $monitor['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Save Monitor
                            </button>
                            <a href="/monitors.php" class="btn btn-secondary">
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
// Show/hide port field based on protocol
document.getElementById('protocol').addEventListener('change', function() {
    const portGroup = document.getElementById('portGroup');
    const portInput = document.getElementById('port');
    if (this.value === 'TCP') {
        portGroup.style.display = 'block';
        portInput.required = true;
    } else {
        portGroup.style.display = 'none';
        portInput.required = false;
        portInput.value = '';
    }
});

// Trigger initial protocol check
document.getElementById('protocol').dispatchEvent(new Event('change'));

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
</script>
</body>
</html>