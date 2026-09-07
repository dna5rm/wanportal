<?php
/**
 * Gate smoke tests for htdocs/proxy.php: auth, CSRF, method allowlist,
 * path allowlist and forward/normalization -- run against the REAL
 * proxy.php source, extracted into a hermetic harness.
 *
 * Run via tests/run.sh (docker exec wanportal /usr/bin/php84 /srv/tests/php/proxy_gate.php)
 * or directly from the repo root: php84 tests/php/proxy_gate.php
 *
 * Exit 0 when every check passes; prints FAIL lines and exits 1 otherwise.
 *
 * Why a harness instead of require: proxy.php's first lines pull in
 * config.php and lib/api_proxy.php, whose include-time wiring would
 * collide with the stubs. So this test strips the two require lines, compiles the
 * remaining statements inside a dedicated namespace that shadows
 * header()/http_response_code()/file_get_contents('php://input')/
 * wanportal_session_start()/api_request() with recording stubs, and turns
 * the script body into a callable closure (every `exit;` becomes `return;`,
 * which is exactly "stop processing this request"). The gate code that
 * runs is the production source verbatim -- the test carries no copy of
 * the logic -- and nothing touches the network or any secret.
 *
 * The api_request() stub returns whatever the case sets in
 * $GLOBALS['gate_upstream'], which lets us pin:
 *   - no forwarding happens before the auth/CSRF/method/path/body gates;
 *   - the upstream status and body pass through verbatim;
 *   - the 204-with-empty-body normalization to 200 {"status":"success"};
 *   - a dead upstream (status 0) surfaces as 502.
 *
 * A source-level section pins gate ordering (auth < CSRF < method < path
 * < forward), hash_equals for the token compare, the exact method
 * allowlist, and the exact path regex (extracted and executed, so the
 * test runs the production expression rather than a lookalike).
 *
 * Harness integrity is loud: if proxy.php grows a third require, a bare
 * `exit` that is not `exit;`, or stops matching the extraction markers,
 * the test prints FATAL and exits 1 instead of silently passing.
 *
 * Contains no secrets, no credentials, no account names.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT   = dirname(__DIR__, 2); // tests/php -> tests -> repo root
$PROXY  = $ROOT . '/htdocs/proxy.php';

$pass = 0;
$fail = 0;

function check(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo 'PASS  ' . $name . "\n";
    } else {
        $fail++;
        echo 'FAIL  ' . $name . "\n";
    }
}

function section(string $name): void
{
    echo "\n== " . $name . " ==\n";
}

function fatal(string $msg): void
{
    fwrite(STDERR, 'FATAL  ' . $msg . "\n");
    exit(1);
}

if (!is_file($PROXY)) {
    fatal("proxy.php not found at {$PROXY}");
}
$src = (string) file_get_contents($PROXY);

/* ------------------------------------------------------------------ */
section('source-level gates (ordering, compare, allowlists)');

$lines = explode("\n", $src);

// CSRF must use hash_equals (constant-time), on the session token.
$hashEqualsLine = null;
foreach ($lines as $line) {
    if (strpos($line, 'hash_equals(') !== false && strpos($line, 'csrf') !== false) {
        $hashEqualsLine = $line;
        break;
    }
}
check($hashEqualsLine !== null, 'proxy.php: CSRF compare uses hash_equals on the session token');

// Gate order: the request must be able to reach api_request() only after
// every gate. Auth -> CSRF -> method -> forward.
$pAuth   = strpos($src, 'Not authenticated');
$pCsrf   = strpos($src, 'Invalid CSRF token');
$pMethod = strpos($src, 'Method not allowed');
$pFwd    = strpos($src, 'api_request(');
check($pAuth !== false && $pCsrf !== false && $pMethod !== false && $pFwd !== false,
    'proxy.php: all gate markers present');
check($pAuth !== false && $pCsrf !== false && $pAuth < $pCsrf,
    'proxy.php: auth gate fires before CSRF gate');
check($pCsrf !== false && $pMethod !== false && $pCsrf < $pMethod,
    'proxy.php: CSRF gate fires before method gate');
check($pMethod !== false && $pFwd !== false && $pMethod < $pFwd,
    'proxy.php: method gate fires before api_request() forward');

// Method allowlist, extracted from the source literal.
$allowed = [];
if (preg_match('/\$allowed\s*=\s*\[([^\]]*)\]/', $src, $m) === 1) {
    foreach (explode(',', $m[1]) as $item) {
        $item = trim($item, " '\t");
        if ($item !== '') {
            $allowed[] = $item;
        }
    }
}
check($allowed === ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
    'proxy.php: method allowlist is exactly GET/POST/PUT/DELETE/PATCH (got: '
    . implode(',', $allowed) . ')');

