<?php
session_start();
require_once 'config.php'; // assumes $mysqli is set up here

// Check connection
if (!isset($mysqli) || !$mysqli instanceof mysqli) {
    die("Database not connected properly");
}

// Helper function to count entries based on table and active status
function count_entries($mysqli, $table) {
    $counts = ['active' => 0, 'disabled' => 0, 'total' => 0];
    $res = $mysqli->query("SELECT is_active, COUNT(*) AS cnt FROM `$table` GROUP BY is_active");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['is_active']) {
                $counts['active'] = (int)$row['cnt'];
            } else {
                $counts['disabled'] = (int)$row['cnt'];
            }
            $counts['total'] += (int)$row['cnt'];
        }
        $res->close();
    }
    return $counts;
}

// Count agents
$agentCounts = count_entries($mysqli, 'agents');

// Count targets
$targetsCount = count_entries($mysqli, 'targets');

// Count monitors
$monitorsCount = count_entries($mysqli, 'monitors');

// Compute server metrics
$uptimeSeconds = 0;
$uptime_str = shell_exec('uptime -s');
if ($uptime_str !== null && trim($uptime_str)) {
    $start_time = strtotime(trim($uptime_str));
    $uptime_seconds = time() - $start_time;
}

function format_uptime($secs) {
    $d = floor($secs/86400); $secs -= $d * 86400;
    $h = floor($secs/3600); $secs -= $h * 3600;
    $m = floor($secs/60); $secs -= $m * 60;
    return "{$d} days, {$h} hours, {$m} minutes";
}

$uptimeStr = format_uptime($uptimeSeconds);

// Build stats array with real metrics
$stats = [
    'server_timezone' => date_default_timezone_get(),
    'server_localtime' => date('Y-m-d H:i:s'),
    'server_utc_time' => gmdate('Y-m-d H:i:s'),
    'server_run_time' => format_uptime($uptime_seconds),
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

// Fetch last_seen times for each agent to mark inactive (>1 hour)
$agents = [];
$result = $mysqli->query("SELECT id, name, last_seen FROM agents");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $agents[] = $row;
    }
    $result->close();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <title>NetPing :: Console</title>
    <meta charset="utf-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body><?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row">

        <!-- Agents list -->
        <div class="col-2">
            <h3>Agents</h3>
            <ul class="list-group mb-3">
                <?php
                $now = time();
                foreach ($agents as $agent):
                    $bgClass = '';
                    if ($agent['last_seen']) {
                        $last = strtotime($agent['last_seen']);
                        if ($last !== false && $last < $now - 3600) {
                            // Last seen older than 1 hour - highlight red
                            $bgClass = 'list-group-item-danger';
                        }
                    }
                ?>
                <li class="list-group-item <?=$bgClass?>">
                    <a href="/agent.php?id=<?= urlencode($agent['id']) ?>" title="<?= htmlspecialchars($agent['last_seen']) ?>" data-toggle="tooltip">
                        <?= htmlspecialchars($agent['name']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Main stats -->
        <div class="col">
            <div class="d-flex align-items-end mb-4">
                <!-- Spacer -->
                <div style="width: 5%;">&nbsp;</div>

                <!-- Statistics -->
                <div class="flex-fill" style="max-width: 55%;">
                    <h3>Statistics</h3>
                    <div class="card shadow rounded">
                        <div class="card-body p-0">
                            <table class="table table-striped table-hover mb-0 rounded-bottom">
                                <thead class="table-dark" style="border-top-left-radius:0.75rem; border-top-right-radius:0.75rem;">
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
                    <ul>
                    <?php if ($stats['server_timezone'] == "UTC"): ?>
                        <li>Server time: <?= $stats['server_localtime'] ?></li>
                    <?php else: ?>
                        <li>Server time: <?= $stats['server_localtime'] ?> (<?= htmlspecialchars($stats['server_timezone']) ?>)</li>
                        <li>UTC time: <?= $stats['server_utc_time'] ?></li>
                    <?php endif; ?>
                    <li>Uptime is <?= $stats['server_run_time'] ?></li>
                    </ul>
                </div>

                <!-- Spacer -->
                <div style="width: 5%;">&nbsp;</div>
            </div>
            <hr />

            <div><center><h3>PLACEHOLDER</h3></center></div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $mysqli->close(); ?>