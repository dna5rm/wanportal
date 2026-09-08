/*
 * AgentsView is the ported agents.php listing: one GET to
 * /cgi-bin/api/agents, inactive rows floated to the top like the
 * classic table's default order, edit links staying on the classic
 * console. Same stubbed-fetch shape as the monitors spec, with a
 * memory router so the per-row <router-link> resolves for real.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AgentsView from '../components/AgentsView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* Agent ids are uuids, same as every other detail id in the api. */
const A1 = 'bbbbbbbb-0000-4000-8000-000000000001'
const A2 = 'bbbbbbbb-0000-4000-8000-000000000002'

/* One active, one retired agent. Api order puts the active one first
 * so the sort has something to flip. */
function agentsBody() {
    return {
        status: 'success',
        agents: [
            { id: A1, name: 'core', address: 'core.example', description: 'primary site', is_active: 1, last_seen: '2026-09-07 18:30:00' },
            { id: A2, name: 'sleepy', address: 'sleepy.example', description: 'retired probe', is_active: 0, last_seen: null }
        ]
    }
}

/* Options is read on every fetch so a test can flip the endpoint to
 * failing between the first load and a refresh. */
async function mountListing(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/agents', name: 'agents', component: { render: () => null } },
            { path: '/agents/new', name: 'agent-new', component: { render: () => null } },
            { path: '/agents/:id', name: 'agent', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/agents') {
            if (options.fail) throw options.fail
            return jsonReply(options.body || agentsBody())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(AgentsView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub }
}

describe('AgentsView', () => {
    it('fetches the listing once and renders both agents problems-first', async () => {
        const { wrapper, stub } = await mountListing()

        expect(stub).toHaveBeenCalledTimes(1)
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/agents')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)

        // The retired agent leads; its title link opens the Vue detail
        // route for that agent.
        expect(rows[0].find('td a').attributes('href')).toBe('/agents/' + A2)
        expect(rows[0].find('td a').text()).toBe('sleepy')
        expect(rows[1].find('td a').attributes('href')).toBe('/agents/' + A1)
        expect(rows[1].find('td a').text()).toBe('core')

        // Address and description read straight from the payload.
        expect(rows[1].findAll('td')[1].text()).toBe('core.example')
        expect(rows[1].findAll('td')[2].text()).toBe('primary site')

        // Status chips and row dimming follow the classic wording.
        expect(rows[0].classes()).toContain('row-inactive')
        expect(rows[1].classes()).not.toContain('row-inactive')
        expect(rows[0].find('.chip').classes()).toContain('chip-warn')
        expect(rows[0].find('.chip').text()).toBe('Inactive')
        expect(rows[1].find('.chip').classes()).toContain('chip-ok')
        expect(rows[1].find('.chip').text()).toBe('Active')

        // Last seen renders minute-resolution with the full stamp in
        // the tooltip; an agent that never checked in reads as Never.
        expect(rows[1].findAll('td')[4].text()).toBe('2026-09-07 18:30')
        expect(rows[1].findAll('td')[4].attributes('title')).toBe('2026-09-07 18:30:00')
        expect(rows[0].findAll('td')[4].text()).toBe('Never')

        // Editing stays on the classic console, per row and in the bar.
        expect(rows[0].find('td:last-child a').attributes('href')).toBe('/agents_edit.php?id=' + A2)
        expect(rows[1].find('td:last-child a').attributes('href')).toBe('/agents_edit.php?id=' + A1)
        expect(wrapper.find('.bar-right a.btn').attributes('href')).toBe('/agents/new')

        // A finished load stamps the bar with the refresh clock.
        expect(wrapper.find('.bar-right .muted').text()).toContain('updated')
    })

    it('says no agents when the api comes back empty', async () => {
        const { wrapper } = await mountListing({ body: { status: 'success', agents: [] } })

        expect(wrapper.find('table').exists()).toBe(true)
        expect(wrapper.text()).toContain('no agents')
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    })

    it('raises the loud banner when the first load fails', async () => {
        const { wrapper } = await mountListing({ fail: new Error('HTTP 503') })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('api unreachable')
        expect(banner.text()).toContain('HTTP 503')
        expect(wrapper.text()).toContain('no agents')
    })

    it('keeps the last good rows and says so when a refresh fails', async () => {
        const options = {}
        const { wrapper } = await mountListing(options)
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)

        options.fail = new Error('HTTP 503')
        await wrapper.find('button.btn').trigger('click')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('refresh failed')
        // The older rows stay visible — no blanking on a failed refresh.
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)
        expect(wrapper.text()).toContain('sleepy')
    })
})