/*
 * AgentDetailView: the identity card, the monitor table, and the
 * agent's services table (spec §8.4). The three doors (agent record,
 * monitors by agent, services by agent) are fetched independently with
 * Promise.allSettled, so each can fail on its own. getJson is mocked
 * at the module door like ServiceDetailView.spec.js. The tests pin the
 * three urls, the services uri cell — the full uri as a new-tab link
 * to the live service, David's sanctioned exception to the no-new-tabs
 * rule — and the independent-failure contract. The details card pins
 * the self-declared capability rows (version, service checks) with the
 * "not reported" / "not supported" fallbacks for an agent that reports
 * neither.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { getJson } from '../api'
import { getSession } from '../session'
import AgentDetailView from '../components/AgentDetailView.vue'
import { localStamp } from './stubs'

vi.mock('../api', () => ({ getJson: vi.fn(), postJson: vi.fn(), delJson: vi.fn() }))
vi.mock('../session', () => ({ getSession: vi.fn() }))

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
    // The show-inactive toggle rides localStorage (prefs.js); drop it
    // so a toggle in one case cannot re-shape the next mount.
    localStorage.clear()
})

// Registered after the clear above on purpose (see ServicesView.spec.js):
// afterEach hooks run in reverse order, so the auto-unmount always runs
// before the storage clear and can never re-persist a leaked write.
enableAutoUnmount(afterEach)

beforeEach(() => {
    // The edit/edit-bar doors are signed-in only; delete is admin only.
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

/* The fake estate's b- agent with one icmp monitor and both service
 * shapes: one fully active UP row and one the api reports disabled with
 * no state yet — the pair the services table must tell apart. */
const A1 = 'bbbbbbbb-0000-4000-8000-000000000001'
const M1 = 'aaaaaaaa-0000-4000-8000-000000000001'
const T5 = 'cccccccc-0000-4000-8000-000000000005'
const S1 = 'dddddddd-0000-4000-8000-000000000001'
const S2 = 'dddddddd-0000-4000-8000-000000000002'

function agentReply() {
    return {
        status: 'success',
        agent: {
            id: A1, name: 'edge-a', address: 'edge-a.example',
            description: 'edge probe', is_active: 1,
            last_seen: localStamp(60 * 1000)
        }
    }
}

function monitorsReply() {
    return {
        status: 'success',
        monitors: [
            {
                id: M1, description: 'uplink rtt',
                target_id: T5, target_address: 'branch-gw.example',
                monitor_is_active: 1, target_is_active: 1, is_active: 1,
                protocol: 'icmp', port: 0, dscp: null,
                current_median: 31.4, current_loss: 0,
                last_update: '2026-10-09 12:00:00', last_down: null
            }
        ]
    }
}

function servicesReply() {
    return {
        status: 'success',
        services: [
            {
                id: S1, description: 'llm backend probe',
                target_id: T5, target_address: 'branch-gw.example',
                scheme: 'https', port: 8443, uri_path: '/v1/chat', uri_query: 'model=a',
                is_active: 1, last_state: 'UP', last_check: '2026-10-09 12:00:00'
            },
            {
                id: S2, description: null,
                target_id: T5, target_address: 'legacy-gw.example',
                scheme: 'http', port: 0, uri_path: '/status', uri_query: '',
                is_active: 0, last_state: null, last_check: null
            }
        ]
    }
}

/* Mount the page the way the router does — the id arrives as a prop —
 * then let the three doors and Vue's updates settle. */
