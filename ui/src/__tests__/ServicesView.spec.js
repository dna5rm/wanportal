/*
 * ServicesView is the SPA-only services listing (spec §8.4): one GET
 * to /cgi-bin/api/services, rows in api order (no classic DataTables
 * default to mirror), the state cell carrying the rolled-up check chip
 * beside the config-active badge, and the persistent client-side text
 * filter. This spec proves the view mounts and renders rows and the
 * empty state without throwing — a throw in module scope or setup
 * blanks the whole route.
 *
 * Same harness as MonitorsView.spec.js: the real api module rides the
 * stubbed global fetch, the session probe is mocked at its door.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ServicesView from '../components/ServicesView.vue'
import { getSession } from '../session'
import { jsonReply } from './stubs'

vi.mock('../session', async (importOriginal) => ({
    ...await importOriginal(),
    getSession: vi.fn()
}))

beforeEach(() => {
    // Write doors are signed-in only, so the default probe says admin
    // signed-in; the bar's New and edit links then render.
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
    // The listing filter rides localStorage across mounts; drop it so
    // a filter typed in one case cannot re-shape the next mount.
    localStorage.clear()
})

// Registered after the clear above on purpose (see MonitorsView.spec.js):
// afterEach hooks run in reverse order, so the auto-unmount — whose
// onBeforeUnmount flushes a pending filter write — must run BEFORE the
// storage clear, or the flushed write leaks into the next case.
enableAutoUnmount(afterEach)

/* Services are the d- siblings in the shared fake estate: monitors a-,
 * agents b-, targets c-, credentials e-. */
const S1 = 'dddddddd-0000-4000-8000-000000000001'
const S2 = 'dddddddd-0000-4000-8000-000000000002'
const A1 = 'bbbbbbbb-0000-4000-8000-000000000001'
const A2 = 'bbbbbbbb-0000-4000-8000-000000000002'
const T5 = 'cccccccc-0000-4000-8000-000000000005'
const T8 = 'cccccccc-0000-4000-8000-000000000008'

/* One fully active UP row and one the api reports with its target
 * switched off, no state yet, and an agent that never announced
 * services support — the two shapes the state cell must tell apart.
 * Api order on purpose: this listing keeps it. */
function servicesBody() {
    return {
        status: 'success',
        services: [
            {
                id: S1, description: 'llm backend probe',
                agent_id: A1, agent_name: 'edge-a', agent_is_active: 1,
                target_id: T5, target_address: 'branch-gw.example', target_is_active: 1,
                scheme: 'https', port: 8443, uri_path: '/v1/chat', uri_query: 'model=a',
                last_state: 'UP', last_reason: 'ok', agent_supports_services: 1,
                is_active: 1, last_check: '2026-10-09 12:00:00', last_change: '2026-10-09 10:30:00'
            },
            {
                id: S2, description: null,
                agent_id: A2, agent_name: 'edge-b', agent_is_active: 1,
                target_id: T8, target_address: 'legacy-gw.example', target_is_active: 0,
                scheme: 'http', port: 0, uri_path: '/status', uri_query: '',
                last_state: null, last_reason: null, agent_supports_services: 0,
                is_active: 1, last_check: null, last_change: null
            }
        ]
    }
}

/* Mount the listing under a memory router that mirrors the names the
 * production route table uses, so <router-link> resolves for real. */
async function mountListing(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/services', name: 'services', component: { render: () => null } },
            { path: '/services/new', name: 'service-new', component: { render: () => null } },
            { path: '/services/:id/edit', name: 'service-edit', component: { render: () => null }, props: true },
            { path: '/services/:id', name: 'service', component: { render: () => null }, props: true },
            { path: '/agents/:id', name: 'agent', component: { render: () => null }, props: true },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/services') {
            if (options.fail) throw options.fail
            return jsonReply(options.body || servicesBody())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(ServicesView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub }
}

describe('ServicesView', () => {
    it('mounts, fetches once, and renders the rows without throwing', async () => {
        const { wrapper, stub } = await mountListing()

        expect(stub).toHaveBeenCalledTimes(1)
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/services')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)

        // Row 1: description link, the fully built uri (cell text and
        // tooltip agree), the UP check chip and the plain active
        // badge, minute-resolution stamps.
        expect(rows[0].text()).toContain('llm backend probe')
        const uri1 = 'https://branch-gw.example:8443/v1/chat?model=a'
        const uriCell = rows[0].find('.uri-cell')
        expect(uriCell.text()).toBe(uri1)
        expect(uriCell.attributes('title')).toBe(uri1)
        expect(rows[0].find('a[href="/services/' + S1 + '"]').exists()).toBe(true)
        expect(rows[0].text()).toContain('UP')
        expect(rows[0].text()).toContain('Active')
        expect(rows[0].text()).toContain('2026-10-09 12:00')
        expect(rows[0].find('.chip-ok').exists()).toBe(true)
        expect(rows[0].classes()).not.toContain('row-inactive')

        // Row 2: null description falls back to the id, port 0 prints
        // '-' not '0', target-off names the side, a missing state
        // renders UNKNOWN amber (never healthy), its title explains
        // the agent_unsupported case, and the row dims.
        expect(rows[1].text()).toContain(S2)
        expect(rows[1].text()).toContain('http://legacy-gw.example/status')
        expect(rows[1].text()).toContain('UNKNOWN')
        expect(rows[1].text()).toContain('Inactive (Target)')
        expect(rows[1].text()).toContain('Never')
        const cells = rows[1].findAll('td')
        expect(cells[4].text()).toBe('-')
        expect(rows[1].classes()).toContain('row-inactive')
        const stateChip = rows[1].find('.state-cell .chip')
        expect(stateChip.classes()).toContain('chip-warn')
        expect(stateChip.attributes('title')).toBe(
            'agent_unsupported — this agent has not announced services support')

        // Chrome: the persistent filter box is on the page and a
        // healthy api never draws a banner.
        expect(wrapper.find('input[aria-label="Filter services"]').exists()).toBe(true)
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('renders a sensible empty state when the api lists nothing', async () => {
        const { wrapper, stub } = await mountListing({
            body: { status: 'success', services: [] }
        })

        expect(stub).toHaveBeenCalledTimes(1)
        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(1)
        expect(rows[0].find('td.muted').exists()).toBe(true)
        expect(rows[0].text()).toBe('no services')
        // A banner would be a lie here: the api answered fine, it
        // simply has nothing to list.
        expect(wrapper.find('.banner').exists()).toBe(false)
    })
})