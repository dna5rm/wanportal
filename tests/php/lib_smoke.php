<?php
/**
 * Smoke tests for the shared PHP libs: htdocs/lib/{page,api_proxy,monitor_metrics}.php
 * plus source-level checks on htdocs/config.php and htdocs/assets/js/listings.js.
 *
 * Run via tests/run.sh (docker exec wanportal /usr/bin/php84 /srv/tests/php/lib_smoke.php)
 * or directly from the repo root: php84 tests/php/lib_smoke.php
 *
 * Exit 0 when every check passes; prints FAIL lines and exits 1 otherwise.
 *
 * htdocs/config.php is deliberately NOT required here: it opens a mysqli
 * connection and dies without MYSQL_PASSWORD. lib/page.php,
 * lib/monitor_metrics.php and lib/api_proxy.php only define functions and
 * constants, so they load cleanly in a plain CLI process.
 *
 * The page.php title sanitizer is tested by extracting the actual
 * preg_replace pattern out of wanportal_render_head() at runtime, so the
 * test runs the production expression and the two cannot drift. A separate
 * source check asserts the <title> echo still runs $server_name through
 * htmlspecialchars(ENT_QUOTES).
 *
 * Contains no secrets, no credentials, no account names.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
// The api_proxy degradation tests below trigger expected error_log()
// calls (unreachable upstream); route them to /dev/null so the PASS/FAIL
// output stays clean. display_errors above still prints real warnings.
ini_set('error_log', '/dev/null');

$ROOT   = dirname(__DIR__, 2); // tests/php -> tests -> repo root
$HTDOCS = $ROOT . '/htdocs';

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

if (!is_dir($HTDOCS)) {
    fwrite(STDERR, "FATAL  htdocs not found at {$HTDOCS}\n");
    exit(1);
}

/* ------------------------------------------------------------------ */
section('lib/page.php loads + public API');

require_once $HTDOCS . '/lib/page.php';

foreach (
    [
        'wanportal_get_show_inactive',
        'wanportal_render_head',
        'wanportal_render_header_row',
        'wanportal_render_stats_card',
        'wanportal_render_page_end',
    ] as $fn
) {
    check(function_exists($fn), "page.php: {$fn}() defined");
}

/* ------------------------------------------------------------------ */
section('PHPDoc coverage on public lib functions');

// True when $func has a doc block that opens with a doc-comment opener
// (slash-star-star) and ends immediately before the function keyword.
function has_phpdoc(string $src, string $func): bool
{
    $pos = strpos($src, 'function ' . $func . '(');
    if ($pos === false) {
        return false;
    }
    $before = substr($src, 0, $pos);
    $end    = strrpos($before, '*/');
    if ($end === false) {
        return false;
    }
    $start = strrpos(substr($before, 0, $end), '/**');
    if ($start === false || $start >= $end) {
        return false;
    }
    return trim(substr($before, $end + 2)) === '';
}

$docTargets = [
    $HTDOCS . '/lib/page.php' => [
        'wanportal_get_show_inactive',
        'wanportal_render_head',
        'wanportal_render_header_row',
        'wanportal_render_stats_card',
        'wanportal_render_page_end',
    ],
    $HTDOCS . '/lib/api_proxy.php'       => ['api_request', 'api_get'],
    $HTDOCS . '/lib/monitor_metrics.php' => ['monitor_color_classes', 'wanportal_is_latency_issue'],
    $HTDOCS . '/config.php'              => ['wanportal_session_start', 'wanportal_csrf_valid'],
];
foreach ($docTargets as $file => $funcs) {
    $src = (string) file_get_contents($file);
    foreach ($funcs as $fn) {
        check(has_phpdoc($src, $fn), basename($file) . ": {$fn}() has PHPDoc");
    }
}

/* ------------------------------------------------------------------ */
section('page.php title sanitizer (extracted from wanportal_render_head)');

$pageSrc    = (string) file_get_contents($HTDOCS . '/lib/page.php');
$pageLines  = explode("\n", $pageSrc);
$rxLine     = null;
$titleLine  = null;
foreach ($pageLines as $line) {
    if ($rxLine === null && strpos($line, '$server_name = strtoupper(preg_replace(') !== false) {
        $rxLine = $line;
    }
    if ($titleLine === null && strpos($line, "<title>' .") !== false) {
        $titleLine = $line;
    }
}
check($rxLine !== null, 'page.php: sanitizer line present in wanportal_render_head()');
check($rxLine !== null && strpos($rxLine, 'strtoupper(') !== false, 'page.php: sanitizer uppercases the label');
check(
    $rxLine !== null && strpos($rxLine, "? getenv('SERVER_NAME')") !== false,
    'page.php: sanitizer falls back to getenv(SERVER_NAME)'
);
check($titleLine !== null, 'page.php: <title> echo present');