async function mountDetail(id = A1) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/agents', name: 'agents', component: { render: () => null } },
            { path: '/agents/:id/edit', name: 'agent-edit', component: { render: () => null }, props: true }
        ]
    })
    const wrapper = mount(AgentDetailView, { props: { id }, global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return wrapper
}

/* The services panel is the second table on the page; pick it by its
 * own heading so the assertions can't drift onto the monitors table. */
function servicesPanel(wrapper) {
    return wrapper.findAll('section.panel').find((p) => p.find('h2').text() === 'services')
}

describe('AgentDetailView rendering an agent', () => {
    it('mounts, pulls the three doors, and renders the services uri as a new-tab link', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/agents/' + A1) return agentReply()
            if (url === '/cgi-bin/api/monitors?agent_id=' + A1) return monitorsReply()
            if (url === '/cgi-bin/api/services?agent_id=' + A1) return servicesReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // Three doors, exactly the urls the page ships — the listings
        // both narrowed server-side by the agent.
        expect(getJson.mock.calls.map((c) => c[0])).toEqual([
            '/cgi-bin/api/agents/' + A1,
            '/cgi-bin/api/monitors?agent_id=' + A1,
            '/cgi-bin/api/services?agent_id=' + A1
        ])

        // The bar names the record.
        expect(wrapper.find('.bar-title h1').text()).toBe('agent')
        expect(wrapper.find('.bar-title .muted').text()).toBe('edge-a')

        // The uri cell is the full uri the agent probes, as a link to
        // the live service in a new tab — mono text with the whole url
        // as its tooltip. With the default filter the disabled row is
        // hidden, so the table shows exactly the active one.
        const svcPanel = servicesPanel(wrapper)
        const URI = 'https://branch-gw.example:8443/v1/chat?model=a'
        const row0 = svcPanel.findAll('tbody tr')[0]
        const uriLink = row0.findAll('td')[2].find('a')
        expect(uriLink.text()).toBe(URI)
        expect(uriLink.attributes('href')).toBe(URI)
        expect(uriLink.attributes('target')).toBe('_blank')
        expect(uriLink.attributes('rel')).toBe('noopener noreferrer')
        expect(uriLink.classes()).toContain('mono')
        expect(uriLink.attributes('title')).toBe(URI)

        // The sanctioned exception is exactly one anchor wide: every
        // other door on the page (service name, target, monitor rows,
        // the bar's netping/edit doors) stays in this tab.
        const popouts = wrapper.findAll('a[target="_blank"]')
        expect(popouts).toHaveLength(1)
        expect(popouts[0].attributes('href')).toBe(URI)
        expect(row0.findAll('td')[0].find('a').attributes('target')).toBeUndefined()
        expect(row0.findAll('td')[0].find('a').attributes('href')).toBe('#/services/' + S1)

        // The state chip and the counters: one active service, one
        // disabled by its own flag, both counted even while hidden.
        expect(row0.text()).toContain('UP')
        const kv = wrapper.findAll('.kv').map((n) => n.text())
        expect(kv).toContain('active services1')
        expect(kv).toContain('inactive services1')
        expect(kv).toContain('total services2')
        expect(kv).toContain('active monitors1')
        expect(kv).toContain('total monitors1')
    })

    it('shows the disabled service behind the show-inactive toggle, with its own uri link', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/agents/' + A1) return agentReply()
            if (url === '/cgi-bin/api/monitors?agent_id=' + A1) return monitorsReply()
            if (url === '/cgi-bin/api/services?agent_id=' + A1) return servicesReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // One toggle runs both tables; flipping it reveals the
        // disabled service, struck through with its reason, and it
        // still carries its uri as a new-tab link.
        await wrapper.find('input[type="checkbox"]').setValue(true)

        const svcPanel = servicesPanel(wrapper)
        const rows = svcPanel.findAll('tbody tr')
        expect(rows).toHaveLength(2)

        const struck = rows[1].find('del a')
        expect(struck.exists()).toBe(true)
        expect(struck.attributes('href')).toBe('#/services/' + S2)
        expect(rows[1].text()).toContain('Service disabled')

        const uriLink = rows[1].findAll('td')[2].find('a')
        expect(uriLink.text()).toBe('http://legacy-gw.example/status')
        expect(uriLink.attributes('href')).toBe('http://legacy-gw.example/status')
        expect(uriLink.attributes('target')).toBe('_blank')
        expect(uriLink.attributes('rel')).toBe('noopener noreferrer')

        // A check that never ran says so instead of a dash.
        expect(rows[1].text()).toContain('Never')
    })

    it('keeps the identity card and reports the failure when the services door dies', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/agents/' + A1) return agentReply()
            if (url === '/cgi-bin/api/monitors?agent_id=' + A1) return monitorsReply()
            if (url === '/cgi-bin/api/services?agent_id=' + A1) throw new Error('HTTP 500')
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // The agent record still renders its card and bar, and the
        // services half says which list failed instead of implying
        // the agent monitors nothing.
        expect(wrapper.find('.bar-title .muted').text()).toBe('edge-a')
        const notes = wrapper.findAll('.err-note').map((n) => n.text())
        expect(notes.some((t) => t.includes('service list failed — counts not shown'))).toBe(true)
        expect(notes.some((t) => t.includes('service list fetch failed'))).toBe(true)

        // No services table at all, while the monitors table lives on.
        expect(servicesPanel(wrapper).find('table').exists()).toBe(false)
        expect(wrapper.text()).toContain('uplink rtt')
    })

    it('declares the agent version and service-checks capability in the details card', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/agents/' + A1) {
                const reply = agentReply()
                reply.agent.agent_version = '2.4.0'
                reply.agent.supports_services = 1
                return reply
            }
            if (url === '/cgi-bin/api/monitors?agent_id=' + A1) return monitorsReply()
            if (url === '/cgi-bin/api/services?agent_id=' + A1) return servicesReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // The two capability rows sit in the details card, after the
        // description and before the status chip.
        const details = wrapper.findAll('section.panel').find((p) => p.find('h2').text() === 'details')
        expect(details.findAll('.k').map((k) => k.text())).toEqual([
            'id', 'address', 'description', 'version', 'service checks', 'status', 'last seen'
        ])

        // A version is a machine token: mono cell carrying the declared
        // string, like the address above it.
        const versionRow = details.findAll('.kv').find((n) => n.find('.k').text() === 'version')
        expect(versionRow.find('.v').text()).toBe('2.4.0')
        expect(versionRow.find('.v').classes()).toContain('mono')

        // Capability is a plain readable value, not a chip: 1 means this
        // agent can be handed service checks.
        const checksRow = details.findAll('.kv').find((n) => n.find('.k').text() === 'service checks')
        expect(checksRow.find('.v').text()).toBe('supported')
        expect(checksRow.find('.chip').exists()).toBe(false)
    })

    it("reads 'not reported' / 'not supported' when the agent predates the capability fields", async () => {
        // Exactly the payload an api build without the capability
        // columns returns: the version key is explicit null (the column
        // default) and supports_services is absent altogether — both
        // defenses on one agent.
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/agents/' + A1) {
                const reply = agentReply()
                reply.agent.agent_version = null
                return reply
            }
            if (url === '/cgi-bin/api/monitors?agent_id=' + A1) return monitorsReply()
            if (url === '/cgi-bin/api/services?agent_id=' + A1) return servicesReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // A null version is an agent that never declared one — announced
        // as "not reported" (same wording as the netping header), never
        // invented and never a bare "-" — and an absent flag is the old
        // monitor-only agent.
        const kv = wrapper.findAll('.kv').map((n) => n.text())
        expect(kv).toContain('versionnot reported')
        expect(kv).toContain('service checksnot supported')
    })
})