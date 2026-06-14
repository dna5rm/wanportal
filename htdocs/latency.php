<?php
require_once 'config.php';
wanportal_session_start();

// Fetch monitors from API
$ch = curl_init(API_BASE_URL . '/monitors');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$latencyIssues = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        // Filter monitors for latency issues
        $latencyIssues = array_filter($data['monitors'], function($monitor) {
            // Only include active monitors
            if ($monitor['is_active'] != 1 ||
                $monitor['agent_is_active'] != 1 ||
                $monitor['target_is_active'] != 1) {
                return false;
            }

            // Calculate threshold (avg_max + (5 * avg_stddev))
            $threshold = $monitor['avg_max'] + (5 * $monitor['avg_stddev']);

            // Return true if current_median exceeds threshold
            return $monitor['current_median'] > $threshold;
        });
    }
}

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
    <title><?= $server_name ?> :: Latency Report</title>
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
            <h3>Latency Report</h3>
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

    <!-- Latency Table -->
    <div class="table-responsive">
        <table id="tablePager" class="table table-striped table-hover" data-empty-message="No latency issues detected">
            <thead>
                <tr>
                    <th>Monitor</th>
                    <th>Agent</th>
                    <th>Target</th>
                    <th>Current</th>
                    <th>Average</th>
                    <th>Threshold</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($latencyIssues)):
                foreach ($latencyIssues as $monitor):
                    // Calculate threshold
                    $threshold = $monitor['avg_max'] + (5 * $monitor['avg_stddev']);

                    // Calculate how much over threshold
                    $percentOver = (($monitor['current_median'] - $threshold) / $threshold) * 100;

                    // Determine severity class based on percentage over threshold.
                    // Use bg-*-subtle (not table-*) so the row tints flip
                    // cleanly with the dark-mode theme.
                    if ($percentOver >= 100) {
                        $rowClass = 'bg-danger-subtle text-danger-emphasis';   // More than double the threshold
                    } elseif ($percentOver >= 50) {
                        $rowClass = 'bg-warning-subtle text-warning-emphasis'; // 50-100% over threshold
                    } else {
                        $rowClass = 'bg-info-subtle text-info-emphasis';       // Up to 50% over threshold
                    }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td>
                            <a href="/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="text-decoration-none">
                                <?= !empty($monitor['description']) ? htmlspecialchars($monitor['description']) : htmlspecialchars($monitor['id']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($monitor['agent_name']) ?></td>
                        <td><?= htmlspecialchars($monitor['target_address']) ?></td>
                        <td><?= number_format($monitor['current_median'], 2) ?> ms</td>
                        <td><?= number_format($monitor['avg_median'], 2) ?> ms</td>
                        <td><?= number_format($threshold, 2) ?> ms</td>
                    </tr>
                <?php endforeach;
            endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
