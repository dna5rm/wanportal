/*
 * Small same-origin JSON fetcher. Every call goes to a relative
 * /cgi-bin/api/... path, so the page works wherever Apache serves it.
 * Public endpoints need no auth; when the SPA holds a login token,
 * session.authHeaders() adds the Bearer header and a 401 drops an
 * expired token so the next call goes out bare. Cookies stay out of it
 * on purpose: the API wants the Bearer header, and credentials:'omit'
 * keeps the classic PHP session cookie from tagging along.
 */

import { authHeaders, clearToken, getToken } from './session'

/*
 * Shared plumbing for all fetchers: timeout wiring, the success
 * envelope check, and the expired-token cleanup. A 401 only means
 * "token expired" when we actually sent one; without a token it is
 * just the endpoint saying no, and there is nothing to clean up.
 * noContent is for the delete path: a 204 answers with no body at
 * all, so there is no envelope to demand from nothing.
 */
async function fetchJson(url, options, { timeoutMs = 10000, noContent = false } = {}) {
    const ctrl = new AbortController();
    const killer = setTimeout(() => ctrl.abort(), timeoutMs);
    try {
        const res = await fetch(url, { ...options, signal: ctrl.signal });
        if (res.status === 401 && getToken()) clearToken();
        if (!res.ok) throw new Error('HTTP ' + res.status);
        if (noContent && res.status === 204) return null;
        const json = await res.json();
        if (!json || json.status !== 'success') throw new Error('bad payload');
        return json;
    } finally {
        clearTimeout(killer);
    }
}

export async function getJson(url, opts = {}) {
    return fetchJson(url, {
        credentials: 'omit',
        headers: { Accept: 'application/json', ...authHeaders() }
    }, opts);
}

/* POST variant for the login call and anything else that sends a JSON
 * body; same envelope rules as getJson so callers handle one shape. */
export async function postJson(url, body, opts = {}) {
    return fetchJson(url, {
        method: 'POST',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...authHeaders()
        },
        body: JSON.stringify(body == null ? {} : body)
    }, opts);
}

/* PUT variant for the save endpoints. The body rides out exactly like
 * a postJson body, so the API sees one request shape for every write
 * and callers handle one envelope shape in return. */
export async function putJson(url, body, opts = {}) {
    return fetchJson(url, {
        method: 'PUT',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...authHeaders()
        },
        body: JSON.stringify(body)
    }, opts);
}

/* DELETE variant: there is no body to send, so no Content-Type. A
 * delete that worked usually comes back as a bare 204 with no
 * envelope, and noContent is what lets that count as success rather
 * than "bad payload". */
export async function delJson(url, opts = {}) {
    return fetchJson(url, {
        method: 'DELETE',
        credentials: 'omit',
        headers: { Accept: 'application/json', ...authHeaders() }
    }, { ...opts, noContent: true });
}