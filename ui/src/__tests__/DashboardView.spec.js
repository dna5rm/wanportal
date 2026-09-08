/*
 * DashboardView is mounted with the real getJson under a stubbed
 * fetch, per endpoint, and a memory twin of the production route
 * table so the row links resolve to real hrefs. What matters here:
 * the cards and tables render from the rollup, inactive agents are
 * dropped, names link into the SPA detail pages carrying the row's
 * uuids, and when one endpoint fails the page says so with a banner
 * and an error note — without wiping the numbers the healthy
 * endpoints still delivered.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import DashboardView from '../components/DashboardView.vue'
import { A1, downBody, localStamp, M1, M2, M7, makeFetchStub, T5, T9 } from './stubs'
import { fmtDownSince } from '../format'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* Mount with fetch stubbed and the router installed, then let the
 * fetches and Vue's reactive updates settle before the test starts
 * looking at the DOM. Options is read on every fetch, so a test can
 * flip endpoints to failing between two refreshes. */
async function mountDashboard(options) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/monitors', name: 'monitors', component: { render: () => null } },
            { path: '/agents', name: 'agents', component: { render: () => null } },
            { path: '/targets', name: 'targets', component: { render: () => null } },
            { path: '/monitors/:id', name: 'monitor', component: { render: () => null }, props: true },
            { path: '/agents/:id', name: 'agent', component: { render: () => null }, props: true },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    const stub = makeFetchStub(options)
    vi.stubGlobal('fetch', stub)
    const wrapper = mount(DashboardView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub }
}

