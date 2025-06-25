<?php
// agent.php
session_start();
require_once 'config.php';

$id = $_GET['id'] ?? '';
if (!$id) die("No agent ID specified");

// Get show_inactive preference from GET or session
$show_inactive = isset($_GET['show_inactive']) ? 
    filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN) : 
    ($_SESSION['show_inactive'] ?? false);
$_SESSION['show_inactive'] = $show_inactive;

try {
    // Fetch agent info with prepared statement
    $stmt = $mysqli->prepare("
        SELECT id, name, address, description, last_seen, is_active 
        FROM agents 
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
        throw new Exception("Agent not found");
    }
    
    $agent = $result->fetch_assoc();
    $stmt->close();

    // Fetch monitors with all related info using JOIN
    $stmt = $mysqli->prepare("
        SELECT 
            m.*,
            t.address as target_address,
            t.description as target_description,
            t.is_active as target_is_active
        FROM monitors m
        JOIN targets t ON m.target_id = t.id
        WHERE m.agent_id = ?
        ORDER BY m.description, m.id
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
        // Calculate status colors
        $current_median = floatval($row['current_median']);
        $current_loss = floatval($row['current_loss']);
        $avg_median = floatval($row['avg_median']);
        $avg_min = floatval($row['avg_min']);
        $avg_max = floatval($row['avg_max']);
        $avg_stddev = floatval($row['avg_stddev']);
        $avg_loss = floatval($row['avg_loss']);

        // Add color classes based on thresholds
        if ($current_median > $avg_median + 2*$avg_stddev)
            $row['current_median_color'] = 'bg-danger';
        elseif ($current_median > $avg_median)
            $row['current_median_color'] = 'bg-warning';
        elseif ($current_median == $avg_median)
            $row['current_median_color'] = 'bg-info';
        else
            $row['current_median_color'] = 'bg-success';

        // Current loss thresholds
        if ($current_loss >= 75)
            $row['current_loss_color'] = 'bg-danger';
        elseif ($current_loss >= 50)
            $row['current_loss_color'] = 'bg-warning';
        elseif ($current_loss >= 2)
            $row['current_loss_color'] = 'bg-info';
        else
            $row['current_loss_color'] = 'bg-success';

        // Average metrics colors
        if ($avg_median > $avg_median + 2*$avg_stddev)
            $row['avg_median_color'] = 'bg-danger';
        elseif ($avg_median > $avg_median + $avg_stddev)
            $row['avg_median_color'] = 'bg-warning';
        elseif ($avg_median >= $avg_median)
            $row['avg_median_color'] = 'bg-info';
        else
            $row['avg_median_color'] = 'bg-success';

        if ($avg_min <= ($avg_median - 3*$avg_stddev))
            $row['avg_minimum_color'] = 'bg-danger';
        elseif ($avg_min <= ($avg_median - 2*$avg_stddev))
            $row['avg_minimum_color'] = 'bg-warning';
        elseif ($avg_min <= ($avg_median - $avg_stddev))
            $row['avg_minimum_color'] = 'bg-info';
        else
            $row['avg_minimum_color'] = 'bg-success';

        if ($avg_max >= ($avg_median + 3*$avg_stddev))
            $row['avg_maximum_color'] = 'bg-danger';
        elseif ($avg_max >= ($avg_median + 2*$avg_stddev))
            $row['avg_maximum_color'] = 'bg-warning';
        elseif ($avg_max >= ($avg_median + $avg_stddev))
            $row['avg_maximum_color'] = 'bg-info';
        else
            $row['avg_maximum_color'] = 'bg-success';

        $avg_stddev_threshold = abs(($avg_max - $avg_min) / 2);
        $row['avg_stddev_color'] = ($avg_stddev > $avg_stddev_threshold) ? 'bg-info' : 'bg-success';

        if ($avg_loss < 2)
            $row['avg_loss_color'] = 'bg-success';
        elseif ($avg_loss < 5)
            $row['avg_loss_color'] = 'bg-info';
        elseif ($avg_loss < 13)
            $row['avg_loss_color'] = 'bg-warning';
        else
            $row['avg_loss_color'] = 'bg-danger';

        // Update statistics
        $monitor_stats['total']++;
        if ($row['is_active'] && $row['target_is_active'] && $agent['is_active']) {
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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= htmlspecialchars($agent['name']) ?></title>
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
                Agent: 
                <?= htmlspecialchars(!empty($agent['name']) ? $agent['name'] : $agent['id']) ?>
            </h3>
        </div>
        <div class="col text-end">  <!-- Changed from text-center to text-end -->
            <div class="btn-group" role="group">
                <?php if (isset($_SERVER['HTTP_REFERER'])): ?>
                    <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER']) ?>" class="btn btn-secondary btn-sm ">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                <?php endif; ?>  
                <a href="/index.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-house-door"></i> Home
                </a>
                <?php if (isset($_SESSION['user'])): ?>
                    <?php if ($agent['name'] != "LOCAL") : ?>
                        <a href="/netping.php?id=<?= htmlspecialchars($agent['id']) ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-play-circle"></i> Agent
                        </a>
                    <?php endif; ?>
                    <a href="/agents_edit.php?id=<?= htmlspecialchars($agent['id']) ?>" class="btn btn-danger btn-sm">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Agent Info Column -->
        <div class="col-md-3">
            <!-- Agent Details Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Details</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>ID:</strong><br/>
                            <?= htmlspecialchars($agent['id']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Address:</strong><br/>
                            <?= htmlspecialchars($agent['address']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Description:</strong><br/>
                            <?= htmlspecialchars($agent['description']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Status:</strong><br/>
                            <?php if ($agent['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Inactive</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Seen:</strong><br/>
                            <?= htmlspecialchars($agent['last_seen']) ?>
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
                            <span class="badge bg-success rounded-pill">
                                <?= $monitor_stats['active'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Inactive Monitors
                            <span class="badge bg-warning rounded-pill">
                                <?= $monitor_stats['inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Effectively Inactive
                            <span class="badge bg-secondary rounded-pill">
                                <?= $monitor_stats['effectively_inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Monitors
                            <span class="badge bg-primary rounded-pill">
                                <?= $monitor_stats['total'] ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Monitors Column -->
        <div class="col-md-9">
            <!-- Filters Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <input type="text" id="searchFilter" class="form-control" placeholder="Search monitors...">
                        </div>
                        <div class="col-md-3">
                            <select id="protocolFilter" class="form-select">
                                <option value="">All Protocols</option>
                                <option value="ICMP">ICMP</option>
                                <option value="ICMPV6">ICMPv6</option>
                                <option value="TCP">TCP</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
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

            <!-- Monitors Table -->
            <div class="table-responsive">
                <table class="table table-light table-bordered table-striped table-hover">
                    <thead>
                        
                        <tr>
                            <th colspan="3" class="text-center"></th>
                            <th colspan="2" class="table-primary text-center">Current</th>
                            <!--
                            <th colspan="5" class="table-secondary text-center">Average</th>
                             -->
                            <th colspan="1" class="text-center"></th>
                        </tr>
                        <tr>
                            <th>Monitor</th>
                            <th>Target</th>
                            <th>Protocol</th>
                            <th class="text-center table-primary">Median</th>
                            <th class="text-center table-primary">Loss</th>
                            <!--
                            <th class="text-center table-secondary">Median</th>
                            <th class="text-center table-secondary">Min</th>
                            <th class="text-center table-secondary">Max</th>
                            <th class="text-center table-secondary">Std Dev</th>
                            <th class="text-center table-secondary">Loss</th>
                            -->
                            <th class="text-center">Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monitors)): ?>
                            <tr>
                                <td colspan="11" class="text-center">No monitors found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($monitors as $m): ?>
                                <?php
                                // Calculate effective status
                                $effectively_active = $agent['is_active'] && $m['target_is_active'] && $m['is_active'];
                                
                                // Skip if inactive and not showing inactive
                                if (!$effectively_active && !$show_inactive) {
                                    continue;
                                }
                                ?>
                                <tr class="<?= $effectively_active ? '' : 'table-secondary' ?>">
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
                                            if (!$agent['is_active']) $inactive_reason[] = "Agent disabled";
                                            if (!$m['target_is_active']) $inactive_reason[] = "Target disabled";
                                            if (!$m['is_active']) $inactive_reason[] = "Monitor disabled";
                                            ?>
                                            <i class="bi bi-info-circle text-muted" 
                                               data-bs-toggle="tooltip" 
                                               title="Inactive: <?= implode(', ', $inactive_reason) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/target.php?id=<?= htmlspecialchars($m['target_id']) ?>"
                                           class="<?= $effectively_active ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['target_address']) ?>
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
                                    <td class="text-center table-primary">
                                        <span class="badge <?= $effectively_active ? $m['current_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-primary">
                                        <span class="badge <?= $effectively_active ? $m['current_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_loss']) ?>%
                                        </span>
                                    </td>
                                    <!--
                                    <td class="text-center table-secondary">
                                        <span class="badge <?= $effectively_active ? $m['avg_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-secondary">
                                        <span class="badge <?= $effectively_active ? $m['avg_minimum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_min']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-secondary">
                                        <span class="badge <?= $effectively_active ? $m['avg_maximum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_max']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-secondary">
                                        <span class="badge <?= $effectively_active ? $m['avg_stddev_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_stddev']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-secondary">
                                        <span class="badge <?= $effectively_active ? $m['avg_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_loss']) ?>%
                                        </span>
                                    </td>
                                    -->
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
</body>
</html>
<?php $mysqli->close(); ?>