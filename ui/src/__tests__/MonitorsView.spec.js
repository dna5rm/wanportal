/*
 * MonitorsView is the ported monitors.php listing: one GET to
 * /cgi-bin/api/monitors, rows sorted problems-first like the classic
 * DataTables default, status badges naming whichever side is disabled,
 * row edits opening the in-app edit form, the bar's New opening the
 * in-app create form. The fetch
 * is stubbed per URL like the other specs, and the router is a memory
 * twin of the production route table so <router-link> resolves for real.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import MonitorsView from '../components/MonitorsView.vue'
import { getSession } from '../session'
import { jsonReply } from './stubs'

/* The session module is mocked at its door, but api.js rides the real
 * authHeaders/clearToken/getToken exports, so the original stays loaded
 * and only the probe is replaced. */
vi.mock('../session', async (importOriginal) => ({
    ...await importOriginal(),
    getSession: vi.fn()
}))

enableAutoUnmount(afterEach)

beforeEach(() => {
    // Write doors are signed-in only, so the default probe says admin
    // signed-in; the gate describe below flips it per case.
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
})

/* The api hands ids back as uuids (char(36)), so the fixtures carry
 * uuid-shaped strings — the detail links and edit urls are built from
 * them. */
const M7 = 'aaaaaaaa-0000-4000-8000-000000000007'
const M9 = 'aaaaaaaa-0000-4000-8000-000000000009'
const M4 = 'aaaaaaaa-0000-4000-8000-000000000004'
const A1 = 'bbbbbbbb-0000-4000-8000-000000000001'
const A2 = 'bbbbbbbb-0000-4000-8000-000000000002'
const T5 = 'cccccccc-0000-4000-8000-000000000005'
const T8 = 'cccccccc-0000-4000-8000-000000000008'
const T9 = 'cccccccc-0000-4000-8000-000000000009'

/* One fully active monitor plus two that are not: one with its agent
 * switched off, one with only its target off (the monitor flag alone
 * is still 1, like the api really reports). The api order puts the
 * active row first on purpose — the view must float the problems back
 * to the top, ties keeping api order. */
function monitorsBody() {
    return {
        status: 'success',
        monitors: [
            { id: M7, description: 'branch vpn', agent_id: A1, agent_name: 'edge-a', agent_is_active: 1, target_id: T5, target_address: 'branch-gw.example', target_is_active: 1, protocol: 'tcp', port: 443, dscp: 46, is_active: 1, last_update: '2026-09-07 18:34:56' },
            { id: M9, description: null, agent_id: A2, agent_name: 'edge-b', agent_is_active: 0, target_id: T8, target_address: 'legacy-gw.example', target_is_active: 1, protocol: 'icmp', port: 0, dscp: null, is_active: 0, last_update: null },
            { id: M4, description: 'quiet probe', agent_id: A1, agent_name: 'edge-a', agent_is_active: 1, target_id: T9, target_address: 'hq-gw.example', target_is_active: 0, protocol: 'udp', port: 53, dscp: 0, is_active: 1, last_update: '2026-09-07 09:00:00' }
        ]
    }
}

/* Mount the listing under a memory router that mirrors the names the
 * production table uses. Options is read on every fetch, so a test can
 * flip the endpoint to failing between the first load and a refresh. */
async function mountListing(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/monitors', name: 'monitors', component: { render: () => null } },
            { path: '/monitors/new', name: 'monitor-new', component: { render: () => null } },
            { path: '/monitors/:id/edit', name: 'monitor-edit', component: { render: () => null }, props: true },
            { path: '/monitors/:id', name: 'monitor', component: { render: () => null }, props: true },
            { path: '/agents/:id', name: 'agent', component: { render: () => null }, props: true },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/monitors') {
            if (options.fail) throw options.fail
            return jsonReply(options.body || monitorsBody())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(MonitorsView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub }
}