describe('DashboardView with a healthy api', () => {
    it('hits all three endpoints with the urls the page ships', async () => {
        const { wrapper, stub } = await mountDashboard()

        const urls = stub.mock.calls.map((call) => call[0])
        expect(urls).toContain('/cgi-bin/api/dashboard')
        expect(urls).toContain('/cgi-bin/api/agents')
        expect(urls).toContain('/cgi-bin/api/monitors?current_loss=100&is_active=1')
        expect(stub).toHaveBeenCalledTimes(3)

        // No banner when everything came back.
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('renders the rollup cards with their percentages', async () => {
        const { wrapper } = await mountDashboard()

        const cards = wrapper.findAll('.card')
        expect(cards).toHaveLength(4)
        expect(cards[0].text()).toContain('total monitors')
        expect(cards[0].text()).toContain('10')
        expect(cards[1].text()).toContain('7')
        expect(cards[1].find('.card-sub').text()).toContain('70%')
        expect(cards[3].text()).toContain('1')
        expect(cards[3].find('.card-sub').text()).toContain('10%')
    })

    it('shows only active agents as chips', async () => {
        const { wrapper } = await mountDashboard()

        const chips = wrapper.findAll('.agents .chip')
        expect(chips).toHaveLength(1)
        expect(chips[0].text()).toBe('core')
        expect(chips[0].classes()).toContain('chip-ok')
        // The chip name opens the agent detail route.
        expect(chips[0].element.tagName).toBe('A')
        expect(chips[0].attributes('href')).toBe('/agents/' + A1)
        expect(wrapper.text()).not.toContain('sleepy')
    })

    it('fills the slow-links and down tables with the right colors', async () => {
        const base = Date.now()
        const { wrapper } = await mountDashboard({ down: downBody(base) })

        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(2)

        // Top-slow table: loss chip is ok at zero, danger at full loss,
        // and a blank description falls back to the monitor id.
        const slowRows = bodies[0].findAll('tr')
        expect(slowRows).toHaveLength(2)
        expect(slowRows[0].text()).toContain('hq uplink')
        expect(slowRows[0].text()).toContain('42.5 ms')
        expect(slowRows[0].text()).toContain('0.0%')
        expect(slowRows[0].find('.chip').classes()).toContain('chip-ok')
        expect(slowRows[1].find('.chip').classes()).toContain('chip-danger')
        expect(slowRows[1].text()).toContain('118.4 ms')
        expect(slowRows[1].text()).toContain('2')

        // Names open the detail pages and carry the row's uuids; the
        // description-less row links by its id instead.
        expect(slowRows[0].findAll('td')[1].find('a').attributes('href')).toBe('/monitors/' + M1)
        expect(slowRows[0].findAll('td')[2].find('a').attributes('href')).toBe('/agents/' + A1)
        expect(slowRows[0].findAll('td')[3].find('a').attributes('href')).toBe('/targets/' + T9)
        expect(slowRows[1].findAll('td')[1].text()).toBe(M2)

        // Down table: four hours down paints warn, six hours danger,
        // and the since/duration cells come from the formatters.
        const downRows = bodies[1].findAll('tr')
        expect(downRows).toHaveLength(2)
        expect(downRows[0].classes()).toContain('row-warn')
        expect(downRows[1].classes()).toContain('row-danger')
        expect(downRows[0].findAll('td')[3].text()).toBe(fmtDownSince(localStamp(4 * 3600000, base)))
        expect(downRows[0].findAll('td')[4].text()).toBe('4h 0m')
        expect(downRows[1].findAll('td')[4].text()).toBe('6h 0m')

        // The down rows link all three names the same way.
        expect(downRows[0].findAll('td')[0].find('a').attributes('href')).toBe('/monitors/' + M7)
        expect(downRows[0].findAll('td')[1].find('a').attributes('href')).toBe('/agents/' + A1)
        expect(downRows[0].findAll('td')[2].find('a').attributes('href')).toBe('/targets/' + T5)
    })

    it('keeps plain text when a row arrives without ids to link', async () => {
        const base = Date.now()
        const { wrapper } = await mountDashboard({
            dashboard: {
                status: 'success',
                dashboard: {
                    total: 1, up: 1, degraded: 0, down: 0,
                    percent_up: 100, percent_degraded: 0, percent_down: 0,
                    top_slow: [
                        { description: 'mystery link', agent_name: 'edge-x', target_address: 'x-gw.example', current_median: 9.5, current_loss: 0 }
                    ]
                }
            },
            agents: { status: 'success', agents: [{ name: 'ghost', is_active: 1 }] },
            down: {
                status: 'success',
                monitors: [
                    { description: 'ghost link', agent_name: 'edge-x', target_address: 'x-gw.example', last_down: localStamp(3600000, base) }
                ]
            }
        })

        // No id, no link: the chip and every name cell stay plain text.
        const chip = wrapper.find('.agents .chip')
        expect(chip.element.tagName).toBe('SPAN')
        expect(chip.attributes('href')).toBeUndefined()
        expect(chip.text()).toBe('ghost')

        const slowCells = wrapper.findAll('tbody')[0].findAll('tr')[0].findAll('td')
        expect(slowCells[1].find('a').exists()).toBe(false)
        expect(slowCells[1].text()).toBe('mystery link')
        expect(slowCells[2].text()).toBe('edge-x')
        expect(slowCells[3].text()).toBe('x-gw.example')

        const downCells = wrapper.findAll('tbody')[1].findAll('tr')[0].findAll('td')
        expect(downCells[0].find('a').exists()).toBe(false)
        expect(downCells[0].text()).toBe('ghost link')
        expect(downCells[1].text()).toBe('edge-x')
        expect(downCells[2].text()).toBe('x-gw.example')
    })
})

describe('DashboardView when the api misbehaves', () => {
    it('keeps good data and says so when one endpoint fails', async () => {
        const options = { fail: {} }
        const { wrapper } = await mountDashboard(options)
        expect(wrapper.findAll('.card')).toHaveLength(4)

        // Flip agents to failing, then hit "refresh now".
        options.fail.agents = new Error('HTTP 503')
        await wrapper.find('button.btn').trigger('click')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.exists()).toBe(true)
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('refresh failed')
        expect(wrapper.find('.agents .err-note').text()).toBe('agents fetch failed')

        // The healthy endpoints' numbers survive untouched — no zeroing.
        expect(wrapper.findAll('.card')[1].text()).toContain('7')
        expect(wrapper.findAll('tbody')[1].findAll('tr')).toHaveLength(2)
    })

    it('shows the loud unreachable banner when nothing answers', async () => {
        const { wrapper } = await mountDashboard({
            fail: { every: new Error('connection refused') }
        })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('api unreachable')
        expect(banner.text()).toContain('connection refused')

        // No rollup means no cards and an honest "no data" row.
        expect(wrapper.find('.cards').exists()).toBe(false)
        expect(wrapper.text()).toContain('no data')
        expect(wrapper.find('.agents .err-note').exists()).toBe(true)
        expect(wrapper.text()).toContain('down-list fetch failed')
    })
})