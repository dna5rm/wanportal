<?php
// check_session.php

// session_start() is invoked by the caller (via wanportal_session_start()
// from config.php, which is included before this file). No need to
// start the session again here.

function is_session_valid() {
    if (!isset($_SESSION['user']) || !isset($_SESSION['token'])) {
        return false;
    }

    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    $inactivity_period = 1800; // 30 minutes
    $current_time = time();
    $last_activity = $_SESSION['last_activity'];

    // Check if last_activity is in the future
    if ($last_activity > $current_time) {
        return false;
    }

    // Check if the session has expired
    if ($current_time - $last_activity > $inactivity_period) {
        return false;
    }

    // Session is valid, update last activity time
    $_SESSION['last_activity'] = $current_time;
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