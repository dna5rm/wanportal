/*
 * Session chip specs: the chip renders the right-hand account cluster
 * from the probe result App hands it — no fetch of its own. Signed-out
 * shows one log-in door with the two utility links — API and
 * Runtime — as compact text beside it; signed-in shows the
 * username button — one plain label, no nested chip, admin and expiry
 * claimed in the tooltip — whose dropdown carries the gated pages
 * (Users only for admins), the API and Runtime doors above
 * the muted classic console link, and log out. The menu
 * closes on route changes and on clicks outside the cluster, and log
 * out forgets the tab's token and asks App to re-probe via the change
 * event.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import SessionChip from '../components/SessionChip.vue'

enableAutoUnmount(afterEach)

afterEach(() => {
    sessionStorage.clear()
    localStorage.clear()
})

const GATED = ['Agents', 'Targets', 'Monitors', 'Credentials']

const signedOut = { authenticated: false, reason: 'signed-out' }
const unavailable = { authenticated: false, reason: 'unavailable', error: 'HTTP 503' }
const adminClaims = { authenticated: true, reason: 'ok', username: 'ops-admin', isAdmin: true, exp: null }
const userClaims = { authenticated: true, reason: 'ok', username: 'read-only', isAdmin: false, exp: null }

/*
 * Mount the cluster with the probe result as a plain prop, on a router
 * that registers every dropdown target so the router-links resolve.
 */
async function mountChip(session) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: ['/agents', '/targets', '/monitors', '/credentials', '/users',
            '/api', '/runtime', '/login']
            .map((path) => ({ path, component: { render: () => null } }))
    })
    await router.push('/')
    await router.isReady()
    const wrapper = mount(SessionChip, {
        props: { session },
        global: { plugins: [router] }
    })
    return { wrapper, router }
}

describe('SessionChip while signed out', () => {
    it('renders a single log-in door and nothing account-ish', async () => {
        const { wrapper } = await mountChip(signedOut)

        expect(wrapper.findAll('a[href="/login"]')).toHaveLength(1)
        expect(wrapper.text()).toContain('Log in')
        expect(wrapper.find('.account-btn').exists()).toBe(false)
        expect(wrapper.findAll('a[href="/classic"]')).toHaveLength(0)
        expect(wrapper.findAll('a[href="/login.php"]')).toHaveLength(0)
    })

    it('keeps the swagger and runtime doors reachable beside log in', async () => {
        const { wrapper } = await mountChip(signedOut)

        // The utility doors sit next to the log-in door as compact
        // in-app links — both render inside the SPA now, no new tab
        // and no /classic/server.php hop.
        const docs = wrapper.findAll('a[href="/api"]')
        expect(docs).toHaveLength(1)
        expect(docs[0].text()).toBe('API')
        expect(docs[0].classes()).toContain('nav-utility')

        const runtime = wrapper.findAll('a[href="/runtime"]')
        expect(runtime).toHaveLength(1)
        expect(runtime[0].text()).toBe('Runtime')
        expect(runtime[0].classes()).toContain('nav-utility')
        expect(runtime[0].attributes('target')).toBeUndefined()
    })
})

describe('SessionChip while the API is unreachable', () => {
    it('shows session? instead of pretending the visitor is signed out', async () => {
        const { wrapper } = await mountChip(unavailable)

        expect(wrapper.text()).toContain('session?')
        expect(wrapper.find('.session-chip span').attributes('title')).toBe('HTTP 503')
        expect(wrapper.find('a[href="/login"]').exists()).toBe(false)
    })
})

describe('SessionChip while the probe is in flight', () => {
    it('renders the pending hint, not a signed-out claim', async () => {
        const { wrapper } = await mountChip(null)

        expect(wrapper.text()).toContain('session')
        expect(wrapper.find('a[href="/login"]').exists()).toBe(false)
    })
})

describe('SessionChip account button', () => {
    it('is one plain username label, admin claimed in the tooltip', async () => {
        const { wrapper } = await mountChip(adminClaims)

        const btn = wrapper.find('.account-btn')
        expect(btn.text()).toBe('ops-admin')
        expect(btn.find('.chip').exists()).toBe(false)
        expect(btn.attributes('title')).toBe('signed in, admin')
    })

    it('puts the token expiry in the tooltip when the token carries one', async () => {
        const { wrapper } = await mountChip({ ...adminClaims, exp: 1234567890 })

        expect(wrapper.find('.account-btn').attributes('title')).toContain('token expires')
    })
})

