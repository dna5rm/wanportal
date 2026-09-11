/*
 * App shell specs: the bar shows the public pages on the left and the
 * account cluster on the right — search is not one of them, it lives
 * on the dashboard itself. The gated listing pages never sit in
 * the bar itself — they live in the account dropdown, so a signed-out
 * visitor sees none of them anywhere in the chrome, and the classic
 * /classic door exists exactly once, inside that dropdown, only while
 * signed in. The API swagger is not a left public page: App
 * renders it in the right cluster — inside .nav-end, immediately
 * before the theme toggle and the account chip — for everyone, once
 * in the whole chrome, never a chip link and never a dropdown item;
 * Runtime is
 * a tool door that lives in the account dropdown only, so a
 * signed-out bar has no Runtime link at all. The probe is
 * answered by one fetch stub keyed by url — same as the other specs —
 * so the real session.js decides what the answer means. The operator
 * config is answered by the same stub: the real siteConfig.js parses
 * whatever the stub returns, so the brand and menu specs exercise the
 * exact load path the browser sees (options.configBody for the body,
 * options.configFail to kill the transport).
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
    document.documentElement.removeAttribute('data-theme')
})

/* The public left nav — search is gone from the chrome entirely: it
 * lives on the dashboard. The API swagger is not here: it sits in the
 * right cluster, ahead of the account chip. */
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
            '/guides', '/login'
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
        if (url === '/config.json') {
            if (options.configFail) throw options.configFail
            return jsonReply(options.configBody || { logo: '', menu: [] })
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

        // The swagger door moved to the right cluster: it sits inside
        // .nav-end ahead of the account chip — not a left public item
        // (the left links are direct children of the nav) and not a
        // chip utility link.
        expect(wrapper.findAll('nav.topnav > a[href="/api"]')).toHaveLength(0)
        expect(wrapper.findAll('.nav-end a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/api"]')[0].classes()).toContain('nav-link')
        expect(wrapper.findAll('.nav-utility')).toHaveLength(0)

        // ...and it is the first thing in the cluster, so the order
        // reads [API] [theme] [Log in]. The theme toggle shares the
        // cluster chrome and the classic console's localStorage key;
        // with the dark default active it offers light.
        const navEnd = [...wrapper.find('.nav-end').element.children]
        const apiAt = navEnd.findIndex((el) => el.getAttribute('href') === '/api')
        const chipAt = navEnd.findIndex((el) => el.classList.contains('session-chip'))
        expect(apiAt).toBe(0)
        expect(chipAt).toBeGreaterThan(apiAt)
        const themeAt = navEnd.findIndex((el) => el.classList.contains('theme-toggle'))
        expect(themeAt).toBeGreaterThan(apiAt)
        expect(themeAt).toBeLessThan(chipAt)
        const themeBtn = wrapper.find('.nav-end .theme-toggle')
        expect(themeBtn.classes()).toContain('btn')
        expect(themeBtn.text()).toContain('Light')

        // Runtime is not bar furniture while signed out — it stays
        // under the account menu, so it appears only after login.
        expect(wrapper.findAll('a[href="/runtime"]')).toHaveLength(0)
        expect(wrapper.text()).not.toContain('Runtime')
    })
})

describe('App chrome for a signed-in admin', () => {
    it('keeps the gated pages behind the account dropdown', async () => {
        const { wrapper } = await mountApp({ sessionBody: adminSession() })

        // Even signed in, the left nav stays public — dashboard and
        // latency, the swagger door now coming from the right cluster
        // — and never the gated pages.
        expect(wrapper.find('nav.topnav').text()).toContain('Dashboard')
        expect(wrapper.find('nav.topnav').text()).toContain('Latency')
        expect(wrapper.find('nav.topnav').text()).toContain('API')
        expect(wrapper.find('nav.topnav').text()).not.toContain('Monitors')

        // ...and the account button is one plain username label — the
        // admin claim lives in its tooltip, not in a nested chip.
        const btn = wrapper.find('.account-btn')
        expect(btn.text()).toContain('ops-admin')
        expect(btn.find('.chip').exists()).toBe(false)
        expect(btn.attributes('title')).toBe('signed in, admin')
        expect(wrapper.findAll('a[href="/login"]')).toHaveLength(0)

        // The gated pages appear once the dropdown is opened, Runtime
        // among them — but not API: the right cluster owns that door
        // and the menu must not render it twice.
        await openAccountMenu(wrapper)
        for (const label of [...GATED, 'Users', 'Runtime']) {
            expect(wrapper.text()).toContain(label)
        }
        expect(wrapper.find('.account-menu').text()).not.toContain('API')

        // API renders once, in the right cluster ahead of the account
        // button — the menu adds none; Runtime renders once, in the
        // dropdown.
        expect(wrapper.findAll('a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/api"]')[0].classes()).toContain('nav-link')
        const navEnd = [...wrapper.find('.nav-end').element.children]
        const apiAt = navEnd.findIndex((el) => el.getAttribute('href') === '/api')
        const chipAt = navEnd.findIndex((el) => el.classList.contains('session-chip'))
        expect(apiAt).toBe(0)
        expect(chipAt).toBeGreaterThan(apiAt)
        expect(wrapper.findAll('a[href="/runtime"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/runtime"]')[0].classes()).toContain('menu-muted')

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

        // The tool door is not admin-gated — a plain operator reaches
        // the runtime page too; the swagger link stays in the right
        // cluster from App, one link in the whole chrome, and the
        // dropdown adds none.
        expect(wrapper.find('.account-menu').text()).not.toContain('API')
        expect(wrapper.findAll('.nav-end a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/api"]')).toHaveLength(1)
        expect(wrapper.findAll('a[href="/runtime"]')).toHaveLength(1)
    })
})

