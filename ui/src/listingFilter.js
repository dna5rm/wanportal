/*
 * Persistent listing filters for the listing views (agents, targets,
 * monitors, users, credentials) — the SPA analog of the classic
 * console's DataTables state, minus everything but the filter state.
 *
 * Contract: each listing owns one localStorage key,
 * 'wanportal-filter-<page>' holding JSON. `q` is the master field —
 * every stored shape carries it as a string, and a stored shape
 * without a string q is junk and reads back as the empty default.
 * Alongside it a listing may keep further string fields (the users
 * role select, the credentials type/site/active filters); they ride
 * under their own names and each is read back only when it is a
 * string, so junk fields never reach the views. The views write it
 * (debounced) as the user changes filters and re-read it on mount,
 * so the state survives navigating away and back — until the view's
 * clear button wipes both the boxes and the key.
 *
 * Every localStorage access is wrapped like prefs.js: Safari private
 * mode and some embedded WebViews throw on getItem, and the page must
 * not error out over a listing filter.
 */

const KEY_PREFIX = 'wanportal-filter-'

function filterKey(page) {
    return KEY_PREFIX + page
}

/* Copy the string fields of the state — q first, then every other
 * string field under its own name. Non-strings are dropped: the
 * selects and inputs the views persist are strings, and a number or
 * object in the stored JSON would read back as junk. */
function stringState(state) {
    const out = { q: String((state && state.q) ?? '') }
    if (state && typeof state === 'object') {
        for (const [k, v] of Object.entries(state)) {
            if (k !== 'q' && typeof v === 'string') out[k] = v
        }
    }
    return out
}

/* The stored filter, or the empty default when unset, junk, or
 * storage unavailable. q is the master field: a stored shape without
 * a string q is junk and is dropped wholesale. With q valid, the
 * other string fields ride back under their own names — anything
 * non-string is dropped, so the views never see stray state. */
export function loadFilter(page) {
    try {
        const raw = localStorage.getItem(filterKey(page))
        if (raw === null) return { q: '' }
        const parsed = JSON.parse(raw)
        if (!parsed || typeof parsed !== 'object' || typeof parsed.q !== 'string') {
            return { q: '' }
        }
        return stringState(parsed)
    } catch (e) {
        return { q: '' }
    }
}

/* Persist the filter — q stringified as always, other string fields
 * under their own names, exactly the shape loadFilter() reads back. */
export function saveFilter(page, state) {
    try {
        localStorage.setItem(filterKey(page), JSON.stringify(stringState(state)))
    } catch (e) { /* storage may be disabled; the filter still applies in-page */ }
}

/* Wipe the key — the storage half of the view's clear button. */
export function clearFilter(page) {
    try {
        localStorage.removeItem(filterKey(page))
    } catch (e) { /* nothing to undo; the view resets its own q */ }
}

/* The one match rule every listing shares — do not drift: a row shows
 * when the filter is empty or ANY field includes it, case-insensitively.
 * Every field rides String(v), so numbers (ports, flags) and booleans
 * match exactly as typed; null and undefined fields are skipped, so
 * their string forms ('null', 'undefined') never hit — the filter is
 * for names, addresses, descriptions, protocols and stamps, not for
 * status chips, which are derived, not stored. */
export function matchesFilter(row, q) {
    const needle = String(q ?? '').toLowerCase()
    if (!needle) return true
    return Object.values(row || {}).some((v) =>
        v !== null && v !== undefined && String(v).toLowerCase().includes(needle)
    )
}