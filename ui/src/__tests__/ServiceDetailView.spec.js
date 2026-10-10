/*
 * ServiceDetailView is the read-only service page (spec §3.1): one GET
 * to /cgi-bin/api/service/:id for the config and live state, one GET
 * to /cgi-bin/api/rrd for the graph window. getJson is mocked at the
 * module door like MonitorDetailView.spec.js, so one side can fail
 * without dragging the other. The tests pin the urls, the record's
 * fields on the page, and a no-throw mount for both a healthy and a
 * dead api — a throw in setup blanks the whole route.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { getJson } from '../api'
import { getSession } from '../session'
import ServiceDetailView from '../components/ServiceDetailView.vue'
import { localStamp } from './stubs'

vi.mock('../api', () => ({ getJson: vi.fn(), postJson: vi.fn(), delJson: vi.fn() }))
vi.mock('../session', () => ({ getSession: vi.fn() }))

enableAutoUnmount(afterEach)

beforeEach(() => {
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
})

/* The same fake record ServiceEditView.spec.js drives through the
 * editor (S9, 'llm backend probe'), plus the live-state fields the
 * detail page adds. last_check rides localStamp so the freshness chip
 * is exact whatever timezone the test box runs in (300s interval x3
 * beats one minute ago). */
const S9 = 'dddddddd-0000-4000-8000-000000000009'
const A1 = 'bbbbbbbb-0000-4000-8000-000000000001'
const T5 = 'cccccccc-0000-4000-8000-000000000005'
const C3 = 'eeeeeeee-0000-4000-8000-000000000003'

function serviceReply() {
    return {
        status: 'success',
        service: {
            id: S9,
            description: 'llm backend probe',
            agent_id: A1, agent_name: 'edge-a',
            target_id: T5, target_address: 'branch-gw.example',
            scheme: 'https', port: 8443,
            uri_path: '/v1/chat', uri_query: 'model=a',
            http_method: 'POST',
            http_headers: { 'Content-Type': 'application/json', 'X-Ping': 'pong' },
            body_encoding: 'json', body: '{"ok":1}',
            send_string: null,
            receive_string: '"ok"', receive_regex: 1,
            disable_string: 'maintenance', disable_regex: 0,
            expected_status: '200-299',
            follow_redirects: 1, verify_tls: 1, timeout: 15,
            auth_type: 'bearer', auth_header_name: null,
            auth_credential_id: C3, credential_name: 'api token',
            pollcount: 1, pollinterval: 300, is_active: 1,
            last_state: 'UP', last_reason: 'ok', last_status_code: 200,
            last_message: 'match', last_check: localStamp(60 * 1000),
            last_change: '2026-10-09 10:30:00', total_down: 2
        }
    }
}

/* Two in-window samples is plenty — the drawing itself is rrdChart's
 * own spec's job. */
function chartReply() {
    const now = Date.now()
    return {
        status: 'success',
        data: [
            { timestamp: now - 20 * 60000, rtt: 31.4, loss: 0 },
            { timestamp: now - 10 * 60000, rtt: 32.1, loss: 0 }
        ]
    }
}

/* Mount the page the way the router does — the id arrives as a prop —
 * then let both doors and Vue's updates settle. The router is a memory
 * twin carrying the names the page links against. */
