/*
 * Sign-in state for the bundled app.
 *
 * The SPA gets its own token now: POST /cgi-bin/api/login takes a JSON
 * {username, password} and answers with a JWT. That token lives in
 * sessionStorage under 'wanportal.jwt' — tab-scoped, gone when the tab
 * closes — and rides the Authorization header on API calls. It never
 * goes to localStorage (too sticky), never into a log line, and never
 * into the DOM: the session chip renders claims only, never the token.
 *
 * GET /cgi-bin/api/session still answers who is calling, now reading
 * the Bearer header the page sends:
 *
 *   200 {status:'success', username, is_admin, exp} -> signed in
 *   401                                             -> signed out
 *   anything else / transport failure               -> unknown
 *
 * The signed-out and unknown cases stay separate on purpose - the
 * dashboard honesty rule applies here too, so a dead API never gets
 * reported as "signed out". A 401 while a token is stored means the
 * token expired, so it gets dropped and the chip falls back to the
 * signed-out state.
 */

import { postJson } from './api'

const SESSION_URL = '/cgi-bin/api/session';
const LOGIN_URL = '/cgi-bin/api/login';

// Tab-scoped on purpose: sessionStorage dies with the tab, so closing
// it signs the visitor out instead of leaving the token on disk.
const TOKEN_KEY = 'wanportal.jwt';

export function getToken() {
    try {
        return sessionStorage.getItem(TOKEN_KEY)
    } catch {
        return null // storage can be off in hard privacy modes
    }
}

export function setToken(token) {
    try {
        sessionStorage.setItem(TOKEN_KEY, token)
    } catch {
        // No storage means no way to keep the session; the next
        // getSession reports signed out rather than half-signed-in.
    }
}

export function clearToken() {
    try {
        sessionStorage.removeItem(TOKEN_KEY)
    } catch {
        // Nothing to clean up when storage was never available.
    }
}

/* Bearer header only when a token is around; public endpoints keep
 * going out bare. */
export function authHeaders() {
    const token = getToken()
    return token ? { Authorization: 'Bearer ' + token } : {}
}

export async function getSession({ timeoutMs = 10000 } = {}) {
    const ctrl = new AbortController();
    const killer = setTimeout(() => ctrl.abort(), timeoutMs);
    try {
        const res = await fetch(SESSION_URL, {
            signal: ctrl.signal,
            credentials: 'omit', // the API takes Bearer, not cookies
            headers: { Accept: 'application/json', ...authHeaders() }
        });
        if (res.status === 401) {
            // An expired token is worse than no token: drop it so the
            // next call goes out bare instead of 401-ing forever.
            if (getToken()) clearToken();
            return { authenticated: false, reason: 'signed-out' };
        }
        if (!res.ok) {
            return { authenticated: false, reason: 'unavailable', error: 'HTTP ' + res.status };
        }
        const claims = await res.json();
        if (!claims || claims.status !== 'success') {
            return { authenticated: false, reason: 'unavailable', error: 'bad payload' };
        }
        return {
            authenticated: true,
            reason: 'ok',
            username: claims.username || '',
            isAdmin: !!claims.is_admin,
            exp: claims.exp || null
        };
    } catch (err) {
        return {
            authenticated: false,
            reason: 'unavailable',
            error: (err && err.message) || 'session request failed'
        };
    } finally {
        clearTimeout(killer);
    }
}

/* Posts the credentials to the JSON login endpoint and parks the
 * returned JWT in sessionStorage. Throws on bad credentials (the API
 * answers 401) so the form can show its error line. Returns the claims
 * for callers that want them; the token itself stays in storage. */
export async function login(username, password) {
    const reply = await postJson(LOGIN_URL, { username, password });
    setToken(reply.token);
    return {
        username: reply.username || '',
        isAdmin: !!reply.is_admin,
        exp: reply.exp || null
    };
}

/* The token is the session: forgetting it signs the tab out. No server
 * round-trip needed — a JWT nobody sends is nobody's session. */
export async function logout() {
    clearToken()
}