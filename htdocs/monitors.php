<?php
session_start();
require_once 'config.php';

$id = $_GET['id'] ?? '';
if (!$id) die("No monitor ID specified");

// Get show_inactive preference from GET or session
$show_inactive = isset($_GET['show_inactive']) ?
    filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN) :
    ($_SESSION['show_inactive'] ?? false);
$_SESSION['show_inactive'] = $show_inactive;

try {
    // Fetch monitor info with prepared statement
    $stmt = $mysqli->prepare("
        SELECT 
            m.*,
            t.address as target_address,
            t.description as target_description,
            t.is_active as target_is_active,
            a.name as agent_name,
            a.description as agent_description,
            a.address as agent_address,
            a.is_active as agent_is_active
        FROM monitors m
        JOIN targets t ON m.target_id = t.id
        JOIN agents a ON m.agent_id = a.id
        WHERE m.id = ?
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
        throw new Exception("Monitor not found");
    }

    $monitor = $result->fetch_assoc();
    $stmt->close();

    // Calculate monitor statistics
    $monitor_stats = [
        'total_samples' => $monitor['sample'],
        'total_down' => $monitor['total_down'],
        'current_status' => $monitor['current_loss'] >= 100 ? 'down' : 'up',
        'effectively_active' => $monitor['is_active'] && $monitor['agent_is_active'] && $monitor['target_is_active']
    ];

    // Calculate status colors
    $current_median = floatval($monitor['current_median']);
    $current_loss = floatval($monitor['current_loss']);
    $avg_median = floatval($monitor['avg_median']);
    $avg_min = floatval($monitor['avg_min']);
    $avg_max = floatval($monitor['avg_max']);
    $avg_stddev = floatval($monitor['avg_stddev']);
    $avg_loss = floatval($monitor['avg_loss']);

    // Add color classes based on thresholds
    if ($current_median > $avg_median + 2*$avg_stddev)
        $monitor['current_median_color'] = 'bg-danger';
    elseif ($current_median > $avg_median)
        $monitor['current_median_color'] = 'bg-warning';
    elseif ($current_median == $avg_median)
        $monitor['current_median_color'] = 'bg-info';
    else
        $monitor['current_median_color'] = 'bg-success';

    // Current loss thresholds
    if ($current_loss >= 75)
        $monitor['current_loss_color'] = 'bg-danger';
    elseif ($current_loss >= 50)
        $monitor['current_loss_color'] = 'bg-warning';
    elseif ($current_loss >= 2)
        $monitor['current_loss_color'] = 'bg-info';
    else
        $monitor['current_loss_color'] = 'bg-success';

    // Average metrics colors
    if ($avg_median > $avg_median + 2*$avg_stddev)
        $monitor['avg_median_color'] = 'bg-danger';
    elseif ($avg_median > $avg_median + $avg_stddev)
        $monitor['avg_median_color'] = 'bg-warning';
    elseif ($avg_median >= $avg_median)
        $monitor['avg_median_color'] = 'bg-info';
    else
        $monitor['avg_median_color'] = 'bg-success';

    if ($avg_min <= ($avg_median - 3*$avg_stddev))
        $monitor['avg_minimum_color'] = 'bg-danger';
    elseif ($avg_min <= ($avg_median - 2*$avg_stddev))
        $monitor['avg_minimum_color'] = 'bg-warning';
    elseif ($avg_min <= ($avg_median - $avg_stddev))
        $monitor['avg_minimum_color'] = 'bg-info';
    else
        $monitor['avg_minimum_color'] = 'bg-success';

    if ($avg_max >= ($avg_median + 3*$avg_stddev))
        $monitor['avg_maximum_color'] = 'bg-danger';
    elseif ($avg_max >= ($avg_median + 2*$avg_stddev))
        $monitor['avg_maximum_color'] = 'bg-warning';
    elseif ($avg_max >= ($avg_median + $avg_stddev))
        $monitor['avg_maximum_color'] = 'bg-info';
    else
        $monitor['avg_maximum_color'] = 'bg-success';

    $avg_stddev_threshold = abs(($avg_max - $avg_min) / 2);
    $monitor['avg_stddev_color'] = ($avg_stddev > $avg_stddev_threshold) ? 'bg-info' : 'bg-success';

    if ($avg_loss < 2)
        $monitor['avg_loss_color'] = 'bg-success';
    elseif ($avg_loss < 5)
        $monitor['avg_loss_color'] = 'bg-info';
    elseif ($avg_loss < 13)
        $monitor['avg_loss_color'] = 'bg-warning';
    else
        $monitor['avg_loss_color'] = 'bg-danger';

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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= htmlspecialchars($monitor['description']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <!-- Header Row -->
    <div class="row mb-3">
        <div class="col">
            <h3>
                Monitor:
                <span title="<?= htmlspecialchars($monitor['id']) ?>" data-bs-toggle="tooltip">
                    <?= htmlspecialchars($monitor['description']) ?>
                </span>
                <?php if (!$monitor['effectively_active']): ?>
                    <span class="badge bg-warning">Inactive</span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="col text-end">
            <div class="btn-group" role="group">
                <a href="/index.php" class="btn btn-secondary">
                    <i class="bi bi-house-door"></i> Home
                </a>
                <?php if (isset($_SESSION['user'])): ?>
                    <a href="/monitors_edit.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="btn btn-danger">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Monitor Info Column -->
        <div class="col-md-3">
            <!-- Monitor Details Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Details</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>ID:</strong><br/>
                            <?= htmlspecialchars($monitor['id']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Agent:</strong><br/>
                            <a href="/agent.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>"
                               title="<?= htmlspecialchars($monitor['agent_description']) ?>"
                               data-bs-toggle="tooltip">
                                <?= htmlspecialchars($monitor['agent_name']) ?>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <strong>Target:</strong><br/>
                            <a href="/target.php?id=<?= htmlspecialchars($monitor['target_id']) ?>"
                               title="<?= htmlspecialchars($monitor['target_description']) ?>"
                               data-bs-toggle="tooltip">
                                <?= htmlspecialchars($monitor['target_address']) ?>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <strong>Protocol:</strong><br/>
                            <?= htmlspecialchars($monitor['protocol']) ?>
                            <?php if ($monitor['protocol'] === 'TCP'): ?>
                                (Port <?= htmlspecialchars($monitor['port']) ?>)
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item">
                            <strong>DSCP:</strong><br/>
                            <?= htmlspecialchars($monitor['dscp']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Status:</strong><br/>
                            <?php if ($monitor['effectively_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Inactive</span>
                                <?php
                                $inactive_reason = [];
                                if (!$monitor['target_is_active']) $inactive_reason[] = "Target disabled";
                                if (!$monitor['agent_is_active']) $inactive_reason[] = "Agent disabled";
                                if (!$monitor['is_active']) $inactive_reason[] = "Monitor disabled";
                                ?>
                                <div class="small text-muted mt-1">
                                    <?= implode(', ', $inactive_reason) ?>
                                </div>
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
                            Total Samples
                            <span class="badge bg-primary rounded-pill">
                                <?= $monitor_stats['total_samples'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Down Events
                            <span class="badge bg-danger rounded-pill">
                                <?= $monitor_stats['total_down'] ?>
                            </span>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Update:</strong><br/>
                            <?= htmlspecialchars($monitor['last_update']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Down:</strong><br/>
                            <?= htmlspecialchars($monitor['last_down']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Clear:</strong><br/>
                            <?= htmlspecialchars($monitor['last_clear']) ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Monitor Data Column -->
        <div class="col-md-9">
            <!-- Current Metrics Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Current Metrics</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th class="text-center">Median</th>
                                    <th class="text-center">Loss</th>
                                    <th class="text-center">Min</th>
                                    <th class="text-center">Max</th>
                                    <th class="text-center">Std Dev</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['current_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['current_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['current_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['current_loss']) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_minimum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_min']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_maximum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_max']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_stddev_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_stddev']) ?>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Performance Graphs -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="card-title">Latency History</h5>
                            <img src="/graph.php?id=<?= htmlspecialchars($monitor['id']) ?>&type=latency" 
                                 class="img-fluid" alt="Latency Graph">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="card-title">Loss History</h5>
                            <img src="/graph.php?id=<?= htmlspecialchars($monitor['id']) ?>&type=loss" 
                                 class="img-fluid" alt="Loss Graph">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
// Initialize tooltips
const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl, {
        html: true,
        placement: 'auto'
    });
});

// Auto-refresh page every 60 seconds if monitor is active
<?php if ($monitor['effectively_active']): ?>
    setTimeout(function() {
        location.reload();
    }, 60000);
<?php endif; ?>
</script>
</body>
</html>
<?php $mysqli->close(); ?>