/*
 * DashboardView is mounted with the real getJson under a stubbed
 * fetch, per endpoint, and a memory twin of the production route
 * table so the row links resolve to real hrefs. What matters here:
 * the slim toolbar carries no second title (the nav already
 * underlines Dashboard, so "bundled vue" and the page h1 are gone),
 * the page orders toolbar → agent chips → compact search → rollup
 * donut → down tables, the rollup renders as an inline SVG donut (no
 * chart library) with the total in its hole and a counts legend —
 * or a muted note when the estate is empty — a second services ring
 * reads the same payload's service counts, each donut names itself
 * with a leading sec-label the empty state keeps, the down-services
 * table mirrors the down-monitors one (and both show an honest empty
 * state when a payload from an api build without the services keys
 * arrives),
 * inactive agents are dropped, names link into the SPA detail pages
 * carrying the row's uuids, and when one endpoint fails the page says
 * so with a banner and an error note — without wiping the numbers the
 * healthy endpoints still delivered. The old top-5-slowest table is
 * gone, so the down tables are the only ones until the embedded
 * MonitorSearch is used; compact mode asks for nothing until a term is
 * submitted, then renders the /monitors?q= and /services?q= sweeps with
 * the stats line, dimmed inactive rows and detail links.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import DashboardView from '../components/DashboardView.vue'
import { A1, downBody, jsonReply, localStamp, M7, makeFetchStub, S1, T5 } from './stubs'
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
 * search's two sweeps for one submitted term (/monitors?q= and
 * /services?q=) are answered here too, from options.search and
 * options.searchServices (options.failSearch / options.failSvcSearch
 * make either throw) — the ?q= urls never collide with the down-list's
 * current_loss filter. */
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
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true },
            // The down-services rows link the service detail page, so
            // the twin carries it the way production's route table does.
            { path: '/services', name: 'services', component: { render: () => null } },
            { path: '/services/:id', name: 'service', component: { render: () => null }, props: true }
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
        if (url.startsWith('/cgi-bin/api/services?q=')) {
            if (options.failSvcSearch) throw options.failSvcSearch
            return jsonReply(options.searchServices || { status: 'success', services: [] })
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

/* The services half of the same term: one live hit with a real port
 * override and one disabled service under no agent, so the uri cell,
 * the state chips, the dim pass and every link rule get exercised on
 * the service table too. Uuid-shaped like the fake estate (services
 * dddddddd-). */
const SS1 = 'dddddddd-0000-4000-8000-000000000021'
const SS2 = 'dddddddd-0000-4000-8000-000000000022'

function searchServicesBody() {
    return {
        status: 'success',
        services: [
            { id: SS1, description: 'payments health', agent_id: SA1, agent_name: 'edge-a', target_id: ST1, target_address: 'hq-gw.example', scheme: 'https', port: 8443, uri_path: '/health', uri_query: '', last_state: 'UP', last_check: '2026-09-07 20:01:00', is_active: 1 },
            { id: SS2, description: null, agent_id: null, agent_name: 'edge-b', target_id: ST2, target_address: 'legacy-gw.example', scheme: 'http', port: 0, uri_path: '/', uri_query: '', last_state: 'DOWN', last_check: null, is_active: 0 }
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
        // The top-5-slowest panel is gone; the down tables remain.
        expect(wrapper.text()).not.toContain('top 5 slowest')
        expect(wrapper.findAll('tbody')).toHaveLength(2)
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

        // The two down tables close the page in order: monitors first,
        // services mirrored under it, same panel chrome for both.
        const panels = wrapper.findAll('section.panel')
        expect(panels[1].find('h2').text()).toBe('down monitors')
        expect(panels[2].find('h2').text()).toBe('down services')
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

        // Both donuts carry their naming label as the cluster's first
        // child — the empty state relies on that order staying put, so
        // the structure is pinned here in the healthy render too.
        const monLabel = wrapper.find('.pie-mon .sec-label')
        expect(monLabel.exists()).toBe(true)
        expect(monLabel.text()).toBe('monitors')
        expect(monLabel.element).toBe(wrapper.find('.pie-mon').element.firstElementChild)
        expect(wrapper.find('.pie-svc .sec-label').text()).toBe('services')
    })

    it('renders the services ring and down-services table from the same payload', async () => {
        const base = Date.now()
        const checked = localStamp(2 * 60000, base)
        const { wrapper } = await mountDashboard({
            dashboard: {
                status: 'success',
                dashboard: {
                    total: 1, up: 1, degraded: 0, down: 0,
                    percent_up: 100, percent_degraded: 0, percent_down: 0,
                    services_total: 4, services_up: 3, services_down: 1,
                    down_services: [
                        {
                            id: S1, description: 'payments portal',
                            agent_id: A1, agent_name: 'edge-a',
                            target_id: T5, target_address: 'branch-gw.example',
                            last_state: 'DOWN', last_status_code: 503,
                            last_reason: 'http_status', last_check: checked
                        }
                    ]
                }
            }
        })

        // Second ring, same arc math: 3 of 4 services up paints three
        // quarters of the circumference, the down quarter follows it,
        // and the services total sits in the hole like the monitor's.
        const cluster = wrapper.find('.pie-svc')
        const svcPie = cluster.find('svg.pie')
        expect(svcPie.exists()).toBe(true)
        expect(svcPie.attributes('aria-label')).toBe('up 3, down 1 of 4 services')
        expect(svcPie.find('.pie-total').text()).toBe('4')
        const svcUp = svcPie.find('.slice-up')
        expect(svcUp.attributes('stroke-dasharray')).toBe('75 25')
        expect(svcUp.attributes('stroke-dashoffset')).toBe('25')
        const svcDown = svcPie.find('.slice-down')
        expect(svcDown.attributes('stroke-dasharray')).toBe('25 75')
        expect(svcDown.attributes('stroke-dashoffset')).toBe('-50')
        expect(cluster.find('.legend-up .legend-num').text()).toBe('3')
        expect(cluster.find('.legend-down .legend-num').text()).toBe('1')

        // Down-services row mirrors the down-monitors linking: every
        // name with an id opens its detail page, the state chip reads
        // DOWN in the danger color with the reason on hover.
        const svcPanel = wrapper.findAll('section.panel')[2]
        const svcRows = svcPanel.findAll('tbody tr')
        expect(svcRows).toHaveLength(1)
        const cells = svcRows[0].findAll('td')
        expect(cells[0].find('a').attributes('href')).toBe('/services/' + S1)
        expect(cells[0].text()).toBe('payments portal')
        expect(cells[1].find('a').attributes('href')).toBe('/agents/' + A1)
        expect(cells[2].find('a').attributes('href')).toBe('/targets/' + T5)
        const chip = cells[3].find('.chip')
        expect(chip.classes()).toContain('chip-danger')
        expect(chip.text()).toBe('DOWN')
        expect(chip.attributes('title')).toBe('http_status')
        expect(cells[4].text()).toBe('503')
        // The last-check stamp reformats through the same helper the
        // down-since column uses.
        expect(cells[5].text()).toBe(fmtDownSince(checked))
    })

    it('shows zeros and empty states, not a throw, when the api predates services', async () => {
        const { wrapper } = await mountDashboard({
            dashboard: {
                status: 'success',
                dashboard: {
                    total: 1, up: 1, degraded: 0, down: 0,
                    percent_up: 100, percent_degraded: 0, percent_down: 0
                }
            }
        })

        // No services keys in the rollup: the monitor donut still
        // renders untouched, the services cluster says so instead of
        // drawing an empty ring, the down-services table opens with its
        // honest empty row — and nothing banners or blanks the page.
        expect(wrapper.find('svg.pie').exists()).toBe(true)
        expect(wrapper.find('.pie-svc svg').exists()).toBe(false)
        expect(wrapper.find('.pie-svc').text()).toContain('no services tracked yet')
        // The services label is part of the empty state, not hidden
        // behind the ring's v-if.
        expect(wrapper.find('.pie-svc .sec-label').text()).toBe('services')
        expect(wrapper.findAll('section.panel')[2].find('tbody').text())
            .toContain('no down services')
        expect(wrapper.find('.banner').exists()).toBe(false)
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
        // The naming rides out the empty estate: the label sits above
        // the muted note, not inside the template branch.
        expect(wrapper.find('.pie-mon .sec-label').text()).toBe('monitors')
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

        // The compact search panel stays rowless until a term is
        // submitted, so the two down tables are the only bodies now.
        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(2)

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
        expect(wrapper.findAll('tbody')).toHaveLength(2)

        // An empty (or whitespace) submit is a no-op, matching what
        // the classic page does with a blank term.
        await searchFor(wrapper, '   ')
        expect(stub).toHaveBeenCalledTimes(3)
        expect(wrapper.findAll('tbody')).toHaveLength(2)
    })

    it('renders the sweep with stats, dimming and detail links', async () => {
        const { wrapper, stub } = await mountDashboard({ search: searchBody() })

        await searchFor(wrapper, 'hq')

        expect(stub.mock.calls.some((c) => c[0] === '/cgi-bin/api/monitors?q=hq')).toBe(true)

        // The results table rides above the down tables.
        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(3)
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

        // Default stub services answer empty: the stats line still
        // reports the zero, and no services table renders for it.
        expect(wrapper.text()).toContain('0 services')
        expect(wrapper.find('.svc-label').exists()).toBe(false)
    })

    it('adds the services sweep: each hit links its service detail route', async () => {
        const { wrapper, stub } = await mountDashboard({
            search: searchBody(),
            searchServices: searchServicesBody()
        })

        await searchFor(wrapper, 'hq')

        expect(stub.mock.calls.some((c) => c[0] === '/cgi-bin/api/services?q=hq')).toBe(true)

        // One stats line covers both halves of the sweep, and the
        // services table rides under the monitor one — four bodies now:
        // sweep tables, then the two down tables.
        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(4)
        expect(wrapper.text()).toContain('2 results')
        expect(wrapper.text()).toContain('2 services')

        const rows = bodies[1].findAll('tr')
        expect(rows).toHaveLength(2)
        // Live service: uri carries the port override, state chip reads
        // UP, and every named cell opens its detail page.
        expect(rows[0].text()).toContain('payments health')
        expect(rows[0].text()).toContain('https://hq-gw.example:8443/health')
        expect(rows[0].text()).toContain('2026-09-07 20:01:00')
        expect(rows[0].find('.chip').classes()).toContain('chip-ok')
        expect(rows[0].findAll('td a').map((a) => a.attributes('href'))).toEqual([
            '/services/' + SS1, '/agents/' + SA1, '/targets/' + ST1
        ])
        // Disabled service keeps its place, dimmed, state still
        // readable; a blank description falls back to the id and only
        // the cells that carry an id link anywhere.
        expect(rows[1].classes()).toContain('dim')
        expect(rows[1].find('.chip').classes()).toContain('chip-danger')
        expect(rows[1].find('.chip').text()).toBe('DOWN')
        expect(rows[1].text()).toContain(SS2)
        expect(rows[1].text()).toContain('http://legacy-gw.example/')
        expect(rows[1].findAll('td a').map((a) => a.attributes('href'))).toEqual([
            '/services/' + SS2, '/targets/' + ST2
        ])
    })

    it('names a failed services sweep without hiding the monitor hits', async () => {
        const { wrapper } = await mountDashboard({
            search: searchBody(),
            failSvcSearch: new Error('HTTP 500')
        })

        await searchFor(wrapper, 'hq')

        const note = wrapper.find('.search-compact .err-note')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('service search failed')
        expect(note.text()).toContain('HTTP 500')

        // The monitor half stands untouched — its table keeps its rows
        // and the stats line just drops the services count — while no
        // services table appears either.
        const bodies = wrapper.findAll('tbody')
        expect(bodies).toHaveLength(3)
        expect(bodies[0].text()).toContain('hq uplink')
        const stats = wrapper.find('.search-compact p.muted')
        expect(stats.text()).toContain('2 results')
        expect(stats.text()).not.toContain('services')
        expect(wrapper.find('.svc-label').exists()).toBe(false)
    })

    it('names a failed sweep without disturbing the dashboard', async () => {
        const { wrapper } = await mountDashboard({ failSearch: new Error('HTTP 503') })

        await searchFor(wrapper, 'hq')

        const note = wrapper.find('.search-compact .err-note')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('search failed')
        expect(note.text()).toContain('HTTP 503')
        // The failed sweep hides its table and leaves the rollup alone.
        expect(wrapper.findAll('tbody')).toHaveLength(2)
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