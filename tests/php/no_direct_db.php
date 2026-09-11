<?php
declare(strict_types=1);
/**
 * Source gate for the database-free web tier: htdocs opens no database
 * connection of its own.
 *
 * All data flows through the CGI API (lib/api_proxy.php). config.php
 * used to open a connection at include time (and login.php closed it
 * after rendering), which forced every page load to depend on database
 * environment variables; that block is gone, and this gate keeps it
 * gone. A revert would still pass a lint run, so the sources themselves
 * are inspected here.
 *
 * Run via tests/run.sh (docker exec wanportal /usr/bin/php84 /srv/tests/php/no_direct_db.php)
 * or directly from the repo root: php84 tests/php/no_direct_db.php
 *
 * Exit 0 when every check passes; prints FAIL lines and exits 1 otherwise.
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

// Any of these in htdocs means a page-side database handle came back:
// constructing one, calling the procedural wrappers, referencing the
// object variable (even just to close it), or reading database
// credentials from the environment.
$patterns = [
    'connection object'   => '/\$mysqli\b/i',
    'object constructor'  => '/new\s+mysqli\b/i',
    'procedural call'     => '/\bmysqli_\w+\s*\(/i',
    'credentials lookup'  => '/getenv\(\s*[\'"]MYSQL_/',
];

echo "== no direct database handle in htdocs ==\n";

// The two files that used to hold the handle get named checks so a
// regression points straight at the offender.
foreach (['config.php', 'login.php'] as $file) {
    $src = @file_get_contents($HTDOCS . '/' . $file);
    if ($src === false) {
        check(false, "{$file}: readable");
        continue;
    }
    check(true, "{$file}: readable");
    foreach ($patterns as $label => $re) {
        check(preg_match($re, $src) !== 1, "{$file}: no {$label}");
    }
}

// Then sweep the rest of the tree so a handle cannot quietly resurface
// in a new page or library.
$sweep = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($HTDOCS, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    foreach ($patterns as $label => $re) {
        if (preg_match($re, $src) === 1) {
            $sweep[] = substr($f->getPathname(), strlen($HTDOCS) + 1) . " ({$label})";
            break;
        }
    }
}
sort($sweep);
check($sweep === [], 'sweep: no other htdocs php file opens a database handle'
    . ($sweep === [] ? '' : ' — offenders: ' . implode('; ', $sweep)));

echo "\nno_direct_db: passed={$pass} failed={$fail}\n";
exit($fail > 0 ? 1 : 0);