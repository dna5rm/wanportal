/*
 * DashboardView is mounted with the real getJson under a stubbed
 * fetch, per endpoint, and a memory twin of the production route
 * table so the row links resolve to real hrefs. What matters here:
 * the slim toolbar carries no second title (the nav already
 * underlines Dashboard, so "bundled vue" and the page h1 are gone),
 * the page orders toolbar → agent chips → compact search → rollup
 * donut → down table, the rollup renders as an inline SVG donut (no
 * chart library) with the total in its hole and a counts legend —
 * or a muted note when the estate is empty — inactive agents are
 * dropped, names link into the SPA detail pages carrying the row's
 * uuids, and when one endpoint fails the page says so with a banner
 * and an error note — without wiping the numbers the healthy
 * endpoints still delivered. The old top-5-slowest table is gone, so
 * the down table is the only one until the embedded MonitorSearch is
 * used; compact mode asks for nothing until a term is submitted, then
 * renders the /monitors?q= sweep with its stats line, dimmed inactive
 * rows and detail links.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import DashboardView from '../components/DashboardView.vue'
import { A1, downBody, jsonReply, localStamp, M7, makeFetchStub, T5 } from './stubs'
import { fmtDownSince } from '../format'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* Mount with fetch stubbed and the router installed, then let the
 * fetches and Vue's reactive updates settle before the test starts
 * looking at the DOM. Options is read on every fetch, so a test can
 * flip endpoints to failing between two refreshes. The dashboard's
 * three endpoints answer through the shared stub; the embedded
 * search's /monitors?q=<term> sweep is answered here too, from
 * options.search (options.failSearch makes it throw) — the ?q= urls
 * never collide with the down-list's current_loss filter. */
async function mountDashboard(options = {}) {
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

    const inner = makeFetchStub(options)
    const stub = vi.fn(async (url) => {
        if (url.startsWith('/cgi-bin/api/monitors?q=')) {
            if (options.failSearch) throw options.failSearch
            return jsonReply(options.search || { status: 'success', monitors: [] })
        }
        return inner(url)
    })
    vi.stubGlobal('fetch', stub)
    const wrapper = mount(DashboardView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub }
}

/* Type a term and submit the embedded search form. */
async function searchFor(wrapper, term) {
    await wrapper.find('input[type=search]').setValue(term)
    await wrapper.find('form.search-form').trigger('submit')
    await flushPromises()
    await flushPromises()
}

/* Two hits for the sweep: one live and one effectively inactive, so
 * the stats line, the dim class and both loss colors all get
 * exercised. Uuid-shaped like the rest of the fake estate
 * (monitors aaaaaaaa-, agents bbbbbbbb-, targets cccccccc-). */
const SA1 = 'bbbbbbbb-0000-4000-8000-000000000021'
const SM1 = 'aaaaaaaa-0000-4000-8000-000000000021'
const SM2 = 'aaaaaaaa-0000-4000-8000-000000000022'
const ST1 = 'cccccccc-0000-4000-8000-000000000021'
const ST2 = 'cccccccc-0000-4000-8000-000000000022'

function searchBody() {
    return {
        status: 'success',
        monitors: [
            { id: SM1, description: 'hq uplink', agent_id: SA1, agent_name: 'edge-a', target_id: ST1, target_address: 'hq-gw.example', protocol: 'tcp', port: 443, dscp: 46, current_median: 55.1, current_loss: 0, is_active: 1, last_update: '2026-09-07 20:00:00' },
            { id: SM2, description: null, agent_id: null, agent_name: 'edge-b', target_id: ST2, target_address: 'legacy-gw.example', protocol: 'icmp', current_median: 210, current_loss: 100, is_active: 0, last_update: null }
        ]
    }
}

/* Assert the page's section order: every listed element must sit
 * before the next one in the document (4 = Node.DOCUMENT_POSITION_
 * FOLLOWING). */
