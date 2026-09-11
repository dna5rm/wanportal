/*
 * Operator site config for the SPA chrome.
 *
 * GET /config.json is a static file the operator edits directly in the
 * Apache docroot (htdocs/config.json) — outside every build, which is
 * why vite.config.js keeps emptyOutDir false. It carries two keys:
 *
 *   logo  URL or path of a brand image. Empty, missing or null keeps
 *         the text brand "wanportal".
 *   menu  the top-nav tree: [{label, to?, href?, children?}]. `to` is
 *         an in-app hash route (/latency); `href` is an external or
 *         same-origin full path; children nest the same shape. Entries
 *         without a label are ignored.
 *
 * The file is operator input, so nothing here trusts it: every field
 * is type-checked, junk is dropped, and every failure path — missing
 * file, non-JSON body, transport error — lands on the same default
 * {logo:'', menu:[]}, so a broken config can never blank the app; the
 * chrome just renders its built-in brand and nav. loadSiteConfig()
 * never throws, and normalizeConfig() is exported so a caller can run
 * the same defensive parse over JSON it fetched itself.
 */

const CONFIG_URL = '/config.json'

/* A string that survives trimming; everything else counts as absent. */
function isText(value) {
    return typeof value === 'string' && value.trim() !== ''
}

/* One menu entry: label is required, to/href are optional strings,
 * children recurse through normalizeMenu. Nothing about a malformed
 * entry invalidates its siblings — bad fields are dropped, the entry
 * survives on its label. */
function normalizeEntry(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null
    const label = isText(raw.label) ? raw.label.trim() : ''
    if (!label) return null // entries without a label are ignored
    const entry = { label }
    if (isText(raw.to)) entry.to = raw.to.trim()
    if (isText(raw.href)) entry.href = raw.href.trim()
    const children = normalizeMenu(raw.children)
    if (children.length) entry.children = children
    return entry
}

/* The menu is an array or it is nothing: junk in the operator's file
 * collapses to an empty nav, never to an exception. */
function normalizeMenu(raw) {
    if (!Array.isArray(raw)) return []
    return raw.map(normalizeEntry).filter(Boolean)
}

/* Defensive parse of one site config document. Anything that is not an
 * object (null, a JSON array, a number, garbage) is the default; a
 * non-string logo is no logo; the menu keeps only well-formed entries.
 * Never throws — callers can hand it anything they read off the wire. */
export function normalizeConfig(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
        return { logo: '', menu: [] }
    }
    return {
        logo: isText(raw.logo) ? raw.logo.trim() : '',
        menu: normalizeMenu(raw.menu)
    }
}

/* Fetch the operator config once at startup. credentials:'omit' keeps
 * the classic PHP session cookie out of a public static request, and
 * cache:'no-store' makes an operator's edit visible on the very next
 * page load. Every failure mode — 404, HTML error page, network down —
 * resolves to the default config instead of throwing, so the shell
 * always has a shape it can render. */
export async function loadSiteConfig() {
    try {
        const res = await fetch(CONFIG_URL, {
            credentials: 'omit',
            cache: 'no-store'
        })
        if (!res.ok) return normalizeConfig(null)
        return normalizeConfig(await res.json())
    } catch (err) {
        return normalizeConfig(null)
    }
}