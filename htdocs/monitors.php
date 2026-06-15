<?php
session_start();
require_once 'check_session.php';
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';

// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Read+write the per-user show_inactive preference. The value
// flows to the detail pages (agent.php, target.php) that DO
// render a toggle, so the listing pages keep it in sync even
// though they don't render the toggle themselves.
$show_inactive = wanportal_get_show_inactive();

// Fetch monitors from API (includes agent and target info)
$ch = curl_init("http://localhost/cgi-bin/api/monitors");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$monitors = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $monitors = $data['monitors'];
    }
}

// Standard page chrome + header row. DataTables is used here so
// we pass 'datatables' => true in the head options. The "New
// Monitor" action matches the listing-page convention documented
// in the wanportal skill: a primary button on the right of the
// header row, gated on auth.
wanportal_render_head('Monitors', ['datatables' => true]);
wanportal_render_header_row('Monitors', [
    [
        'url'     => '/monitors_edit.php',
        'icon'    => 'bi bi-plus-circle',
        'label'   => 'New Monitor',
        'variant' => 'primary',
        'auth'    => true,
    ],
]);
?>
        <table id="tablePager" class="table table-hover" data-order='[[6, "desc"]]'>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Agent</th>
                    <th>Target</th>
                    <th>Protocol</th>
                    <th>Port</th>
                    <th>DSCP</th>
                    <th>Status</th>
                    <th>Last Update</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
        <?php foreach ($monitors as $monitor): ?>
                        <?php
                        // Calculate effective active status
                        $effectively_active = ($monitor['is_active'] == 1 && 
                                           $monitor['agent_is_active'] == 1 && 
                                           $monitor['target_is_active'] == 1);
                        ?>
                        <tr class="<?= $effectively_active ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/monitor.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($monitor['description']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="/agents_edit.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>"
                                   class="<?= $monitor['agent_is_active'] == 1 ? '' : 'text-muted' ?>">
                                    <?= htmlspecialchars($monitor['agent_name']) ?>
                                    <?= $monitor['agent_is_active'] == 1 ? '' : ' (disabled)' ?>
                                </a>
                            </td>
                            <td>
                                <a href="/targets_edit.php?id=<?= htmlspecialchars($monitor['target_id']) ?>"
                                   class="<?= $monitor['target_is_active'] == 1 ? '' : 'text-muted' ?>">
                                    <?= htmlspecialchars($monitor['target_address']) ?>
                                    <?= $monitor['target_is_active'] == 1 ? '' : ' (disabled)' ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($monitor['protocol']) ?></td>
                            <td><?= $monitor['port'] ? htmlspecialchars($monitor['port']) : '-' ?></td>
                            <td><?= htmlspecialchars($monitor['dscp']) ?></td>
                            <td>
                                <span class="badge bg-<?= $effectively_active ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
                                    <?php if ($effectively_active): ?>
                                        Active
                                    <?php else: ?>
                                        Inactive
                                        <?php if ($monitor['agent_is_active'] != 1): ?>
                                            (Agent)
                                        <?php endif; ?>
                                        <?php if ($monitor['target_is_active'] != 1): ?>
                                            (Target)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($monitor['last_update']): ?>
                                    <span data-bs-toggle="tooltip" 
                                          title="<?= htmlspecialchars($monitor['last_update']) ?>">
                                        <?= date('Y-m-d H:i', strtotime($monitor['last_update'])) ?>
                                    </span>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/monitors_edit.php?id=<?= htmlspecialchars($monitor['id']) ?>" 
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="deleteMonitor('<?= htmlspecialchars($monitor['id']) ?>', '<?= htmlspecialchars($monitor['description']) ?>')"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<script>
// Surface a toast on the listing page when the user was just
// redirected here from an *edit.php save (the edit form
// appends ?saved=1 on success). We strip the query param
// after firing so a refresh doesn't re-toast.
wanportalPageOnLoad = function() {
    var url = new URL(window.location.href);
    if (url.searchParams.get('saved') === '1') {
        showToast('Monitor saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};
</script>
<?php wanportal_render_page_end(); ?>