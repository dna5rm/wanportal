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

// Fetch agents from API
$ch = curl_init("http://localhost/cgi-bin/api/agents");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$agents = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $agents = $data['agents'];
    }
}

// Standard page chrome + header row. DataTables is used here so
// we pass 'datatables' => true in the head options. The "New
// Agent" action matches the listing-page convention documented
// in the wanportal skill: a primary button on the right of the
// header row, gated on auth.
wanportal_render_head('Agents', ['datatables' => true]);
wanportal_render_header_row('Agents', [
    [
        'url'     => '/agents_edit.php',
        'icon'    => 'bi bi-plus-circle',
        'label'   => 'New Agent',
        'variant' => 'primary',
        'auth'    => true,
    ],
]);
?>
        <table id="tablePager" class="table table-hover" data-order='[[3, "desc"]]'>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
        <?php foreach ($agents as $agent): ?>
                        <tr class="<?= $agent['is_active'] ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/agent.php?id=<?= htmlspecialchars($agent['id']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($agent['name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($agent['address']) ?></td>
                            <td><?= htmlspecialchars($agent['description']) ?></td>
                            <td>
                                <span class="badge bg-<?= $agent['is_active'] ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
                                    <?= $agent['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($agent['last_seen']): ?>
                                    <span data-bs-toggle="tooltip" 
                                          title="<?= htmlspecialchars($agent['last_seen']) ?>">
                                        <?= date('Y-m-d H:i', strtotime($agent['last_seen'])) ?>
                                    </span>
                                <?php else: ?>
                                    Never
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/agents_edit.php?id=<?= htmlspecialchars($agent['id']) ?>" 
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($agent['name'] !== 'LOCAL'): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="deleteAgent('<?= htmlspecialchars($agent['id']) ?>', '<?= htmlspecialchars($agent['name']) ?>')"
                                                title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
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
        showToast('Agent saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};
</script>
<?php wanportal_render_page_end(); ?>