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

// Dashboard rollup (counts, percents) comes from the public
// /dashboard endpoint, so this page and any other consumer share one
// definition of up / degraded / down instead of the page re-sorting
// /monitors rows by hand. The rollup still carries top_slow, but the
// top-5 widget is gone from this page — nothing here reads it.
$dashboardResponse = api_get('/dashboard');
$dashboard = $dashboardResponse['dashboard'] ?? null;

// True when the /dashboard rollup is unusable: api_get() returns
// null on transport errors, non-200 responses, and undecodable JSON.
// The summary cards must not render their all-zero stats in that
// case.
$monitorsFetchFailed = ($dashboardResponse === null || !is_array($dashboard));

$monitor_stats = [
    'total'    => $dashboard['total']    ?? 0,
    'up'       => $dashboard['up']       ?? 0,
    'degraded' => $dashboard['degraded'] ?? 0,
    'down'     => $dashboard['down']     ?? 0,
];
$pct_up        = $dashboard['percent_up']       ?? 0;
$pct_degraded  = $dashboard['percent_degraded'] ?? 0;
$pct_down      = $dashboard['percent_down']     ?? 0;

// Initialize error message
$error_message = null;

// Check for API errors: a failure in any of the three fetches
// (agents, down monitors, dashboard rollup) must surface as the
// error banner rather than a dashboard full of zeros.
if ($agentsResponse === null || $monitorsResponse === null || $monitorsFetchFailed) {
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
                        <a href="/classic/agent.php?id=<?= htmlspecialchars($agent['id']) ?>" 
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
                    <a href="/classic/server.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-graph-up"></i> Runtime </a>
                    <form action="/classic/search.php" method="GET" class="d-flex align-items-center">
                        <input type="text" name="q" class="form-control form-control-sm me-2" placeholder="Search monitors...">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Dashboard summary cards: total monitors split by
                 status. When the /dashboard rollup fetch fails the
                 cards are skipped entirely — a wall of zeros would
                 read as "everything healthy". -->
            <?php if (!$monitorsFetchFailed): ?>
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
            <?php endif; ?>

            <!-- Down Monitors -->
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
                                        <a href="/classic/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>"
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

<?php wanportal_render_page_end(); ?>

<?php
// Log execution time if in debug mode
if (DEBUG_MODE) {
    $execution_time = microtime(true) - $start_time;
    error_log("Page generated in {$execution_time} seconds");
}
?>
