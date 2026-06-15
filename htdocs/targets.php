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

// Fetch targets from API
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

// Standard page chrome + header row. DataTables is used here so
// we pass 'datatables' => true in the head options. The "New
// Target" action matches the listing-page convention documented
// in the wanportal skill: a primary button on the right of the
// header row, gated on auth.
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
// Surface a toast on the listing page when the user was just
// redirected here from an *edit.php save (the edit form
// appends ?saved=1 on success). We strip the query param
// after firing so a refresh doesn't re-toast.
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