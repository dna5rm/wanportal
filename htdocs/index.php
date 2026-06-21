<?php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();

// Fetch active agents for sidebar
$agentsResponse = api_get('/agents');
$activeAgents = array_filter($agentsResponse['agents'] ?? [], function($agent) {
    return $agent['is_active'] == 1;
});

// Fetch down monitors
$monitorsResponse = api_get('/monitors?current_loss=100&is_active=1');
$downHosts = $monitorsResponse['monitors'] ?? [];

// Fetch all monitors (active+inactive) for the summary cards and
// "top 5 slowest" widget. We compute the dashboard stats here
// rather than hitting a specialized API endpoint so the layout
// can be tweaked without a Perl-side change.
$allMonitorsResponse = api_get('/monitors?is_active=1');
$allMonitors = $allMonitorsResponse['monitors'] ?? [];

$monitor_stats = [
    'total'     => 0,
    'up'        => 0,
    'degraded'  => 0,    // 1 <= loss < 100
    'down'      => 0,    // loss >= 100
    'effectively_active' => 0,  // monitor active AND agent active AND target active
];
foreach ($allMonitors as $m) {
    $monitor_stats['total']++;
    $loss = (float)($m['current_loss'] ?? 0);
    if ($loss >= 100)        $monitor_stats['down']++;
    elseif ($loss >= 1)      $monitor_stats['degraded']++;
    else                     $monitor_stats['up']++;
    // Effectively-active means the link is monitored (i.e. not
    // disabled by the operator). We approximate this by the
    // current_loss being a recent value (0..100); 0 means
    // "sampled and fine" which is what effective-active looks like.
    if ($m['is_active'] == 1 && $m['agent_is_active'] == 1 && $m['target_is_active'] == 1) {
        $monitor_stats['effectively_active']++;
    }
}
$pct_up        = $monitor_stats['total'] > 0 ? round(100 * $monitor_stats['up']        / $monitor_stats['total']) : 0;
$pct_degraded  = $monitor_stats['total'] > 0 ? round(100 * $monitor_stats['degraded']  / $monitor_stats['total']) : 0;
$pct_down      = $monitor_stats['total'] > 0 ? round(100 * $monitor_stats['down']      / $monitor_stats['total']) : 0;

// Top 5 slowest by current_median (excluding down monitors —
// those are already in the down table below)
$topSlow = $allMonitors;
usort($topSlow, function ($a, $b) {
    $a_loss = (float)($a['current_loss'] ?? 0);
    $b_loss = (float)($b['current_loss'] ?? 0);
    if ($a_loss >= 100 && $b_loss < 100) return 1;  // down sorts to end
    if ($a_loss < 100 && $b_loss >= 100) return -1;
    return (float)($b['current_median'] ?? 0) <=> (float)($a['current_median'] ?? 0);
});
$topSlow = array_slice($topSlow, 0, 5);

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

