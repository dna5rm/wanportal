<?php
// agent.php

require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
$id = $_GET['id'] ?? '';
if (!$id) die("No agent ID specified");

$show_inactive = wanportal_get_show_inactive();

// Detail and its monitor list both come from the public API.
// api_get() returns null on a 404 or transport failure, so a miss
// reads as "not found" here either way.
$agentResponse = api_get('/agents/' . rawurlencode($id));
if (!$agentResponse || ($agentResponse['status'] ?? '') !== 'success') {
    die("Error: Agent not found");
}
$agent = $agentResponse['agent'];

$monitorsResponse = api_get('/monitors?agent_id=' . rawurlencode($id));
$monitors = [];

$monitor_stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'effectively_inactive' => 0
];

foreach (($monitorsResponse['monitors'] ?? []) as $row) {
    // Color classes for the row (current vs lifetime avg).
    // Thresholds live in lib/monitor_metrics.php.
    monitor_color_classes($row);

    $monitor_stats['total']++;
    if ($row['is_active'] && $row['target_is_active'] && $agent['is_active']) {
        $monitor_stats['active']++;
    } else {
        $monitor_stats['effectively_inactive']++;
        // monitor_is_active is the monitor's own flag; is_active on the
        // row is the effective one (monitor AND agent AND target).
        if (!$row['monitor_is_active']) {
            $monitor_stats['inactive']++;
        }
    }

    $monitors[] = $row;
}

// Action buttons. "Agent" is hidden for LOCAL agents (no netping
// script); Edit is always available for authenticated users.
$actions = [];
if (isset($_SESSION['user']) && $agent['name'] != "LOCAL") {
    $actions[] = [
        'url'     => '/netping.php?id=' . htmlspecialchars($agent['id'], ENT_QUOTES, 'UTF-8'),
        'icon'    => 'bi bi-play-circle',
        'label'   => 'Agent',
        'variant' => 'warning',
    ];
}
if (isset($_SESSION['user'])) {
    $actions[] = [
        'url'     => '/agents_edit.php?id=' . htmlspecialchars($agent['id'], ENT_QUOTES, 'UTF-8'),
        'icon'    => 'bi bi-pencil',
        'label'   => 'Edit',
        'variant' => 'danger',
    ];
}

wanportal_render_head('Agent: ' . (!empty($agent['name']) ? $agent['name'] : $agent['id']), ['datatables' => true]);
wanportal_render_header_row(
    'Agent: ' . (!empty($agent['name']) ? $agent['name'] : $agent['id']),
    $actions,
    ['show_inactive_toggle' => true]
);
?>

    <div class="row">
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Details</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>ID:</strong><br/>
                            <?= htmlspecialchars($agent['id']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Address:</strong><br/>
                            <?= htmlspecialchars($agent['address']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Description:</strong><br/>
                            <?= htmlspecialchars($agent['description']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Status:</strong><br/>
                            <?php if ($agent['is_active']): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Inactive</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Seen:</strong><br/>
                            <?= htmlspecialchars($agent['last_seen']) ?>
                        </li>
                    </ul>
                </div>
            </div>

            <?php wanportal_render_stats_card('Statistics', [
                ['Active Monitors',         $monitor_stats['active'],              'success'],
                ['Inactive Monitors',       $monitor_stats['inactive'],            'warning'],
                ['Effectively Inactive',    $monitor_stats['effectively_inactive'], 'secondary'],
                ['Total Monitors',          $monitor_stats['total'],               'primary'],
            ]); ?>
        </div>

        <div class="col-md-9">
            <div class="table-responsive">
                <table id="tablePager" class="table table-bordered table-striped table-hover" data-empty-message="No monitors found">
                    <thead>
                        <tr>
                            <th>Monitor</th>
                            <th>Target</th>
                            <th>Protocol</th>
                            <th class="text-center bg-primary-subtle">Median</th>
                            <th class="text-center bg-primary-subtle">Loss</th>
                            <th class="text-center">Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($monitors)): ?>
                        <?php foreach ($monitors as $m): ?>
                            <?php
                            $effectively_active = $agent['is_active'] && $m['target_is_active'] && $m['is_active'];
                            if (!$effectively_active && !$show_inactive) {
                                continue;
                            }
                            ?>
                                <tr class="<?= $effectively_active ? '' : 'bg-secondary-subtle' ?>">
                                    <td>
                                        <?php if (!$effectively_active): ?>
                                            <del class="text-muted">
                                        <?php endif; ?>
                                        
                                        <a href="/classic/monitor.php?id=<?= htmlspecialchars($m['id']) ?>" 
                                           class="<?= $effectively_active ? 'text-decoration-none' : 'text-muted' ?>"
                                           title="<?= htmlspecialchars($m['id']) ?>" 
                                           data-bs-toggle="tooltip">
                                            <?= !empty($m['description']) ? htmlspecialchars($m['description']) : htmlspecialchars($m['id']) ?>
                                        </a>

                                        <?php if (!$effectively_active): ?>
                                            </del>
                                            <?php
                                            $inactive_reason = [];
                                            if (!$agent['is_active']) $inactive_reason[] = "Agent disabled";
                                            if (!$m['target_is_active']) $inactive_reason[] = "Target disabled";
                                            if (!$m['monitor_is_active']) $inactive_reason[] = "Monitor disabled";
                                            ?>
                                            <i class="bi bi-info-circle text-muted" 
                                               data-bs-toggle="tooltip" 
                                               title="Inactive: <?= implode(', ', $inactive_reason) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/classic/target.php?id=<?= htmlspecialchars($m['target_id']) ?>"
                                           class="<?= $effectively_active ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['target_address']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="<?= $effectively_active ? '' : 'text-muted' ?>"
                                              title="DSCP: <?= htmlspecialchars($m['dscp']) ?>" 
                                              data-bs-toggle="tooltip">
                                            <?php if (strtoupper($m['protocol']) == 'ICMP'): ?>
                                                <?= strtoupper($m['protocol']) ?>
                                            <?php else: ?>
                                                <?= strtoupper($m['protocol']) ?>/<?= htmlspecialchars($m['port']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-primary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['current_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-primary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['current_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_loss']) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?= $effectively_active ? '' : 'text-muted' ?>"
                                              title="Last Down: <?= htmlspecialchars($m['last_down']) ?>" 
                                              data-bs-toggle="tooltip">
                                            <?= htmlspecialchars($m['last_update']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php wanportal_render_page_end(); ?>