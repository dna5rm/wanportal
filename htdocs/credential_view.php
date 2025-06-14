<?php
// credential_view.php
session_start();
require_once 'config.php';

// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: /credentials.php');
    exit;
}

// Fetch credential details
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

// var_dump([
//     'API Response Status' => $status,
//     'Raw Response' => $response,
//     'Decoded Data' => json_decode($response, true),
//     'Session Token' => $_SESSION['token']
// ]);

curl_close($ch);

if ($status !== 200) {
    header('Location: /credentials.php');
    exit;
}

$data = json_decode($response, true);
if ($data['status'] !== 'success' || !isset($data['credential'])) {
    header('Location: /credentials.php');
    exit;
}

$cred = $data['credential'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: View Credential</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
    <style>
        .password-field {
            position: relative;
        }
        .password-field .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3>
                View Credential: <?= htmlspecialchars($cred['name']) ?>
                <span class="badge bg-<?= getBadgeColor($cred['type']) ?>"><?= htmlspecialchars($cred['type']) ?></span>
            </h3>
        </div>
        <div class="col text-end">
            <a href="/credential_edit.php?id=<?= htmlspecialchars($id) ?>" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <a href="/credentials.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Basic Information</h5>
                    <dl class="row">
                        <dt class="col-sm-3">Name</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['name']) ?></dd>

                        <dt class="col-sm-3">Site</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['site'] ?? 'N/A') ?></dd>

                        <dt class="col-sm-3">Type</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['type']) ?></dd>

                        <dt class="col-sm-3">Username</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['username']): ?>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($cred['username']) ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard(this.previousElementSibling)">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Password</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['password']): ?>
                                <div class="input-group">
                                    <input type="password" class="form-control" value="<?= htmlspecialchars($cred['password']) ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword(this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard(this.parentElement.querySelector('input'))">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">URL</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['url']): ?>
                                <a href="<?= htmlspecialchars($cred['url']) ?>" target="_blank">
                                    <?= htmlspecialchars($cred['url']) ?>
                                </a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Owner</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['owner'] ?? 'N/A') ?></dd>

                        <dt class="col-sm-3">Sensitivity</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-<?= getSensitivityColor($cred['sensitivity']) ?>">
                                <?= htmlspecialchars($cred['sensitivity']) ?>
                            </span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Additional Information</h5>
                    <dl class="row">
                        <dt class="col-sm-3">Comment</dt>
                        <dd class="col-sm-9"><?= nl2br(htmlspecialchars($cred['comment'] ?? 'N/A')) ?></dd>

                        <dt class="col-sm-3">Metadata</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['metadata']): ?>
                                <pre class="bg-light p-2 rounded"><code><?= htmlspecialchars(json_encode(json_decode($cred['metadata']), JSON_PRETTY_PRINT)) ?></code></pre>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">System Information</h5>
                    <dl class="row">
                        <dt class="col-sm-3">Created</dt>
                        <dd class="col-sm-9">
                            <?= htmlspecialchars($cred['created_at']) ?>
                            by <?= htmlspecialchars($cred['created_by']) ?>
                        </dd>

                        <dt class="col-sm-3">Updated</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['updated_at']): ?>
                                <?= htmlspecialchars($cred['updated_at']) ?>
                                by <?= htmlspecialchars($cred['updated_by']) ?>
                            <?php else: ?>
                                Never
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Last Accessed</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['last_accessed_at']): ?>
                                <?= htmlspecialchars($cred['last_accessed_at']) ?>
                                by <?= htmlspecialchars($cred['last_accessed_by']) ?>
                            <?php else: ?>
                                Never
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
// Password visibility toggle
function togglePassword(button) {
    const input = button.parentElement.querySelector('input');
    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
    input.setAttribute('type', type);
    button.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
}

// Copy to clipboard function
function copyToClipboard(element) {
    element.select();
    document.execCommand('copy');
    
    // Show feedback on the clipboard button
    const button = element.parentElement.querySelector('.bi-clipboard').parentElement;
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="bi bi-check"></i>';
    setTimeout(() => {
        button.innerHTML = originalHTML;
    }, 1000);
}

<?php
function getBadgeColor($type) {
    $colors = [
        'ACCOUNT' => 'primary',
        'CERTIFICATE' => 'success',
        'API' => 'info',
        'PSK' => 'warning',
        'CODE' => 'secondary'
    ];
    return $colors[$type] ?? 'secondary';
}

function getSensitivityColor($sensitivity) {
    $colors = [
        'LOW' => 'success',
        'MEDIUM' => 'warning',
        'HIGH' => 'danger',
        'CRITICAL' => 'dark'
    ];
    return $colors[$sensitivity] ?? 'secondary';
}
?>
</script>
</body>
</html>