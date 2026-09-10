/*
 * Router construction is the SPA's boot. A bad alias (undefined)
 * used to throw "aliases is not iterable" and leave #app empty.
 *
 * The sign-in gate is exercised here too: meta.auth routes bounce a
 * signed-out tab to /login with the intended path parked in
 * ?redirect=, a signed-in tab passes through, and public routes never
 * even ask the session API — the dashboard drill-down must survive a
 * dead API.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import router from '../router.js'

afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
    localStorage.clear()
    // Land back on a public route so no test starts mid-gate.
    return router.push('/')
})

describe('router boot', () => {
    it('creates without throwing', () => {
        expect(router).toBeTruthy()
        expect(router.hasRoute('dashboard')).toBe(true)
        expect(router.hasRoute('credential')).toBe(true)
        expect(router.hasRoute('monitor')).toBe(true)
    })

    it('keeps the singular aliases resolving to the detail records', () => {
        // detailShared.detailLink still builds '#/monitor/<uuid>'
        // hrefs, so the aliases must survive every route-table patch.
        expect(router.resolve('/monitor/abc').name).toBe('monitor')
        expect(router.resolve('/agent/abc').name).toBe('agent')
        expect(router.resolve('/target/abc').name).toBe('target')
    })
})

describe('auth gate', () => {
    it('flags the gated routes and leaves the public ones open', () => {
        const byName = new Map()
        for (const record of router.getRoutes()) {
            if (record.name && !byName.has(record.name)) byName.set(record.name, record)
        }

        // Listings, create/edit doors and the vault record all match
        // the classic console's check_session.php pages.
        const gated = [
            'monitors', 'agents', 'targets', 'users', 'credentials',
            'user-new', 'user-edit', 'monitor-new', 'monitor-edit',
            'agent-new', 'agent-edit', 'target-new', 'target-edit',
            'credential-new', 'credential-edit', 'credential'
        ]
        // Dashboard, tools and the drill-down details stay public —
        // the dashboard links those records for anonymous visitors.
        const open = ['dashboard', 'search', 'latency', 'login', 'monitor', 'agent', 'target', 'agent-netping']

        for (const name of gated) {
            expect(byName.get(name), name).toBeDefined()
            expect(byName.get(name).meta.auth, name).toBe(true)
        }
        for (const name of open) {
            expect(byName.get(name), name).toBeDefined()
            expect(byName.get(name).meta.auth, name).toBeUndefined()
        }
    })

    it('bounces a signed-out visit to login with the redirect parked', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 401 })))

        await router.push('/monitors')

        expect(router.currentRoute.value.name).toBe('login')
        expect(router.currentRoute.value.query.redirect).toBe('/monitors')
    })

    it('keeps the full deep-link path in the redirect query', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 401 })))

        await router.push('/agents/abc/edit')

        expect(router.currentRoute.value.name).toBe('login')
        expect(router.currentRoute.value.query.redirect).toBe('/agents/abc/edit')
    })

    it('lets a signed-in tab through to the gated route', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ status: 'success', username: 'ops', is_admin: false, exp: 9999999999 })
        })))

        await router.push('/monitors')

        expect(router.currentRoute.value.name).toBe('monitors')
    })

    it('never asks the session API on public routes', async () => {
        const fetchMock = vi.fn(() => {
            throw new Error('session API must stay idle on public routes')
        })
        vi.stubGlobal('fetch', fetchMock)

        await router.push('/search')
        expect(router.currentRoute.value.name).toBe('search')

        // The dashboard's drill-down detail stays reachable signed out.
        await router.push('/monitors/abc')
        expect(router.currentRoute.value.name).toBe('monitor')

        expect(fetchMock).not.toHaveBeenCalled()
    })
})