// Path allowlist regex, extracted so we execute the production pattern.
$pathRx = '';
if (preg_match("/preg_match\\(\\s*'([^']*)'\\s*,\\s*\\\$path\\s*\\)/", $src, $m) === 1) {
    $pathRx = $m[1];
}
check($pathRx === '#^/[A-Za-z0-9._/\\-]+$#',
    'proxy.php: path allowlist is the exact production regex (got: ' . $pathRx . ')');

if ($pathRx !== '') {
    $goodPaths = ['/credentials/42', '/agents', '/a.b-c_d/e.f'];
    foreach ($goodPaths as $p) {
        check(preg_match($pathRx, $p) === 1, "path regex accepts {$p}");
    }
    $badPaths = [
        'http://evil.example/x',   // absolute URL -> SSRF attempt
        'https://169.254.169.254', // cloud metadata style target
        '/x?y=1',                  // query string
        '/x#frag',                 // fragment
        '/a b',                    // space
        'relative/path',           // no leading slash
        '../etc/passwd',           // traversal without leading slash
        '',                        // empty (also caught by the earlier 400)
    ];
    foreach ($badPaths as $p) {
        check(preg_match($pathRx, $p) !== 1, "path regex rejects '" . $p . "'");
    }
}

/* ------------------------------------------------------------------ */
section('harness build (strip open tag + requires, neutralize exit, shadow externals)');

// 0. Strip the opening tag: eval() compiles plain statements, and a
//    stray open tag inside would be a parse error. A closing tag would
//    silently end PHP mode mid-closure, so refuse that too. (The tag
//    literals below are built by concatenation: writing the real close
//    tag in a comment would terminate PHP mode right here.)
$openTag  = '<' . '?php';
$closeTag = '?' . '>';
$openCount  = substr_count($src, $openTag);
$closeCount = substr_count($src, $closeTag);
check($openCount === 1 && $closeCount === 0,
    "harness: one open tag, no close tag (open={$openCount} close={$closeCount})");
$src = (string) preg_replace('/^\s*<\?php/', '', $src);
if ($closeCount !== 0) {
    fatal('proxy.php contains a closing tag the harness cannot embed');
}

// 1. Strip the two requires. The harness stubs stand in for both
//    config.php and lib/api_proxy.php.
$requiresStripped = 0;
$src = (string) preg_replace(
    "/^require_once __DIR__ \. '\/(config\.php|lib\/api_proxy\.php)';[^\n]*$/m",
    '// stripped by proxy_gate.php harness',
    $src,
    -1,
    $requiresStripped
);
check($requiresStripped === 2, "harness: stripped both require lines ({$requiresStripped})");
if (preg_match('/\brequire\b/', $src) === 1) {
    fatal('unexpected require left in proxy.php after stripping config.php/lib/api_proxy.php; extend the harness stub list');
}

// 2. Neutralize exit: every bare `exit;` becomes `return;`, which inside
//    the closure body means "stop processing this request". Any other
//    exit spelling (exit(1), exit;) we did not account for is fatal --
//    a missed exit would kill the whole test process.
$exitCount = 0;
$src = (string) preg_replace('/\bexit\s*;/', 'return;', $src, -1, $exitCount);
$leftover = preg_match_all('/\bexit\b/', $src);
check($exitCount === 7 && $leftover === 0,
    "harness: neutralized all exit statements ({$exitCount} replaced, {$leftover} left)");
if ($leftover !== 0) {
    fatal('proxy.php contains an exit the harness could not neutralize');
}

// 3. Shadow the script's external surface inside a dedicated namespace,
//    then compile the body as a reusable closure. Unqualified calls in
//    the namespace resolve to the shadows first; everything else
//    (json_encode, hash_equals, preg_match, ...) falls through to global.
$ns = 'WanportalProxyGateHarness';
$code = <<<NS
namespace {$ns};

function http_response_code(\$code = null)
{
    if (\$code !== null) {
        \$GLOBALS['gate_status'] = (int) \$code;
    }
    return \$GLOBALS['gate_status'] ?? 0;
}

function header(...\$args)
{
    \$GLOBALS['gate_headers'][] = (string) (\$args[0] ?? '');
}

function wanportal_session_start()
{
    // no-op: config.php was stripped; session state is injected directly
}

function api_request(string \$method, string \$path, ?array \$body = null): array
{
    \$GLOBALS['gate_calls'][] = ['method' => \$method, 'path' => \$path, 'body' => \$body];
    return \$GLOBALS['gate_upstream'];
}

