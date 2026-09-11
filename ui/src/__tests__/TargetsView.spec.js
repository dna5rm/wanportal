/*
 * TargetsView is the ported targets.php listing: one GET to
 * /cgi-bin/api/targets, inactive rows floated to the top like the
 * classic table's default order, row edits opening the in-app edit
 * form, the bar's New opening the in-app create form. Same
 * stubbed-fetch shape as the sibling listing specs, with a memory
 * router so the per-row <router-link> resolves for real, and the same
 * persistent-filter coverage: the filter reads on mount from the
 * wanportal-filter-targets key, writes there debounced (200ms) as the
 * user types, and the clear button — visible only while a filter is
 * set — empties both the box and the key (the 250ms settle waits out
 * the debounce with real timers, the way a real pause types).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TargetsView from '../components/TargetsView.vue'
import { getSession } from '../session'
import { jsonReply } from './stubs'
import { saveFilter } from '../listingFilter'

/* The session module is mocked at its door, but api.js rides the real
 * authHeaders/clearToken/getToken exports, so the original stays loaded
 * and only the probe is replaced. */
vi.mock('../session', async (importOriginal) => ({
    ...await importOriginal(),
    getSession: vi.fn()
}))

beforeEach(() => {
    // Write doors are signed-in only, so the default probe says admin
    // signed-in; the gate describe below flips it per case.
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
    // The listing filter rides localStorage across mounts; drop it so
    // a filter typed in one case cannot re-shape the next mount.
    localStorage.clear()
})

// Registered after the clear above on purpose: afterEach hooks run in
// reverse order, so the auto-unmount — whose onBeforeUnmount flushes a
// pending filter write — must run BEFORE the storage clear, or the
// flushed write leaks into the next case.
enableAutoUnmount(afterEach)

/* Target ids are uuids, same as every other detail id in the api. */
const T5 = 'cccccccc-0000-4000-8000-000000000005'
const T8 = 'cccccccc-0000-4000-8000-000000000008'

/* One active, one retired target. Api order puts the active one first
 * so the sort has something to flip. */
function targetsBody() {
    return {
        status: 'success',
        targets: [
            { id: T5, address: 'branch-gw.example', description: 'branch site', is_active: 1 },
            { id: T8, address: 'legacy-gw.example', description: 'retired site', is_active: 0 }
        ]
    }
}

/* Options is read on every fetch so a test can flip the endpoint to
 * failing between the first load and a refresh. */
async function mountListing(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/targets', name: 'targets', component: { render: () => null } },
            { path: '/targets/new', name: 'target-new', component: { render: () => null } },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true },
            { path: '/targets/:id/edit', name: 'target-edit', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/targets') {
            if (options.fail) throw options.fail
            return jsonReply(options.body || targetsBody())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(TargetsView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub }
}

describe('TargetsView', () => {
    it('fetches the listing once and renders both targets problems-first', async () => {
        const { wrapper, stub } = await mountListing()

        expect(stub).toHaveBeenCalledTimes(1)
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/targets')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)

        // The retired target leads; its title link opens the Vue
        // detail route for that target.
        expect(rows[0].find('td a').attributes('href')).toBe('/targets/' + T8)
        expect(rows[0].find('td a').text()).toBe('legacy-gw.example')
        expect(rows[1].find('td a').attributes('href')).toBe('/targets/' + T5)
        expect(rows[1].find('td a').text()).toBe('branch-gw.example')

        // Description reads straight from the payload.
        expect(rows[0].findAll('td')[1].text()).toBe('retired site')
        expect(rows[1].findAll('td')[1].text()).toBe('branch site')

        // Status chips and row dimming follow the classic wording.
        expect(rows[0].classes()).toContain('row-inactive')
        expect(rows[1].classes()).not.toContain('row-inactive')
        expect(rows[0].find('.chip').classes()).toContain('chip-warn')
        expect(rows[0].find('.chip').text()).toBe('Inactive')
        expect(rows[1].find('.chip').classes()).toContain('chip-ok')
        expect(rows[1].find('.chip').text()).toBe('Active')

        // Editing opens the in-app target form, per row and in the bar.
        expect(rows[0].find('td:last-child a').attributes('href')).toBe('/targets/' + T8 + '/edit')
        expect(rows[1].find('td:last-child a').attributes('href')).toBe('/targets/' + T5 + '/edit')
        expect(wrapper.find('.bar-right a.btn').attributes('href')).toBe('/targets/new')

        // A finished load stamps the bar with the refresh clock.
        expect(wrapper.find('.bar-right .muted').text()).toContain('updated')
    })

    it('says no targets when the api comes back empty', async () => {
        const { wrapper } = await mountListing({ body: { status: 'success', targets: [] } })

        expect(wrapper.find('table').exists()).toBe(true)
        expect(wrapper.text()).toContain('no targets')
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    })

    it('raises the loud banner when the first load fails', async () => {
        const { wrapper } = await mountListing({ fail: new Error('HTTP 503') })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('api unreachable')
        expect(banner.text()).toContain('HTTP 503')
        expect(wrapper.text()).toContain('no targets')
    })

    it('keeps the last good rows and says so when a refresh fails', async () => {
        const options = {}
        const { wrapper } = await mountListing(options)
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)

        options.fail = new Error('HTTP 503')
        await wrapper.find('button.btn').trigger('click')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('refresh failed')
        // The older rows stay visible — no blanking on a failed refresh.
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)
        expect(wrapper.text()).toContain('legacy-gw.example')
    })
})

