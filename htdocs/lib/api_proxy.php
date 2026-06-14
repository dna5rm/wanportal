<?php
// lib/api_proxy.php
//
// Server-side helper for forwarding authenticated requests to the
// internal cgi-bin/api endpoint. The session JWT never leaves the
// server when callers use this helper, so client-side JavaScript
// does not need to embed the token in the page source.
//
// This file intentionally does NOT require config.php. The full
// config opens a mysqli connection that the proxy never needs, and
// skipping it keeps the per-request cost of state-changing calls
// (deletes, edits) low.

if (!defined('API_PROXY_BASE_URL')) {
    define('API_PROXY_BASE_URL', getenv('API_BASE_URL') ?: 'http://localhost/cgi-bin/api');
}

/**
 * Make an authenticated API request server-side using the JWT stored
 * in the current session.
 *
 * @param string     $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
 * @param string     $path    API path beginning with "/", e.g. "/users/123"
 * @param array|null $body    Optional associative array to JSON-encode as the body
 *
 * @return array{status:int, body:string, error:?string}
 *         Decoded body is the caller's responsibility; this returns
 *         the raw upstream response so callers can stream it back
 *         unchanged or inspect it as needed.
 */
function api_request(string $method, string $path, ?array $body = null): array
{
    // Normalize the path so a caller passing "users/123" or "/users/123" both work.
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    $url = API_PROXY_BASE_URL . $path;

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    // Only attach Authorization if we actually have a session token.
    // The proxy.php endpoint already enforces authentication, so a
    // missing token here is a programming error rather than a normal
    // condition, but we degrade gracefully.
    if (!empty($_SESSION['token']) && is_string($_SESSION['token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['token'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'status' => 502,
            'body'   => json_encode(['status' => 'error', 'message' => 'curl init failed']),
            'error'  => 'curl_init_failed',
        ];
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        // Loopback: no need to verify the local cert, and the cgi-bin
        // endpoint is plain HTTP. If this proxy is ever fronted by a
        // TLS-terminating reverse proxy that re-encrypts to a non-local
        // backend, revisit this.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $errstr   = curl_error($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $response === false) {
        error_log('api_request: curl error for ' . $method . ' ' . $path . ': ' . $errstr);
        return [
            'status' => 502,
            'body'   => json_encode([
                'status'  => 'error',
                'message' => 'Upstream API request failed',
            ]),
            'error' => $errstr ?: 'curl_error_' . $errno,
        ];
    }

    return [
        'status' => $status,
        'body'   => (string) $response,
        'error'  => null,
    ];
}