function file_get_contents(...\$args)
{
    if ((\$args[0] ?? null) === 'php://input') {
        return \$GLOBALS['gate_input'];
    }
    return \\file_get_contents(...\$args);
}

\$GLOBALS['gate_run'] = function () {
NS;
$code .= "\n" . $src . "\n};\n";

try {
    eval($code);
} catch (\ParseError $e) {
    fatal('harness: extracted proxy.php body does not compile: ' . $e->getMessage());
}

check(isset($GLOBALS['gate_run']) && $GLOBALS['gate_run'] instanceof Closure,
    'harness: proxy.php body compiled into a callable closure');
if (!isset($GLOBALS['gate_run']) || !$GLOBALS['gate_run'] instanceof Closure) {
    fatal('harness closure missing; cannot continue');
}

/**
 * Drive one request through the extracted gate closure.
 *
 * @return array{status:int, out:string, calls:array<int,array>, headers:array<int,string>}
 */
function run_gate(array $session, array $server, array $post, string $rawInput, array $upstream): array
{
    $_SESSION = $session;
    $_POST    = $post;
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['CONTENT_TYPE']);
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    $GLOBALS['gate_input']    = $rawInput;
    $GLOBALS['gate_upstream'] = $upstream;
    $GLOBALS['gate_calls']    = [];
    $GLOBALS['gate_headers']  = [];
    $GLOBALS['gate_status']   = 0;

    try {
        ob_start();
        ($GLOBALS['gate_run'])();
        $out = (string) ob_get_clean();
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $out = 'THREW: ' . get_class($e) . ': ' . $e->getMessage();
        $GLOBALS['gate_status'] = -1;
    }

    return [
        'status'  => (int) $GLOBALS['gate_status'],
        'out'     => $out,
        'calls'   => $GLOBALS['gate_calls'],
        'headers' => $GLOBALS['gate_headers'],
    ];
}

const GATE_TOKEN = 'gate-test-csrf-token-0123456789';
const GATE_UPSTREAM_OK = ['status' => 200, 'body' => '{"ok":true}', 'error' => null];

/* ------------------------------------------------------------------ */
section('runtime: auth gate');

$r = run_gate([], [], [], '', GATE_UPSTREAM_OK);
check($r['status'] === 401, 'no session -> 401');
check(strpos($r['out'], 'Not authenticated') !== false, 'no session -> JSON error body');
check($r['calls'] === [], 'no session -> nothing forwarded');

$hdrs = implode('|', $r['headers']);
check(strpos($hdrs, 'X-Content-Type-Options: nosniff') !== false, 'nosniff header set');
check(strpos($hdrs, 'Cache-Control: no-store') !== false, 'no-store header set');

/* ------------------------------------------------------------------ */
section('runtime: CSRF gate');

$session = ['user' => 'gate-tester', 'token' => 'gate-test-jwt', 'csrf_token' => GATE_TOKEN];

$r = run_gate($session, [], [], '', GATE_UPSTREAM_OK);
check($r['status'] === 403, 'logged in, missing CSRF token -> 403');
check($r['calls'] === [], 'missing CSRF -> nothing forwarded');

$r = run_gate($session, ['HTTP_X_CSRF_TOKEN' => 'wrong-token'], [], '', GATE_UPSTREAM_OK);
check($r['status'] === 403, 'wrong header CSRF token -> 403');
check($r['calls'] === [], 'wrong CSRF -> nothing forwarded');

$r = run_gate(['user' => 'gate-tester', 'token' => 'gate-test-jwt'],
    ['HTTP_X_CSRF_TOKEN' => GATE_TOKEN], [], '', GATE_UPSTREAM_OK);
check($r['status'] === 403, 'empty session csrf_token -> 403 even with matching header');
check($r['calls'] === [], 'empty session csrf_token -> nothing forwarded');

$r = run_gate($session, ['HTTP_X_CSRF_TOKEN' => 'wrong-token'],
    ['csrf_token' => GATE_TOKEN], '', GATE_UPSTREAM_OK);
check($r['status'] === 403, 'header token wins over POST field: wrong header + right POST -> 403');
check($r['calls'] === [], 'header-precedence rejection -> nothing forwarded');

/* ------------------------------------------------------------------ */
section('runtime: valid CSRF -> forward');

