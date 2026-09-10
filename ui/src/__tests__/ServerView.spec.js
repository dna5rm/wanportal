/*
 * ServerView is mounted with fetch stubbed per endpoint, the same
 * keyed-stub pattern the dashboard spec uses (options are read on
 * every fetch, so a test can flip doors to failing between two
 * refreshes). What matters here: all four doors fire together with
 * the exact urls the page ships (/agents /targets /monitors /health),
 * the toolbar stays slim (updated stamp, refresh button — no second
 * title, no 'bundled vue' copy), the counts table reads active/disabled/total
 * per door exactly like the classic server.php table, uptime formats
 * like the classic format_uptime (days, hours, minutes, singulars,
 * seconds dropped), the clock rows are labeled for where they really
 * come from — 'browser' while /health carries no clock fields, 'server'
 * if it ever grows them — the page renders no links at all (the
 * classic table had none and no classic hrefs may sneak back), and
 * when a door fails the banner names it while the healthy doors'
 * numbers survive untouched: a failed refresh never zeroes anything.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import ServerView from '../components/ServerView.vue'
import { A1, A2, agentsBody, jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* Uuid-shaped like the rest of the fake estate (targets cccccccc-,
 * monitors aaaaaaaa-). Counts are chosen so every column is eyeballable:
 * agents 1/1/2, targets 1/1/2, monitors 2/1/3. */
const T1 = 'cccccccc-0000-4000-8000-000000000001'
const T2 = 'cccccccc-0000-4000-8000-000000000002'

function targetsBody() {
    return {
        status: 'success',
        targets: [
            { id: T1, address: 'hq-gw.example', description: 'primary site', is_active: 1 },
            { id: T2, address: 'legacy-gw.example', description: null, is_active: 0 }
        ]
    }
}

function monitorsBody() {
    return {
        status: 'success',
        monitors: [
            { id: 'aaaaaaaa-0000-4000-8000-000000000011', description: 'hq uplink', agent_id: A1, target_id: T1, is_active: 1 },
            { id: 'aaaaaaaa-0000-4000-8000-000000000012', description: 'branch vpn', agent_id: A1, target_id: T1, is_active: 1 },
            { id: 'aaaaaaaa-0000-4000-8000-000000000013', description: 'legacy dial-up', agent_id: A2, target_id: T2, is_active: 0 }
        ]
    }
}

/* 3d 2h 5m in seconds — the default uptime the healthy fixtures carry. */
const UP = 3 * 86400 + 2 * 3600 + 5 * 60

function healthBody(seconds = UP) {
    return { status: 'ok', uptime_seconds: seconds }
}

/* The four doors answer through one keyed stub; options.fail makes a
 * door throw instead, and options.<door> swaps the body wholesale
 * (health swaps only its uptime_seconds unless a full body is given). */
function makeServerFetch(options = {}) {
    return vi.fn(async (url) => {
        const fail = options.fail || {}
        if (fail.every) throw fail.every
        if (url === '/cgi-bin/api/agents') {
            if (fail.agents) throw fail.agents
            return jsonReply(options.agents || agentsBody())
        }
        if (url === '/cgi-bin/api/targets') {
            if (fail.targets) throw fail.targets
            return jsonReply(options.targets || targetsBody())
        }
        if (url === '/cgi-bin/api/monitors') {
            if (fail.monitors) throw fail.monitors
            return jsonReply(options.monitors || monitorsBody())
        }
        if (url === '/cgi-bin/api/health') {
            if (fail.health) throw fail.health
            return jsonReply(options.healthBody || healthBody(options.uptimeSeconds))
        }
        throw new Error('unexpected url: ' + url)
    })
}

async function mountServer(options = {}) {
    const stub = makeServerFetch(options)
    vi.stubGlobal('fetch', stub)
    const wrapper = mount(ServerView)
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, options }
}

/* The page carries no links at all — this table is the one place the
 * old console had no hrefs, and none may sneak back in. */
