<?php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();

// Monitors come from the public API via the shared api_get() helper;
// the raw curl-to-API dance this page used to do is gone.
$data = api_get('/monitors');

$latencyIssues = [];
if ($data && ($data['status'] ?? '') === 'success') {
    // Filter monitors for latency issues
    $latencyIssues = array_filter($data['monitors'], 'wanportal_is_latency_issue');
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
                    // Threshold from lib/monitor_metrics.php so this
                    // display matches wanportal_is_latency_issue()
                    // exactly (avg_median + 2*avg_stddev, the same
                    // bar as the monitor badge colors).
                    $threshold = wanportal_latency_threshold($monitor);

                    // Guard percentOver: a <= 0 threshold (no
                    // baseline) never reaches this loop, but the
                    // division must stay divide-by-zero safe.
                    $percentOver = ($threshold > 0)
                        ? (($monitor['current_median'] - $threshold) / $threshold) * 100
                        : 0;

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
                            <a href="/classic/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="text-decoration-none">
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
