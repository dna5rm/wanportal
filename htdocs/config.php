<?php
// Constants
define('DEBUG_MODE', false);
define('HOUR_IN_SECONDS', 3600);
define('WARNING_THRESHOLD_HOURS', 3);
define('DANGER_THRESHOLD_HOURS', 5);
define('API_BASE_URL', 'http://localhost/cgi-bin/api');

// Database credentials
$db_host = getenv('MYSQL_HOST') ?: 'localhost';
$db_user = getenv('MYSQL_USER') ?: 'root';
$db_pass = getenv('MYSQL_PASSWORD') ?: 'netops';
$db_name = getenv('MYSQL_DB') ?: 'netping';

// Create mysqli connection
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    die("DB connection failed: " . $mysqli->connect_error);
}

// Menu Structure
$menuItems = [
    'Home' => [
        'url' => '/',
        'icon' => 'bi bi-house-door',
        'auth' => false
    ],
    'Admin' => [
        'type' => 'dropdown',
        'icon' => 'bi bi-shield-lock',
        'auth' => true,
        'items' => [
            'Agents' => [
                'url' => '/agents.php',
                'icon' => 'bi bi-server',
                'auth' => true
            ],
            'Targets' => [
                'url' => '/targets.php',
                'icon' => 'bi bi-bullseye',
                'auth' => true
            ],
            'Monitors' => [
                'url' => '/monitors.php',
                'icon' => 'bi bi-graph-up',
                'auth' => true
            ],
            'Credentials' => [
                'url' => '/credentials.php',
                'icon' => 'bi bi-key',
                'auth' => true
            ],
            'Users' => [
                'url' => '/users.php',
                'icon' => 'bi bi-people',
                'admin' => true
            ]
        ]
    ],
];
?>
