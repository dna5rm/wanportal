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

$ch = curl_init("http://localhost/cgi-bin/api/targets");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$targets = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $targets = $data['targets'];
    }
}

wanportal_render_head('Targets', ['datatables' => true]);
wanportal_render_header_row('Targets', [
    [
        'url'     => '/targets_edit.php',
        'icon'    => 'bi bi-plus-circle',
        'label'   => 'New Target',
        'variant' => 'primary',
        'auth'    => true,
    ],
]);
?>
        <table id="tablePager" class="table table-hover" data-order='[[2, "desc"]]'>
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
        <?php foreach ($targets as $target): ?>
                        <tr class="<?= $target['is_active'] ? '' : 'table-secondary' ?>">
                            <td>
                                <a href="/target.php?id=<?= htmlspecialchars($target['id']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($target['address']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($target['description']) ?></td>
                            <td>
                                <span class="badge bg-<?= $target['is_active'] ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
                                    <?= $target['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/targets_edit.php?id=<?= htmlspecialchars($target['id']) ?>" 
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="deleteTarget('<?= htmlspecialchars($target['id']) ?>', '<?= htmlspecialchars($target['address']) ?>')"
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
// Toast on redirect from targets_edit.php?saved=1.
wanportalPageOnLoad = function() {
    var url = new URL(window.location.href);
    if (url.searchParams.get('saved') === '1') {
        showToast('Target saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};
</script>
<?php wanportal_render_page_end(); ?>