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

// Fetch agents from API
$ch = curl_init("http://localhost/cgi-bin/api/agents");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$agents = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $agents = $data['agents'];
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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Agents</title>
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
         The previous `page_header()` rendered a Home > Agents
         breadcrumb, which looked like a "back to home" link and
         was confusing on a listing page. -->
    <div class="row mb-3">
        <div class="col">
            <h3>Agents</h3>
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
                    <a href="/agents_edit.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> New Agent
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Agents Table: now using DataTables (auto-initialized
         by assets/js/listings.js). The header search box above
         has been removed in favor of DataTables' built-in global
         search. The "Show Inactive" toggle below stays since
         DataTables' built-in search doesn't cover that case. -->

    <!-- Agents Table -->
    <div class="card mb-4">
    <div class="table-responsive">
        <table id="tablePager" class="table table-hover" data-order='[[3, "desc"]]'>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
        <?php foreach ($agents as $agent): ?>
                        <tr class="<?= $agent['is_active'] ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/agent.php?id=<?= htmlspecialchars($agent['id']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($agent['name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($agent['address']) ?></td>
                            <td><?= htmlspecialchars($agent['description']) ?></td>
                            <td>
                                <span class="badge bg-<?= $agent['is_active'] ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
                                    <?= $agent['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($agent['last_seen']): ?>
                                    <span data-bs-toggle="tooltip" 
                                          title="<?= htmlspecialchars($agent['last_seen']) ?>">
                                        <?= date('Y-m-d H:i', strtotime($agent['last_seen'])) ?>
                                    </span>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/agents_edit.php?id=<?= htmlspecialchars($agent['id']) ?>" 
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($agent['name'] !== 'LOCAL'): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="deleteAgent('<?= htmlspecialchars($agent['id']) ?>', '<?= htmlspecialchars($agent['name']) ?>')"
                                                title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
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
        showToast('Agent saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};
</script>
</body>
</html>