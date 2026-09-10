/*
 * App shell specs: the bar shows the public pages on the left and the
 * account cluster on the right — search is not one of them, it lives
 * on the dashboard itself. The gated listing pages never sit in
 * the bar itself — they live in the account dropdown, so a signed-out
 * visitor sees none of them anywhere in the chrome, and the classic
 * /classic door exists exactly once, inside that dropdown, only while
 * signed in. The two utility doors — API and Runtime — sit
 * beside the log-in link while signed out and inside the dropdown
 * once signed in, never in both places at once. The probe is
 * answered by one fetch stub keyed by url — same as the other specs —
 * so the real session.js decides what the answer means.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import LoginView from '../components/LoginView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
    localStorage.clear()
})

/* Search is gone from the chrome entirely: it lives on the dashboard. */
const PUBLIC = ['Dashboard', 'Latency']
const GATED = ['Monitors', 'Agents', 'Targets', 'Credentials']

function adminSession() {
    return { status: 'success', username: 'ops-admin', is_admin: 1, exp: null }
}

function nonAdminSession() {
    return { status: 'success', username: 'read-only', is_admin: 0, exp: null }
}

/*
 * Mount the shell with the session probe answered by `options`, which
 * is read live on every call so a test can flip the answer between
 * route changes (options is the same object the test holds). Every
 * nav target is registered so the router-links resolve, mirroring the
 * production route table's paths — /login carries the real LoginView
 * so a sign-in can run for real inside the shell, answered by
 * options.loginBody.
 */
async function mountApp(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            '/', '/monitors', '/agents', '/targets', '/users',
            '/search', '/latency', '/credentials', '/api', '/runtime',
            '/login'
        ].map((path) => ({
            path,
            component: path === '/login' ? LoginView : { render: () => null }
        }))
    })
    await router.push('/')
    await router.isReady()

    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/session') {
            if (options.hangSession) return new Promise(() => {}) // probe never settles
            if (options.sessionStatus === 401) {
                return { ok: false, status: 401, json: async () => ({}) }
            }
            return jsonReply(options.sessionBody || nonAdminSession())
        }
        if (url === '/cgi-bin/api/login') {
            if (!options.loginBody) throw new Error('unexpected login call')
            return jsonReply(options.loginBody)
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(App, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, router, stub, options }
}

/* Opens the account dropdown the way a user would: click the button. */
async function openAccountMenu(wrapper) {
    await wrapper.find('.account-btn').trigger('click')
}

describe('App chrome for a signed-out visitor', () => {
    it('keeps the bar public with one log-in door and no classic console', async () => {
        const { wrapper } = await mountApp({ sessionStatus: 401 })

        for (const label of PUBLIC) expect(wrapper.text()).toContain(label)
        // The gated pages live in the account dropdown, not the bar.
        for (const label of [...GATED, 'Users']) expect(wrapper.text()).not.toContain(label)

        // Search left the chrome entirely — the dashboard owns it now;
        // only the /search deep-link route survives.
        expect(wrapper.text()).not.toContain('Search')
        expect(wrapper.findAll('a[href="/search"]')).toHaveLength(0)

        // One log-in door on the right; nothing account-ish beyond it.
        expect(wrapper.findAll('a[href="/login"]')).toHaveLength(1)
        expect(wrapper.find('.account-btn').exists()).toBe(false)
        expect(wrapper.findAll('a[href="/classic"]')).toHaveLength(0)
        expect(wrapper.findAll('a[href="/login.php"]')).toHaveLength(0)

        // The two utility doors stay reachable next to log in, so a
        // signed-out operator can still reach swagger and the runtime
        // page — in-app now, no /classic/server.php anywhere.
        expect(wrapper.findAll('a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/runtime"]')).toHaveLength(1)
    })
})

describe('App chrome for a signed-in admin', () => {
    it('keeps the gated pages behind the account dropdown', async () => {
        const { wrapper } = await mountApp({ sessionBody: adminSession() })

        // Even signed in, the bar itself stays public...
        expect(wrapper.find('nav.topnav').text()).toContain('Dashboard')
        expect(wrapper.find('nav.topnav').text()).not.toContain('Monitors')

        // ...and the account button is one plain username label — the
        // admin claim lives in its tooltip, not in a nested chip.
        const btn = wrapper.find('.account-btn')
        expect(btn.text()).toContain('ops-admin')
        expect(btn.find('.chip').exists()).toBe(false)
        expect(btn.attributes('title')).toBe('signed in, admin')
        expect(wrapper.findAll('a[href="/login"]')).toHaveLength(0)

        // The gated pages appear once the dropdown is opened.
        await openAccountMenu(wrapper)
        for (const label of [...PUBLIC, ...GATED, 'Users', 'API', 'Runtime']) {
            expect(wrapper.text()).toContain(label)
        }

        // The utility doors live here and only here once signed in —
        // no nav-end duplicates beside the account button.
        expect(wrapper.findAll('a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/runtime"]')).toHaveLength(1)

        // Exactly one classic console door in the whole chrome, muted.
        const classic = wrapper.findAll('a[href="/classic"]')
        expect(classic).toHaveLength(1)
        expect(classic[0].classes()).toContain('menu-muted')
    })
})