describe('SessionChip for a signed-in admin', () => {
    it('opens the gated pages in the account dropdown', async () => {
        const { wrapper } = await mountChip(adminClaims)

        // Closed until the button is clicked.
        expect(wrapper.find('.account-menu').exists()).toBe(false)
        expect(wrapper.findAll('a[href="/classic"]')).toHaveLength(0)

        await wrapper.find('.account-btn').trigger('click')
        const menu = wrapper.find('.account-menu')
        for (const label of GATED) expect(menu.text()).toContain(label)
        expect(menu.text()).toContain('Users')
        expect(wrapper.findAll('a[href="/login"]')).toHaveLength(0)

        // The classic console door lives here and only here, muted.
        const classic = wrapper.findAll('a[href="/classic"]')
        expect(classic).toHaveLength(1)
        expect(classic[0].classes()).toContain('menu-muted')

        // The two in-app doors sit above it, muted too.
        const docs = menu.findAll('a[href="/api"]')
        expect(docs).toHaveLength(1)
        expect(docs[0].classes()).toContain('menu-muted')

        expect(menu.findAll('a[href="/runtime"]')).toHaveLength(1)
        const labels = menu.findAll('a').map((a) => a.text())
        expect(labels.indexOf('API')).toBeGreaterThan(-1)
        expect(labels.indexOf('Runtime')).toBeGreaterThan(labels.indexOf('API'))
        expect(labels.indexOf('Classic console')).toBeGreaterThan(labels.indexOf('Runtime'))

        // A second click folds it back up.
        await wrapper.find('.account-btn').trigger('click')
        expect(wrapper.find('.account-menu').exists()).toBe(false)
    })
})

describe('SessionChip for a signed-in non-admin', () => {
    it('keeps Users out of the dropdown', async () => {
        const { wrapper } = await mountChip(userClaims)

        expect(wrapper.find('.account-btn').text()).toBe('read-only')
        expect(wrapper.find('.account-btn .chip').exists()).toBe(false)
        expect(wrapper.find('.account-btn').attributes('title')).toBe('signed in')

        await wrapper.find('.account-btn').trigger('click')
        const menu = wrapper.find('.account-menu')
        for (const label of GATED) expect(menu.text()).toContain(label)
        expect(menu.text()).not.toContain('Users')

        // The info doors are not admin-gated — a plain operator
        // reaches swagger and the runtime page too.
        expect(menu.findAll('a[href="/api"]')).toHaveLength(1)
        expect(menu.findAll('a[href="/runtime"]')).toHaveLength(1)
    })
})

describe('SessionChip dropdown closing', () => {
    it('closes when the route changes', async () => {
        const { wrapper, router } = await mountChip(adminClaims)

        await wrapper.find('.account-btn').trigger('click')
        expect(wrapper.find('.account-menu').exists()).toBe(true)

        await router.push('/agents')
        await flushPromises()

        expect(wrapper.find('.account-menu').exists()).toBe(false)
    })

    it('closes on a click outside the cluster but not one inside it', async () => {
        const { wrapper } = await mountChip(adminClaims)

        await wrapper.find('.account-btn').trigger('click')
        expect(wrapper.find('.account-menu').exists()).toBe(true)

        // A click that lands on the cluster itself (but not on the
        // toggle) leaves the menu open.
        const chipRoot = wrapper.find('.session-chip').element
        chipRoot.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('.account-menu').exists()).toBe(true)

        // A click on the page outside the cluster folds it up.
        document.body.click()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.account-menu').exists()).toBe(false)
    })
})

describe('SessionChip log out', () => {
    it('forgets the tab token and asks App to re-probe', async () => {
        sessionStorage.setItem('wanportal.jwt', 'jwt-token')
        const { wrapper } = await mountChip(adminClaims)

        await wrapper.find('.account-btn').trigger('click')
        const logoutBtn = wrapper.findAll('button').find((b) => b.text() === 'Log out')
        expect(logoutBtn).toBeTruthy()
        await logoutBtn.trigger('click')
        await flushPromises()

        expect(sessionStorage.getItem('wanportal.jwt')).toBe(null)
        expect(wrapper.emitted('change')).toHaveLength(1)
    })
})