function expectSectionOrder(wrapper, selectors) {
    const els = selectors.map((sel) => wrapper.find(sel).element)
    for (let i = 0; i < els.length - 1; i++) {
        expect(els[i].compareDocumentPosition(els[i + 1]) & 4).toBeTruthy()
    }
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
        // The top-5-slowest panel is gone; only the down table remains.
        expect(wrapper.text()).not.toContain('top 5 slowest')
        expect(wrapper.findAll('tbody')).toHaveLength(1)
    })

    it('keeps the toolbar slim: no second dashboard title anywhere', async () => {
        const { wrapper } = await mountDashboard()

        // The nav already underlines "Dashboard", so the page h1 and
        // the old tagline are gone; the toolbar keeps only the
        // refresh/updated/stale cluster on the right.
        expect(wrapper.find('h1').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('bundled vue')
        // The refresh button is the bar's only control. (A compound
        // selector anchored on header.bar would never match: VTU
        // multi-root find() queries each root node's subtree, and the
        // header is itself a root node.)
        const toolbarBtn = wrapper.find('header.bar').find('.btn')
        expect(toolbarBtn.text()).toBe('refresh now')
    })

    it('lays the page out toolbar, agents, search, pie, down table', async () => {
        const { wrapper } = await mountDashboard()

        // The agent chips come first, above the compact search; the
        // pie sits between search and the down table. The search panel
        // is also a .panel, so the down table is picked out with :not.
        expectSectionOrder(wrapper, [
            'header.bar',
            '.agents',
            '.search-compact',
            '.pie-row',
            'section.panel:not(.search-compact)'
        ])
    })

    it('renders the rollup as an svg donut with the counts beside it', async () => {
        const { wrapper } = await mountDashboard()

        const pie = wrapper.find('svg.pie')
        expect(pie.exists()).toBe(true)

        // The total sits in the hole.
        expect(pie.find('.pie-total').text()).toBe('10')

        // Arcs on a circumference-100 ring, drawn top-clockwise in
        // up → degraded → down order (dashoffset 25 - start).
        const up = pie.find('.slice-up')
        expect(up.attributes('stroke-dasharray')).toBe('70 30')
        expect(up.attributes('stroke-dashoffset')).toBe('25')
        const degraded = pie.find('.slice-degraded')
        expect(degraded.attributes('stroke-dasharray')).toBe('20 80')
        expect(degraded.attributes('stroke-dashoffset')).toBe('-45')
        const downSlice = pie.find('.slice-down')
        expect(downSlice.attributes('stroke-dasharray')).toBe('10 90')
        expect(downSlice.attributes('stroke-dashoffset')).toBe('-65')

        // The legend carries every bucket's count, zeros included.
        const legend = wrapper.find('.pie-legend')
        expect(legend.find('.legend-up .legend-num').text()).toBe('7')
        expect(legend.find('.legend-degraded .legend-num').text()).toBe('2')
        expect(legend.find('.legend-down .legend-num').text()).toBe('1')
    })

    it('shows a muted note, not a broken pie, when the rollup is empty', async () => {
        const { wrapper } = await mountDashboard({
            dashboard: {
                status: 'success',
                dashboard: {
                    total: 0, up: 0, degraded: 0, down: 0,
                    percent_up: 0, percent_degraded: 0, percent_down: 0
                }
            }
        })

        expect(wrapper.find('svg.pie').exists()).toBe(false)
        expect(wrapper.find('.pie-row').text()).toContain('no monitors')
    })

    it('shows only active agents as chips', async () => {
        const { wrapper } = await mountDashboard()

        // The agents row is still on the page, now leading it.
        expect(wrapper.find('.agents').exists()).toBe(true)

        const chips = wrapper.findAll('.agents .chip')
        expect(chips).toHaveLength(1)
        expect(chips[0].text()).toBe('core')
        expect(chips[0].classes()).toContain('chip-ok')
        // The chip name opens the agent detail route.
        expect(chips[0].element.tagName).toBe('A')
        expect(chips[0].attributes('href')).toBe('/agents/' + A1)
        expect(wrapper.text()).not.toContain('sleepy')
    })

    it('fills the down table with the right colors and links', async () => {
        const base = Date.now()
        const { wrapper } = await mountDashboard({ down: downBody(base) })

        // Only the down table exists now: the compact search panel
        // stays rowless until a term is submitted.
        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(1)

        // Down table: four hours down paints warn, six hours danger,
        // and the since/duration cells come from the formatters.
        const downRows = bodies[0].findAll('tr')
        expect(downRows).toHaveLength(2)
        expect(downRows[0].classes()).toContain('row-warn')
        expect(downRows[1].classes()).toContain('row-danger')
        expect(downRows[0].findAll('td')[3].text()).toBe(fmtDownSince(localStamp(4 * 3600000, base)))
        expect(downRows[0].findAll('td')[4].text()).toBe('4h 0m')
        expect(downRows[1].findAll('td')[4].text()).toBe('6h 0m')

        // The down rows link all three names the same way the old
        // tables did: an id rides, no id stays plain text.
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
                    // The rollup still carries top_slow; the dashboard
                    // ignores it — 'mystery link' must not render.
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

        // No id, no link: the chip stays a plain span.
        const chip = wrapper.find('.agents .chip')
        expect(chip.element.tagName).toBe('SPAN')
        expect(chip.attributes('href')).toBeUndefined()
        expect(chip.text()).toBe('ghost')

        // The dropped widget's rows stay out of the page.
        expect(wrapper.text()).not.toContain('mystery link')

        const downCells = wrapper.findAll('tbody')[0].findAll('tr')[0].findAll('td')
        expect(downCells[0].find('a').exists()).toBe(false)
        expect(downCells[0].text()).toBe('ghost link')
        expect(downCells[1].text()).toBe('edge-x')
        expect(downCells[2].text()).toBe('x-gw.example')
    })
})