async function mountDetail(id = S9) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/services', name: 'services', component: { render: () => null } },
            { path: '/services/:id/edit', name: 'service-edit', component: { render: () => null }, props: true },
            { path: '/agents/:id', name: 'agent', component: { render: () => null }, props: true },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true },
            { path: '/credentials/:id', name: 'credential', component: { render: () => null }, props: true }
        ]
    })
    const wrapper = mount(ServiceDetailView, { props: { id }, global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return wrapper
}

describe('ServiceDetailView rendering a record', () => {
    it('mounts, pulls the detail and its rrd window, and shows the record without throwing', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/service/' + S9) return serviceReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + S9 + '&start=')) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // Two doors, exactly the urls the page ships: the record by
        // id, the chart with the default three-hour window attached.
        expect(getJson.mock.calls.map((c) => c[0])).toEqual([
            '/cgi-bin/api/service/' + S9,
            expect.stringMatching(new RegExp('^/cgi-bin/api/rrd\\?id=' + S9 + '&start=.+&end='))
        ])

        // The bar names the record and carries the state chip (UP
        // green), the freshness chip (fresh inside 3x the interval),
        // and the in-app edit link. verify_tls is 1, so no tls chip.
        expect(wrapper.find('.bar-title h1').text()).toBe('service')
        expect(wrapper.find('.bar-title .muted').text()).toBe('llm backend probe')
        const barChips = wrapper.findAll('.bar-right .chip')
        expect(barChips[0].text()).toBe('UP')
        expect(barChips[0].classes()).toContain('chip-ok')
        expect(barChips[1].text()).toBe('fresh data')
        expect(wrapper.find('a[href="/services/' + S9 + '/edit"]').exists()).toBe(true)

        // The config panels read from the record, in the page's own
        // kv shorthand.
        const kv = wrapper.findAll('.kv').map((n) => n.text())
        expect(kv).toContain('id' + S9)
        expect(kv).toContain('agentedge-a')
        expect(kv).toContain('targetbranch-gw.example')
        expect(kv).toContain('methodPOST')
        expect(kv).toContain('polling1x every 300s')
        expect(kv).toContain('headersContent-Type: application/jsonX-Ping: pong')
        expect(kv).toContain('bodyjson: {"ok":1}')
        expect(kv).toContain('send string-')
        expect(kv).toContain('redirectsyes')
        expect(kv).toContain('verify tlson')
        expect(kv).toContain('timeout15s')
        expect(kv).toContain('expected status200-299')
        // The receive/disable v-cells wrap their value on its own
        // template line, so textContent carries interior whitespace —
        // match those two squashed.
        const squash = (t) => t.replace(/\s+/g, '')
        expect(kv.some((t) => squash(t) === 'receive"ok"(regex)')).toBe(true)
        expect(kv.some((t) => squash(t) === 'disablemaintenance')).toBe(true)
        expect(kv).toContain('typebearer')
        expect(kv).toContain('credentialapi token')

        // The uri left the details panel for its own address line: one
        // strip under the bar, above the panels, the full url as a
        // new-tab link to the live service — and nothing else on the
        // page pops out of the tab.
        const URI = 'https://branch-gw.example:8443/v1/chat?model=a'
        const uriLine = wrapper.find('.service-uri')
        expect(uriLine.exists()).toBe(true)
        expect(uriLine.find('.k').text()).toBe('url')
        const uriLink = uriLine.find('a')
        expect(uriLink.text()).toBe(URI)
        expect(uriLink.attributes('href')).toBe(URI)
        expect(uriLink.attributes('target')).toBe('_blank')
        expect(uriLink.attributes('rel')).toBe('noopener noreferrer')
        expect(uriLink.classes()).toEqual(['mono', 'wrap'])
        // relocated, not duplicated — and above the panels, not inside
        expect(kv.some((row) => row.startsWith('uri'))).toBe(false)
        expect(uriLink.element.closest('.cols')).toBeNull()
        const html = wrapper.html()
        expect(html.indexOf('service-uri')).toBeLessThan(html.indexOf('class="cols"'))
        expect(wrapper.find('.kv a[target]').exists()).toBe(false)

        // The state panel: the rolled-up chip again, status code,
        // reason token verbatim, stamps verbatim, down count.
        expect(kv).toContain('last stateUP')
        expect(kv).toContain('last status code200')
        expect(kv).toContain('last reasonok')
        expect(kv).toContain('last messagematch')
        expect(kv).toContain('total down events2')

        // The graph drew something for the two samples (shallow check —
        // the chart has its own spec).
        expect(wrapper.find('svg.rrd-svg').exists()).toBe(true)

        // A healthy api never draws a banner.
        expect(wrapper.find('.banner').exists()).toBe(false)
    })
})

describe('ServiceDetailView with a dead api', () => {
    it('renders the error banner instead of throwing when the service is gone', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/service/' + S9) throw new Error('HTTP 404')
            if (url.startsWith('/cgi-bin/api/rrd?id=' + S9)) throw new Error('HTTP 404')
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // The detail door's 404 maps to not-found wording, loudly.
        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('service api: service not found')
        expect(banner.text()).toContain('nothing below is live data')

        // No record — no config, state or graph panels at all, and no
        // address line either (it rides the record, like the panels).
        expect(wrapper.find('.cols').exists()).toBe(false)
        expect(wrapper.find('.service-uri').exists()).toBe(false)
    })

    it('keeps the config panels when only the graph door fails', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/service/' + S9) return serviceReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + S9)) throw new Error('HTTP 404')
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // The rrd 404 maps to the muted graph note inside the panel.
        expect(wrapper.find('.err-note').exists()).toBe(true)
        expect(wrapper.find('.err-note').text()).toContain('no rrd file yet for this service')

        // The graph failing never blanks the config panels, and a
        // dead graph alone draws no banner. The address line rides
        // the record too, so it still leads the page here.
        expect(wrapper.text()).toContain('id' + S9)
        expect(wrapper.find('.service-uri a').exists()).toBe(true)
        expect(wrapper.find('.banner').exists()).toBe(false)
    })
})