describe('MonitorsView', () => {
    it('fetches the listing once and renders the rows problems-first', async () => {
        const { wrapper, stub } = await mountListing()

        expect(stub).toHaveBeenCalledTimes(1)
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/monitors')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(3)

        // Inactive rows lead and keep api order among themselves; the
        // healthy monitor drops to the bottom.
        expect(rows[0].find('td a').attributes('href')).toBe('/monitors/' + M9)
        expect(rows[1].find('td a').attributes('href')).toBe('/monitors/' + M4)
        expect(rows[2].find('td a').attributes('href')).toBe('/monitors/' + M7)
        expect(rows[2].text()).toContain('branch vpn')

        // The badges name whichever side is off, in the php wording.
        expect(rows[0].text()).toContain('Inactive (Agent)')
        expect(rows[1].text()).toContain('Inactive (Target)')
        expect(rows[2].text()).toContain('Active')
        expect(rows[0].classes()).toContain('row-inactive')
        expect(rows[1].classes()).toContain('row-inactive')
        expect(rows[2].classes()).not.toContain('row-inactive')
        expect(rows[0].find('.chip').classes()).toContain('chip-warn')
        expect(rows[2].find('.chip').classes()).toContain('chip-ok')

        // A disabled side dims its link and says so next to the name.
        expect(rows[0].text()).toContain('edge-b (disabled)')
        expect(rows[0].find('td:nth-child(2) a').classes()).toContain('muted')
        expect(rows[1].text()).toContain('hq-gw.example (disabled)')
        expect(rows[1].find('td:nth-child(3) a').classes()).toContain('muted')
        expect(rows[2].find('td:nth-child(2) a').classes()).not.toContain('muted')

        // Ports and protocols read like the classic table: udp keeps
        // its port, icmp port 0 falls back to a dash.
        expect(rows[0].findAll('td')[3].text()).toBe('icmp')
        expect(rows[0].findAll('td')[4].text()).toBe('-')
        expect(rows[1].findAll('td')[3].text()).toBe('udp')
        expect(rows[1].findAll('td')[4].text()).toBe('53')
        expect(rows[2].findAll('td')[3].text()).toBe('tcp')
        expect(rows[2].findAll('td')[4].text()).toBe('443')

        // Stamps render minute-resolution with the full value in the
        // tooltip; a monitor that never reported reads as Never.
        expect(rows[2].findAll('td')[7].text()).toBe('2026-09-07 18:34')
        expect(rows[2].findAll('td')[7].attributes('title')).toBe('2026-09-07 18:34:56')
        expect(rows[0].findAll('td')[7].text()).toBe('Never')

        // Row edits open the in-app edit form; the bar's New opens the
        // in-app create form.
        expect(rows[2].find('td:last-child a').attributes('href')).toBe('/monitors/' + M7 + '/edit')
        expect(rows[0].find('td:last-child a').attributes('href')).toBe('/monitors/' + M9 + '/edit')
        expect(wrapper.find('.bar-right a.btn').attributes('href')).toBe('/monitors/new')

        // A finished load stamps the bar with the refresh clock.
        expect(wrapper.find('.bar-right .muted').text()).toContain('updated')
    })

    it('says no monitors when the api comes back empty', async () => {
        const { wrapper } = await mountListing({ body: { status: 'success', monitors: [] } })

        expect(wrapper.find('table').exists()).toBe(true)
        expect(wrapper.text()).toContain('no monitors')
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    })

    it('raises the loud banner when the first load fails', async () => {
        const { wrapper } = await mountListing({ fail: new Error('HTTP 503') })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('api unreachable')
        expect(banner.text()).toContain('HTTP 503')
        expect(wrapper.text()).toContain('no monitors')
    })

    it('keeps the last good rows and says so when a refresh fails', async () => {
        const options = {}
        const { wrapper } = await mountListing(options)
        expect(wrapper.findAll('tbody tr')).toHaveLength(3)

        options.fail = new Error('HTTP 503')
        await wrapper.find('button.btn').trigger('click')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('refresh failed')
        expect(banner.text()).toContain('showing older data')
        // The older data stays on the page — no zeroing out.
        expect(wrapper.findAll('tbody tr')).toHaveLength(3)
        expect(wrapper.text()).toContain('branch vpn')
    })
})

describe('MonitorsView write doors', () => {
    it('hides New Monitor and the per-row edit while signed out', async () => {
        getSession.mockResolvedValue({ authenticated: false, reason: 'signed-out' })
        const { wrapper } = await mountListing()

        // Reads stay public — the rows render, but no write door does.
        expect(wrapper.findAll('tbody tr')).toHaveLength(3)
        expect(wrapper.find('tbody tr td:last-child a').exists()).toBe(false)
        expect(wrapper.find('.bar-right a[href="/monitors/new"]').exists()).toBe(false)
    })

    it('shows New Monitor and the per-row edit once signed in', async () => {
        const { wrapper } = await mountListing()

        expect(wrapper.find('.bar-right a[href="/monitors/new"]').exists()).toBe(true)
        expect(wrapper.find('tbody tr td:last-child a').exists()).toBe(true)
    })
})