describe('TargetsView write doors', () => {
    it('hides New Target and the per-row edit while signed out', async () => {
        getSession.mockResolvedValue({ authenticated: false, reason: 'signed-out' })
        const { wrapper } = await mountListing()

        // Reads stay public — the rows render, but no write door does.
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)
        expect(wrapper.find('tbody tr td:last-child a').exists()).toBe(false)
        expect(wrapper.find('.bar-right a[href="/targets/new"]').exists()).toBe(false)
    })

    it('shows New Target and the per-row edit once signed in', async () => {
        const { wrapper } = await mountListing()

        expect(wrapper.find('.bar-right a[href="/targets/new"]').exists()).toBe(true)
        expect(wrapper.find('tbody tr td:last-child a').exists()).toBe(true)
    })
})

describe('TargetsView persistent filter', () => {
    it('renders the filter input and no clear button while empty', async () => {
        const { wrapper } = await mountListing()

        const input = wrapper.find('.listing-filter input')
        expect(input.attributes('placeholder')).toBe('filter…')
        expect(input.element.value).toBe('')
        expect(wrapper.find('.listing-filter button').exists()).toBe(false)

        // An empty filter hides nothing.
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    })

    it('narrows the rows case-insensitively over any field', async () => {
        const { wrapper } = await mountListing()

        const input = wrapper.find('.listing-filter input')
        await input.setValue('BRANCH')
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
        expect(wrapper.find('tbody tr td a').text()).toBe('branch-gw.example')

        // Description matches too — the retired row's only hit.
        await input.setValue('retired')
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
        expect(wrapper.text()).toContain('legacy-gw.example')

        // A filter nothing matches reads as such, not as "no targets".
        await input.setValue('zzz')
        expect(wrapper.text()).toContain('no targets match')
    })

    it('persists the typed filter to storage once the debounce passes', async () => {
        const { wrapper } = await mountListing()

        await wrapper.find('.listing-filter input').setValue('branch')
        expect(localStorage.getItem('wanportal-filter-targets')).toBeNull()

        await new Promise((r) => setTimeout(r, 250))
        expect(localStorage.getItem('wanportal-filter-targets')).toBe('{"q":"branch"}')

        // The rows follow the typed filter immediately, not on reload.
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    })

    it('flushes a pending debounce when leaving mid-pause', async () => {
        const { wrapper } = await mountListing()

        // Typed, then away before the 200ms debounce can fire — the
        // unmount must flush the write, not drop it.
        await wrapper.find('.listing-filter input').setValue('branch')
        expect(localStorage.getItem('wanportal-filter-targets')).toBeNull()

        wrapper.unmount()
        expect(localStorage.getItem('wanportal-filter-targets')).toBe('{"q":"branch"}')
    })

    it('clear button empties the box and the storage for good', async () => {
        const { wrapper } = await mountListing()

        await wrapper.find('.listing-filter input').setValue('branch')
        await new Promise((r) => setTimeout(r, 250))
        expect(wrapper.find('.listing-filter button').exists()).toBe(true)

        await wrapper.find('.listing-filter button').trigger('click')
        expect(wrapper.find('.listing-filter input').element.value).toBe('')
        expect(wrapper.find('.listing-filter button').exists()).toBe(false)
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)

        // The key stays gone — the clear must not be re-persisted by
        // the same watch tick the emptying triggers.
        await new Promise((r) => setTimeout(r, 250))
        expect(localStorage.getItem('wanportal-filter-targets')).toBeNull()
    })

    it('re-reads a stored filter on mount, so it survives leaving', async () => {
        saveFilter('targets', { q: 'legacy' })

        const { wrapper } = await mountListing()

        expect(wrapper.find('.listing-filter input').element.value).toBe('legacy')
        expect(wrapper.findAll('tbody tr')).toHaveLength(1)
        expect(wrapper.text()).toContain('legacy-gw.example')
        expect(wrapper.text()).not.toContain('branch-gw.example')
    })
})