// Drift guard: the <title> echo must escape $server_name.
check(
    $titleLine !== null && strpos($titleLine, "htmlspecialchars(\$server_name, ENT_QUOTES, 'UTF-8')") !== false,
    'page.php: <title> escapes $server_name with ENT_QUOTES (drift guard)'
);
check(
    $titleLine !== null && strpos($titleLine, 'WANPORTAL_TITLE') !== false,
    'page.php: <title> uses the escaped WANPORTAL_TITLE constant'
);

// Extract the actual preg_replace pattern literal so the test runs the
// production expression instead of a copy of it.
$pattern = '';
if (is_string($rxLine) && preg_match("/preg_replace\(\s*'([^']*)'/", $rxLine, $m) === 1) {
    // The capture spans the full delimited literal, e.g. /[^A-Za-z0-9-]/.
    $pattern = $m[1];
}
check(
    $pattern === '/[^A-Za-z0-9-]/',
    'page.php: production sanitizer is the exact allowlist /[^A-Za-z0-9-]/'
);

if ($pattern !== '') {
    $rx = $pattern;
    // Mirror of the production expression: first hostname label only,
    // strip non-allowlist chars, uppercase, LOCALHOST fallback.
    $sanitize = static function (string $raw) use ($rx): string {
        $label = strtoupper((string) preg_replace($rx, '', explode('.', $raw)[0]));
        return ($label === '' || $label === null) ? 'LOCALHOST' : $label;
    };
    check($sanitize('netops.crc1.net') === 'NETOPS', 'sanitize: hostname label extracted');
    check($sanitize('<script>alert("x")</script>') === 'SCRIPTALERTXSCRIPT', 'sanitize: HTML/JS metacharacters stripped');
    check($sanitize('a<b>&quot;') === 'ABQUOT', 'sanitize: angle brackets and quotes stripped');
    check($sanitize('exa mple.io') === 'EXAMPLE', 'sanitize: spaces and dots stripped, case uppered');
    check($sanitize('a-b') === 'A-B', 'sanitize: hyphen preserved');
    check($sanitize('a-b_c1') === 'A-BC1', 'sanitize: underscore stripped');
    check($sanitize('   ') === 'LOCALHOST', 'sanitize: empty label falls back to LOCALHOST');
    check($sanitize('..') === 'LOCALHOST', 'sanitize: dots-only label falls back to LOCALHOST');
}

/* ------------------------------------------------------------------ */
section('lib/page.php functional');

$_SESSION = [];
$_GET     = [];
check(wanportal_get_show_inactive() === false, 'show_inactive: defaults to false');
check(($_SESSION['show_inactive'] ?? null) === false, 'show_inactive: default persisted to session');
$_GET['show_inactive'] = '1';
check(wanportal_get_show_inactive() === true, 'show_inactive: GET param wins');
$_GET = [];
check(wanportal_get_show_inactive() === true, 'show_inactive: session round-trip after GET cleared');
$_GET['show_inactive'] = 'false';
check(wanportal_get_show_inactive() === false, 'show_inactive: explicit false via GET');
$_GET = [];
check(wanportal_get_show_inactive() === false, 'show_inactive: false round-trips too');

// stats card: escaping, default variant, malformed-entry skip
ob_start();
wanportal_render_stats_card('<b>Stats</b>', [
    ['Users', '3', 'success'],
    ['Evil', '<img src=x onerror=alert(1)>'],
    ['Incomplete'],
]);
$out = (string) ob_get_clean();
check(strpos($out, '&lt;b&gt;Stats&lt;/b&gt;') !== false, 'stats_card: title escaped');
check(strpos($out, 'bg-success-subtle text-success-emphasis') !== false, 'stats_card: subtle badge classes used');
check(strpos($out, '<img') === false, 'stats_card: value HTML escaped');
check(strpos($out, 'bg-secondary-subtle') !== false, 'stats_card: missing variant defaults to secondary');
check(strpos($out, 'Incomplete') === false, 'stats_card: malformed entries skipped');

// header row: escaping, back/home, actions, auth + admin gates
$_SESSION['user'] = 'tester';
$_SERVER['HTTP_REFERER'] = 'http://localhost/list.php';
ob_start();
wanportal_render_header_row('Listing <x>', [
    ['url' => '/thing_edit.php', 'label' => 'New & more', 'variant' => 'primary', 'auth' => true],
    ['click' => 'doThing(1)', 'label' => 'Do', 'icon' => 'bi bi-play'],
    ['label' => 'neither url nor click, skipped'],
]);
$out = (string) ob_get_clean();
check(strpos($out, '<h3>Listing &lt;x&gt;</h3>') !== false, 'header_row: title escaped in h3');
check(strpos($out, 'bi bi-arrow-left') !== false, 'header_row: Back rendered when referer present');
check(strpos($out, 'bi bi-house-door') !== false, 'header_row: Home always rendered');
check(strpos($out, 'New &amp; more') !== false, 'header_row: action label escaped');
check(strpos($out, 'btn-primary') !== false, 'header_row: variant applied');
check(strpos($out, 'onclick="doThing(1)"') !== false, 'header_row: click action rendered as button');
check(strpos($out, 'neither url nor click, skipped') === false, 'header_row: action without url/click skipped');