describe('DashboardView embedded search panel', () => {
    it('asks the api nothing until a real term is submitted', async () => {
        const { wrapper, stub } = await mountDashboard()
        expect(stub).toHaveBeenCalledTimes(3)   // the dashboard's own three calls

        // Compact embed: one form row, no heading and no empty-state
        // hint; the results table appears only after a search.
        expect(wrapper.find('form.search-form').exists()).toBe(true)
        expect(wrapper.find('.search-compact h2').exists()).toBe(false)
        expect(wrapper.find('.search-hint').exists()).toBe(false)
        expect(wrapper.findAll('tbody')).toHaveLength(1)

        // An empty (or whitespace) submit is a no-op, matching what
        // the classic page does with a blank term.
        await searchFor(wrapper, '   ')
        expect(stub).toHaveBeenCalledTimes(3)
        expect(wrapper.findAll('tbody')).toHaveLength(1)
    })

    it('renders the sweep with stats, dimming and detail links', async () => {
        const { wrapper, stub } = await mountDashboard({ search: searchBody() })

        await searchFor(wrapper, 'hq')

        expect(stub.mock.calls.some((c) => c[0] === '/cgi-bin/api/monitors?q=hq')).toBe(true)

        // The results table rides above the down table.
        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(2)
        expect(wrapper.text()).toContain('2 results')
        expect(wrapper.text()).toContain('1 effectively active')
        expect(wrapper.text()).toContain('1 effectively inactive')

        const rows = bodies[0].findAll('tr')
        expect(rows).toHaveLength(2)
        // Active row reads normally, inactive one is dimmed.
        expect(rows[0].classes()).not.toContain('dim')
        expect(rows[1].classes()).toContain('dim')
        expect(rows[0].text()).toContain('hq uplink')
        expect(rows[1].text()).toContain('edge-b')   // blank description, no agent id
        expect(rows[0].text()).toContain('55.1 ms')
        expect(rows[0].find('.chip').classes()).toContain('chip-ok')
        expect(rows[1].find('.chip').classes()).toContain('chip-danger')
        // Rows keep their detail links, riding the SPA routes.
        expect(rows[0].findAll('td a').map((a) => a.attributes('href'))).toEqual([
            '/monitors/' + SM1, '/agents/' + SA1, '/targets/' + ST1
        ])

        // The dashboard's own rollup is untouched by the sweep.
        expect(wrapper.find('svg.pie').exists()).toBe(true)
    })

    it('names a failed sweep without disturbing the dashboard', async () => {
        const { wrapper } = await mountDashboard({ failSearch: new Error('HTTP 503') })

        await searchFor(wrapper, 'hq')

        const note = wrapper.find('.search-compact .err-note')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('search failed')
        expect(note.text()).toContain('HTTP 503')
        // The failed sweep hides its table and leaves the rollup alone.
        expect(wrapper.findAll('tbody')).toHaveLength(1)
        expect(wrapper.find('svg.pie').exists()).toBe(true)
    })
})

describe('DashboardView when the api misbehaves', () => {
    it('keeps good data and says so when one endpoint fails', async () => {
        const options = { fail: {} }
        const { wrapper } = await mountDashboard(options)
        expect(wrapper.find('svg.pie').exists()).toBe(true)

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
        expect(wrapper.find('.legend-up .legend-num').text()).toBe('7')
        expect(wrapper.findAll('tbody')[0].findAll('tr')).toHaveLength(2)
    })

    it('shows the loud unreachable banner when nothing answers', async () => {
        const { wrapper } = await mountDashboard({
            fail: { every: new Error('connection refused') }
        })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('api unreachable')
        expect(banner.text()).toContain('connection refused')

        // No rollup means no pie row and an honest empty down table.
        expect(wrapper.find('.pie-row').exists()).toBe(false)
        expect(wrapper.text()).toContain('no down monitors')
        expect(wrapper.find('.agents .err-note').exists()).toBe(true)
        expect(wrapper.text()).toContain('down-list fetch failed')
    })
})