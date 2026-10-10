/*
 * ServerView is mounted with fetch stubbed per endpoint, the same
 * keyed-stub pattern the dashboard spec uses (options are read on
 * every fetch, so a test can flip doors to failing between two
 * refreshes). What matters here: all five doors fire together with
 * the exact urls the page ships (/agents /targets /monitors /services
 * /health),
 * the toolbar stays slim (updated stamp, refresh button — no second
 * title, no 'bundled vue' copy), the counts table reads active/disabled/total
 * per door exactly like the classic server.php table, uptime formats
 * like the classic format_uptime (days, hours, minutes, singulars,
 * seconds dropped), the clock rows are labeled for where they really
 * come from — 'browser' while /health carries no clock fields, 'server'
 * if it ever grows them — the one link the page carries is the
 * toolbar's sanctioned GitHub repo door (the classic table had no
 * hrefs and nothing else may sneak back), and when a door fails the
 * banner names it while the healthy doors' numbers survive untouched:
 * a failed refresh never zeroes anything.
 *
 * The page is mixed-auth, and the spec pins the split: the five public
 * doors fire for everyone, two freshness lines ride their rows (agents
 * reporting counts heartbeats within the hour — is_active does not
 * vote — and the data-fresh stamp is the newest monitor last_update,
 * parsed as the api's naive UTC), and the JWT-gated runtime-stats door
 * opens only for a tab that holds and validates a session token,
 * rendering the host block and agent versions when it answers and
 * rendering NOTHING — no banner, no empty panels — when the session is
 * missing, expired, or the payload is junk, so an anonymous visitor
 * keeps today's public page exactly.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import ServerView from '../components/ServerView.vue'
import { A1, A2, agentsBody, jsonReply, localStamp } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
    // The session token lives in the tab's sessionStorage; wiping it
    // keeps a signed-in test from leaking a session into the next one.
    sessionStorage.clear()
})

/* Uuid-shaped like the rest of the fake estate (targets cccccccc-,
 * monitors aaaaaaaa-, services dddddddd-). Counts are chosen so every
 * column is eyeballable: agents 1/1/2, targets 1/1/2, monitors 2/1/3,
 * services 1/2/3. */
const T1 = 'cccccccc-0000-4000-8000-000000000001'
const T2 = 'cccccccc-0000-4000-8000-000000000002'
const S1 = 'dddddddd-0000-4000-8000-000000000001'
const S2 = 'dddddddd-0000-4000-8000-000000000002'
const S3 = 'dddddddd-0000-4000-8000-000000000003'
const A3 = 'bbbbbbbb-0000-4000-8000-000000000003'

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
            { id: 'aaaaaaaa-0000-4000-8000-000000000011', description: 'hq uplink', agent_id: A1, target_id: T1, is_active: 1, last_update: localStamp(2 * 60000) },
            { id: 'aaaaaaaa-0000-4000-8000-000000000012', description: 'branch vpn', agent_id: A1, target_id: T1, is_active: 1, last_update: localStamp(90 * 60000) },
            { id: 'aaaaaaaa-0000-4000-8000-000000000013', description: 'legacy dial-up', agent_id: A2, target_id: T2, is_active: 0, last_update: localStamp(3 * 86400000) }
        ]
    }
}

function servicesBody() {
    return {
        status: 'success',
        services: [
            { id: S1, description: 'payments portal', agent_id: A1, target_id: T1, is_active: 1 },
            { id: S2, description: 'metrics mirror', agent_id: A2, target_id: T2, is_active: 0 },
            { id: S3, description: 'legacy feed', agent_id: A2, target_id: T2, is_active: 0 }
        ]
    }
}

/* 3d 2h 5m in seconds — the default uptime the healthy fixtures carry. */
const UP = 3 * 86400 + 2 * 3600 + 5 * 60

function healthBody(seconds = UP) {
    return { status: 'ok', uptime_seconds: seconds }
}

/* The signed-in answer the session door gives; an expired session
 * rides options.sessionStatus = 401 through the same branch. */
function sessionBody() {
    return { status: 'success', username: 'david', is_admin: 1, exp: 2200000000 }
}

