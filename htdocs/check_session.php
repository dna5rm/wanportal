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

    // Enforce JWT expiry locally. login.php stores the token's exp
    // claim (from the /login response) in $_SESSION['token_exp'], so
    // expiry needs no extra CGI+MySQL spawn on admin pages. Same
    // cutoff as the Perl middleware: expired when exp < now.
    if (isset($_SESSION['token_exp']) && (int)$_SESSION['token_exp'] < $current_time) {
        return false;
    }

    // Sessions created before token_exp was stored at login get a
    // one-time backfill via GET /session. The flag is written BEFORE
    // the call, so this fires at most once per session - it does not
    // add a CGI+MySQL spawn on every admin page. On a 401 the stored
    // token is expired or revoked and the session is dropped; on a
    // transport failure the check degrades to the inactivity logout
    // only (pre-existing behavior).
    if (!isset($_SESSION['token_exp']) && empty($_SESSION['token_exp_backfill_done'])) {
        $_SESSION['token_exp_backfill_done'] = true;
        $res = api_request('GET', '/session');
        if (is_array($res) && ($res['status'] ?? 0) === 401) {
            return false;
        }
        if (is_array($res) && ($res['status'] ?? 0) === 200) {
            $claims = json_decode((string)($res['body'] ?? ''), true);
            if (is_array($claims)
                && ($claims['status'] ?? '') === 'success'
                && isset($claims['exp'])) {
                $_SESSION['token_exp'] = (int)$claims['exp'];
            }
        }
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