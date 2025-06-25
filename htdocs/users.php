<?php
// users.php
session_start();
require_once 'config.php';

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

// Fetch users from API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost/cgi-bin/api/users",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $_SESSION['token'],
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$users = [];
if ($status === 200) {
    $data = json_decode($response, true);
    if ($data['status'] === 'success') {
        $users = $data['users'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h3>User Management</h3>
        </div>
        <div class="col text-end">
            <a href="/user_edit.php" class="btn btn-primary btn-sm">
                <i class="bi bi-person-plus"></i> New User
            </a>
        </div>
    </div>

    <!-- Search/Filter -->
    <div class="row mb-3">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" id="searchFilter" class="form-control" placeholder="Search users...">
                        </div>
                        <div class="col-md-2">
                            <select id="adminFilter" class="form-select">
                                <option value="">All Users</option>
                                <option value="1">Admins Only</option>
                                <option value="0">Non-Admins Only</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="showInactive">
                                <label class="form-check-label" for="showInactive">
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
        <table class="table table-hover">
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
                            <span class="badge bg-<?= $user['is_admin'] ? 'danger' : 'primary' ?>">
                                <?= $user['is_admin'] ? 'Admin' : 'User' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $user['is_active'] ? 'success' : 'warning' ?>">
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
                                            onclick="deleteUser('<?= htmlspecialchars($user['id']) ?>', '<?= htmlspecialchars($user['username']) ?>')"
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

<?php include 'footer.php'; ?>

<script>
// Filter functionality
document.getElementById('searchFilter').addEventListener('input', filterUsers);
document.getElementById('adminFilter').addEventListener('change', filterUsers);
document.getElementById('showInactive').addEventListener('change', filterUsers);

function filterUsers() {
    const search = document.getElementById('searchFilter').value.toLowerCase();
    const adminFilter = document.getElementById('adminFilter').value;
    const showInactive = document.getElementById('showInactive').checked;
    
    const rows = document.querySelectorAll('tbody tr');
    
    rows.forEach(row => {
        const username = row.cells[0].textContent.toLowerCase();
        const fullName = row.cells[1].textContent.toLowerCase();
        const email = row.cells[2].textContent.toLowerCase();
        const isAdmin = row.querySelector('.badge').textContent.trim() === 'Admin';
        const isActive = !row.classList.contains('table-secondary');
        
        const searchMatch = username.includes(search) || 
                          fullName.includes(search) || 
                          email.includes(search);
        
        const adminMatch = adminFilter === '' || 
                          (adminFilter === '1' && isAdmin) || 
                          (adminFilter === '0' && !isAdmin);
        
        const activeMatch = showInactive || isActive;
        
        row.style.display = (searchMatch && adminMatch && activeMatch) ? '' : 'none';
    });
}

// Delete confirmation
function deleteUser(id, username) {
    if (confirm(`Are you sure you want to delete user "${username}"?`)) {
        fetch(`/cgi-bin/api/users/${id}`, {
            method: 'DELETE',
            headers: {
                'Authorization': 'Bearer <?= $_SESSION['token'] ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('Error deleting user: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}
</script>
</body>
</html>