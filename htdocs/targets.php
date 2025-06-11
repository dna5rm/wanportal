<?php
// DB config from environment
$db_host = getenv('MYSQL_HOST') ?: 'localhost';
$db_user = getenv('MYSQL_USER') ?: 'root';
$db_pass = getenv('MYSQL_PASSWORD') ?: 'netops';
$db_name = getenv('MYSQL_DB') ?: 'netping';

// Fetch all targets
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) die("DB connection failed: " . $mysqli->connect_error);
$result = $mysqli->query("SELECT * FROM targets");
$fields = $result ? $result->fetch_fields() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NetPing Targets</title>
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
        <span class="navbar-text ms-auto">Targets Listing</span>
    </div>
</nav>
<div class="container">
    <h3 class="mb-3">All Targets</h3>
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
        <div class="alert alert-warning my-4">No targets found.</div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$mysqli->close();
?>