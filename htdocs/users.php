<?php
// users.php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
require_once 'check_session.php';

// Check authentication and admin status
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

// Check if user is admin
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /');  // Redirect to home page
    exit;
}

// Fetch users from the API via the shared api_get() helper
// (auto-loaded by config.php), matching agents/targets/monitors.
//
// Filtering is server-side: GET /users supports q (substring match over
// username/full_name/email), is_admin and is_active (0 or 1; any other
// value is ignored by the API, so they are normalized here too). The
// "Show Inactive" checkbox maps to is_active: checked == no is_active
// param (all users), unchecked == is_active=1 (active only).
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$is_admin = (isset($_GET['is_admin']) && preg_match('/^[01]$/', $_GET['is_admin'])) ? $_GET['is_admin'] : '';
$is_active = (isset($_GET['is_active']) && preg_match('/^[01]$/', $_GET['is_active'])) ? $_GET['is_active'] : '';
$show_inactive = ($is_active === '');

$api_params = [];
if ($q !== '') {
    $api_params['q'] = $q;
}
if ($is_admin !== '') {
    $api_params['is_admin'] = $is_admin;
}
if ($is_active !== '') {
    $api_params['is_active'] = $is_active;
}

$users = [];
$response = api_get('/users' . ($api_params ? '?' . http_build_query($api_params) : ''));
if ($response && ($response['status'] ?? '') === 'success') {
    $users = $response['users'] ?? [];
}

wanportal_render_head('Users', ['datatables' => true]);
wanportal_render_header_row('Users', [
    [
        'url'     => '/user_edit.php',
        'icon'    => 'bi bi-person-plus',
        'label'   => 'New User',
        'variant' => 'primary',
        // The "New User" button should only show for admins -- this
        // page is already admin-gated above, so the button is safe
        // to render unconditionally. (We could also pass 'auth' =>
        // true here, but on this page auth and admin are equivalent
        // by the time we reach the header row.)
    ],
]);
?>

    <!-- Search/Filter -->
    <div class="row mb-3">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" id="searchFilter" class="form-control" placeholder="Search users..."
                                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-2">
                            <select id="adminFilter" class="form-select">
                                <option value="" <?= $is_admin === '' ? 'selected' : '' ?>>All Users</option>
                                <option value="1" <?= $is_admin === '1' ? 'selected' : '' ?>>Admins Only</option>
                                <option value="0" <?= $is_admin === '0' ? 'selected' : '' ?>>Non-Admins Only</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="showInactiveFilter" <?= $show_inactive ? 'checked' : '' ?>>
                                <label class="form-check-label" for="showInactiveFilter">
                                    Show Inactive
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="table-responsive">
        <table id="tablePager" class="table table-hover">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr class="<?= $user['is_active'] ? '' : 'table-secondary' ?>">
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                        <td>
                            <?php if ($user['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($user['email']) ?>">
                                    <?= htmlspecialchars($user['email']) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $user['is_admin'] ? 'danger-subtle text-danger-emphasis border border-danger-subtle' : 'primary-subtle text-primary-emphasis border border-primary-subtle' ?>">
                                <?= $user['is_admin'] ? 'Admin' : 'User' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $user['is_active'] ? 'success-subtle text-success-emphasis border border-success-subtle' : 'warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['last_login']): ?>
                                <span data-bs-toggle="tooltip" 
                                      title="<?= htmlspecialchars($user['last_login']) ?>">
                                    <?= date('Y-m-d H:i', strtotime($user['last_login'])) ?>
                                </span>
                            <?php else: ?>
                                Never
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="/user_edit.php?id=<?= htmlspecialchars($user['id']) ?>" 
                                   class="btn btn-sm btn-outline-secondary"
                                   title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($user['username'] !== 'admin'): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="deleteUser('<?= htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>')"
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

<script>
// Toast on redirect from user_edit.php?saved=1; strip the query
// param after firing so a refresh doesn't re-toast.
wanportalPageOnLoad = function() {
    var url = new URL(window.location.href);
    if (url.searchParams.get('saved') === '1') {
        showToast('User saved', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, '', url.toString());
    }
};

// Server-side filters: navigate to the same URL with updated query
// params; PHP re-queries GET /users (q, is_admin, is_active) and
// re-renders the table. No client-side row hiding.

// Update one filter param and reload. An empty value removes the param
// entirely so the API sees no filter for it.
function applyUsersFilter(key, value) {
    var url = new URL(window.location.href);
    if (value === '' || value === null) {
        url.searchParams.delete(key);
    } else {
        url.searchParams.set(key, value);
    }
    window.location.href = url.toString();
}

// Free-text search: debounce keystrokes so typing doesn't reload the
// page per character; Enter applies immediately.
var searchTimer = null;
document.getElementById('searchFilter').addEventListener('input', function() {
    clearTimeout(searchTimer);
    var value = this.value.trim();
    searchTimer = setTimeout(function() {
        applyUsersFilter('q', value);
    }, 500);
});
document.getElementById('searchFilter').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimer);
        applyUsersFilter('q', this.value.trim());
    }
});

document.getElementById('adminFilter').addEventListener('change', function() {
    applyUsersFilter('is_admin', this.value);
});

document.getElementById('showInactiveFilter').addEventListener('change', function() {
    // Checked == show all users (no is_active param);
    // unchecked == active only (is_active=1).
    applyUsersFilter('is_active', this.checked ? '' : '1');
});

// Delete confirmation
function deleteUser(id, username) {
    if (confirm(`Are you sure you want to delete user "${username}"?`)) {
        proxyRequest('DELETE', '/users/' + id)
            .then(function(data) {
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Error deleting user: ' + data.message);
                }
            })
            .catch(function(error) {
                alert('Error: ' + error.message);
            });
    }
}
</script>
<?php wanportal_render_page_end(); ?>