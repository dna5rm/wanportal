<?php
require_once 'config.php';
wanportal_session_start();
$id = $_GET['id'] ?? '';
if (!$id) die("No target ID specified");

// Get show_inactive preference from GET or session
$show_inactive = isset($_GET['show_inactive']) ?
    filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN) :
    ($_SESSION['show_inactive'] ?? false);
$_SESSION['show_inactive'] = $show_inactive;

try {
    // Fetch target info with prepared statement
    $stmt = $mysqli->prepare("
        SELECT id, address, description, is_active
        FROM targets
        WHERE id = ?
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("s", $id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        throw new Exception("Target not found");
    }

    $target = $result->fetch_assoc();
    $stmt->close();

    // Fetch monitors with all related info using JOIN
    $stmt = $mysqli->prepare("
        SELECT
            m.*,
            a.name as agent_name,
            a.description as agent_description,
            a.is_active as agent_is_active
        FROM monitors m
        JOIN agents a ON m.agent_id = a.id
        WHERE m.target_id = ?
        ORDER BY a.name, m.description
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("s", $id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $monitors = [];

    // Calculate monitor statistics while fetching
    $monitor_stats = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'effectively_inactive' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        // Compute the color classes used by the table (current vs
        // average comparisons, and range-based comparisons for the
        // lifetime averages). See lib/monitor_metrics.php for the
        // threshold definitions.
        monitor_color_classes($row);

        // Update statistics
        $monitor_stats['total']++;
        if ($row['is_active'] && $row['agent_is_active'] && $target['is_active']) {
            $monitor_stats['active']++;
        } else {
            $monitor_stats['effectively_inactive']++;
            if (!$row['is_active']) {
                $monitor_stats['inactive']++;
            }
        }

        $monitors[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= htmlspecialchars($target['address']) ?></title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <!-- Header Row -->
    <div class="row mb-3">
        <div class="col">
            <h3>
                Target:
                <?= htmlspecialchars(!empty($target['description']) ? $target['description'] : $target['address']) ?>
            </h3>
        </div>
        <div class="col text-end">
            <!-- Header Button Group -->
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
                <!-- User Session Options -->                
                <?php if (isset($_SESSION['user'])): ?>                  
                    <a href="/targets_edit.php?id=<?= htmlspecialchars($target['id']) ?>" class="btn btn-danger btn-sm">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                <?php endif; ?>
                <!-- Show Inactive Switch -->
                <div class="btn btn-secondary btn-sm d-flex align-items-center" style="gap: 5px;">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="showInactive" 
                            <?= $show_inactive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showInactive">
                            Inactive
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Target Info Column -->
        <div class="col-md-3">
            <!-- Target Details Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Details</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>ID:</strong><br/>
                            <?= htmlspecialchars($target['id']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Address:</strong><br/>
                            <?= htmlspecialchars($target['address']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Status:</strong><br/>
                            <?php if ($target['is_active']): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Inactive</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Monitor Statistics Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Statistics</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Active Monitors
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle rounded-pill">
                                <?= $monitor_stats['active'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Inactive Monitors
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill">
                                <?= $monitor_stats['inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Effectively Inactive
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle rounded-pill">
                                <?= $monitor_stats['effectively_inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Monitors
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle rounded-pill">
                                <?= $monitor_stats['total'] ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Monitors Column -->
        <div class="col-md-9">
            <!-- Monitors Table -->
            <div class="table-responsive">
                <table id="tablePager" class="table table-bordered table-striped table-hover" data-empty-message="No monitors found">
                    <thead>
                        <tr>
                            <th>Monitor</th>
                            <th>Agent</th>
                            <th>Protocol</th>
                            <!--
                            <th class="text-center bg-primary-subtle">Median</th>
                            <th class="text-center bg-primary-subtle">Loss</th>
                            -->
                            <th class="text-center bg-secondary-subtle">Median</th>
                            <th class="text-center bg-secondary-subtle">Min</th>
                            <th class="text-center bg-secondary-subtle">Max</th>
                            <th class="text-center bg-secondary-subtle">Std Dev</th>
                            <th class="text-center bg-secondary-subtle">Loss</th>
                            <th class="text-center">Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($monitors)): ?>
                            <?php foreach ($monitors as $m): ?>
                                <?php
                                // Calculate effective status
                                $effectively_active = $target['is_active'] && $m['agent_is_active'] && $m['is_active'];

                                // Skip if inactive and not showing inactive
                                if (!$effectively_active && !$show_inactive) {
                                    continue;
                                }
                                ?>
                                <tr class="<?= $effectively_active ? '' : 'bg-secondary-subtle' ?>">
                                    <td>
                                        <?php if (!$effectively_active): ?>
                                            <del class="text-muted">
                                        <?php endif; ?>

                                        <a href="/monitor.php?id=<?= htmlspecialchars($m['id']) ?>"
                                           class="<?= $effectively_active ? 'text-decoration-none' : 'text-muted' ?>"
                                           title="<?= htmlspecialchars($m['id']) ?>"
                                           data-bs-toggle="tooltip">
                                            <?= !empty($m['description']) ? htmlspecialchars($m['description']) : htmlspecialchars($m['id']) ?>
                                        </a>

                                        <?php if (!$effectively_active): ?>
                                            </del>
                                            <?php
                                            $inactive_reason = [];
                                            if (!$target['is_active']) $inactive_reason[] = "Target disabled";
                                            if (!$m['agent_is_active']) $inactive_reason[] = "Agent disabled";
                                            if (!$m['is_active']) $inactive_reason[] = "Monitor disabled";
                                            ?>
                                            <i class="bi bi-info-circle text-muted"
                                               data-bs-toggle="tooltip"
                                               title="Inactive: <?= implode(', ', $inactive_reason) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/agent.php?id=<?= htmlspecialchars($m['agent_id']) ?>"
                                           class="<?= $effectively_active ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['agent_name']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="<?= $effectively_active ? '' : 'text-muted' ?>"
                                              title="DSCP: <?= htmlspecialchars($m['dscp']) ?>"
                                              data-bs-toggle="tooltip">
                                            <?php if (strtoupper($m['protocol']) == 'ICMP'): ?>
                                                <?= strtoupper($m['protocol']) ?>
                                            <?php else: ?>
                                                <?= strtoupper($m['protocol']) ?>/<?= htmlspecialchars($m['port']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <!--
                                    <td class="text-center bg-primary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['current_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-primary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['current_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_loss']) ?>%
                                        </span>
                                    </td>
                                    -->
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_minimum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_min']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_maximum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_max']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_stddev_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_stddev']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_loss']) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?= $effectively_active ? '' : 'text-muted' ?>"
                                              title="Last Down: <?= htmlspecialchars($m['last_down']) ?>"
                                              data-bs-toggle="tooltip">
                                            <?= htmlspecialchars($m['last_update']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
    // Persist the "Show Inactive" toggle across page navigations.
    // The PHP session keeps the value once it's set, so we just
    // round-trip the new value through the URL on every change.
    // Preserves all other query params (e.g. ?id=...) and the
    // hash fragment. Listings pages (agents/targets/monitors) have
    // their own client-side filter via listings.js and don't need
    // this hook.
    window.pageSpecificScripts = function () {
        var toggle = document.getElementById('showInactive');
        if (!toggle) { return; }
        toggle.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('show_inactive', toggle.checked ? 'true' : 'false');
            window.location.assign(url.toString());
        });
    };
</script>
</body>
</html>
<?php $mysqli->close(); ?>