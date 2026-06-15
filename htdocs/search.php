<?php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
$search = trim($_GET['q'] ?? '');
if (empty($search)) {
    header('Location: /index.php');
    exit;
}

$show_inactive = wanportal_get_show_inactive();

try {
    // Search query with prepared statement
    $stmt = $mysqli->prepare("
        SELECT 
            m.*,
            a.name as agent_name,
            a.address as agent_address,
            a.description as agent_description,
            a.is_active as agent_is_active,
            t.address as target_address,
            t.description as target_description,
            t.is_active as target_is_active
        FROM monitors m
        JOIN agents a ON m.agent_id = a.id
        JOIN targets t ON m.target_id = t.id
        WHERE 
            m.description LIKE ? OR
            a.name LIKE ? OR
            a.address LIKE ? OR
            t.address LIKE ? OR
            t.description LIKE ?
        ORDER BY m.description, a.name, t.address
    ");

    $search_param = "%$search%";
    $stmt->bind_param("sssss", 
        $search_param, $search_param, $search_param, 
        $search_param, $search_param
    );

    $stmt->execute();
    $result = $stmt->get_result();
    $monitors = [];

    // Calculate statistics while fetching
    $stats = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'effectively_inactive' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        // Compute the color classes used by the results table.
        // (search.php only renders the current_* colors; the avg_*
        // ones are computed but unused — harmless.)
        monitor_color_classes($row);

        // Calculate effective status
        $row['effectively_active'] = $row['is_active'] &&
                                   $row['agent_is_active'] &&
                                   $row['target_is_active'];

        // Update statistics
        $stats['total']++;
        if ($row['effectively_active']) {
            $stats['active']++;
        } else {
            $stats['effectively_inactive']++;
            if (!$row['is_active']) {
                $stats['inactive']++;
            }
        }

        $monitors[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
wanportal_render_head('Search Results', ['datatables' => true]);
wanportal_render_header_row('Search: ' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8'), [], [
    'show_inactive_toggle' => true,
]);
?>

<!-- Search form. Lives in its own row after the standard header
     so the user can re-run a search without scrolling back up to
     the page title. The form is a thin row (mb-2) so it doesn't
     compete with the page title visually. -->
<form action="/search.php" method="GET" class="row mb-2 justify-content-end">
    <div class="col-md-4 col-lg-3">
        <div class="input-group input-group-sm">
            <input type="text"
                name="q"
                class="form-control"
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="Search monitors..."
                aria-label="Search monitors">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search"></i>
            </button>
        </div>
    </div>
</form>

<div class="row">
        <!-- Statistics Column -->
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Search Statistics</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Active Monitors
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle rounded-pill">
                                <?= $stats['active'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Inactive Monitors
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill">
                                <?= $stats['inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Effectively Inactive
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle rounded-pill">
                                <?= $stats['effectively_inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Results
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle rounded-pill">
                                <?= $stats['total'] ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Results Column -->
        <div class="col-md-9">
            <!-- Results Table -->
            <div class="table-responsive">
                <table id="tablePager" class="table table-bordered table-striped table-hover" data-empty-message="No results found">
                    <thead>
                        <tr>
                            <th>Monitor</th>
                            <th>Agent</th>
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
                                // Skip if inactive and not showing inactive
                                if (!$m['effectively_active'] && !$show_inactive) {
                                    continue;
                                }
                                ?>
                                <tr class="<?= $m['effectively_active'] ? '' : 'table-secondary' ?>">
                                    <td>
                                        <a href="/monitor.php?id=<?= htmlspecialchars($m['id']) ?>"
                                           class="<?= $m['effectively_active'] ? 'text-decoration-none' : 'text-muted' ?>"
                                           title="<?= htmlspecialchars($m['id']) ?>"
                                           data-bs-toggle="tooltip">
                                            <?= !empty($m['description']) ? htmlspecialchars($m['description']) : htmlspecialchars($m['id']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="/agent.php?id=<?= htmlspecialchars($m['agent_id']) ?>"
                                           class="<?= $m['effectively_active'] ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['agent_name']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="/target.php?id=<?= htmlspecialchars($m['target_id']) ?>"
                                           class="<?= $m['effectively_active'] ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['target_address']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="<?= $m['effectively_active'] ? '' : 'text-muted' ?>"
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
                                        <span class="badge <?= $m['effectively_active'] ? $m['current_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center bg-primary-subtle">
                                        <span class="badge <?= $m['effectively_active'] ? $m['current_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_loss']) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?= $m['effectively_active'] ? '' : 'text-muted' ?>"
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