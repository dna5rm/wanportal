<?php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
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

// Latency Report auto-refreshes every 5 minutes. Pass the meta
// tag through head_extras so it lives inside <head> (required
// for browsers to honor it).
$head_extras = '    <meta http-equiv="refresh" content="300">' . "\n";

wanportal_render_head('Latency Report', ['datatables' => true, 'head_extras' => $head_extras]);
wanportal_render_header_row('Latency Report');
?>

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

    <?php wanportal_render_page_end(); ?>
