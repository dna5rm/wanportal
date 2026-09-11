/*
 * Persistent "show inactive" for the SPA — the analog of the classic
 * console's session flag (lib/page.php's wanportal_get_show_inactive()
 * plus the URL round-trip hook wanportal_render_page_end() emits).
 *
 * Classic contract: GET ?show_inactive=true|false wins when present,
 * else $_SESSION['show_inactive'], else false — and the resolved value
 * is written back to the session, so a link carrying the query updates
 * every page that follows. The toggle round-trips the new value
 * through the URL (?show_inactive=true|false, other query params and
 * the hash preserved) and reloads the page.
 *
 * SPA analog: there is no PHP session, so the localStorage key
 * 'wanportal-show-inactive' ('true'/'false') stands in for it, and
 * history.replaceState carries the query in the address bar without a
 * full reload — a hash-router page must not reload. The agent and
 * target details and the users listing share the one key, exactly
 * like the classic pages shared the one session flag: a choice made
 * on the users listing follows to the agent detail and back.
 *
 * Every localStorage access is wrapped: Safari private mode and some
 * embedded WebViews throw on getItem, and the page must not error out
 * over a listing filter.
 */

/* Shared by the three views that bind it — do not drift. */
export const SHOW_INACTIVE_KEY = 'wanportal-show-inactive'

/* One show_inactive value, parsed the way PHP's
 * filter_var($x, FILTER_VALIDATE_BOOLEAN) does for lib/page.php:
 * '1'/'true'/'on'/'yes' (case-insensitive) are true; everything else
 * — 'false', the empty string, junk — is false, the same coercion the
 * classic function's bool return type applies. */
export function parseShowInactive(raw) {
    return ['1', 'true', 'on', 'yes'].includes(String(raw).toLowerCase())
}

/* The URL override, or null when the param is absent. The real query
 * string is read first (what the classic pages carry:
 * /agent.php?show_inactive=true), then the hash query — the same two
 * spots detailShared.idFromLocation reads. */
export function showInactiveInUrl() {
    const spots = [window.location.search]
    const q = window.location.hash.indexOf('?')
    if (q !== -1) {
        spots.push(window.location.hash.slice(q + 1))
    }
    for (const spot of spots) {
        const params = new URLSearchParams(spot)
        if (params.has('show_inactive')) {
            return parseShowInactive(params.get('show_inactive'))
        }
    }
    return null
}

/* The stored choice, or null when unset / storage unavailable. A
 * stored value other than the truthy spellings parses false — the
 * storage should only ever hold 'true'/'false', so junk normalizes to
 * the same default the classic session would hold. */
export function storedShowInactive() {
    try {
        const raw = localStorage.getItem(SHOW_INACTIVE_KEY)
        return raw === null ? null : parseShowInactive(raw)
    } catch (e) {
        return null
    }
}

/* Persist a choice and, unless url:false, carry it into the address
 * bar the way the classic toggle does: show_inactive set explicitly
 * to 'true'/'false' in both directions (page.php's hook never deletes
 * the param), with every other query param and the hash preserved.
 * replaceState, not location.assign — the SPA must not reload. */
export function setShowInactive(value, { url = true } = {}) {
    try {
        localStorage.setItem(SHOW_INACTIVE_KEY, value ? 'true' : 'false')
    } catch (e) { /* storage may be disabled; the toggle still applies in-page */ }
    if (url) {
        try {
            const target = new URL(window.location.href)
            target.searchParams.set('show_inactive', value ? 'true' : 'false')
            window.history.replaceState(
                null, '', target.pathname + target.search + target.hash
            )
        } catch (e) { /* the address bar is cosmetic; the choice still binds */ }
    }
    return value
}

/* The classic resolution, verbatim in shape: URL wins, then the
 * stored choice, else false. The resolution is written back to
 * storage — the $_SESSION['show_inactive'] write-back — so a
 * ?show_inactive=true deep link sticks for every page that follows;
 * the URL itself is left alone on load. Views call this once at
 * setup to seed the checkbox. */
export function resolveShowInactive() {
    const fromUrl = showInactiveInUrl()
    const value = fromUrl !== null ? fromUrl : (storedShowInactive() ?? false)
    return setShowInactive(value, { url: false })
}