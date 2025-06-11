<!DOCTYPE html>
<html lang="en">
<head>
    <title>NetPing Dashboard</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark bg-primary mb-4">
    <div class="container-fluid">
        <span class="navbar-brand">NetPing Dashboard</span>
    </div>
</nav>
<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-12 col-md-6 col-lg-4">
            <div class="list-group shadow">
                <a href="agents.php"   class="list-group-item list-group-item-action">Agents</a>
                <a href="targets.php"  class="list-group-item list-group-item-action">Targets</a>
                <a href="monitors.php" class="list-group-item list-group-item-action">Monitors</a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>