/* The runtime-stats payload as cgi-bin/runtime.pm ships it (the
 * endpoint is JWT-gated, any bearer token reads it): a runtime block
 * with host loadavg/memory/disk figures and the agent version census —
 * grouped {agent_version, count} rows, the never-reported group under
 * a null key that must print as a deliberate 'never reported a
 * version' line, not disappear. */
function statsBody() {
    return {
        status: 'success',
        runtime: {
            host: {
                scope: 'host (container shares the host kernel and mounts)',
                uptime_seconds: 123456,
                loadavg: { one_min: 0.05, five_min: 0.08, fifteen_min: 0.12 },
                memory: { total_kb: 8192000, available_kb: 3145600 },
                disk: { path: '/srv', total_kb: 41943040, used_kb: 16384000, avail_kb: 25559040, use_pct: 39 }
            },
            monitoring: {
                agents_total: 2, agents_reporting: 1, agents_stale: 1,
                reporting_window: '1h',
                last_collection: '2026-10-10 05:00:03',
                last_service_check: null
            },
            agent_versions: [
                { agent_version: '1.0.0', count: 1 },
                { agent_version: null, count: 1 }
            ]
        }
    }
}

/* session.js reads the JWT straight from the tab's sessionStorage;
 * this is the signed-in state. */
function signIn() {
    sessionStorage.setItem('wanportal.jwt', 'test-token')
}

/* The five doors answer through one keyed stub; options.fail makes a
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
        if (url === '/cgi-bin/api/services') {
            if (fail.services) throw fail.services
            return jsonReply(options.services || servicesBody())
        }
        if (url === '/cgi-bin/api/health') {
            if (fail.health) throw fail.health
            return jsonReply(options.healthBody || healthBody(options.uptimeSeconds))
        }
        if (url === '/cgi-bin/api/session') {
            if (fail.session) throw fail.session
            return jsonReply(options.sessionBody || sessionBody(),
                options.sessionOk !== false, options.sessionStatus || 200)
        }
        if (url === '/cgi-bin/api/runtime-stats') {
            if (fail.stats) throw fail.stats
            if (options.statsStatus) {
                return jsonReply(options.statsBody || { status: 'error' }, false, options.statsStatus)
            }
            return jsonReply(options.statsBody || statsBody())
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

/* The classic table had no hrefs and none may sneak back — the single
 * sanctioned link is the toolbar's repo door, which must always ride
 * the same external-door exception class as the service URIs
 * (target=_blank, rel=noopener noreferrer). */
function expectOnlyRepoDoor(wrapper) {
    const doors = wrapper.findAll('a')
    expect(doors).toHaveLength(1)
    expect(doors[0].attributes('href')).toBe('https://github.com/dna5rm/wanportal')
    expect(doors[0].attributes('rel')).toBe('noopener noreferrer')
    expect(wrapper.text()).not.toContain('.php')
}

