/*
 * CredentialsView is the ported credentials.php listing behind the
 * login wall: the session probe runs first, a signed-out visitor is
 * walked to /login before the vault ever loads, and any signed-in
 * user may read it (the api gates writes, not reads). Type and site
 * narrow the loaded rows client-side; active/inactive is the one
 * filter the api answers, so it refetches with ?is_active=.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import CredentialsView from '../components/CredentialsView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* The d-prefix ids continue the sibling fixtures' lettering (monitors
 * a-, agents b-, targets c-): one active account entry and one retired
 * PSK with blank cells, so the dash and dimmed-row paths get
 * exercised. No fixture carries a password — the list api never sends
 * one, and the view must not pretend otherwise. */
const C1 = 'dddddddd-0000-4000-8000-000000000001'
const C2 = 'dddddddd-0000-4000-8000-000000000002'

function credentialsBody() {
    return {
        status: 'success',
        credentials: [
            { id: C1, site: 'hq', name: 'core router', type: 'ACCOUNT', username: 'admin', url: null, owner: 'netops', comment: 'core ssh login', expiry_date: null, is_active: 1, sensitivity: 'HIGH', updated_at: '2026-09-07 18:00:00', updated_by: 'ops-admin' },
            { id: C2, site: 'branch', name: 'branch ap', type: 'PSK', username: null, url: 'https://ap.example', owner: null, comment: null, expiry_date: '2027-01-01 00:00:00', is_active: 0, sensitivity: 'MEDIUM', updated_at: null, updated_by: null }
        ]
    }
}

/* Mount under a memory router mirroring the production credential
 * route names, so router-links resolve for real and the /login
 * redirect is assertable off currentRoute. Options is read on every
 * fetch, so a test can flip endpoints between loads. */
async function mountListing(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/credentials', name: 'credentials', component: { render: () => null } },
            { path: '/credentials/new', name: 'credential-new', component: { render: () => null } },
            { path: '/credentials/:id', name: 'credential', component: { render: () => null }, props: true },
            { path: '/credentials/:id/edit', name: 'credential-edit', component: { render: () => null }, props: true },
            { path: '/login', name: 'login', component: { render: () => null } }
        ]
    })
    await router.push('/credentials')
    await router.isReady()

    const credUrls = []
    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/session') {
            if (options.sessionFails) throw options.sessionFails
            return jsonReply(
                options.sessionBody || { status: 'success', username: 'ops', is_admin: 0, exp: null },
                true,
                options.sessionStatus ?? 200
            )
        }
        if (url.startsWith('/cgi-bin/api/credentials')) {
            credUrls.push(url)
            if (options.failCreds) throw options.failCreds
            return jsonReply(options.credsBody || credentialsBody())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(CredentialsView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, credUrls, router }
}

describe('CredentialsView for a signed-in user', () => {
    it('loads the active listing once and renders identity columns only', async () => {
        const { wrapper, stub, credUrls } = await mountListing()

        // Probe first, then the listing, active by default like the
        // classic page (is_active only takes 0 or 1).
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/session')
        expect(credUrls).toEqual(['/cgi-bin/api/credentials?is_active=1'])

        expect(wrapper.find('h2').text()).toContain('credentials')
        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)

        // The name opens the in-app detail route; comment rides the tooltip.
        expect(rows[0].find('a').attributes('href')).toBe('/credentials/' + C1)
        expect(rows[0].find('a').attributes('title')).toBe('core ssh login')
        expect(rows[0].text()).toContain('core router')
        expect(rows[0].find('.chip').text()).toBe('ACCOUNT')
        expect(rows[0].text()).toContain('2026-09-07 18:00')
        expect(rows[0].find('a[href="/credentials/' + C1 + '/edit"]').exists()).toBe(true)

        // Blanks read as dashes; the inactive row dims.
        expect(rows[1].findAll('td')[3].text()).toBe('-')
        expect(rows[1].find('td:nth-child(5)').text()).toBe('-')
        expect(rows[1].classes()).toContain('row-inactive')

        // No secret anywhere: the list carries no password column and
        // no inputs to reveal one.
        expect(wrapper.find('input[type=password]').exists()).toBe(false)
    })

    it('refetches server-side when inactive is picked', async () => {
        const { wrapper, credUrls } = await mountListing()

        // Two selects: type (client-side) then active (server-side).
        await wrapper.findAll('select')[1].setValue('0')
        await flushPromises()
        await flushPromises()
        expect(credUrls[1]).toBe('/cgi-bin/api/credentials?is_active=0')
    })

    it('narrows rows client-side by type and site without refetching', async () => {
        const { wrapper, credUrls } = await mountListing()

        await wrapper.find('select').setValue('PSK')
        await flushPromises()
        expect(credUrls).toHaveLength(1) // client filters never hit the api
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
        expect(wrapper.findAll('tbody tr')[0].text()).toContain('branch ap')
        expect(wrapper.find('h2').text()).toContain('(1)')

        // Site narrows within the type: hq matches nothing under PSK.
        await wrapper.find('input[type=search]').setValue('hq')
        await flushPromises()
        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(1)
        expect(rows[0].text()).toContain('no credentials match')

        // Clear the type and hq finds the router entry again.
        await wrapper.find('select').setValue('')
        await wrapper.find('input[type=search]').setValue('')
        await flushPromises()
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    })

    it('reports a dead api with a banner instead of fake rows', async () => {
        const { wrapper } = await mountListing({ failCreds: new Error('HTTP 500') })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('credentials fetch failed')
        expect(banner.text()).toContain('HTTP 500')
        expect(wrapper.find('.err-note').text()).toContain('listing not trustworthy')
    })
})

describe('CredentialsView behind the login wall', () => {
    it('walks a signed-out visitor to /login without loading the vault', async () => {
        const { wrapper, credUrls, router } = await mountListing({ sessionStatus: 401 })

        expect(credUrls).toEqual([])
        expect(router.currentRoute.value.name).toBe('login')
        expect(wrapper.find('table').exists()).toBe(false)
    })

    it('walks to /login when the listing answers 401 mid-flight', async () => {
        const { credUrls, router } = await mountListing({ failCreds: new Error('HTTP 401') })

        expect(credUrls).toHaveLength(1)
        expect(router.currentRoute.value.name).toBe('login')
    })

    it('keeps the gate up when the session probe dies', async () => {
        const { wrapper, credUrls } = await mountListing({ sessionFails: new Error('connection refused') })

        expect(credUrls).toEqual([])
        const gate = wrapper.find('.gate')
        expect(gate.text()).toContain('session check failed (connection refused)')
        expect(gate.text()).toContain('stays hidden rather than guessed')
        expect(wrapper.find('table').exists()).toBe(false)
    })
})