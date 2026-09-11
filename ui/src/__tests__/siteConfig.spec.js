/*
 * siteConfig specs: the defensive parse of the operator's /config.json.
 * The contract under test — an empty/missing logo leaves the text
 * brand in place, a real logo URL is kept, a nested menu survives
 * intact, and every way the fetch can fail (missing file, garbage
 * body, network down) resolves to the default {logo:'', menu:[]} with
 * no exception. The fetch is stubbed the way session.spec.js stubs
 * it, including a body that makes res.json() throw, which is what a
 * HTML error page served at /config.json would do.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { loadSiteConfig, normalizeConfig } from '../siteConfig'

/* The default every failure path must land on. */
const DEFAULTS = { logo: '', menu: [] }

afterEach(() => {
    vi.unstubAllGlobals()
})

/* Stub fetch with a status and a body; a body that is not JSON makes
 * json() reject exactly like the browser does for an HTML error page. */
function serve(status, body) {
    vi.stubGlobal('fetch', vi.fn(async () => ({
        ok: status >= 200 && status < 300,
        status,
        json: async () => JSON.parse(body)
    })))
}

describe('normalizeConfig — the logo', () => {
    it('keeps a real logo URL', () => {
        expect(normalizeConfig({ logo: 'https://example.com/wanportal.png' }))
            .toEqual({ logo: 'https://example.com/wanportal.png', menu: [] })
    })

    it('keeps a same-origin path too', () => {
        expect(normalizeConfig({ logo: '/assets/brand.png' }).logo)
            .toBe('/assets/brand.png')
    })

    it('falls back to the text brand on an empty logo', () => {
        expect(normalizeConfig({ logo: '', menu: [] })).toEqual(DEFAULTS)
    })

    it('treats a missing, null or non-string logo as the text brand', () => {
        expect(normalizeConfig({ menu: [] }).logo).toBe('')
        expect(normalizeConfig({ logo: null }).logo).toBe('')
        expect(normalizeConfig({ logo: 42 }).logo).toBe('')
    })
})

describe('normalizeConfig — the menu tree', () => {
    it('preserves a nested menu with in-app and external entries', () => {
        const config = {
            logo: '/assets/brand.png',
            menu: [
                { label: 'Dashboard', to: '/' },
                {
                    label: 'Reports',
                    href: '/classic/report.php',
                    children: [
                        { label: 'Latency', to: '/latency' },
                        {
                            label: 'More',
                            children: [{ label: 'Search', to: '/search' }]
                        }
                    ]
                },
                { label: 'Status', href: 'https://status.crc1.net' }
            ]
        }
        // structuredClone so the comparison is against an independent
        // copy, not the same object the parser was handed.
        expect(normalizeConfig(config)).toEqual(structuredClone(config))
    })

    it('keeps a children-only group and drops empty or invalid children', () => {
        const out = normalizeConfig({
            menu: [
                { label: 'Group', children: [] },
                { label: 'Junk children', children: 'nope' },
                { label: 'Kept', children: [{ label: 'Inner', to: '/search' }, { href: '/x' }] }
            ]
        })
        expect(out.menu).toEqual([
            { label: 'Group' },
            { label: 'Junk children' },
            { label: 'Kept', children: [{ label: 'Inner', to: '/search' }] }
        ])
    })

    it('ignores entries without a usable label but keeps their siblings', () => {
        const out = normalizeConfig({
            menu: [
                null,
                'nope',
                42,
                { to: '/latency' }, // navigates nowhere without a label
                { label: '   ' }, // whitespace-only is no label
                { label: 42 },
                { label: 'Kept', to: '/latency' }
            ]
        })
        expect(out.menu).toEqual([{ label: 'Kept', to: '/latency' }])
    })

    it('drops malformed to/href values without losing the entry', () => {
        expect(normalizeConfig({ menu: [{ label: 'Dashboard', to: 42, href: null }] }))
            .toEqual({ logo: '', menu: [{ label: 'Dashboard' }] })
    })

    it('collapses a non-array menu to an empty nav', () => {
        expect(normalizeConfig({ menu: 'dashboard' }).menu).toEqual([])
        expect(normalizeConfig({}).menu).toEqual([])
    })
})

describe('normalizeConfig — junk in, defaults out', () => {
    it('returns the default for anything that is not a config object', () => {
        for (const junk of [null, undefined, 42, 'config', true, [], [{ label: 'x' }]]) {
            expect(normalizeConfig(junk)).toEqual(DEFAULTS)
        }
    })
})

describe('loadSiteConfig', () => {
    it('fetches /config.json without credentials and without caching', async () => {
        serve(200, '{"logo":"","menu":[]}')

        await loadSiteConfig()

        const fetchMock = globalThis.fetch
        expect(fetchMock).toHaveBeenCalledTimes(1)
        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/config.json')
        expect(opts.credentials).toBe('omit')
        expect(opts.cache).toBe('no-store')
    })

    it('parses a good config end to end', async () => {
        serve(200, '{"logo":"/assets/brand.png","menu":[{"label":"Latency","to":"/latency"}]}')

        await expect(loadSiteConfig()).resolves.toEqual({
            logo: '/assets/brand.png',
            menu: [{ label: 'Latency', to: '/latency' }]
        })
    })

    it('falls back to the default when the file is missing (404)', async () => {
        serve(404, '<html>not found</html>')

        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)
    })

    it('falls back to the default on any other non-ok status', async () => {
        serve(500, '{}')

        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)
    })

    it('falls back to the default when the body is not JSON (garbage)', async () => {
        // json() rejects with SyntaxError, the transport failure a
        // stray HTML page at /config.json would produce.
        serve(200, 'this is not json at all')

        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)
    })

    it('falls back to the default when the body is not an object', async () => {
        serve(200, 'null')
        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)

        serve(200, '[]')
        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)
    })

    it('falls back to the default when the network is down', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('Failed to fetch')
        }))

        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)
    })

    it('never throws, even when fetch itself blows up synchronously', async () => {
        vi.stubGlobal('fetch', vi.fn(() => {
            throw new Error('sync failure')
        }))

        await expect(loadSiteConfig()).resolves.toEqual(DEFAULTS)
    })
})