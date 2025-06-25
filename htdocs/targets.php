<?php
session_start();
require_once 'config.php';

// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Handle show_inactive preference
$show_inactive = isset($_GET['show_inactive']) ?
    filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN) :
    ($_SESSION['show_inactive'] ?? false);
$_SESSION['show_inactive'] = $show_inactive;

// Fetch targets from API
$ch = curl_init("http://localhost/cgi-bin/api/targets");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$targets = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $targets = $data['targets'];
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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Targets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3>Target Management</h3>
        </div>
        <div class="col text-end">
            <a href="/targets_edit.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> New Target
            </a>
        </div>
    </div>

    <!-- Search/Filter -->
    <div class="row mb-3">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" id="searchFilter" class="form-control" placeholder="Search targets...">
                        </div>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="showInactive"
                                    <?= $show_inactive ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showInactive">
                                    Show Inactive
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Targets Table -->
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($targets)): ?>
                    <tr>
                        <td colspan="4" class="text-center">No targets found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($targets as $target): ?>
                        <tr class="<?= $target['is_active'] ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/target.php?id=<?= htmlspecialchars($target['id']) ?>" class="btn btn-sm">
                                    <i class="bi bi-arrow-bar-left"></i>
                                </a> <?= htmlspecialchars($target['address']) ?></td>
                            <td><?= htmlspecialchars($target['description']) ?></td>
                            <td>
                                <span class="badge bg-<?= $target['is_active'] ? 'success' : 'warning' ?>">
                                    <?= $target['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/targets_edit.php?id=<?= htmlspecialchars($target['id']) ?>" 
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="deleteTarget('<?= htmlspecialchars($target['id']) ?>', '<?= htmlspecialchars($target['address']) ?>')"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
// Filter functionality
document.getElementById('searchFilter').addEventListener('input', filterTargets);
document.getElementById('showInactive').addEventListener('change', function() {
    window.location.href = '?show_inactive=' + this.checked;
});

function filterTargets() {
    const search = document.getElementById('searchFilter').value.toLowerCase();
    const showInactive = document.getElementById('showInactive').checked;
    
    const rows = document.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        if (row.cells.length < 2) return; // Skip empty table messages
        
        const address = row.cells[0].textContent.toLowerCase();
        const description = row.cells[1].textContent.toLowerCase();
        
        // Check if row is inactive
        const isInactive = row.classList.contains('table-secondary');
        
        const searchMatch = address.includes(search) || 
                          description.includes(search);
        
        // Hide inactive rows unless showInactive is checked
        let shouldShow = searchMatch;
        if (isInactive && !showInactive) {
            shouldShow = false;
        }
        
        row.style.display = shouldShow ? '' : 'none';
    });
}

// Apply initial filtering on page load
window.addEventListener('DOMContentLoaded', function() {
    filterTargets();
});

// Delete confirmation
function deleteTarget(id, address) {
    if (confirm(`Are you sure you want to delete target "${address}"?\nThis will also delete all associated monitors and their RRD files.`)) {
        fetch(`/cgi-bin/api/target/${id}`, {
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
                alert('Error deleting target: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}
</script>
</body>
</html>