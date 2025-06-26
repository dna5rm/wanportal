<?php
session_start();
require_once '/srv/htdocs/config.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <title><?= isset($_SERVER['SERVER_NAME']) ? 
        strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0]) : 
        'NETPING'; ?> :: API Documentation</title>
    <meta charset="utf-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <meta http-equiv="refresh" content="300">
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- SwaggerUI -->
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@latest/swagger-ui.css" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include '/srv/htdocs/navbar.php'; ?>

<div id="swagger-ui"></div>

<?php include '/srv/htdocs/footer.php'; ?>
<script src="https://unpkg.com/swagger-ui-dist@latest/swagger-ui-bundle.js"></script>
<script>
    window.onload = () => {
        window.ui = SwaggerUIBundle({
            url: '/api-docs/openapi.yaml',
            dom_id: '#swagger-ui',
        });
    };
</script>
</body>
</html>
