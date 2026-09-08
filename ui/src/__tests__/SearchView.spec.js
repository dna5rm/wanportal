/*
 * SearchView is a listing page: one GET to /cgi-bin/api/monitors?q=,
 * rows out, loss chips colored, inactive rows dimmed, and every row
 * linking into the app's own detail routes. The fetch is stubbed per
 * URL, same as the dashboard spec does, and the router is a memory
 * twin of the production table so <router-link> resolves for real.
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
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true }
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
        const stub = makeFetchStub({ down: hits })
        const wrapper = await mountSearch(stub)

        await searchFor(wrapper, 'branch')

        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/monitors?q=branch')
        expect(wrapper.find('table').exists()).toBe(true)
        expect(wrapper.text()).toContain('2 results')
        expect(wrapper.text()).toContain('1 effectively active')
        expect(wrapper.text()).toContain('1 effectively inactive')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)
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