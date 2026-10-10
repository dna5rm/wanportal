/*
 * SearchView is a listing page: one GET each to /cgi-bin/api/monitors?q=
 * and /cgi-bin/api/services?q= per submitted term, monitor rows then
 * service hits out, loss and state chips colored, inactive rows dimmed,
 * and every row linking into the app's own detail routes. The fetch is
 * stubbed per URL, same as the dashboard spec does, and the router is
 * a memory twin of the production table so <router-link> resolves for
 * real.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import SearchView from '../components/SearchView.vue'
import { makeFetchStub } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* One hit that is live and one that is effectively inactive, so the
 * stats line, the dim class and both loss colors all get exercised. */
const hits = {
    status: 'success',
    monitors: [
        { id: 3, description: 'branch vpn', agent_id: 1, agent_name: 'edge-a', target_id: 5, target_address: 'branch-gw.example', protocol: 'tcp', port: 443, dscp: 46, current_median: 55.1, current_loss: 0, is_active: 1, last_update: '2026-09-07 20:00:00' },
        { id: 9, description: null, agent_id: 2, agent_name: 'edge-b', target_id: 8, target_address: 'legacy-gw.example', protocol: 'icmp', current_median: 210, current_loss: 100, is_active: 0, last_update: null }
    ]
}

/* The service half of the same term: one live hit with a port
 * override, so the uri cell, the state chip and the service detail
 * link all get exercised. */
const svcHits = {
    status: 'success',
    services: [
        { id: 12, description: 'payments health', agent_id: 1, agent_name: 'edge-a', target_id: 5, target_address: 'branch-gw.example', scheme: 'https', port: 8443, uri_path: '/health', uri_query: '', last_state: 'UP', last_check: '2026-09-07 20:01:00', is_active: 1 }
    ]
}

async function searchFor(wrapper, term) {
    await wrapper.find('input[type=search]').setValue(term)
    await wrapper.find('form.search-form').trigger('submit')
    await flushPromises()
    await flushPromises()
}

/* The detail cells ride the SPA route names, so the spec mounts under
 * a memory twin of the production table and lets <router-link> build
 * real hrefs. */
async function mountSearch(stub) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/monitors/:id', name: 'monitor', component: { render: () => null }, props: true },
            { path: '/agents/:id', name: 'agent', component: { render: () => null }, props: true },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true },
            { path: '/services/:id', name: 'service', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()
    vi.stubGlobal('fetch', stub)
    return mount(SearchView, { global: { plugins: [router] } })
}

describe('SearchView', () => {
    it('does not call the api until a real term is entered', async () => {
        const stub = makeFetchStub()
        const wrapper = await mountSearch(stub)
        await flushPromises()

        expect(stub).not.toHaveBeenCalled()
        expect(wrapper.find('.search-hint').exists()).toBe(true)
        expect(wrapper.find('table').exists()).toBe(false)

        // An empty (or whitespace) submit is a no-op, matching what
        // the classic page does with a blank term.
        await searchFor(wrapper, '   ')
        expect(stub).not.toHaveBeenCalled()
    })

    it('lists hits with stats, protocol labels and loss colors', async () => {
        const stub = makeFetchStub({ down: hits, services: svcHits })
        const wrapper = await mountSearch(stub)

        await searchFor(wrapper, 'branch')

        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/monitors?q=branch')
        expect(stub.mock.calls.some((c) => c[0] === '/cgi-bin/api/services?q=branch')).toBe(true)
        expect(wrapper.find('table').exists()).toBe(true)
        expect(wrapper.text()).toContain('2 results')
        expect(wrapper.text()).toContain('1 effectively active')
        expect(wrapper.text()).toContain('1 effectively inactive')
        // The services count rides the same stats line — singular here.
        expect(wrapper.text()).toContain('1 service')
        expect(wrapper.text()).not.toContain('1 services')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(3)
        // Active row reads normally, inactive one is dimmed.
        expect(rows[0].classes()).not.toContain('dim')
        expect(rows[1].classes()).toContain('dim')
        expect(rows[0].text()).toContain('branch vpn')
        expect(rows[1].text()).toContain('9') // blank description falls back to id
        expect(rows[0].text()).toContain('TCP/443')
        expect(rows[1].text()).toContain('ICMP')
        expect(rows[0].text()).toContain('55.1 ms')
        expect(rows[0].find('.chip').classes()).toContain('chip-ok')
        expect(rows[1].find('.chip').classes()).toContain('chip-danger')
        // Rows keep their detail links, now riding the SPA routes.
        expect(rows[0].findAll('td a').map((a) => a.attributes('href'))).toEqual([
            '/monitors/3', '/agents/1', '/targets/5'
        ])

        // The service hit follows in its own table: same link rules,
        // the services listing's uri cell, state chip green.
        expect(rows[2].text()).toContain('payments health')
        expect(rows[2].text()).toContain('https://branch-gw.example:8443/health')
        expect(rows[2].find('.chip').classes()).toContain('chip-ok')
        expect(rows[2].findAll('td a').map((a) => a.attributes('href'))).toEqual([
            '/services/12', '/agents/1', '/targets/5'
        ])
    })

    it('names a failed services sweep without disturbing monitor hits', async () => {
        const wrapper = await mountSearch(makeFetchStub({
            down: hits,
            fail: { services: new Error('HTTP 500') }
        }))

        await searchFor(wrapper, 'branch')

        const note = wrapper.find('.err-note')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('service search failed')
        expect(note.text()).toContain('HTTP 500')
        // The monitor half of the sweep stands untouched; only the
        // services count drops out of the stats line.
        expect(wrapper.text()).toContain('2 results')
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)
        const stats = wrapper.find('p.muted')
        expect(stats.text()).not.toContain('service')
    })

    it('names the failure and hides the table when the search errors', async () => {
        const wrapper = await mountSearch(makeFetchStub({ fail: { down: new Error('HTTP 503') } }))

        await searchFor(wrapper, 'branch')

        const note = wrapper.find('.err-note')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('search failed')
        expect(note.text()).toContain('HTTP 503')
        expect(wrapper.find('table').exists()).toBe(false)
    })
})