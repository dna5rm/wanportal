<?php
declare(strict_types=1);
/**
 * Source gate for the read-only pages: everything they display comes
 * through api_get() -- no direct mysqli handles and no shell-outs.
 *
 * Run via tests/run.sh (docker exec wanportal /usr/bin/php84 /srv/tests/php/pages_api_only.php)
 * or directly from the repo root: php84 tests/php/pages_api_only.php
 *
 * Exit 0 when every check passes; prints FAIL lines and exits 1 otherwise.
 *
 * config.php is still the shared require (it wires session helpers and
 * the api_get() auto-load), so requiring it is expected; what these
 * pages must not do is touch $mysqli, spawn processes, or hand-roll
 * curl calls around the proxy helper.
 *
 * The endpoint-pinning section ties each page to the API route built
 * for it (dashboard rollup, public detail lookups, filtered listings),
 * so a silent revert to page-side SQL cannot pass unnoticed.
 *
 * Contains no secrets, no credentials, no account names.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT   = dirname(__DIR__, 2); // tests/php -> tests -> repo root
$HTDOCS = $ROOT . '/htdocs/classic';

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

function page_src(string $page): ?string
{
    $src = @file_get_contents($GLOBALS['HTDOCS'] . '/' . $page);
    if ($src === false) {
        check(false, "{$page}: readable");
        return null;
    }
    check(true, "{$page}: readable");
    return $src;
}

$pages = ['monitor.php', 'agent.php', 'target.php', 'search.php', 'index.php', 'latency.php', 'server.php'];

echo "== api_get-only source gate ==\n";

foreach ($pages as $page) {
    $src = page_src($page);
    if ($src === null) {
        continue;
    }
    check(strpos($src, "require_once 'config.php'") !== false, "{$page}: still requires config.php");
    check(strpos($src, 'api_get(') !== false, "{$page}: fetches through api_get()");
    check(preg_match('/\$mysqli\b/', $src) !== 1, "{$page}: no \$mysqli use");
    check(strpos($src, 'new mysqli') === false, "{$page}: no direct mysqli construction");
    check(strpos($src, 'shell_exec') === false, "{$page}: no shell_exec");
    check(strpos($src, 'exec(') === false, "{$page}: no exec() calls");
    check(strpos($src, 'curl_init') === false, "{$page}: no hand-rolled curl");
}

echo "\n== endpoint wiring ==\n";

$src = page_src('index.php');
if ($src !== null) {
    check(strpos($src, "api_get('/dashboard')") !== false, 'index.php: dashboard rollup comes from /dashboard');
}

$src = page_src('monitor.php');
if ($src !== null) {
    check(strpos($src, "api_get('/monitors/' . rawurlencode(\$id))") !== false, 'monitor.php: detail via public /monitors/:id');
}

$src = page_src('agent.php');
if ($src !== null) {
    check(strpos($src, "api_get('/agents/' . rawurlencode(\$id))") !== false, 'agent.php: detail via public /agents/:id');
    check(strpos($src, "api_get('/monitors?agent_id=' . rawurlencode(\$id))") !== false, 'agent.php: monitor list via /monitors?agent_id');
}

$src = page_src('target.php');
if ($src !== null) {
    check(strpos($src, "api_get('/targets/' . rawurlencode(\$id))") !== false, 'target.php: detail via public /targets/:id');
    check(strpos($src, "api_get('/monitors?target_id=' . rawurlencode(\$id))") !== false, 'target.php: monitor list via /monitors?target_id');
}

$src = page_src('search.php');
if ($src !== null) {
    check(strpos($src, "api_get('/monitors?q=' . rawurlencode(\$search))") !== false, 'search.php: search via /monitors?q');
}

$src = page_src('latency.php');
if ($src !== null) {
    check(strpos($src, "api_get('/monitors')") !== false, 'latency.php: monitors via api_get(/monitors)');
}

$src = page_src('server.php');
if ($src !== null) {
    check(strpos($src, "api_get('/health')") !== false, 'server.php: uptime via api_get(/health)');
    check(strpos($src, 'uptime_seconds') !== false, 'server.php: reads uptime_seconds from /health');
}

echo "\npages_api_only: passed={$pass} failed={$fail}\n";
exit($fail > 0 ? 1 : 0);