$_SESSION = [];
ob_start();
wanportal_render_header_row('Gated', [['url' => '/x.php', 'label' => 'Auth Thing', 'auth' => true]]);
$out = (string) ob_get_clean();
check(strpos($out, 'Auth Thing') === false, 'header_row: auth action hidden when logged out');

$_SESSION['user']     = 'tester';
$_SESSION['is_admin'] = false;
ob_start();
wanportal_render_header_row('Gated', [['url' => '/x.php', 'label' => 'Admin Thing', 'admin' => true]]);
$out = (string) ob_get_clean();
check(strpos($out, 'Admin Thing') === false, 'header_row: admin action hidden for non-admin');

$_SESSION['is_admin'] = true;
ob_start();
wanportal_render_header_row('Gated', [['url' => '/x.php', 'label' => 'Admin Thing', 'admin' => true]]);
$out = (string) ob_get_clean();
check(strpos($out, 'Admin Thing') !== false, 'header_row: admin action shown for admin');

// page end without render_head: hook + body close, no container close
ob_start();
wanportal_render_page_end();
$out = (string) ob_get_clean();
check(strpos($out, 'pageSpecificScripts') !== false, 'page_end: showInactive hook emitted');
check(strpos($out, 'show_inactive') !== false, 'page_end: hook round-trips the toggle via URL');
check(strpos($out, '</body>') !== false && strpos($out, '</html>') !== false, 'page_end: body/html closed');
check(strpos($out, '</div>') === false, 'page_end: no container close without render_head');

/* ------------------------------------------------------------------ */
section('lib/monitor_metrics.php');

require_once $HTDOCS . '/lib/monitor_metrics.php';
check(function_exists('monitor_color_classes'), 'monitor_metrics: monitor_color_classes defined');

$allowed = [
    'bg-success-subtle text-success-emphasis border border-success-subtle',
    'bg-info-subtle text-info-emphasis border border-info-subtle',
    'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
    'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
];
$keys = [
    'current_median_color', 'current_loss_color', 'avg_median_color',
    'avg_minimum_color', 'avg_maximum_color', 'avg_stddev_color', 'avg_loss_color',
];

$row = [
    'current_median' => 100, 'current_loss' => 80,
    'avg_median' => 50, 'avg_min' => 20, 'avg_max' => 80,
    'avg_stddev' => 10, 'avg_loss' => 20,
];
monitor_color_classes($row);
check(($row['current_median_color'] ?? '') === $allowed[3], 'metrics: current >> avg+2sigma -> danger');
check(($row['current_loss_color'] ?? '') === $allowed[3], 'metrics: current loss 80 -> danger');
check(($row['avg_median_color'] ?? '') === $allowed[2], 'metrics: avg at top of range -> warning');
check(($row['avg_minimum_color'] ?? '') === $allowed[3], 'metrics: avg_min <= avg-3sigma -> danger');
check(($row['avg_maximum_color'] ?? '') === $allowed[3], 'metrics: avg_max >= avg+3sigma -> danger');
check(($row['avg_stddev_color'] ?? '') === $allowed[0], 'metrics: stddev within half-range -> success');
check(($row['avg_loss_color'] ?? '') === $allowed[3], 'metrics: avg_loss >= 13 -> danger');

$row2 = [
    'current_median' => 10, 'current_loss' => 1,
    'avg_median' => 50, 'avg_min' => 45, 'avg_max' => 50.5,
    'avg_stddev' => 1, 'avg_loss' => 1,
];
monitor_color_classes($row2);
check(($row2['current_median_color'] ?? '') === $allowed[0], 'metrics: current below avg -> success');
check(($row2['current_loss_color'] ?? '') === $allowed[0], 'metrics: current loss < 2 -> success');
check(($row2['avg_maximum_color'] ?? '') === $allowed[0], 'metrics: avg_max near avg -> success');
check(($row2['avg_loss_color'] ?? '') === $allowed[0], 'metrics: avg_loss < 2 -> success');

$row3 = ['current_loss' => 10, 'avg_loss' => 3] + $row2;
monitor_color_classes($row3);
check(($row3['current_loss_color'] ?? '') === $allowed[1], 'metrics: current loss 10 -> info');
check(($row3['avg_loss_color'] ?? '') === $allowed[1], 'metrics: avg_loss 3 -> info');