function expectNoLinks(wrapper) {
    expect(wrapper.findAll('a')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('.php')
}

describe('ServerView with a healthy api', () => {
    it('hits all four doors with the urls the page ships', async () => {
        const { wrapper, stub } = await mountServer()

        const urls = stub.mock.calls.map((call) => call[0])
        expect(urls).toContain('/cgi-bin/api/agents')
        expect(urls).toContain('/cgi-bin/api/targets')
        expect(urls).toContain('/cgi-bin/api/monitors')
        expect(urls).toContain('/cgi-bin/api/health')
        expect(stub).toHaveBeenCalledTimes(4)

        // No banner when every door came back.
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('keeps the toolbar slim: no second title, updated stamp, refresh button', async () => {
        const { wrapper } = await mountServer()

        // The account bar already names the page (Runtime), so the h1
        // is gone; the toolbar keeps the updated stamp and the one
        // refresh control.
        expect(wrapper.find('h1').exists()).toBe(false)
        expect(wrapper.find('header.bar').find('.btn').text()).toBe('refresh now')
        expect(wrapper.text()).toMatch(/updated \d{2}:\d{2}:\d{2}/)
        // No tagline, no "bundled vue" copy anywhere.
        expect(wrapper.text()).not.toContain('bundled vue')
    })

    it('counts active/disabled/total per door like the classic table', async () => {
        const { wrapper } = await mountServer()

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(3)

        const cells = (row) => row.findAll('td').map((td) => td.text())
        expect(rows[0].find('td').text()).toBe('agents')
        expect(cells(rows[0])).toEqual(['agents', '1', '1', '2'])
        expect(cells(rows[1])).toEqual(['targets', '1', '1', '2'])
        expect(cells(rows[2])).toEqual(['monitors', '2', '1', '3'])

        const heads = wrapper.findAll('thead th').map((th) => th.text())
        expect(heads).toEqual(['', 'active', 'disabled', 'total'])

        expectNoLinks(wrapper)
    })

    it('formats uptime like the classic page: days, hours, minutes', async () => {
        const { wrapper, options } = await mountServer()
        expect(wrapper.text()).toContain('uptime: 3 days, 2 hours, 5 minutes')

        // Refresh flips the payload; seconds are dropped and the
        // singulars survive, like format_uptime did.
        const flips = [
            [90061, 'uptime: 1 day, 1 hour, 1 minute'],   // seconds truncated
            [3600, 'uptime: 1 hour'],
            [60, 'uptime: 1 minute'],
            [45, 'uptime: under a minute']                // classic printed a blank here
        ]
        for (const [secs, expected] of flips) {
            options.uptimeSeconds = secs
            await wrapper.find('header.bar .btn').trigger('click')
            await flushPromises()
            await flushPromises()
            expect(wrapper.text()).toContain(expected)
        }
    })

    it('labels the clock rows as browser clock while /health has none', async () => {
        const { wrapper } = await mountServer()

        const runtime = wrapper.find('.runtime')
        expect(runtime.text()).toMatch(/browser time: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/)
        expect(runtime.text()).toMatch(/browser utc: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/)
        // The clock is the browser's; nothing may claim it is the server's.
        expect(runtime.text()).not.toContain('server time')
        expect(runtime.text()).not.toContain('server utc')
    })

    it('switches to server-labeled clocks when /health carries them', async () => {
        const { wrapper } = await mountServer({
            healthBody: {
                status: 'ok',
                uptime_seconds: UP,
                server_timezone: 'America/New_York',
                server_localtime: '2026-09-07 08:00:00',
                server_utc_time: '2026-09-07 12:00:00'
            }
        })

        const runtime = wrapper.find('.runtime')
        expect(runtime.text()).toContain('server time: 2026-09-07 08:00:00 (America/New_York)')
        expect(runtime.text()).toContain('server utc: 2026-09-07 12:00:00')
        expect(runtime.text()).not.toContain('browser')
    })

    it('hides the utc row when the server itself runs UTC', async () => {
        const { wrapper } = await mountServer({
            healthBody: {
                status: 'ok',
                uptime_seconds: UP,
                server_timezone: 'UTC',
                server_localtime: '2026-09-07 12:00:00',
                server_utc_time: '2026-09-07 12:00:00'
            }
        })

        const runtime = wrapper.find('.runtime')
        expect(runtime.text()).toContain('server time: 2026-09-07 12:00:00')
        expect(runtime.text()).not.toContain('(UTC)')
        expect(runtime.text()).not.toContain('server utc')
    })
})

describe('ServerView when a door fails', () => {
    it('names the failed door and keeps the healthy doors numbers', async () => {
        const options = {}
        const { wrapper } = await mountServer(options)
        expect(wrapper.find('.banner').exists()).toBe(false)

        // Flip agents to failing, then hit "refresh now".
        options.fail = { agents: new Error('HTTP 503') }
        await wrapper.find('header.bar .btn').trigger('click')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('refresh failed')
        expect(banner.text()).toContain('agents')

        // The failed door keeps its row but shows no numbers...
        const rows = wrapper.findAll('tbody tr')
        const agentCells = rows[0].findAll('td')
        expect(agentCells).toHaveLength(2)
        expect(agentCells[1].text()).toBe('fetch failed')
        // ...and the healthy doors keep theirs — nothing was zeroed.
        expect(rows[1].findAll('td').map((td) => td.text())).toEqual(['targets', '1', '1', '2'])
        expect(rows[2].findAll('td').map((td) => td.text())).toEqual(['monitors', '2', '1', '3'])
        expect(wrapper.text()).toContain('uptime: 3 days, 2 hours, 5 minutes')
    })

    it('names the uptime door and keeps its last good value', async () => {
        const options = {}
        const { wrapper } = await mountServer(options)

        options.fail = { health: new Error('HTTP 503') }
        await wrapper.find('header.bar .btn').trigger('click')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('uptime')

        // The last good uptime stands; only the clock rows keep ticking
        // (they are browser-side and need nothing from the api).
        expect(wrapper.text()).toContain('uptime: 3 days, 2 hours, 5 minutes')
        const rows = wrapper.findAll('tbody tr')
        expect(rows[0].findAll('td').map((td) => td.text())).toEqual(['agents', '1', '1', '2'])
    })

    it('shows no numbers for a door that has never answered', async () => {
        const { wrapper } = await mountServer({
            fail: { targets: new Error('HTTP 503') }
        })

        // targets died on the first load: no numbers exist, so none may
        // be shown — its row carries 'fetch failed' instead of zeros.
        const rows = wrapper.findAll('tbody tr')
        const targetCells = rows[1].findAll('td')
        expect(targetCells).toHaveLength(2)
        expect(targetCells[1].text()).toBe('fetch failed')

        // The healthy doors still render their counts, and the toolbar
        // still shows the stamp because some door did answer.
        expect(rows[0].findAll('td').map((td) => td.text())).toEqual(['agents', '1', '1', '2'])
        expect(wrapper.find('.banner.banner-warn').text()).toContain('targets')
        expect(wrapper.text()).toMatch(/updated \d{2}:\d{2}:\d{2}/)
    })

    it('shows the loud unreachable banner when nothing answers yet', async () => {
        const { wrapper } = await mountServer({
            fail: { every: new Error('connection refused') }
        })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('api unreachable')
        expect(banner.text()).toContain('connection refused')

        // Every door names its failure instead of printing zeros, the
        // uptime line reads as failed, and no stamp claims an update
        // that never happened.
        const rows = wrapper.findAll('tbody tr')
        for (const row of rows) {
            const cells = row.findAll('td')
            expect(cells).toHaveLength(2)
            expect(cells[1].text()).toBe('fetch failed')
        }
        expect(wrapper.findAll('td.num')).toHaveLength(0)
        expect(wrapper.find('.runtime').text()).toContain('uptime fetch failed — connection refused')
        expect(wrapper.text()).not.toContain('updated ')
        expectNoLinks(wrapper)
    })
})

describe('ServerView refresh rhythm', () => {
    it('re-fires all four doors every 300s like the classic meta refresh', async () => {
        vi.useFakeTimers()
        try {
            const stub = makeServerFetch({})
            vi.stubGlobal('fetch', stub)
            const wrapper = mount(ServerView)
            await flushPromises()
            await flushPromises()
            const afterMount = stub.mock.calls.length
            expect(afterMount).toBe(4)

            await vi.advanceTimersByTimeAsync(300000)
            await flushPromises()
            await flushPromises()
            expect(stub.mock.calls.length).toBe(afterMount + 4)

            // Leaving the page stops the timer.
            wrapper.unmount()
            await vi.advanceTimersByTimeAsync(300000)
            expect(stub.mock.calls.length).toBe(afterMount + 4)
        } finally {
            vi.useRealTimers()
            vi.unstubAllGlobals()
        }
    })
})