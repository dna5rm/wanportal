<?php
// proxy.php
//
// Same-origin proxy for state-changing API calls. The browser POSTs
// to this endpoint with a CSRF token; the proxy reads the session's
// JWT and forwards the request to the internal cgi-bin/api. This
// keeps the token out of the HTML/JS the browser sees and adds CSRF
// protection to previously-unprotected DELETE/PUT operations.
//
// Request shape (JSON body):
//   {
//     "_method": "DELETE",          // optional override; defaults to POST
//     "path":    "/credentials/42", // required, must start with /
//     "body":    { ... }            // optional JSON object for POST/PUT/PATCH
//   }

require_once __DIR__ . '/config.php';
wanportal_session_start();
require_once __DIR__ . '/lib/api_proxy.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// 1. Authentication
if (empty($_SESSION['user']) || empty($_SESSION['token'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// 2. CSRF: accept the token from the X-CSRF-Token header (preferred,
//    since fetch() with JSON body cannot use a form field) or from
//    the csrf_token POST field as a fallback for non-JS callers.
$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrf_post   = $_POST['csrf_token']         ?? '';
$csrf        = $csrf_header !== '' ? $csrf_header : $csrf_post;

if ($csrf === '' || empty($_SESSION['csrf_token'])
    || !hash_equals((string) $_SESSION['csrf_token'], $csrf)
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}

// 3. Read the request envelope. Accept either JSON or form-encoded.
$raw = file_get_contents('php://input');
$envelope = [];

if ($raw !== false && $raw !== '' && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $envelope = $decoded;
    }
} else {
    // Form-encoded fallback (also covers the case where the browser
    // submitted the proxy form via <form action="/proxy.php" method="post">).
    $envelope = $_POST;
}

$path   = isset($envelope['path'])    ? (string) $envelope['path']    : '';
$method = isset($envelope['_method']) ? (string) $envelope['_method'] : 'POST';
$body   = $envelope['body'] ?? null;

if ($path === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing "path"']);
    exit;
}

$method = strtoupper($method);
$allowed = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
if (!in_array($method, $allowed, true)) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// 4. Whitelist the path prefix to anything under /cgi-bin/api on the
//    loopback. We pass it through verbatim, but reject anything that
//    doesn't look like a normal API path (defense in depth against a
//    compromised session trying to SSRF the proxy into reaching
//    other loopback services).
if (!preg_match('#^/[A-Za-z0-9._/\-]+$#', $path)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid path']);
    exit;
}

// 5. Body must be an object/array (or null for GET/DELETE).
if ($body !== null && !is_array($body)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Body must be a JSON object']);
    exit;
}

// 6. Forward to the API.
$result = api_request($method, $path, $body);

// 7. Pass the upstream status code back, but normalize 204 -> 200
//    with a synthetic success body if the upstream returned empty
//    (the cgi-bin API is sometimes terse on DELETE).
if ($result['status'] === 204 && $result['body'] === '') {
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;
}

http_response_code($result['status'] > 0 ? $result['status'] : 502);
echo $result['body'] !== '' ? $result['body']
                             : json_encode(['status' => 'error', 'message' => 'Empty upstream response']);
