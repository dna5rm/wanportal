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

// Fetch active agents for sidebar
$agentsResponse = callAPI('/agents');
$activeAgents = array_filter($agentsResponse['agents'] ?? [], function($agent) {
    return $agent['is_active'] == 1;
});

// Fetch down hosts
$monitorsResponse = callAPI('/monitors?current_loss=100&is_active=1');
$downHosts = $monitorsResponse['monitors'] ?? [];

// Get server name safely
$server_name = isset($_SERVER['SERVER_NAME']) ? 
    strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0]) : 
    'NETPING';

// Initialize error message
$error_message = null;

// Check for API errors
if ($agentsResponse === null || $monitorsResponse === null) {
    $error_message = "Unable to fetch data from API";
}

// Start performance timing if in debug mode
if (DEBUG_MODE) {
    $start_time = microtime(true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?= $server_name ?> :: Console</title>
    <meta charset="utf-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta http-equiv="refresh" content="300">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Agents list -->
        <div class="col-2">
            <h3>Agents</h3>
            <ul class="list-group mb-3">
                <?php if (empty($activeAgents)): ?>
                    <li class="list-group-item">No active agents found</li>
                <?php else: ?>
                    <?php 
                    $now = time();
                    foreach ($activeAgents as $agent):
                        $bgClass = '';
                        if ($agent['last_seen']) {
                            $last = strtotime($agent['last_seen']);
                            if ($last !== false && $last < $now - HOUR_IN_SECONDS) {
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
                        </a>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Main content -->
        <div class="col">
            <div class="row mb-3">
                <div class="col text-end d-flex justify-content-end align-items-center gap-3">
                    <a href="/server.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-graph-up"></i> Runtime </a>
                    <a href="/latency.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-speedometer2"></i> Latency </a>
                    <form action="/search.php" method="GET" class="d-flex align-items-center">
                        <input type="text" name="q" class="form-control form-control-sm me-2" placeholder="Search monitors...">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Down Hosts -->
            <div class="text-center mb-4">
                <div class="d-flex justify-content-center align-items-center gap-3">
                    <h3 class="mb-0">Down Hosts</h3>
                    <a href="/cgi-bin/api/monitors?current_loss=100&is_active=1" 
                       class="btn btn-sm btn-outline-secondary" 
                       target="_blank"
                       title="View raw API data"
                       data-bs-toggle="tooltip">
                        <i class="bi bi-code-slash"></i> Raw Data
                    </a>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Monitor</th>
                            <th>Agent</th>
                            <th>Target</th>
                            <th>Down Since</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($downHosts)): ?>
                        <tr>
                            <td colspan="4" class="text-center">No down hosts</td>
                        </tr>
                    <?php else:
                        foreach ($downHosts as $monitor):
                            // Convert last_down to timestamp for comparison
                            $downTime = strtotime($monitor['last_down']);
                            
                            // Determine severity class using Bootstrap's colors
                            if ($downTime <= strtotime('-' . DANGER_THRESHOLD_HOURS . ' hours')) {
                                $rowClass = 'table-danger';    // Red background
                            } elseif ($downTime <= strtotime('-' . WARNING_THRESHOLD_HOURS . ' hours')) {
                                $rowClass = 'table-warning';   // Yellow background
                            } elseif ($downTime <= strtotime('-1 hours')) {
                                $rowClass = 'table-info';      // Light blue background
                            } else {
                                $rowClass = '';                // Default background
                            }
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <a href="/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>" 
                                       class="text-decoration-none">
                                        <?= htmlspecialchars($monitor['description']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($monitor['agent_name']) ?></td>
                                <td><?= htmlspecialchars($monitor['target_address']) ?></td>
                                <td><?= date("m/d H:i:s", strtotime($monitor['last_down'])) ?></td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<?php
// Log execution time if in debug mode
if (DEBUG_MODE) {
    $execution_time = microtime(true) - $start_time;
    error_log("Page generated in {$execution_time} seconds");
}
?>
</body>
</html>