// Standard page chrome. The dashboard auto-refreshes every 5
// minutes via a meta tag, which we pass in 'head_extras' since
// it must live inside <head> to be honored by the browser.
// DataTables is used by the Down Monitors table at the bottom
// of the page.
wanportal_render_head('Console', [
    'datatables'  => true,
    'head_extras' => '<meta http-equiv="refresh" content="300">',
]);
?>
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
                    <a href="/latency.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-speedometer2"></i> Latency </a> |
                    <a href="/api-docs/swagger.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-braces"></i> API </a>
                    <a href="/server.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-graph-up"></i> Runtime </a>
                    <form action="/search.php" method="GET" class="d-flex align-items-center">
                        <input type="text" name="q" class="form-control form-control-sm me-2" placeholder="Search monitors...">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Dashboard summary cards: total monitors split by
                 status. Sits directly above the Top 5 slowest
                 widget so the two summary tables read together as a
                 pair: cards give the high-level breakdown, slowest
                 table gives the per-link detail. -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card stat-card h-100 border">
                        <div class="card-body">
                            <div class="stat-label text-muted">Total monitors</div>
                            <div class="stat-number"><?= $monitor_stats['total'] ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 border border-success-subtle bg-success-subtle">
                        <div class="card-body">
                            <div class="stat-label text-success-emphasis">Up</div>
                            <div class="stat-number text-success-emphasis"><?= $monitor_stats['up'] ?>
                                <span class="stat-sub">(<?= $pct_up ?>%)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 border border-warning-subtle bg-warning-subtle">
                        <div class="card-body">
                            <div class="stat-label text-warning-emphasis">Degraded</div>
                            <div class="stat-number text-warning-emphasis"><?= $monitor_stats['degraded'] ?>
                                <span class="stat-sub">(<?= $pct_degraded ?>%)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card h-100 border border-danger-subtle bg-danger-subtle">
                        <div class="card-body">
                            <div class="stat-label text-danger-emphasis">Down</div>
                            <div class="stat-number text-danger-emphasis"><?= $monitor_stats['down'] ?>
                                <span class="stat-sub">(<?= $pct_down ?>%)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top 5 slowest monitors (excluding down — those
                 are in the down table below) -->
            <?php if (!empty($topSlow)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-speedometer2"></i> Top 5 slowest links (by current median)
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Monitor</th>
                                <th>Agent</th>
                                <th>Target</th>
                                <th class="text-end">Median</th>
                                <th class="text-end">Loss</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topSlow as $m):
                            $loss = (float)($m['current_loss'] ?? 0);
                            // Use Bootstrap 5.3 "subtle" color tokens so the
                            // badge adapts to dark mode. The saturated
                            // bg-success / bg-warning / bg-danger variants
                            // stay bright in both themes and look out of
                            // place on a dark surface.
                            if ($loss >= 100) {
                                $badge = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
                            } elseif ($loss >= 1) {
                                $badge = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                            } else {
                                $badge = 'bg-success-subtle text-success-emphasis border border-success-subtle';
                            }
                        ?>
                            <tr>
                                <td>
                                    <a href="/monitor.php?id=<?= htmlspecialchars($m['id']) ?>"
                                       class="text-decoration-none">
                                        <?= htmlspecialchars($m['description'] ?? $m['id']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($m['agent_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($m['target_address'] ?? '-') ?></td>
                                <td class="text-end"><?= htmlspecialchars($m['current_median'] ?? '-') ?> ms</td>
                                <td class="text-end">
                                    <span class="badge <?= $badge ?>">
                                        <?= number_format($loss, 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Down Monitors
                 Re-styled to match the Top 5 slowest widget: a card
                 wrapper with a card-header showing the icon and
                 title, and a compact `table table-sm mb-0` body. The
                 row-level severity classes (table-danger / -warning /
                 -info) are preserved so the time-since-down coloring
                 still works. -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle"></i> Down Monitors</span>
                    <a href="/cgi-bin/api/monitors?current_loss=100&is_active=1"
                       class="btn btn-sm btn-outline-secondary"
                       target="_blank" title="View raw API data" data-bs-toggle="tooltip">
                        <i class="bi bi-code-slash"></i> Raw Data
                    </a>
                </div>
                <div class="table-responsive">
                    <table id="tablePager" class="table table-sm mb-0" data-empty-message="No down monitors">
                        <thead>
                            <tr>
                                <th>Monitor</th>
                                <th>Agent</th>
                                <th>Target</th>
                                <th class="text-end">Down Since</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($downHosts)):
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
                                    <td class="text-end"><?= date("m/d H:i:s", strtotime($monitor['last_down'])) ?></td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php wanportal_render_page_end(); ?>

<?php
// Log execution time if in debug mode
if (DEBUG_MODE) {
    $execution_time = microtime(true) - $start_time;
    error_log("Page generated in {$execution_time} seconds");
}
?>
