<?php
// Helper function for badge colors (PHP version) -- defined
// here rather than in lib/page.php because it's only used by
// the credentials page and its two associated pages
// (credential_edit.php, credential_view.php).
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

// credentials.php - Main listing page
session_start();
require_once 'check_session.php';
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';

// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Fetch credentials from API
$ch = curl_init();
$is_active = isset($_GET['is_active']) ? $_GET['is_active'] : '1'; // Default to active credentials
$url = "http://localhost/cgi-bin/api/credentials?is_active=" . $is_active;

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $_SESSION['token'],
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status === 401) {
    header('Location: /login.php');
    exit;
}

$credentials = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $credentials = $data['credentials'];
    }
}

// Standard page chrome + header row. DataTables is used here so
// we pass 'datatables' => true in the head options. The "New
// Credential" action matches the listing-page convention
// documented in the wanportal skill: a primary button on the
// right of the header row, gated on auth.
wanportal_render_head('Credentials Management', ['datatables' => true]);
wanportal_render_header_row('Credentials Management', [
    [
        'url'     => '/credential_edit.php',
        'icon'    => 'bi bi-plus-circle',
        'label'   => 'New Credential',
        'variant' => 'primary',
        'auth'    => true,
    ],
]);
?>

    <!-- Filters -->
    <div class="row mb-3">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <select id="typeFilter" class="form-select">
                                <option value="">All Types</option>
                                <option value="ACCOUNT">Account</option>
                                <option value="CERTIFICATE">Certificate</option>
                                <option value="API">API Key</option>
                                <option value="PSK">Pre-Shared Key</option>
                                <option value="CODE">Code/License</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" id="siteFilter" class="form-control" placeholder="Filter by site...">
                        </div>
<div class="col-md-3">
    <select id="activeFilter" class="form-select form-select-sm">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Credentials Table -->
    <div class="table-responsive">
        <table id="tablePager" class="table table-bordered table-striped table-hover <?= ($is_active == '0') ? 'table-dark' : '' ?> rounded-bottom">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="text-center">Type</th>
                    <th>Site</th>
                    <th class="text-center">Username</th>
                    <th class="text-center">Owner</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($credentials as $cred): ?>
                    <tr class="<?= $cred['is_active'] ? '' : 'table-secondary' ?>">
                        <td>
                            <a href="/credential_view.php?id=<?= htmlspecialchars($cred['id']) ?>"
                               class="text-decoration-none"
                               data-bs-toggle="tooltip"
                               title="<?= htmlspecialchars($cred['comment']) ?>">
                                <?= htmlspecialchars($cred['name']) ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= getBadgeColor($cred['type']) ?>">
                                <?= htmlspecialchars($cred['type']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($cred['site']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($cred['username']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($cred['owner']) ?></td>
                        <td>
                            <?php if ($cred['updated_at']): ?>
                                <span data-bs-toggle="tooltip" 
                                      title="Updated by: <?= htmlspecialchars($cred['updated_by']) ?>">
                                    <?= htmlspecialchars($cred['updated_at']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="/credential_view.php?id=<?= htmlspecialchars($cred['id']) ?>" 
                                   class="btn btn-sm btn-outline-primary"
                                   title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="/credential_edit.php?id=<?= htmlspecialchars($cred['id']) ?>" 
                                   class="btn btn-sm btn-outline-secondary"
                                   title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" 
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="deleteCredential('<?= htmlspecialchars($cred['id']) ?>')"
                                        title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php wanportal_render_page_end(); ?>

<script>
// Filter functionality
document.getElementById('typeFilter').addEventListener('change', filterCredentials);
document.getElementById('siteFilter').addEventListener('input', filterCredentials);
document.getElementById('activeFilter').addEventListener('change', function() {
    // Debug log
    console.log('Changing active filter to:', this.value);

    // Construct new URL with the is_active parameter
    let url = new URL(window.location.href);
    url.searchParams.set('is_active', this.value);

    // Debug log
    console.log('Redirecting to:', url.toString());

    // Redirect to new URL
    window.location.href = url.toString();
});

// Helper function for badge colors
function getBadgeColor(type) {
    const colors = {
        'ACCOUNT': 'primary',
        'CERTIFICATE': 'success',
        'API': 'info',
        'PSK': 'warning',
        'CODE': 'secondary'
    };
    return colors[type] || 'secondary';
}

function filterCredentials() {
    const type = document.getElementById('typeFilter').value.toLowerCase();
    const site = document.getElementById('siteFilter').value.toLowerCase();

    const rows = document.querySelectorAll('tbody tr');

    rows.forEach(row => {
        const typeMatch = !type || row.querySelector('td:nth-child(2)').textContent.toLowerCase().includes(type);
        const siteMatch = !site || row.querySelector('td:nth-child(3)').textContent.toLowerCase().includes(site);

        row.style.display = (typeMatch && siteMatch) ? '' : 'none';
    });
}

// Delete confirmation
function deleteCredential(id) {
    if (confirm('Are you sure you want to delete this credential?')) {
        fetch(`/cgi-bin/api/credentials/${id}`, {
            method: 'DELETE',
            headers: {
                'Authorization': 'Bearer <?= $_SESSION['token'] ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('Error deleting credential: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}

// Initialize filters on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set initial filter states
    const urlParams = new URLSearchParams(window.location.search);
    const isActive = urlParams.get('is_active') || '1';
    document.getElementById('activeFilter').value = isActive;

    // Apply initial filtering
    filterCredentials();
});
</script>