describe('ServerView with a healthy api', () => {
    it('hits all five doors with the urls the page ships', async () => {
        const { wrapper, stub } = await mountServer()

        const urls = stub.mock.calls.map((call) => call[0])
        expect(urls).toContain('/cgi-bin/api/agents')
        expect(urls).toContain('/cgi-bin/api/targets')
        expect(urls).toContain('/cgi-bin/api/monitors')
        expect(urls).toContain('/cgi-bin/api/services')
        expect(urls).toContain('/cgi-bin/api/health')
        expect(stub).toHaveBeenCalledTimes(5)

        // No banner when every door came back.
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('keeps the toolbar slim: no second title, updated stamp, refresh button', async () => {
        const { wrapper } = await mountServer()

        // The account bar already names the page (Runtime), so the h1
        // is gone; the toolbar keeps the updated stamp and the one
        // refresh control.
        expect(wrapper.find('h1').exists()).toBe(false)
        expect(wrapper.find('header.bar button.btn').text()).toBe('refresh now')
        expect(wrapper.text()).toMatch(/updated \d{2}:\d{2}:\d{2}/)
        // No tagline, no "bundled vue" copy anywhere.
        expect(wrapper.text()).not.toContain('bundled vue')
    })

    it('opens the repo door leftmost in the bar as the one new-tab link', async () => {
        const { wrapper } = await mountServer()

        const repo = wrapper.find('header.bar a.doc-icon')
        expect(repo.exists()).toBe(true)
        expect(repo.classes()).toContain('btn')
        expect(repo.attributes('href')).toBe('https://github.com/dna5rm/wanportal')
        expect(repo.attributes('target')).toBe('_blank')
        expect(repo.attributes('rel')).toBe('noopener noreferrer')
        expect(repo.attributes('title')).toBe('wanportal source on GitHub')
        expect(repo.attributes('aria-label')).toBe('wanportal source on GitHub')

        // A stable anchor leftmost in the cluster, ahead of the stamp
        // that only exists once a door has answered.
        const cluster = wrapper.find('header.bar .bar-right')
        expect(cluster.element.firstElementChild).toBe(repo.element)

        // The octocat rides inline, hidden from screen readers that
        // already have the aria-label; no icon library, no img.
        const mark = repo.find('svg')
        expect(mark.exists()).toBe(true)
        expect(mark.attributes('aria-hidden')).toBe('true')

        // It is the only new-tab door on the page, and the refresh
        // control stays a plain same-tab button beside it.
        expect(wrapper.findAll('a[target="_blank"]')).toHaveLength(1)
        const btn = wrapper.find('header.bar button.btn')
        expect(btn.exists()).toBe(true)
        expect(btn.attributes('type')).toBe('button')
    })

    it('counts active/disabled/total per door like the classic table', async () => {
        const { wrapper } = await mountServer()

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(4)

        const cells = (row) => row.findAll('td').map((td) => td.text())
        expect(rows[0].find('td').text()).toBe('agents')
        expect(cells(rows[0])).toEqual(['agents', '1', '1', '2'])
        expect(cells(rows[1])).toEqual(['targets', '1', '1', '2'])
        expect(cells(rows[2])).toEqual(['monitors', '2', '1', '3'])
        expect(cells(rows[3])).toEqual(['services', '1', '2', '3'])

        const heads = wrapper.findAll('thead th').map((th) => th.text())
        expect(heads).toEqual(['', 'active', 'disabled', 'total'])

        expectOnlyRepoDoor(wrapper)
    })

    it('reports agent freshness from the listing heartbeats', async () => {
        const { wrapper } = await mountServer()

        // core heartbeated 5 minutes ago, sleepy 2 hours ago — the
        // default listing, so 1 reporting of 2 with 1 stale.
        expect(wrapper.find('.runtime').text()).toContain('agents reporting: 1 of 2 (1 stale)')
    })

    it('counts reporting agents by heartbeat, not by is_active', async () => {
        const { wrapper } = await mountServer({
            agents: {
                status: 'success',
                agents: [
                    { id: A1, name: 'core', is_active: 1, last_seen: localStamp(5 * 60000) },
                    { id: A2, name: 'sleepy', is_active: 0, last_seen: localStamp(2 * 3600000) },
                    { id: A3, name: 'fresh and disabled', is_active: 0, last_seen: localStamp(30 * 60000) }
                ]
            }
        })

        // A disabled agent that still heartbeats counts as reporting,
        // and the two counts always add up to the whole listing.
        expect(wrapper.text()).toContain('agents reporting: 2 of 3 (1 stale)')
    })

    it('stamps data fresh from the newest monitor last_update', async () => {
        const { wrapper } = await mountServer()

        // hq uplink updated 2 minutes ago is the newest stamp; the
        // 90-minute and 3-day ones must not win, and the age rides the
        // dashboard's duration format.
        const newest = localStamp(2 * 60000)
        const runtime = wrapper.find('.runtime')
        expect(runtime.text()).toContain('data fresh as of ' + newest + ' utc (2m)')
        expect(runtime.text()).not.toContain(localStamp(3 * 86400000) + ' utc')
    })

    it('renders no data-fresh line when no monitor stamp parses', async () => {
        const { wrapper } = await mountServer({
            monitors: {
                status: 'success',
                monitors: [
                    { id: 'aaaaaaaa-0000-4000-8000-000000000021', description: 'no stamp', is_active: 1 },
                    { id: 'aaaaaaaa-0000-4000-8000-000000000022', description: 'junk stamp', is_active: 1, last_update: 'yesterday' }
                ]
            }
        })

        // No parseable stamp anywhere in the payload — no line rather
        // than a fake one.
        expect(wrapper.text()).not.toContain('data fresh as of')
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
            await wrapper.find('header.bar button.btn').trigger('click')
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
        await wrapper.find('header.bar button.btn').trigger('click')
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
        expect(rows[3].findAll('td').map((td) => td.text())).toEqual(['services', '1', '2', '3'])
        expect(wrapper.text()).toContain('uptime: 3 days, 2 hours, 5 minutes')
    })

    it('names the uptime door and keeps its last good value', async () => {
        const options = {}
        const { wrapper } = await mountServer(options)

        options.fail = { health: new Error('HTTP 503') }
        await wrapper.find('header.bar button.btn').trigger('click')
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

    it('shows no numbers for services when its first fetch fails', async () => {
        const { wrapper } = await mountServer({
            fail: { services: new Error('HTTP 503') }
        })

        // services died on the first load: no numbers exist, so none may
        // be shown — its fourth row carries 'fetch failed' too.
        const rows = wrapper.findAll('tbody tr')
        const serviceCells = rows[3].findAll('td')
        expect(serviceCells).toHaveLength(2)
        expect(serviceCells[1].text()).toBe('fetch failed')

        // The older doors still render their counts, and the banner
        // names the new door like it names any other.
        expect(rows[0].findAll('td').map((td) => td.text())).toEqual(['agents', '1', '1', '2'])
        expect(wrapper.find('.banner.banner-warn').text()).toContain('services')
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
        expectOnlyRepoDoor(wrapper)
    })
})

describe('ServerView mixed auth: the gated runtime-stats door', () => {
    it('asks for nothing gated when nobody is signed in', async () => {
        const { wrapper, stub } = await mountServer()

        // Anonymous traffic is exactly the five public doors — no
        // session probe, no stats fetch, today's page untouched.
        const urls = stub.mock.calls.map((call) => call[0])
        expect(urls).not.toContain('/cgi-bin/api/session')
        expect(urls).not.toContain('/cgi-bin/api/runtime-stats')
        expect(stub).toHaveBeenCalledTimes(5)

        // And the page is complete without the gated blocks.
        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['runtime', 'statistics'])
        expect(wrapper.text()).not.toContain('load:')
        expect(wrapper.text()).not.toContain('agent versions')
    })

    it('opens the gated door for a signed-in tab and renders host + versions', async () => {
        signIn()
        const { wrapper, stub } = await mountServer()

        // Token present: the session door validates it first, then the
        // stats door answers, and the public five still fire alongside.
        const urls = stub.mock.calls.map((call) => call[0])
        expect(urls).toContain('/cgi-bin/api/session')
        expect(urls).toContain('/cgi-bin/api/runtime-stats')
        expect(urls).toContain('/cgi-bin/api/agents')
        expect(urls).toContain('/cgi-bin/api/health')

        // The host block renders what the shipped endpoint carries: the
        // loadavg trio, memory percent computed from total vs available
        // kB, and the disk row's own use_pct.
        expect(wrapper.findAll('h2').map((h) => h.text()))
            .toEqual(['runtime', 'statistics', 'host', 'agent versions'])
        expect(wrapper.text()).toContain('load: 0.05 0.08 0.12')
        expect(wrapper.text()).toContain('memory: 62%')
        expect(wrapper.text()).toContain('disk: 39%')

        // The version census reads as whole lines: the counted group
        // names its version, the never-reported group explains itself
        // instead of leaving a bare 'unknown', and a healthy gated call
        // raises no banner.
        expect(wrapper.text()).toContain('1 agent on 1.0.0')
        expect(wrapper.text()).toContain('1 agent on unknown (never reported a version)')
        const versionPanel = wrapper.findAll('section.panel')[3]
        expect(versionPanel.find('h2').text()).toBe('agent versions')
        expect(wrapper.find('.banner').exists()).toBe(false)

        // A manual refresh keeps both halves of the mixed-auth page
        // fresh together.
        await wrapper.find('header.bar button.btn').trigger('click')
        await flushPromises()
        await flushPromises()
        const statsCalls = stub.mock.calls.filter((call) => call[0] === '/cgi-bin/api/runtime-stats')
        expect(statsCalls).toHaveLength(2)
    })

    it('pluralizes the census line when several agents share a version', async () => {
        signIn()
        const { wrapper } = await mountServer({
            statsBody: {
                status: 'success',
                runtime: {
                    agent_versions: [
                        { agent_version: '0.2.0', count: 2 },
                        { agent_version: null, count: 1 }
                    ]
                }
            }
        })

        // Two agents on one build read as a plural sentence, and the
        // pair-shaped rendering that needed decoding is gone: the line
        // states the count, the noun and the version on its own.
        expect(wrapper.text()).toContain('2 agents on 0.2.0')
        expect(wrapper.text()).toContain('1 agent on unknown (never reported a version)')
        expect(wrapper.text()).not.toContain('2 agent on')
        expect(wrapper.text()).not.toContain('0.2.0: 2')
    })

    it('renders nothing extra when the gated door 404s or speaks nonsense', async () => {
        // An api old enough to predate the door: the signed-in call
        // 404s, the block simply never shows, no banner either.
        signIn()
        const oldApi = await mountServer({ statsStatus: 404 })
        expect(oldApi.wrapper.find('.banner').exists()).toBe(false)
        expect(oldApi.wrapper.findAll('h2').map((h) => h.text())).toEqual(['runtime', 'statistics'])
        expect(oldApi.wrapper.text()).toContain('agents reporting: 1 of 2 (1 stale)')
        expect(oldApi.wrapper.text()).toContain('data fresh as of ')

        sessionStorage.clear()

        // Garbage payload: the envelope says success but carries no
        // block — same silence.
        signIn()
        const junk = await mountServer({ statsBody: { status: 'success' } })
        expect(junk.wrapper.find('.banner').exists()).toBe(false)
        expect(junk.wrapper.findAll('h2').map((h) => h.text())).toEqual(['runtime', 'statistics'])
    })

    it('reads tolerant older shapes of the gated payload too', async () => {
        signIn()
        const { wrapper } = await mountServer({
            statsBody: {
                status: 'success',
                runtime_stats: {
                    host: { load: [0.1, 0.2, 0.3], memory: { percent: 42 }, disk: { used_percent: 38 } },
                    agents: [
                        { name: 'core', agent_version: '1.0.0' },
                        { name: 'sleepy', version: '0.9.2' },
                        { name: 'ghost' }
                    ]
                }
            }
        })

        // The runtime_stats envelope, a load array, direct percent
        // spellings, and per-agent versions (agent_version and version)
        // all render, each line naming its agent — visibly its own
        // shape, never a census count; a row naming no version drops
        // out.
        expect(wrapper.text()).toContain('load: 0.1 0.2 0.3')
        expect(wrapper.text()).toContain('memory: 42%')
        expect(wrapper.text()).toContain('disk: 38%')
        expect(wrapper.text()).toContain('agent core — 1.0.0')
        expect(wrapper.text()).toContain('agent sleepy — 0.9.2')
        const versionPanel = wrapper.findAll('section.panel')[3]
        expect(versionPanel.text()).not.toContain('ghost')
    })

    it('drops the gated block and the dead token when the session expired', async () => {
        signIn()
        const { wrapper, stub } = await mountServer({ sessionStatus: 401 })

        const urls = stub.mock.calls.map((call) => call[0])
        expect(urls).toContain('/cgi-bin/api/session')
        expect(urls).not.toContain('/cgi-bin/api/runtime-stats')
        // session.js dropped the expired token, so the tab is truly
        // signed out and the stats door never opens; the public page
        // stands unharmed.
        expect(sessionStorage.getItem('wanportal.jwt')).toBe(null)
        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['runtime', 'statistics'])
        expect(wrapper.text()).toContain('agents reporting: 1 of 2 (1 stale)')
    })
})

describe('ServerView refresh rhythm', () => {
    it('re-fires all five doors every 300s like the classic meta refresh', async () => {
        vi.useFakeTimers()
        try {
            const stub = makeServerFetch({})
            vi.stubGlobal('fetch', stub)
            const wrapper = mount(ServerView)
            await flushPromises()
            await flushPromises()
            const afterMount = stub.mock.calls.length
            expect(afterMount).toBe(5)

            await vi.advanceTimersByTimeAsync(300000)
            await flushPromises()
            await flushPromises()
            expect(stub.mock.calls.length).toBe(afterMount + 5)

            // Leaving the page stops the timer.
            wrapper.unmount()
            await vi.advanceTimersByTimeAsync(300000)
            expect(stub.mock.calls.length).toBe(afterMount + 5)
        } finally {
            vi.useRealTimers()
            vi.unstubAllGlobals()
        }
    })
})