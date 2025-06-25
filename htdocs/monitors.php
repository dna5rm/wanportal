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

// Fetch monitors from API (includes agent and target info)
$ch = curl_init("http://localhost/cgi-bin/api/monitors");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$monitors = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $monitors = $data['monitors'];
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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Monitors</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3>Monitor Management</h3>
        </div>
        <div class="col text-end">
            <a href="/monitors_edit.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> New Monitor
            </a>
        </div>
    </div>

    <!-- Search/Filter -->
    <div class="row mb-3">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <input type="text" id="searchFilter" class="form-control" placeholder="Search monitors...">
                        </div>
                        <div class="col-md-2">
                            <select id="protocolFilter" class="form-select">
                                <option value="">All Protocols</option>
                                <option value="ICMP">ICMP</option>
                                <option value="ICMPV6">ICMPv6</option>
                                <option value="TCP">TCP</option>
                            </select>
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

    <!-- Monitors Table -->
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Agent</th>
                    <th>Target</th>
                    <th>Protocol</th>
                    <th>Port</th>
                    <th>DSCP</th>
                    <th>Status</th>
                    <th>Last Update</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($monitors)): ?>
                    <tr>
                        <td colspan="9" class="text-center">No monitors found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($monitors as $monitor): ?>
                        <?php
                        // Calculate effective active status
                        $effectively_active = ($monitor['is_active'] == 1 && 
                                           $monitor['agent_is_active'] == 1 && 
                                           $monitor['target_is_active'] == 1);
                        ?>
                        <tr class="<?= $effectively_active ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="btn btn-sm">
                                    <i class="bi bi-arrow-bar-left"></i>
                                </a> <?= htmlspecialchars($monitor['description']) ?></td>
                            <td>
                                <a href="/agents_edit.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>"
                                   class="<?= $monitor['agent_is_active'] == 1 ? '' : 'text-muted' ?>">
                                    <?= htmlspecialchars($monitor['agent_name']) ?>
                                    <?= $monitor['agent_is_active'] == 1 ? '' : ' (disabled)' ?>
                                </a>
                            </td>
                            <td>
                                <a href="/targets_edit.php?id=<?= htmlspecialchars($monitor['target_id']) ?>"
                                   class="<?= $monitor['target_is_active'] == 1 ? '' : 'text-muted' ?>">
                                    <?= htmlspecialchars($monitor['target_address']) ?>
                                    <?= $monitor['target_is_active'] == 1 ? '' : ' (disabled)' ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($monitor['protocol']) ?></td>
                            <td><?= $monitor['port'] ? htmlspecialchars($monitor['port']) : '-' ?></td>
                            <td><?= htmlspecialchars($monitor['dscp']) ?></td>
                            <td>
                                <span class="badge bg-<?= $effectively_active ? 'success' : 'warning' ?>">
                                    <?php if ($effectively_active): ?>
                                        Active
                                    <?php else: ?>
                                        Inactive
                                        <?php if ($monitor['agent_is_active'] != 1): ?>
                                            (Agent)
                                        <?php endif; ?>
                                        <?php if ($monitor['target_is_active'] != 1): ?>
                                            (Target)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($monitor['last_update']): ?>
                                    <span data-bs-toggle="tooltip" 
                                          title="<?= htmlspecialchars($monitor['last_update']) ?>">
                                        <?= date('Y-m-d H:i', strtotime($monitor['last_update'])) ?>
                                    </span>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/monitors_edit.php?id=<?= htmlspecialchars($monitor['id']) ?>" 
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="deleteMonitor('<?= htmlspecialchars($monitor['id']) ?>', '<?= htmlspecialchars($monitor['description']) ?>')"
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
document.getElementById('searchFilter').addEventListener('input', filterMonitors);
document.getElementById('protocolFilter').addEventListener('change', filterMonitors);
document.getElementById('showInactive').addEventListener('change', function() {
    window.location.href = '?show_inactive=' + this.checked;
});

function filterMonitors() {
    const search = document.getElementById('searchFilter').value.toLowerCase();
    const protocol = document.getElementById('protocolFilter').value;
    const showInactive = document.getElementById('showInactive').checked;
    
    const rows = document.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        if (row.cells.length < 2) return; // Skip empty table messages
        
        const description = row.cells[0].textContent.toLowerCase();
        const agent = row.cells[1].textContent.toLowerCase();
        const target = row.cells[2].textContent.toLowerCase();
        const rowProtocol = row.cells[3].textContent;
        
        // Check if row is inactive (has table-secondary class)
        const isInactive = row.classList.contains('table-secondary');
        
        const searchMatch = description.includes(search) || 
                          agent.includes(search) || 
                          target.includes(search);
        const protocolMatch = !protocol || rowProtocol === protocol;
        
        // Hide inactive rows unless showInactive is checked
        let shouldShow = searchMatch && protocolMatch;
        if (isInactive && !showInactive) {
            shouldShow = false;
        }
        
        row.style.display = shouldShow ? '' : 'none';
    });
}

// Apply initial filtering on page load
window.addEventListener('DOMContentLoaded', function() {
    // Ensure initial filter is applied
    filterMonitors();
});

// Delete confirmation
function deleteMonitor(id, description) {
    if (confirm(`Are you sure you want to delete monitor "${description}"?\nThis will also delete its RRD file.`)) {
        fetch(`/cgi-bin/api/monitor/${id}`, {
            method: 'DELETE',
            headers: {
                'Authorization': 'Bearer <?= $_SESSION['token'] ?? '' ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('Error deleting monitor: ' + data.message);
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