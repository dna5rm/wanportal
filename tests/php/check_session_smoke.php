<?php
// check_session_smoke.php - hermetic tests for htdocs/check_session.php
//
// Covers the token-expiry logic added alongside GET /session:
//   * $_SESSION['token_exp'] (stored at login from the /login claims) is
//     enforced locally: an expired claim invalidates the session, a valid
//     one passes, and the inactivity logout still applies - with no CGI
//     call at all for sessions that carry token_exp.
//   * Sessions created before token_exp existed get a ONE-TIME backfill
//     via GET /session (guarded by $_SESSION['token_exp_backfill_done'],
//     written before the call so it never becomes a per-page CGI spawn):
//     a transport failure degrades gracefully (session stays valid, no
//     retry storm), a 200 response stores the exp claim, and a 401 drops
//     the session.
//
// check_session.php self-executes check_session() at include time, so a
// valid session must be primed BEFORE the include. The main process
// points API_BASE_URL at a dead port (instant connection refused, no
// live-API dependency); the backfill-200 and backfill-401 cases run in
// child processes against a local php -S stub router that answers
// according to a mode file. Nothing here touches the real API and no
// secrets are involved.

$case = in_array('--case401', $argv ?? [], true) ? '401'
      : (in_array('--case200', $argv ?? [], true) ? '200' : 'main');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/check_session_smoke.log');

if ($case === 'main') {
    putenv('API_BASE_URL=http://127.0.0.1:9'); // dead port: connection refused
}

function prime_session(array $over = []): void {
    $_SESSION = array_merge([
        'user'          => 'smoketester',
        'token'         => 'synthetic-priming-string-never-used-as-a-real-jwt',
        'last_activity' => time(),
    ], $over);
}

ini_set('session.save_path', sys_get_temp_dir());
if (session_id() === '' && !@session_start()) {
    echo "SKIP cannot start a PHP session in this environment\n";
    exit(0);
}

// Prime BEFORE the include: check_session.php runs its gate at include
// time. Children prime with a future token_exp so the include-time gate
// passes without touching the API; the main process primes a legacy
// session (no token_exp) whose one-time backfill hits the dead port.
prime_session($case !== 'main' ? ['token_exp' => time() + 900] : []);

require_once __DIR__ . '/../../htdocs/lib/api_proxy.php';
require_once __DIR__ . '/../../htdocs/check_session.php';

$failures = 0;
$check = function (bool $cond, string $what) use (&$failures): void {
    if ($cond) {
        echo "ok - $what\n";
    } else {
        $failures++;
        echo "FAIL - $what\n";
    }
};

// ---------------------------------------------------------------------------
// Child cases (stub API): the parent re-invokes this file with an env
// pointing at the stub server and asserts on the marker + exit code.
// ---------------------------------------------------------------------------
if ($case === '200') {
    prime_session();
    unset($_SESSION['token_exp'], $_SESSION['token_exp_backfill_done']);
    $valid = is_session_valid();
    $ok = $valid === true
        && isset($_SESSION['token_exp'])
        && (int)$_SESSION['token_exp'] > time()
        && isset($_SESSION['token_exp_backfill_done']);
    if ($ok) {
        echo "CASE200 OK\n";
    } else {
        echo "CASE200 BAD valid=" . var_export($valid, true) . "\n";
    }
    exit($ok ? 0 : 1);
}