$row4 = ['current_loss' => 60, 'avg_loss' => 6] + $row2;
monitor_color_classes($row4);
check(($row4['current_loss_color'] ?? '') === $allowed[2], 'metrics: current loss 60 -> warning');
check(($row4['avg_loss_color'] ?? '') === $allowed[2], 'metrics: avg_loss 6 -> warning');

$row5 = [];
monitor_color_classes($row5);
$allOk = true;
foreach ($keys as $k) {
    if (!isset($row5[$k]) || !in_array($row5[$k], $allowed, true)) {
        $allOk = false;
    }
}
check($allOk, 'metrics: empty row -> all 7 color fields set, all from the subtle palette');

$baseLat = [
    'is_active' => 1, 'agent_is_active' => 1, 'target_is_active' => 1,
    'sample' => 10, 'avg_max' => 20.0, 'avg_stddev' => 2.0,
    'current_median' => 40.0, 'current_loss' => 0,
];
check(wanportal_is_latency_issue($baseLat), 'latency: spike above avg_max+5sigma is an issue');
$down = $baseLat; $down['current_loss'] = 100; $down['current_median'] = 0;
check(!wanportal_is_latency_issue($down), 'latency: 100% loss is not a latency issue');
$fresh = $baseLat; $fresh['sample'] = 1; $fresh['avg_max'] = 0; $fresh['avg_stddev'] = 0; $fresh['current_median'] = 5;
check(!wanportal_is_latency_issue($fresh), 'latency: no baseline (threshold 0) is not an issue');
$ok = $baseLat; $ok['current_median'] = 21;
check(!wanportal_is_latency_issue($ok), 'latency: current near avg_max is not an issue');
$latSrc = (string) file_get_contents($HTDOCS . '/latency.php');
check(strpos($latSrc, "array_filter(\$data['monitors'], 'wanportal_is_latency_issue')") !== false,
    'latency.php: filter uses wanportal_is_latency_issue');

/* ------------------------------------------------------------------ */
section('lib/api_proxy.php');

// Pre-define the base URL on an unreachable port so the smoke test never
// depends on the live API (connection refused is instant and identical
// on host and container).
if (!defined('API_PROXY_BASE_URL')) {
    define('API_PROXY_BASE_URL', 'http://127.0.0.1:9/cgi-bin/api');
}
require_once $HTDOCS . '/lib/api_proxy.php';
check(defined('API_PROXY_BASE_URL'), 'api_proxy: base URL constant defined');
check(
    API_PROXY_BASE_URL === 'http://127.0.0.1:9/cgi-bin/api',
    'api_proxy: pre-defined base URL is respected by the guard'
);
check(
    function_exists('api_request') && function_exists('api_get'),
    'api_proxy: api_request() + api_get() defined'
);

$_SESSION = [];
if (function_exists('curl_init')) {
    $resp = api_request('GET', '/health');
    check(
        is_array($resp) && ($resp['status'] ?? 0) === 502 && ($resp['error'] ?? null) !== null,
        'api_proxy: unreachable upstream returns 502 + error, does not throw'
    );
    check(api_get('/health') === null, 'api_proxy: api_get returns null on unreachable upstream');
} else {
    echo "SKIP  curl extension missing; transport degradation checks skipped\n";
}

/* ------------------------------------------------------------------ */
section('listings.js DELETE paths');

$jsPath = $HTDOCS . '/assets/js/listings.js';
$js     = file_get_contents($jsPath);
check($js !== false && $js !== '', 'listings.js: file readable');

$js = (string) $js;
check(
    strpos($js, 'window.deleteAgent') !== false
    && strpos($js, 'window.deleteTarget') !== false
    && strpos($js, 'window.deleteMonitor') !== false,
    'listings.js: delete helpers present'
);

// Every DELETE proxyRequest path must be API-relative: proxy.php prefixes
// the /cgi-bin/api base server-side, so a full prefix here would 404.
preg_match_all("/proxyRequest\(\s*'DELETE'\s*,\s*([^)]+)\)/", $js, $m);
check(count($m[1]) >= 3, 'listings.js: found >=3 DELETE proxyRequest calls (got ' . count($m[1]) . ')');
$badPaths = [];
foreach ($m[1] as $expr) {
    if (strpos($expr, '/cgi-bin/api') !== false) {
        $badPaths[] = $expr;
    }
}
check($badPaths === [], 'listings.js: DELETE proxyRequest paths carry no /cgi-bin/api prefix');

/* ------------------------------------------------------------------ */

echo "\nlib_smoke: passed={$pass} failed={$fail}\n";
exit($fail > 0 ? 1 : 0);