describe('App chrome while the probe is in flight', () => {
    it('keeps the bar to the public set until the probe answers', async () => {
        const { wrapper } = await mountApp({ hangSession: true })

        for (const label of PUBLIC) expect(wrapper.text()).toContain(label)
        for (const label of [...GATED, 'Users']) expect(wrapper.text()).not.toContain(label)

        // The right cluster still opens with the swagger door even
        // while the probe is unanswered.
        expect(wrapper.findAll('.nav-end a[href="/api"]')).toHaveLength(1)

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

describe('App brand follows the site config', () => {
    it('keeps the text brand when the config carries no logo', async () => {
        const { wrapper } = await mountApp({
            configBody: { logo: '', menu: [] }
        })

        const brand = wrapper.find('span.brand')
        expect(brand.exists()).toBe(true)
        expect(brand.text()).toBe('wanportal')
        expect(wrapper.find('.brand-logo').exists()).toBe(false)
    })

    it('swaps the text brand for the operator logo image when one is set', async () => {
        const { wrapper } = await mountApp({
            configBody: { logo: '/brand/wanportal.png', menu: [] }
        })

        const img = wrapper.find('.brand-logo')
        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('/brand/wanportal.png')
        expect(img.attributes('alt')).toBe('wanportal')
        // The text brand is replaced, not doubled up next to it.
        expect(wrapper.find('span.brand').exists()).toBe(false)
        expect(wrapper.find('nav.topnav').text()).not.toContain('wanportal')
    })

    it('falls back to the text brand when the config cannot be loaded', async () => {
        const { wrapper } = await mountApp({ configFail: new Error('config down') })

        // loadSiteConfig never throws: a dead config file leaves the
        // built-in chrome standing.
        expect(wrapper.find('span.brand').text()).toBe('wanportal')
        expect(wrapper.find('.brand-logo').exists()).toBe(false)
        expect(wrapper.findAll('.nav-drop')).toHaveLength(0)
    })
})

describe('App renders the site-config menu after the public pages', () => {
    it('places custom entries between Latency and the right cluster', async () => {
        const { wrapper } = await mountApp({
            configBody: {
                logo: '',
                menu: [
                    { label: 'Guides', to: '/guides' },
                    { label: 'Status page', href: 'https://status.example.net' }
                ]
            }
        })

        // The custom labels render in the bar...
        expect(wrapper.find('nav.topnav').text()).toContain('Guides')
        expect(wrapper.find('nav.topnav').text()).toContain('Status page')

        // ...after the built-in public pages and before the right
        // cluster: [brand] [Dashboard] [Latency] [Guides] [Status
        // page] [.nav-end].
        const nav = [...wrapper.find('nav.topnav').element.children]
        const latencyAt = nav.findIndex((el) => el.getAttribute('href') === '/latency')
        const guidesAt = nav.findIndex((el) => el.getAttribute('href') === '/guides')
        const statusAt = nav.findIndex((el) => el.getAttribute('href') === 'https://status.example.net')
        const endAt = nav.findIndex((el) => el.classList.contains('nav-end'))
        expect(latencyAt).toBeGreaterThan(-1)
        expect(guidesAt).toBeGreaterThan(latencyAt)
        expect(statusAt).toBeGreaterThan(guidesAt)
        expect(endAt).toBeGreaterThan(statusAt)

        // The `href` entry is an external door — new tab, no opener —
        // and the `to` entry stays an in-app router-link.
        const status = nav.find((el) => el.getAttribute('href') === 'https://status.example.net')
        expect(status.getAttribute('target')).toBe('_blank')
        expect(status.getAttribute('rel')).toBe('noopener')
        expect(wrapper.findAll('.nav-end a[href="https://status.example.net"]')).toHaveLength(0)
    })

    it('opens the config dropdown under the parent label, keeping the cluster untouched', async () => {
        const { wrapper } = await mountApp({
            configBody: {
                menu: [
                    {
                        label: 'Docs',
                        children: [
                            { label: 'Guide', to: '/guides' },
                            { label: 'Schema', href: 'https://schema.example.net' }
                        ]
                    }
                ]
            }
        })

        // Children stay hidden until the parent is opened.
        expect(wrapper.text()).not.toContain('Guide')
        expect(wrapper.text()).not.toContain('Schema')
        await wrapper.find('.nav-drop-label').trigger('click')
        expect(wrapper.text()).toContain('Guide')
        expect(wrapper.text()).toContain('Schema')
        expect(wrapper.find('.nav-drop-menu a[href="/guides"]').exists()).toBe(true)
        const schema = wrapper.find('.nav-drop-menu a[href="https://schema.example.net"]')
        expect(schema.attributes('target')).toBe('_blank')
        expect(schema.attributes('rel')).toBe('noopener')

        // The right cluster is exactly the built-in set: the custom
        // menu never lands inside it.
        const navEnd = [...wrapper.find('.nav-end').element.children]
        expect(navEnd[0].getAttribute('href')).toBe('/api')
        expect(wrapper.findAll('.nav-end a[href="/guides"]')).toHaveLength(0)
        expect(wrapper.findAll('.nav-end .nav-drop')).toHaveLength(0)
    })

    it('renders no extra nav when the config menu is empty', async () => {
        const { wrapper } = await mountApp({ configBody: { logo: '', menu: [] } })

        // The built-in chrome only: the default config adds nothing.
        expect(wrapper.findAll('.nav-drop')).toHaveLength(0)
        expect(wrapper.findAll('nav.topnav > a')).toHaveLength(2)
    })
})