if ($case === '401') {
    prime_session();
    unset($_SESSION['token_exp'], $_SESSION['token_exp_backfill_done']);
    $valid = is_session_valid();
    if ($valid === false) {
        echo "CASE401 OK\n";
    } else {
        echo "CASE401 BAD valid=" . var_export($valid, true) . "\n";
    }
    exit($valid === false ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Main process: dead API port. The include-time gate above already
// exercised the legacy path once; assert its observable effects first.
// ---------------------------------------------------------------------------
$check(isset($_SESSION['token_exp_backfill_done']),
    'legacy session sets the backfill flag on first gate pass');
$check(!isset($_SESSION['token_exp']),
    'legacy session gets no token_exp fabricated on transport failure');
$check(is_session_valid(),
    'legacy session stays valid when the API is unreachable');

// Local expiry enforcement for sessions that carry token_exp.
prime_session(['token_exp' => time() - 5]);
$check(!is_session_valid(), 'expired token_exp invalidates the session');

prime_session(['token_exp' => time()]);
$check(is_session_valid(),
    'token_exp equal to now is still valid (same cutoff as Perl: exp < time)');

prime_session(['token_exp' => time() + 600]);
$check(is_session_valid(), 'future token_exp keeps the session valid');
$check($_SESSION['last_activity'] >= time() - 2,
    'valid path still refreshes last_activity (inactivity timer not frozen)');

prime_session(['token_exp' => time() + 600, 'last_activity' => time() - 1900]);
$check(!is_session_valid(), 'inactivity logout still applies with a valid token_exp');

// Flag set -> the backfill is never retried (no per-page CGI spawn).
prime_session();
$_SESSION['token_exp_backfill_done'] = true;
unset($_SESSION['token_exp']);
$check(is_session_valid(), 'flagged legacy session skips the backfill');

// Fresh legacy session + dead API: degrades gracefully, flag prevents retries.
prime_session();
unset($_SESSION['token_exp'], $_SESSION['token_exp_backfill_done']);
$check(is_session_valid(), 'legacy session survives a failed backfill');
$check(isset($_SESSION['token_exp_backfill_done']),
    'backfill flag is set even on failure (no retry on every page)');
$check(!isset($_SESSION['token_exp']),
    'no token_exp stored when the API could not answer');

// ---------------------------------------------------------------------------
// Stub-server cases: backfill against a 200-claim API and a 401 API.
// Degrade to a skip (not a failure) if no local listener can be bound.
// ---------------------------------------------------------------------------
$router = sys_get_temp_dir() . '/wanportal_cs_router.php';
file_put_contents($router, <<<'PHP'
<?php
// Stub for check_session_smoke.php: answers GET /session per mode file.
$mode = trim((string) @file_get_contents(sys_get_temp_dir() . '/wanportal_cs_mode'));
if ($mode === '401') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"status":"error","message":"Invalid or expired token"}';
    return true;
}
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status'   => 'success',
    'username' => 'smoketester',
    'is_admin' => false,
    'exp'      => time() + 900,
]);
return true;
PHP);

$proc = null;
$port = null;
for ($try = 0; $try < 5 && $proc === null; $try++) {
    $candidate = 18080 + $try;
    $pipes = [];
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$candidate", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        $proc = null;
        continue;
    }
    // poll for readiness
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        $fp = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
        if ($fp !== false) {
            fclose($fp);
            $ready = true;
            break;
        }
        if (!proc_get_status($proc)['running']) {
            break; // server died (port likely busy) - try the next one
        }
    }
    if ($ready) {
        $port = $candidate;
    } else {
        proc_terminate($proc);
        proc_close($proc);
        $proc = null;
    }
}

if ($proc === null) {
    echo "SKIP could not bind a local php -S stub (ports 18080-18084); "
       . "backfill 200/401 cases not exercised\n";
    exit($failures === 0 ? 0 : 1);
}

$run_child = function (string $flag) use ($port): string {
    $cmd = 'API_BASE_URL=' . escapeshellarg("http://127.0.0.1:$port")
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' ' . escapeshellarg($flag) . ' 2>&1';
    return (string) shell_exec($cmd);
};

file_put_contents(sys_get_temp_dir() . '/wanportal_cs_mode', '200');
$out200 = $run_child('--case200');
$check(strpos($out200, 'CASE200 OK') !== false,
    'backfill from a 200 /session response stores a future exp claim');

file_put_contents(sys_get_temp_dir() . '/wanportal_cs_mode', '401');
$out401 = $run_child('--case401');
$check(strpos($out401, 'CASE401 OK') !== false,
    'backfill against a 401 API drops the session (expired token logs out)');

proc_terminate($proc);
proc_close($proc);

echo $failures === 0 ? "check_session_smoke: all cases passed\n" : "check_session_smoke: $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);