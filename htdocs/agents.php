<?php
session_start();
// DB config from environment
$db_host = getenv('MYSQL_HOST') ?: 'localhost';
$db_user = getenv('MYSQL_USER') ?: 'root';
$db_pass = getenv('MYSQL_PASSWORD') ?: 'netops';
$db_name = getenv('MYSQL_DB') ?: 'netping';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_errno) die("DB connection failed: " . $mysqli->connect_error);
    $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE username=?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($pw_hash_db);
    if ($stmt->fetch() && hash('sha256', $password) === $pw_hash_db) {
        $_SESSION['user'] = $username;
    } else {
        $error = "Invalid username or password";
    }
    $stmt->close();
    $mysqli->close();
}
// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header('Location: agents.php');
    exit;
}

// Show login form if not logged in
if (!isset($_SESSION['user'])): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NetPing Agents Login</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
<div class="card shadow" style="min-width:320px;max-width:340px;">
    <div class="card-body">
        <h4 class="mb-4 text-center">NetPing Agents Login</h4>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-1"><?=$error?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input name="username" class="form-control" autofocus required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input name="password" type="password" class="form-control" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Login</button>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php exit; endif; ?>

<?php
// Fetch agents info
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) die("DB connection failed: " . $mysqli->connect_error);
$result = $mysqli->query("SELECT * FROM agents");
$fields = $result ? $result->fetch_fields() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NetPing Agents</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .table thead th { position: sticky; top: 0; background: #f9fafb; }
        .table-responsive { max-height: 75vh; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark bg-primary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">NetPing</a>
        <form method="post" class="ms-auto">
            <button name="logout" class="btn btn-outline-light btn-sm" type="submit">Logout</button>
        </form>
    </div>
</nav>
<div class="container">
    <h3 class="mb-3">Agents <small class="text-body-secondary">(user: <?=htmlspecialchars($_SESSION['user'])?>)</small></h3>
    <?php if ($result && $result->num_rows): ?>
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
            <tr>
                <?php foreach ($fields as $f): ?>
                    <th><?=htmlspecialchars($f->name)?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                <?php foreach ($fields as $f): ?>
                    <td><?=htmlspecialchars($row[$f->name])?></td>
                <?php endforeach; ?>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="alert alert-warning my-4">No agents found.</div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$mysqli->close();
?>