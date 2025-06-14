<?php
session_start();
require_once 'config.php';

// Check if user is authenticated
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: phpinfo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="/assets/base.css">
    <style>
        /* Style for phpinfo output */
        .container {
            margin-top: 20px;
        }
        .phpinfo {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .phpinfo table {
            width: 100%;
            margin-bottom: 1rem;
        }
        .phpinfo td, .phpinfo th {
            padding: 0.5rem;
        }
        .phpinfo h1, .phpinfo h2 {
            color: #000080;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
    <div class="phpinfo">
        <?php phpinfo(); ?>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>