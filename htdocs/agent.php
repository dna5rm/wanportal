<?php
// agent.php

require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
$id = $_GET['id'] ?? '';
if (!$id) die("No agent ID specified");

// Read+write the per-user show_inactive preference. The
// show_inactive_toggle option in the header row below reads the
// session value to decide whether the toggle is checked.
$show_inactive = wanportal_get_show_inactive();

try {
    // Fetch agent info with prepared statement
    $stmt = $mysqli->prepare("
        SELECT id, name, address, description, last_seen, is_active
        FROM agents
        WHERE id = ?
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("s", $id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        throw new Exception("Agent not found");
    }

    $agent = $result->fetch_assoc();
    $stmt->close();

    // Fetch monitors with all related info using JOIN
    $stmt = $mysqli->prepare("
        SELECT
            m.*,
            t.address as target_address,
            t.description as target_description,
            t.is_active as target_is_active
        FROM monitors m
        JOIN targets t ON m.target_id = t.id
        WHERE m.agent_id = ?
        ORDER BY m.description, m.id
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("s", $id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $monitors = [];

    // Calculate monitor statistics while fetching
    $monitor_stats = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'effectively_inactive' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        // Compute the color classes used by the table (current vs
        // average comparisons, and range-based comparisons for the
        // lifetime averages). See lib/monitor_metrics.php for the
        // threshold definitions.
        monitor_color_classes($row);

        // Update statistics
        $monitor_stats['total']++;
        if ($row['is_active'] && $row['target_is_active'] && $agent['is_active']) {
            $monitor_stats['active']++;
        } else {
            $monitor_stats['effectively_inactive']++;
            if (!$row['is_active']) {
                $monitor_stats['inactive']++;
            }
        }

        $monitors[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Build the action list. The "Agent" action is conditional on
// the agent's name (LOCAL agents don't have a netping script to
// show), and both actions are gated on auth.
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
        <!-- Agent Info Column -->
        <div class="col-md-3">
            <!-- Agent Details Card -->
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

            <!-- Monitor Statistics Card (now rendered by the
                 shared partial; the layout, color tokens, and
                 list-group structure match the previous hand-rolled
                 version exactly). -->
            <?php wanportal_render_stats_card('Statistics', [
                ['Active Monitors',         $monitor_stats['active'],              'success'],
                ['Inactive Monitors',       $monitor_stats['inactive'],            'warning'],
                ['Effectively Inactive',    $monitor_stats['effectively_inactive'], 'secondary'],
                ['Total Monitors',          $monitor_stats['total'],               'primary'],
            ]); ?>
        </div>

        <!-- Monitors Column -->
        <div class="col-md-9">
            <!-- Monitors Table -->
            <div class="table-responsive">
                <table id="tablePager" class="table table-bordered table-striped table-hover" data-empty-message="No monitors found">
                    <thead>
                        <tr>
                            <th>Monitor</th>
                            <th>Target</th>
                            <th>Protocol</th>
                            <th class="text-center bg-primary-subtle">Median</th>
                            <th class="text-center bg-primary-subtle">Loss</th>
                            <!--
                            <th class="text-center bg-secondary-subtle">Median</th>
                            <th class="text-center bg-secondary-subtle">Min</th>
                            <th class="text-center bg-secondary-subtle">Max</th>
                            <th class="text-center bg-secondary-subtle">Std Dev</th>
                            <th class="text-center bg-secondary-subtle">Loss</th>
                            -->
                            <th class="text-center">Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($monitors)): ?>
                            <?php foreach ($monitors as $m): ?>
                                <?php
                                // Calculate effective status
                                $effectively_active = $agent['is_active'] && $m['target_is_active'] && $m['is_active'];
                                
                                // Skip if inactive and not showing inactive
                                if (!$effectively_active && !$show_inactive) {
                                    continue;
                                }
                                ?>
                                <tr class="<?= $effectively_active ? '' : 'bg-secondary-subtle' ?>">
                                    <td>
                                        <?php if (!$effectively_active): ?>
                                            <del class="text-muted">
                                        <?php endif; ?>
                                        
                                        <a href="/monitor.php?id=<?= htmlspecialchars($m['id']) ?>" 
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
                                            if (!$m['is_active']) $inactive_reason[] = "Monitor disabled";
                                            ?>
                                            <i class="bi bi-info-circle text-muted" 
                                               data-bs-toggle="tooltip" 
                                               title="Inactive: <?= implode(', ', $inactive_reason) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/target.php?id=<?= htmlspecialchars($m['target_id']) ?>"
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
                                    <!--
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_minimum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_min']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_maximum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_max']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_stddev_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_stddev']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-secondary-subtle">
                                        <span class="badge <?= $effectively_active ? $m['avg_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['avg_loss']) ?>%
                                        </span>
                                    </td>
                                    -->
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
</div>

<?php wanportal_render_page_end(); ?>
<?php $mysqli->close(); ?>