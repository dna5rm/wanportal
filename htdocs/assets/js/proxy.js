// assets/js/proxy.js
//
// Small helper for making state-changing calls (DELETE, PUT, PATCH,
// POST) to the API. The browser POSTs to /proxy.php with a CSRF
// token; proxy.php reads the session's JWT and forwards to the
// internal cgi-bin/api. This keeps the JWT out of the page source.
//
// Usage from a page script:
//   window.proxyRequest('DELETE', '/credentials/42')
//     .then(function(data) { /* ... */ })
//     .catch(function(err) { /* ... */ });

(function () {
    'use strict';

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        if (window.csrfToken) {
            return window.csrfToken;
        }
        return '';
    }

    /**
     * Make an authenticated, CSRF-protected request through /proxy.php.
     *
     * @param {string} method  HTTP method to forward to the API
     * @param {string} path    API path beginning with /
     * @param {object} [body]  Optional JSON body for POST/PUT/PATCH
     * @returns {Promise<object>}  Resolves with the parsed JSON body
     *                             from the API (or a synthesized
     *                             {status:'error',message:...} on
     *                             transport failure).
     */
    window.proxyRequest = function (method, path, body) {
        var csrfToken = getCsrfToken();
        if (!csrfToken) {
            return Promise.reject(new Error('CSRF token not available'));
        }

        var envelope = { _method: method, path: path };
        if (body !== undefined && body !== null) {
            envelope.body = body;
        }

        return fetch('/proxy.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(envelope)
        }).then(function (response) {
            // Try to parse JSON; if upstream returned non-JSON, fall
            // back to a structured error.
            return response.text().then(function (text) {
                var data = null;
                if (text) {
                    try { data = JSON.parse(text); } catch (e) { data = null; }
                }
                if (!data) {
                    data = { status: response.ok ? 'success' : 'error',
                             message: text || ('HTTP ' + response.status) };
                }
                // Surface upstream HTTP errors as promise rejections
                // so the calling code's .catch() fires.
                if (!response.ok) {
                    var err = new Error(data.message || ('HTTP ' + response.status));
                    err.status = response.status;
                    err.data = data;
                    throw err;
                }
                return data;
            });
        });
    };
})();