describe('App chrome for a signed-in non-admin', () => {
    it('opens the gated pages in the dropdown but keeps Users admin-only', async () => {
        const { wrapper } = await mountApp({ sessionBody: nonAdminSession() })

        // One plain label for a non-admin too; no admin claim anywhere
        // on the button.
        expect(wrapper.find('.account-btn').find('.chip').exists()).toBe(false)
        expect(wrapper.find('.account-btn').attributes('title')).toBe('signed in')

        await openAccountMenu(wrapper)
        for (const label of GATED) expect(wrapper.text()).toContain(label)
        expect(wrapper.text()).not.toContain('Users')
        expect(wrapper.findAll('a[href="/classic"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/api"]')).toHaveLength(1)
    })
})

describe('App chrome while the probe is in flight', () => {
    it('keeps the bar to the public set until the probe answers', async () => {
        const { wrapper } = await mountApp({ hangSession: true })

        for (const label of PUBLIC) expect(wrapper.text()).toContain(label)
        for (const label of [...GATED, 'Users']) expect(wrapper.text()).not.toContain(label)

        // The account cluster is still probing — no log-in door yet.
        expect(wrapper.find('.session-chip').text()).toContain('session')
        expect(wrapper.find('a[href="/login"]').exists()).toBe(false)
    })
})

describe('App chrome follows route changes', () => {
    it('re-probes on navigation, so a sign-in lights the account menu', async () => {
        const options = { sessionStatus: 401 }
        const { wrapper, router } = await mountApp(options)
        expect(wrapper.find('.account-btn').exists()).toBe(false)

        // The probe answer flips (as after a sign-in on /login) and the
        // route change triggers the re-probe that notices.
        options.sessionStatus = 200
        options.sessionBody = adminSession()
        await router.push('/search')
        await flushPromises()
        await flushPromises()

        expect(wrapper.find('.account-btn').text()).toContain('ops-admin')
        await openAccountMenu(wrapper)
        expect(wrapper.text()).toContain('Monitors')
        expect(wrapper.text()).toContain('Users')
    })

    it('collapses the account dropdown on navigation', async () => {
        const { wrapper, router } = await mountApp({ sessionBody: adminSession() })

        await openAccountMenu(wrapper)
        expect(wrapper.find('.account-menu').exists()).toBe(true)

        await router.push('/search')
        await flushPromises()

        expect(wrapper.find('.account-menu').exists()).toBe(false)
    })
})

describe('App chrome re-probes on the sign-in flow', () => {
    it('re-probes when only the hash moves, not just the path', async () => {
        const options = { sessionStatus: 401 }
        const { wrapper, router } = await mountApp(options)
        await router.push('/search')
        await flushPromises()
        await flushPromises()
        expect(wrapper.find('.account-btn').exists()).toBe(false)

        // Same path, different hash: a path-only watch never fires
        // here, so this is exactly the navigation the login page can
        // leave behind. The probe must still run.
        options.sessionStatus = 200
        options.sessionBody = adminSession()
        await router.push('/search#recheck')
        await flushPromises()
        await flushPromises()

        expect(wrapper.find('.account-btn').text()).toContain('ops-admin')
    })

    it('lights the account menu when the bundled login form signs in', async () => {
        const options = { sessionStatus: 401 }
        const { wrapper, router } = await mountApp(options)
        await router.push('/login')
        await flushPromises()
        expect(wrapper.find('.account-btn').exists()).toBe(false)

        // The login POST answers, and the session probe now sees the
        // token login() parked — the re-probe LoginView runs turns the
        // chip into the account menu even before the redirect.
        options.loginBody = { status: 'success', token: 'jwt-spec', username: 'ops-admin', is_admin: 1, exp: null }
        options.sessionStatus = 200
        options.sessionBody = adminSession()
        await wrapper.find('#login-user').setValue('ops-admin')
        await wrapper.find('#login-pass').setValue('secret')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        expect(sessionStorage.getItem('wanportal.jwt')).toBe('jwt-spec')
        expect(wrapper.find('.account-btn').text()).toContain('ops-admin')
        expect(router.currentRoute.value.path).toBe('/')
    })
})