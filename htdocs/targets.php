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
         The previous `page_header()` rendered a Home > Targets
         breadcrumb, which looked like a "back to home" link and
         was confusing on a listing page. -->
    <div class="row mb-3">
        <div class="col">
            <h3>Targets</h3>
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
                    <a href="/targets_edit.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> New Target
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Listing table: now using DataTables (auto-initialized
         by assets/js/listings.js). Bespoke filter card above
         removed in favor of DataTables' built-in search. -->

    <!-- Targets Table -->
    <div class="card mb-4">
    <div class="table-responsive">
        <table id="tablePager" class="table table-hover" data-order='[[2, "desc"]]'>
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
        <?php foreach ($targets as $target): ?>
                        <tr class="<?= $target['is_active'] ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/target.php?id=<?= htmlspecialchars($target['id']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($target['address']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($target['description']) ?></td>
                            <td>
                                <span class="badge bg-<?= $target['is_active'] ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
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
        showToast('Target saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};
</script>
</body>
</html>