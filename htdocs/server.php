<?php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();

// Fetch data from APIs
$agentsResponse = api_get('/agents');
$targetsResponse = api_get('/targets');
$monitorsResponse = api_get('/monitors');

// Count entries helper function
function count_entries_from_api($items) {
    $counts = ['active' => 0, 'disabled' => 0, 'total' => 0];
    
    foreach ($items as $item) {
        if ($item['is_active']) {
            $counts['active']++;
        } else {
            $counts['disabled']++;
        }
        $counts['total']++;
    }
    
    return $counts;
}

// Calculate counts from API data
$agentCounts = count_entries_from_api($agentsResponse['agents'] ?? []);
$targetsCount = count_entries_from_api($targetsResponse['targets'] ?? []);
$monitorsCount = count_entries_from_api($monitorsResponse['monitors'] ?? []);

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

// Local Server auto-refreshes every 5 minutes (uptime / agent
// counts change slowly). Pass the meta tag through head_extras
// so it lives inside <head> (required for browsers to honor it).
$head_extras = '    <meta http-equiv="refresh" content="300">' . "\n";

wanportal_render_head('Statistics', ['head_extras' => $head_extras]);
wanportal_render_header_row('Local Server');
?>

    <div class="row">
        <div class="col-md-8 mx-auto">
            <!-- Runtime -->
            <div class="card shadow rounded mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Runtime</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <?php if ($stats['server_timezone'] == "UTC"): ?>
                            <li>Server time: <?= $stats['server_localtime'] ?></li>
                        <?php else: ?>
                            <li>Server time: <?= $stats['server_localtime'] ?> (<?= htmlspecialchars($stats['server_timezone']) ?>)</li>
                            <li>UTC time: <?= $stats['server_utc_time'] ?></li>
                        <?php endif; ?>
                        <li>Uptime: <?= $stats['server_run_time'] ?></li>
                    </ul>
                </div>
            </div>

            <br />

            <!-- Statistics -->
            <div class="card shadow rounded">
                <div class="card-body p-0">
                    <table class="table table-striped table-hover mb-0 rounded-bottom">
                        <thead>
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

            <br />
        </div>
    </div>

<?php wanportal_render_page_end(); ?>