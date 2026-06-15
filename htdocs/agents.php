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

// Per-user show_inactive preference. The listing pages don't
// render the toggle, but they keep the session value in sync
// with the detail pages (agent.php, target.php) that do.
$show_inactive = wanportal_get_show_inactive();

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
// Toast on redirect from agents_edit.php?saved=1; strip the
// query param after firing so a refresh doesn't re-toast.
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