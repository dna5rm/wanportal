<?php
session_start();
require_once 'config.php';

/**
 * Make API calls to the backend
 * @param string $endpoint API endpoint to call
 * @return array|null Returns decoded JSON response or null on failure
 */
function callAPI($endpoint) {
    // Ensure endpoint starts with /
    if (substr($endpoint, 0, 1) !== '/') {
        $endpoint = '/' . $endpoint;
    }

    // For debugging
    if (DEBUG_MODE) {
        error_log("Calling API: " . API_BASE_URL . $endpoint);
    }

    $ch = curl_init(API_BASE_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("API Error for " . $endpoint . ": " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    if ($httpCode === 200) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        error_log("JSON decode error for " . $endpoint . ": " . json_last_error_msg());
    } else {
        error_log("API returned status code " . $httpCode . " for " . $endpoint);
        if (DEBUG_MODE) {
            error_log("API Response: " . $response);
        }
    }
    
    return null;
}

// Fetch data from APIs
$agentsResponse = callAPI('/agents');
$targetsResponse = callAPI('/targets');
$monitorsResponse = callAPI('/monitors');

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

// Get server name safely
$server_name = isset($_SERVER['SERVER_NAME']) ? 
    strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0]) : 
    'NETPING';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta http-equiv="refresh" content="300">
    <title><?= $server_name ?> :: Statistics</title>
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
            <h3>Local Server</h3>
        </div>
        <div class="col text-end">
            <div class="btn-group">
                <?php if (isset($_SERVER['HTTP_REFERER'])): ?>
                    <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER']) ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                <?php endif; ?>
                <a href="/index.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-house-door"></i> Home
                </a>
            </div>
        </div>
    </div>

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
            <?php if (isset($_SESSION['user'])): ?>
                <div class="card shadow rounded">
                    <div class="card-body p-0">
                        <?php phpinfo(); ?>
                    </div>
                </div>
            <?php endif; ?>

            <br />
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>