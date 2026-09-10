/*
 * MonitorDetailView is the ported monitor.php, read-only: one GET to
 * /cgi-bin/api/monitors/:id for the identity card and latency table,
 * one GET to /cgi-bin/api/rrd for the graph window. getJson is mocked
 * at the module door here, per request, so one side can fail without
 * dragging the other. The graph drawing itself is covered in its own
 * spec — these tests only pin the urls, the payload on the page, and
 * the honesty rules when a request comes back empty or broken.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { getJson, postJson } from '../api'
import { getSession } from '../session'
import MonitorDetailView from '../components/MonitorDetailView.vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../api', () => ({ getJson: vi.fn(), postJson: vi.fn() }))
vi.mock('../session', () => ({ getSession: vi.fn() }))

enableAutoUnmount(afterEach)

beforeEach(() => {
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
})

/* Every detail id is a uuid (char(36)) and the api rejects anything
 * else, so the fixture carries one. */
const MONITOR_ID = 'aaaaaaaa-0000-4000-8000-000000000007'

function monitorReply() {
    return {
        status: 'success',
        monitor: {
            id: MONITOR_ID,
            description: 'branch vpn',
            agent_id: 'bbbbbbbb-0000-4000-8000-000000000001', agent_name: 'edge-a',
            target_id: 'cccccccc-0000-4000-8000-000000000005', target_address: 'branch-gw.example',
            protocol: 'tcp', port: 443, dscp: 46,
            is_active: 1, monitor_is_active: 1, agent_is_active: 1, target_is_active: 1,
            pollcount: 20, pollinterval: 60,
            sample: 1200, total_down: 3,
            last_update: '2026-09-07 18:00:00', last_down: '2026-09-06 08:30:00', last_clear: '2026-09-06 09:00:00',
            current_median: 55.1, current_min: 40.2, current_max: 80.4, current_stddev: 6.5, current_loss: 0,
            avg_median: 52, avg_min: 38, avg_max: 90, avg_stddev: 7, avg_loss: 0.4,
            latency_threshold_ms: 70, latency_flag: 0
        }
    }
}

/* Two in-window samples is plenty — the drawing is another spec's job. */
function chartReply() {
    const now = Date.now()
    return {
        status: 'success',
        data: [
            { timestamp: now - 20 * 60000, rtt: 54.9, loss: 0 },
            { timestamp: now - 10 * 60000, rtt: 55.3, loss: 0 }
        ]
    }
}

/* Mount the page the way the router does — the id arrives as a prop —
 * then let both requests and Vue's updates settle. The router is a
 * memory twin carrying just the route the bar's edit link resolves
 * against. */
async function mountDetail(id = MONITOR_ID) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/monitors/:id/edit', name: 'monitor-edit', component: { render: () => null }, props: true }
        ]
    })
    const wrapper = mount(MonitorDetailView, { props: { id }, global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return wrapper
}

describe('MonitorDetailView with both doors answering', () => {
    it('pulls the monitor and its rrd window on mount and shows it', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + MONITOR_ID + '&start=')) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // Two doors, exactly the urls the page ships: the detail by id,
        // the chart with the default three-hour window attached.
        expect(getJson.mock.calls.map((c) => c[0])).toEqual([
            '/cgi-bin/api/monitors/' + MONITOR_ID,
            expect.stringMatching(new RegExp('^/cgi-bin/api/rrd\\?id=' + MONITOR_ID + '&start=.+&end='))
        ])

        // The identity card reads from the detail payload.
        expect(wrapper.text()).toContain('branch vpn')
        expect(wrapper.text()).toContain('edge-a')
        expect(wrapper.text()).toContain('branch-gw.example')
        expect(wrapper.text()).toContain('TCP/443')

        // A handful of key/value cells, in the page's own shorthand.
        const kv = wrapper.findAll('.kv').map((n) => n.text())
        expect(kv).toContain('dscp46')
        expect(kv).toContain('polling20x every 60s')
        expect(kv).toContain('total samples1200')
        expect(kv).toContain('total down events3')

        // Status chrome: active chip, a refresh clock, the in-app edit
        // link, and the raw-data link out.
        const barChips = wrapper.findAll('.bar-right .chip')
        expect(barChips[0].text()).toBe('active')
        expect(barChips[0].classes()).toContain('chip-ok')
        expect(wrapper.find('.bar-right .muted').text()).toContain('updated')
        expect(wrapper.find('a[href="/monitors/' + MONITOR_ID + '/edit"]').exists()).toBe(true)
        expect(wrapper.find('a[href="/cgi-bin/api/rrd?id=' + MONITOR_ID + '"]').exists()).toBe(true)

        // The graph drew something for the two samples (shallow check —
        // the chart has its own spec).
        expect(wrapper.find('svg.rrd-svg').exists()).toBe(true)
    })
})

