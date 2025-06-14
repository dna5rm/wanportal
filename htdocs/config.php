<?php
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
                'icon' => 'bi bi-hdd-network',
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
            ],
            'PHP Info' => [
                'url' => '/phpinfo.php',
                'icon' => 'bi bi-info-circle',
                'admin' => true
            ]
        ]
    ],
    'IPControl' => [
        'type' => 'dropdown',
        'icon' => 'bi bi-hdd-network',
        'auth' => false,
        'items' => [
            'Network Map' => [
                'url' => '#',
                'icon' => 'bi bi-diagram-3'
            ],
            'IP Management' => [
                'url' => '#',
                'icon' => 'bi bi-grid-3x3'
            ],
            'DHCP Leases' => [
                'url' => '#',
                'icon' => 'bi bi-card-list'
            ]
        ]
    ]
];
?>