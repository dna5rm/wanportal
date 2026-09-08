/*
 * Shared helpers for the three detail pages (monitor, agent, target).
 * Strictly view plumbing: number/timestamp formatting, status wording,
 * and the link builders that keep cross-page navigation in one place.
 * No data fetching lives here — each detail component owns its own
 * requests and its own failure banners.
 */
import { lossClass } from '../format'

export { lossClass }

/*
 * Every detail id is a char(36) uuid and the API rejects anything else
 * with a 400, so check the shape here before calling — a garbage id
 * should read as "not found", not as a broken request.
 */
export function uuidOk(s) {
    return typeof s === 'string' && /^[0-9a-fA-F-]{36}$/.test(s)
}

/*
 * Detail components accept an optional id prop. When it is missing they
 * fall back to the current URL, in this order:
 *   1. ?id=... in the plain query string (classic-page style links)
 *   2. ?id=... inside the hash (works for '#/monitor?id=...')
 *   3. a trailing uuid path segment in the hash (works for
 *      '#/monitor/<uuid>')
 * The router wiring can pass the id as a prop and none of this runs.
 */
export function idFromLocation(prop) {
    if (uuidOk(prop)) return prop
    const fromSearch = new URLSearchParams(window.location.search).get('id') || ''
    if (uuidOk(fromSearch)) return fromSearch
    let hash = window.location.hash || ''
    const q = hash.indexOf('?')
    if (q !== -1) {
        const fromHash = new URLSearchParams(hash.slice(q + 1)).get('id') || ''
        if (uuidOk(fromHash)) return fromHash
        hash = hash.slice(0, q)
    }
    const parts = hash.split('/').filter(Boolean)
    const tail = parts.length ? decodeURIComponent(parts[parts.length - 1]) : ''
    return uuidOk(tail) ? tail : ''
}

/*
 * In-app links between detail pages. The SPA is hash-navigated today
 * (App.vue builds '#/...' hrefs), so these follow the same shape. When
 * route wiring lands it should honor these paths — or change them here,
 * in this one spot, so every page keeps matching.
 */
export function detailLink(kind, id) {
    return '#/' + kind + '/' + encodeURIComponent(id)
}

/*
 * Editing stays on the classic console: the Vue app is read-only by
 * design for these pages, so the edit button just deep-links the PHP
 * editor the way the classic pages did.
 */
export function editLink(kind, id) {
    const page = {
        monitor: 'monitors_edit.php',
        agent: 'agents_edit.php',
        target: 'targets_edit.php'
    }[kind]
    return page ? '/' + page + '?id=' + encodeURIComponent(id) : '/'
}

/* The RRD endpoint also serves the raw sample dump; the classic page
 * offered it as a "Raw Data" button that opens in a new tab. */
export function rrdRawUrl(id) {
    return '/cgi-bin/api/rrd?id=' + encodeURIComponent(id)
}

/* Minute-resolution ISO string ('YYYY-MM-DDTHH:MM') in UTC — the same
 * shape the classic chart page puts in its start/end inputs. */
export function isoMinutes(ms) {
    return new Date(ms).toISOString().slice(0, 16)
}

/* /rrd data-URL builder. start/end are ms epochs. Note the classic page
 * sends UTC-stamped strings and the endpoint parses them against the
 * server clock; keep sending the identical shape so ranges line up with
 * the legacy graphs. */
export function rrdDataUrl(id, startMs, endMs) {
    return '/cgi-bin/api/rrd?id=' + encodeURIComponent(id)
        + '&start=' + encodeURIComponent(isoMinutes(startMs))
        + '&end=' + encodeURIComponent(isoMinutes(endMs))
}

/*
 * DBI hands most numeric columns back as strings, and absent values
 * arrive as null or ''. Format plainly and never dress a gap up as a
 * zero — an empty stat reads as '-'.
 */
export function numOr(v, suffix = '') {
    if (v === null || v === undefined || v === '') return '-'
    const n = Number(v)
    return (Number.isFinite(n) ? String(n) : String(v)) + suffix
}

/* Timestamps arrive as 'YYYY-MM-DD HH:MM:SS' strings; the classic pages
 * show them verbatim, so do the same and use '-' for the empty case. */
export function stampOr(s) {
    return (s === null || s === undefined) ? '-' : String(s).trim() || '-'
}

/* ICMP rows show the bare protocol; everything else gets protocol/port,
 * matching the classic tables. DSCP rides in the title attribute. */
export function protoLabel(protocol, port) {
    const proto = String(protocol || '').toUpperCase()
    if (!proto) return '-'
    return proto === 'ICMP' ? proto : proto + '/' + (port || '?')
}

/* Status chips: up-green when active, warn-amber when not. */
export function activeChipCls(on) {
    return Number(on) === 1 ? 'chip chip-ok' : 'chip chip-warn'
}

/*
 * Why a monitor row is not effectively active, in the classic pages'
 * wording. The API's row.is_active is the combined flag (monitor AND
 * agent AND target), so a row is only "on" when all three are.
 */
export function rowActive(m) {
    return !!m && Number(m.is_active) === 1
}

export function inactiveReasons(m) {
    const why = []
    if (m && Number(m.monitor_is_active) !== 1) why.push('Monitor disabled')
    if (m && Number(m.agent_is_active) !== 1) why.push('Agent disabled')
    if (m && Number(m.target_is_active) !== 1) why.push('Target disabled')
    return why
}

/*
 * Advisory freshness chip: a monitor's data looks stale once its last
 * update is older than three poll intervals — the same freshness gate
 * public_api applies before raising the latency flag. Poll interval is
 * in seconds and the DB default is 60. Stamps are written with MySQL
 * NOW() (server wall clock) and are parsed like the existing format.js
 * helpers do, so this is only exact when viewer and server share a
 * timezone — which is why the verbatim stamp is always shown next to
 * it and this is treated as advisory, never as data.
 */
export function isDataFresh(m, now = Date.now()) {
    const t = Date.parse(String((m && m.last_update) || '').replace(' ', 'T'))
    if (Number.isNaN(t)) return false
    const interval = Number(m && m.pollinterval) > 0 ? Number(m.pollinterval) : 60
    return now - t <= 3 * interval * 1000
}

/* Turn a thrown fetch error into banner text, mapping the API's 404s
 * (which getJson surfaces as 'HTTP 404') to plain not-found wording. */
export function humanErr(err, notFoundText) {
    const msg = (err && err.message) || 'unknown error'
    return msg === 'HTTP 404' ? notFoundText : msg
}