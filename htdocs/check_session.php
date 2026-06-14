<?php
// check_session.php

// session_start() is invoked by the caller (via wanportal_session_start()
// from config.php, which is included before this file). No need to
// start the session again here.

function debug_to_console($data) {
    $output = $data;
    if (is_array($output))
        $output = implode(',', $output);

    echo "<script>console.log('" . addslashes($output) . "');</script>";
}

function is_session_valid() {
    debug_to_console("Checking session validity...");
    
    if (!isset($_SESSION['user']) || !isset($_SESSION['token'])) {
        debug_to_console("Session invalid: user or token not set");
        return false;
    }

    if (!isset($_SESSION['last_activity'])) {
        debug_to_console("Session invalid: last_activity not set");
        return false;
    }

    $inactivity_period = 1800; // 30 minutes
    $current_time = time();
    $last_activity = $_SESSION['last_activity'];

    debug_to_console("Current time: " . date('Y-m-d H:i:s', $current_time) . " (" . $current_time . ")");
    debug_to_console("Last activity: " . date('Y-m-d H:i:s', $last_activity) . " (" . $last_activity . ")");
    debug_to_console("Inactivity period: " . $inactivity_period . " seconds");
    debug_to_console("Time since last activity: " . ($current_time - $last_activity) . " seconds");

    // Check if last_activity is in the future
    if ($last_activity > $current_time) {
        debug_to_console("Session invalid: last_activity is in the future");
        return false;
    }

    // Check if the session has expired
    if ($current_time - $last_activity > $inactivity_period) {
        debug_to_console("Session expired: " . ($current_time - $last_activity) . " seconds of inactivity");
        return false;
    }

    // Session is valid, update last activity time
    $_SESSION['last_activity'] = $current_time;
    debug_to_console("Session is valid, updated last_activity");
    return true;
}

function check_session() {
    if (!is_session_valid()) {
        // Clear any existing session data
        session_unset();
        session_destroy();
        
        // Redirect to login page
        header('Location: /login.php');
        exit;
    }
}

// Call the check_session function
check_session();

?>