// JSON envelope, default POST method.
$server = ['HTTP_X_CSRF_TOKEN' => GATE_TOKEN, 'CONTENT_TYPE' => 'application/json'];
$r = run_gate($session, $server, [], '{"path":"/credentials/42"}', GATE_UPSTREAM_OK);
check($r['status'] === 200, 'valid CSRF + valid path -> upstream status passed through');
check(count($r['calls']) === 1, 'valid request -> exactly one forward');
check(($r['calls'][0]['method'] ?? '') === 'POST', 'default method is POST');
check(($r['calls'][0]['path'] ?? '') === '/credentials/42', 'path forwarded verbatim');
check(!array_key_exists('body', $r['calls'][0]) || $r['calls'][0]['body'] === null,
    'envelope without body forwards null body');
check($r['out'] === '{"ok":true}', 'upstream body passed through verbatim');

// Form-encoded envelope: CSRF via POST field, lowercase _method is
// normalized to uppercase.
$r = run_gate($session, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
    ['csrf_token' => GATE_TOKEN, 'path' => '/credentials/42', '_method' => 'delete'],
    '', GATE_UPSTREAM_OK);
check($r['status'] === 200, 'form envelope with POST-field CSRF -> forwarded');
check(($r['calls'][0]['method'] ?? '') === 'DELETE', '_method delete normalized to DELETE');
check(($r['calls'][0]['path'] ?? '') === '/credentials/42', 'form envelope path forwarded');

// GET forward: body stays null.
$r = run_gate($session, $server, [], '{"path":"/agents","_method":"GET"}', GATE_UPSTREAM_OK);
check($r['status'] === 200, 'GET forward -> upstream status');
check(($r['calls'][0]['method'] ?? '') === 'GET', 'GET method forwarded');
check(($r['calls'][0]['body'] ?? null) === null, 'GET forwards null body');

// PUT with a body object: body forwarded as-is, non-200 status passes through.
$up201 = ['status' => 201, 'body' => '{"id":7}', 'error' => null];
$r = run_gate($session, $server, [],
    '{"path":"/monitors/7","_method":"PUT","body":{"median":12}}', $up201);
check($r['status'] === 201, 'upstream 201 passed through');
check(($r['calls'][0]['method'] ?? '') === 'PUT', 'PUT method forwarded');
check(($r['calls'][0]['body'] ?? null) === ['median' => 12], 'PUT body object forwarded');
check($r['out'] === '{"id":7}', 'PUT upstream body passed through');

/* ------------------------------------------------------------------ */
section('runtime: method allowlist');

foreach (['TRACE', 'OPTIONS'] as $badMethod) {
    $raw = json_encode(['path' => '/credentials/42', '_method' => $badMethod]);
    $r = run_gate($session, $server, [], (string) $raw, GATE_UPSTREAM_OK);
    check($r['status'] === 405, "method {$badMethod} -> 405");
    check($r['calls'] === [], "method {$badMethod} -> nothing forwarded");
}

/* ------------------------------------------------------------------ */
section('runtime: path/body validation');

$r = run_gate($session, $server, [], '{}', GATE_UPSTREAM_OK);
check($r['status'] === 400, 'missing path -> 400');
check(strpos($r['out'], 'Missing') !== false, 'missing path -> error names the problem');
check($r['calls'] === [], 'missing path -> nothing forwarded');

$r = run_gate($session, $server, [], '{"path":"http://evil.example/x"}', GATE_UPSTREAM_OK);
check($r['status'] === 400, 'absolute-URL path -> 400');
check($r['calls'] === [], 'absolute-URL path -> nothing forwarded');

$r = run_gate($session, $server, [], '{"path":"/x?y=1"}', GATE_UPSTREAM_OK);
check($r['status'] === 400, 'path with query string -> 400');
check($r['calls'] === [], 'query-string path -> nothing forwarded');

$r = run_gate($session, $server, [], '{"path":"/credentials/42","body":"not-an-object"}',
    GATE_UPSTREAM_OK);
check($r['status'] === 400, 'string body -> 400');
check($r['calls'] === [], 'string body -> nothing forwarded');

/* ------------------------------------------------------------------ */
section('runtime: upstream normalization');

$r = run_gate($session, $server, [], '{"path":"/credentials/42","_method":"DELETE"}',
    ['status' => 204, 'body' => '', 'error' => null]);
check($r['status'] === 200, 'upstream 204 with empty body -> 200');
check($r['out'] === '{"status":"success"}', 'upstream 204 normalized to success JSON');

$r = run_gate($session, $server, [], '{"path":"/credentials/42"}',
    ['status' => 0, 'body' => '', 'error' => 'connection refused']);
check($r['status'] === 502, 'dead upstream (status 0) -> 502');
check(strpos($r['out'], 'Empty upstream response') !== false, 'dead upstream -> synthetic error body');

/* ------------------------------------------------------------------ */

echo "\nproxy_gate: passed={$pass} failed={$fail}\n";
exit($fail > 0 ? 1 : 0);