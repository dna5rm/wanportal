<?php
session_start();
require_once 'check_session.php';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <!-- Header Row: matches the Back | Home | action-button
         layout used by the detail pages (agent.php, target.php,
         monitor.php) so navigation is consistent across the app.
         The previous `page_header()` rendered a Home > Monitors
         breadcrumb, which looked like a "back to home" link and
         was confusing on a listing page. -->
    <div class="row mb-3">
        <div class="col">
            <h3>Monitors</h3>
        </div>
        <div class="col text-end">
            <div class="btn-group" role="group">
                <!-- BACK -->
                <?php if (isset($_SERVER['HTTP_REFERER'])): ?>
                    <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER']) ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                <?php endif; ?>
                <!-- HOME -->
                <a href="/index.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-house-door"></i> Home
                </a>
                <!-- NEW (the listing-page equivalent of the
                     detail pages' Edit button: a primary action
                     to create a new record, matching the style
                     already used on credentials.php). -->
                <?php if (isset($_SESSION['user'])): ?>
                    <a href="/monitors_edit.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> New Monitor
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Listing table: now using DataTables (auto-initialized
         by assets/js/listings.js). Bespoke filter card above
         removed in favor of DataTables' built-in search. -->

    <!-- Monitors Table -->
    <div class="card mb-4">
    <div class="table-responsive">
        <table id="tablePager" class="table table-hover" data-order='[[6, "desc"]]'>
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
        <?php foreach ($monitors as $monitor): ?>
                        <?php
                        // Calculate effective active status
                        $effectively_active = ($monitor['is_active'] == 1 && 
                                           $monitor['agent_is_active'] == 1 && 
                                           $monitor['target_is_active'] == 1);
                        ?>
                        <tr class="<?= $effectively_active ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($monitor['description']) ?>
                                </a>
                            </td>
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
                                <span class="badge bg-<?= $effectively_active ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
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
            </tbody>
        </table>
    </div>
</div>
</div>

<?php include 'footer.php'; ?>


<script>
// Surface a toast on the listing page when the user was just
// redirected here from an *edit.php save (the edit form
// appends ?saved=1 on success). We strip the query param
// after firing so a refresh doesn't re-toast.
wanportalPageOnLoad = function() {
    var url = new URL(window.location.href);
    if (url.searchParams.get('saved') === '1') {
        showToast('Monitor saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};
</script>
</body>
</html>