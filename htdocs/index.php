<?php
session_start();
require_once 'config.php';

// Check database connection
if (!isset($mysqli) || !$mysqli instanceof mysqli) {
    die("Database not connected properly");
}

// Helper function to count entries based on table and active status using prepared statements
function count_entries($mysqli, $table) {
    $counts = ['active' => 0, 'disabled' => 0, 'total' => 0];
    
    $stmt = $mysqli->prepare("SELECT is_active, COUNT(*) AS cnt FROM `$table` GROUP BY is_active");
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if ($row['is_active']) {
                $counts['active'] = (int)$row['cnt'];
            } else {
                $counts['disabled'] = (int)$row['cnt'];
            }
            $counts['total'] += (int)$row['cnt'];
        }
        $result->close();
    }
    $stmt->close();
    return $counts;
}

// Count agents, targets, and monitors
$agentCounts = count_entries($mysqli, 'agents');
$targetsCount = count_entries($mysqli, 'targets');
$monitorsCount = count_entries($mysqli, 'monitors');

// Compute server metrics
$uptimeSeconds = 0;
$uptime = shell_exec('cat /proc/uptime');
if ($uptime !== null) {
    $uptimeSeconds = (int)floatval(explode(' ', $uptime)[0]);
}

function format_uptime($secs) {
    $d = floor($secs/86400);
    $secs -= $d * 86400;
    
    $h = floor($secs/3600);
    $secs -= $h * 3600;
    
    $m = floor($secs/60);
    $secs -= $m * 60;
    
    $parts = [];
    if ($d > 0) $parts[] = $d . ' ' . ($d == 1 ? 'day' : 'days');
    if ($h > 0) $parts[] = $h . ' ' . ($h == 1 ? 'hour' : 'hours');
    if ($m > 0) $parts[] = $m . ' ' . ($m == 1 ? 'minute' : 'minutes');
    
    return implode(', ', $parts);
}

$uptimeStr = format_uptime($uptimeSeconds);

// Build stats array with real metrics
$stats = [
    'server_timezone' => date_default_timezone_get(),
    'server_localtime' => date('Y-m-d H:i:s'),
    'server_utc_time' => gmdate('Y-m-d H:i:s'),
    'server_run_time' => format_uptime($uptimeSeconds),
    'active_agents' => $agentCounts['active'],
    'disabled_agents' => $agentCounts['disabled'],
    'total_agents' => $agentCounts['total'],
    'active_targets' => $targetsCount['active'],
    'disabled_targets' => $targetsCount['disabled'],
    'total_targets' => $targetsCount['total'],
    'active_monitors' => $monitorsCount['active'],
    'disabled_monitors' => $monitorsCount['disabled'],
    'total_monitors' => $monitorsCount['total']
];

// Fetch agents with last_seen times using prepared statement
$agents = [];
$stmt = $mysqli->prepare("
    SELECT 
        id, 
        name, 
        description,
        address,
        last_seen,
        is_active
    FROM agents 
    ORDER BY name
");

if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $agents[] = $row;
    }
    $result->close();
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Console</title>
    <meta charset="utf-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Agents list -->
        <div class="col-2">
            <h3>Agents</h3>
            <ul class="list-group mb-3">
                <?php if (empty($agents)): ?>
                    <li class="list-group-item">No agents found</li>
                <?php else: ?>
                    <?php 
                    $now = time();
                    foreach ($agents as $agent):
                        $bgClass = '';
                        if ($agent['last_seen']) {
                            $last = strtotime($agent['last_seen']);
                            if ($last !== false && $last < $now - 3600) {
                                $bgClass = 'list-group-item-danger';
                            }
                        }
                    ?>
                    <li class="list-group-item <?= $bgClass ?>">
                        <a href="/agent.php?id=<?= htmlspecialchars($agent['id']) ?>" 
                           title="<?= htmlspecialchars($agent['description']) ?> (<?= htmlspecialchars($agent['address']) ?>)&#13;Last seen: <?= htmlspecialchars($agent['last_seen']) ?>" 
                           data-bs-toggle="tooltip" 
                           data-html="true">
                            <?= htmlspecialchars($agent['name']) ?>
                            <?php if (!$agent['is_active']): ?>
                                <span class="badge bg-warning">Disabled</span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Main stats -->
        <div class="col">

            <div class="row mb-3">
                <div class="col text-end d-flex justify-content-end align-items-center">
                    <form action="/search.php" method="GET" class="d-flex align-items-center">
                        <input type="text" name="q" class="form-control form-control-sm me-2" placeholder="Search monitors...">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                </div>
            </div>

            <div class="d-flex align-items-end mb-4">
                <!-- Spacer -->
                <div style="width: 5%;">&nbsp;</div>

                <!-- Statistics -->
                <div class="flex-fill" style="max-width: 55%;">
                    <!-- <h3>Statistics</h3> -->
                    <div class="card shadow rounded">
                        <div class="card-body p-0">
                            <table class="table table-striped table-hover mb-0 rounded-bottom">
                                <thead class="table-dark">
                                    <tr>
                                        <th></th>
                                        <th class="text-center">Active</th>
                                        <th class="text-center">Disabled</th>
                                        <th class="text-center">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Agents</td>
                                        <td class="text-center"><?= $stats['active_agents'] ?></td>
                                        <td class="text-center"><?= $stats['disabled_agents'] ?></td>
                                        <td class="text-center"><?= $stats['total_agents'] ?></td>
                                    </tr>
                                    <tr>
                                        <td>Targets</td>
                                        <td class="text-center"><?= $stats['active_targets'] ?></td>
                                        <td class="text-center"><?= $stats['disabled_targets'] ?></td>
                                        <td class="text-center"><?= $stats['total_targets'] ?></td>
                                    </tr>
                                    <tr>
                                        <td>Monitors</td>
                                        <td class="text-center"><?= $stats['active_monitors'] ?></td>
                                        <td class="text-center"><?= $stats['disabled_monitors'] ?></td>
                                        <td class="text-center"><?= $stats['total_monitors'] ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Spacer -->
                <div style="width: 5%;">&nbsp;</div>

                <!-- Server Runtime -->
                <div style="max-width: 30%;">
                    <h4>Server Runtime</h4>
                    <ul class="list-unstyled">
                        <?php if ($stats['server_timezone'] == "UTC"): ?>
                            <li>Server time: <?= $stats['server_localtime'] ?></li>
                        <?php else: ?>
                            <li>Server time: <?= $stats['server_localtime'] ?> (<?= htmlspecialchars($stats['server_timezone']) ?>)</li>
                            <li>UTC time: <?= $stats['server_utc_time'] ?></li>
                        <?php endif; ?>
                        <li>Uptime: <?= $stats['server_run_time'] ?></li>
                    </ul>
                </div>

                <!-- Spacer -->
                <div style="width: 5%;">&nbsp;</div>
            </div>
            <hr />

            <div class="text-center">
                <h3>PLACEHOLDER</h3>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
<?php $mysqli->close(); ?>