describe('MonitorDetailView reset', () => {
    it('confirms, posts the reset, and refetches the detail', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + MONITOR_ID)) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        postJson.mockResolvedValue({ status: 'success' })
        vi.stubGlobal('confirm', () => true)
        const wrapper = await mountDetail()

        // The reset button is the second button in the bar.
        await wrapper.findAll('.bar-right button.btn')[1].trigger('click')
        await flushPromises()
        await flushPromises()

        expect(postJson).toHaveBeenCalledWith('/cgi-bin/api/monitor/' + MONITOR_ID + '/reset', {})
        // The detail door ran again after the reset: two calls on mount,
        // one more for the refetch.
        expect(getJson).toHaveBeenCalledTimes(3)
    })

    it('leaves the counters alone when confirm is declined', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + MONITOR_ID)) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        postJson.mockResolvedValue({ status: 'success' })
        vi.stubGlobal('confirm', () => false)
        const wrapper = await mountDetail()

        await wrapper.findAll('.bar-right button.btn')[1].trigger('click')
        await flushPromises()
        await flushPromises()

        expect(postJson).not.toHaveBeenCalled()
        expect(getJson).toHaveBeenCalledTimes(2)
    })

    it('hides reset when the spa session is not an admin', async () => {
        getSession.mockResolvedValue({ authenticated: false, isAdmin: false })
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + MONITOR_ID)) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()
        const labels = wrapper.findAll('.bar-right button.btn').map((n) => n.text())
        expect(labels).not.toContain('reset')
        expect(postJson).not.toHaveBeenCalled()
    })
})

describe('MonitorDetailView edit door', () => {
    it('hides the edit link while the spa session is signed out', async () => {
        getSession.mockResolvedValue({ authenticated: false, isAdmin: false })
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + MONITOR_ID)) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        expect(wrapper.find('a[href="/monitors/' + MONITOR_ID + '/edit"]').exists()).toBe(false)
        // Reset shares the gate and then some (admin), so it hides too.
        const labels = wrapper.findAll('.bar-right button.btn').map((n) => n.text())
        expect(labels).not.toContain('reset')
    })

    it('shows edit for a signed-in non-admin but keeps reset admin-only', async () => {
        getSession.mockResolvedValue({ authenticated: true, isAdmin: false })
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            if (url.startsWith('/cgi-bin/api/rrd?id=' + MONITOR_ID)) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        expect(wrapper.find('a[href="/monitors/' + MONITOR_ID + '/edit"]').exists()).toBe(true)
        const labels = wrapper.findAll('.bar-right button.btn').map((n) => n.text())
        expect(labels).not.toContain('reset')
    })
})

describe('MonitorDetailView when a request misbehaves', () => {
    it('says not found and keeps nothing live when the api answers 404', async () => {
        getJson.mockImplementation(async (url) => {
            if (url.startsWith('/cgi-bin/api/monitors/')) throw new Error('HTTP 404')
            if (url.startsWith('/cgi-bin/api/rrd')) return chartReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountDetail()

        // Both requests still went out — the failure is per request.
        expect(getJson).toHaveBeenCalledTimes(2)

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('monitor not found')
        expect(banner.text()).toContain('nothing below is live data')
        // No payload means no cards, no latency table, no graph at all.
        expect(wrapper.find('.cols').exists()).toBe(false)
    })

    it('keeps the monitor details when the rrd side fails on its own', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            throw new Error('HTTP 404')
        })
        const wrapper = await mountDetail()

        // 404 on the chart reads as "no rrd file yet", not as a crash.
        expect(wrapper.find('.err-note').text()).toContain('no rrd file yet for this monitor')
        // The identity card survived the broken graph.
        expect(wrapper.find('.cols').exists()).toBe(true)
        expect(wrapper.text()).toContain('branch vpn')
    })

    it('reports an empty rrd window instead of drawing an empty chart', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/monitors/' + MONITOR_ID) return monitorReply()
            return { status: 'success', data: [] }
        })
        const wrapper = await mountDetail()

        expect(wrapper.find('.err-note').text()).toBe('rrd returned no samples for this range')
        expect(wrapper.text()).toContain('branch vpn')
        expect(wrapper.find('svg.rrd-svg').exists()).toBe(false)
    })
})

describe('MonitorDetailView without an id', () => {
    it('asks for nothing and says the url carries no monitor id', async () => {
        const wrapper = await mountDetail('')

        expect(getJson).not.toHaveBeenCalled()
        expect(wrapper.find('.banner').text()).toContain('no monitor id found in the url')
    })
})