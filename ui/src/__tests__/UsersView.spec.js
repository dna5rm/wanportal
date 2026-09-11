/*
 * UsersView sits behind the login wall: a session probe runs first and
 * the table only ever wires up for an admin. Filters ride as query
 * params exactly like the classic page (empty values dropped), typing
 * is debounced with Enter applying at once, and a 401/403 from the
 * listing itself folds back to the gate instead of a generic error.
 * Both doors — the probe and the listing — are answered by one stubbed
 * fetch, keyed by url, same as the other specs.
 *
 * The show-inactive checkbox is the shared flag from prefs.js (the
 * session-flag stand-in): resolved URL > localStorage > false, and
 * persisted to both places on toggle. The fetch stub answers per url
 * and ignores the params, like the real API it stands in for — the
 * url assertions carry the filter contract, and both afterEach steps
 * reset the flag and the address bar between tests.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import UsersView from '../components/UsersView.vue'
import { SHOW_INACTIVE_KEY } from '../prefs'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
    // The shared flag is localStorage-backed and the toggle
    // round-trips the address bar — reset both between tests.
    localStorage.clear()
    window.history.replaceState(null, '', window.location.pathname)
})

/* An admin row and a half-registered one: blank name/email cells and
 * no last login, so the dash and "never" fallbacks get exercised. */
function usersBody() {
    return {
        status: 'success',
        users: [
            { id: 1, username: 'ops', full_name: 'Ops Person', email: 'ops@example', is_admin: 1, is_active: 1, last_login: '2026-09-07 18:00:00' },
            { id: 2, username: 'guest', full_name: null, email: null, is_admin: 0, is_active: 0, last_login: null }
        ]
    }
}

function adminSession() {
    return { status: 'success', username: 'ops-admin', is_admin: 1, exp: null }
}

/* Mount with the probe answered by options (status, body, or a thrown
 * error) and the listing breaking via failUsers. userUrls collects
 * every listing url the page asked for, in order. The memory router
 * mirrors the production table for the routes this page links to, so
 * the router-links resolve for real. localStorage is wiped at entry
 * as well as in afterEach: a previous test's unmount can flush a
 * pending filter save after the hook ran, and a restored filter would
 * silently re-narrow the listing under test. */
async function mountUsers(options = {}) {
    localStorage.clear()
    const userUrls = []
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/users', name: 'users', component: { render: () => null } },
            { path: '/users/new', name: 'user-new', component: { render: () => null } },
            { path: '/users/:id/edit', name: 'user-edit', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/session') {
            if (options.sessionFails) throw options.sessionFails
            return jsonReply(options.sessionBody || {}, true, options.sessionStatus ?? 200)
        }
        if (url.startsWith('/cgi-bin/api/users')) {
            userUrls.push(url)
            if (options.failUsers) throw options.failUsers
            return jsonReply(options.usersBody || usersBody())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(UsersView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, userUrls }
}

describe('UsersView for a signed-in admin', () => {
    it('loads the listing honoring the stored show-inactive choice', async () => {
        // The shared flag says show all users: no is_active param.
        localStorage.setItem(SHOW_INACTIVE_KEY, 'true')
        const { wrapper, stub, userUrls } = await mountUsers({ sessionBody: adminSession() })

        // The probe answers first, then the listing — once, unfiltered.
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/session')
        expect(userUrls).toEqual(['/cgi-bin/api/users'])
        expect(wrapper.find('input[type=checkbox]').element.checked).toBe(true)

        expect(wrapper.find('h2').text()).toContain('users')
        expect(wrapper.text()).toContain('(2)')

        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)

        // The admin row: full name, mailto link, role and status chips,
        // minute-resolution last login, edit inside the app.
        expect(rows[0].text()).toContain('ops')
        expect(rows[0].text()).toContain('Ops Person')
        expect(rows[0].find('a[href^="mailto:"]').attributes('href')).toBe('mailto:ops@example')
        expect(rows[0].find('.chip-danger').text()).toBe('admin')
        expect(rows[0].find('.chip-ok').text()).toBe('active')
        expect(rows[0].text()).toContain('2026-09-07 18:00')
        expect(rows[0].find('a[href="/users/1/edit"]').exists()).toBe(true)

        // The bar's new-user door opens the in-app editor too.
        expect(wrapper.find('.bar-right a[href="/users/new"]').exists()).toBe(true)

        // Blanks read as dashes, an inactive account dims its row.
        expect(rows[1].findAll('td')[1].text()).toBe('-')
        expect(rows[1].find('.chip-warn').text()).toBe('inactive')
        expect(rows[1].classes()).toContain('row-inactive')
        expect(rows[1].text()).toContain('never')
    })

    it('defaults to active only until the shared flag says otherwise', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })

        // Nothing stored and nothing in the URL: the classic session
        // default (false) applies, so the call narrows to active rows.
        expect(userUrls).toEqual(['/cgi-bin/api/users?is_active=1'])
        expect(wrapper.find('input[type=checkbox]').element.checked).toBe(false)
    })

    it('lets a show_inactive URL query override the stored choice', async () => {
        localStorage.setItem(SHOW_INACTIVE_KEY, 'true')
        window.history.replaceState(null, '', '/?show_inactive=false')

        const { userUrls } = await mountUsers({ sessionBody: adminSession() })

        // The URL wins like $_GET does for wanportal_get_show_inactive.
        expect(userUrls).toEqual(['/cgi-bin/api/users?is_active=1'])
    })

    it('debounces typing and applies the term on enter', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })
        expect(userUrls).toEqual(['/cgi-bin/api/users?is_active=1'])

        // Typing alone waits for the debounce — nothing is fetched yet.
        const input = wrapper.find('input[type=search]')
        await input.setValue('ops')
        expect(userUrls).toHaveLength(1)

        await input.trigger('keydown.enter')
        await flushPromises()
        await flushPromises()
        expect(userUrls).toEqual([
            '/cgi-bin/api/users?is_active=1',
            '/cgi-bin/api/users?q=ops&is_active=1'
        ])
    })

    it('rides the role and inactive filters as query params and persists the flag', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })
        const select = wrapper.find('select')

        // Active-only is the default, so the role filter rides with it.
        await select.setValue('1')
        await flushPromises()
        await flushPromises()
        expect(userUrls[1]).toBe('/cgi-bin/api/users?is_admin=1&is_active=1')

        // Checking "show inactive" drops the is_active param entirely —
        // every user comes back.
        await wrapper.find('input[type=checkbox]').setValue(true)
        await flushPromises()
        await flushPromises()
        expect(userUrls[2]).toBe('/cgi-bin/api/users?is_admin=1')

        // The checked state round-trips through localStorage and the
        // address bar, like the classic session write-back plus its
        // URL hook — without a reload.
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('true')
        expect(window.location.search).toBe('?show_inactive=true')

        // Back to every role with the flag still on.
        await select.setValue('')
        await flushPromises()
        await flushPromises()
        expect(userUrls[3]).toBe('/cgi-bin/api/users')

        // Unchecking narrows the call to active rows again.
        await wrapper.find('input[type=checkbox]').setValue(false)
        await flushPromises()
        await flushPromises()
        expect(userUrls[4]).toBe('/cgi-bin/api/users?is_active=1')
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('false')
        expect(window.location.search).toBe('?show_inactive=false')
    })

    it('restores q and role from the stored filter and applies them on load', async () => {
        // The shape the view itself writes through listingFilter.js:
        // q is the master field, role rides beside it.
        localStorage.setItem(
            'wanportal-filter-users',
            JSON.stringify({ q: 'ops', role: '1' })
        )
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })

        // The restored filters ride the very first listing call —
        // no fetch fires for the restoration, it seeds the state.
        expect(userUrls).toEqual(['/cgi-bin/api/users?q=ops&is_admin=1&is_active=1'])
        expect(wrapper.find('input[type=search]').element.value).toBe('ops')
        expect(wrapper.find('select').element.value).toBe('1')

        // Something to wipe: the clear button is up while a filter is set.
        expect(wrapper.find('button[aria-label="clear filters"]').exists()).toBe(true)
    })

    it('ignores a stored role outside the select\'s values', async () => {
        localStorage.setItem('wanportal-filter-users', '{"q":"","role":"7"}')
        const { userUrls } = await mountUsers({ sessionBody: adminSession() })

        // Junk role reads as the all-users default instead of
        // narrowing the listing to nothing.
        expect(userUrls).toEqual(['/cgi-bin/api/users?is_active=1'])
        expect(localStorage.getItem('wanportal-filter-users')).toBe('{"q":"","role":"7"}')
    })

    it('persists the role at once and the text debounced, flushing on unmount', async () => {
        const { wrapper } = await mountUsers({ sessionBody: adminSession() })

        // A role pick saves immediately, without waiting out a timer.
        await wrapper.find('select').setValue('0')
        await flushPromises()
        expect(localStorage.getItem('wanportal-filter-users'))
            .toBe('{"q":"","role":"0"}')

        // Typing debounces: nothing stored right after the keystroke.
        await wrapper.find('input[type=search]').setValue('ops')
        expect(localStorage.getItem('wanportal-filter-users'))
            .toBe('{"q":"","role":"0"}')

        // Leaving the page mid-debounce still lands the edit — the
        // unmount flush carries it, so the filter survives navigation.
        wrapper.unmount()
        expect(localStorage.getItem('wanportal-filter-users'))
            .toBe('{"q":"ops","role":"0"}')
    })

    it('persists the typed filter once the debounce settles', async () => {
        const { wrapper } = await mountUsers({ sessionBody: adminSession() })
        await wrapper.find('input[type=search]').setValue('ops')

        // The debounce the fetch already used (500ms) covers the save.
        await new Promise((resolve) => setTimeout(resolve, 550))
        expect(localStorage.getItem('wanportal-filter-users'))
            .toBe('{"q":"ops","role":""}')
    })

    it('clears the boxes and the stored key and refetches unfiltered', async () => {
        localStorage.setItem(
            'wanportal-filter-users',
            JSON.stringify({ q: 'ops', role: '1' })
        )
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })
        expect(userUrls[0]).toBe('/cgi-bin/api/users?q=ops&is_admin=1&is_active=1')

        await wrapper.find('button[aria-label="clear filters"]').trigger('click')
        await flushPromises()
        await flushPromises()

        // Back to the all-users, active-only default — and the key is
        // gone, not rewritten with the defaults.
        expect(userUrls[1]).toBe('/cgi-bin/api/users?is_active=1')
        expect(localStorage.getItem('wanportal-filter-users')).toBeNull()
        expect(wrapper.find('input[type=search]').element.value).toBe('')
        expect(wrapper.find('select').element.value).toBe('')
        expect(wrapper.find('button[aria-label="clear filters"]').exists()).toBe(false)
    })

    it('reports a plain failure without gating the page', async () => {
        const { wrapper } = await mountUsers({
            sessionBody: adminSession(),
            failUsers: new Error('HTTP 500')
        })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('users fetch failed')
        expect(banner.text()).toContain('HTTP 500')
        expect(wrapper.find('.err-note').text()).toContain('listing not trustworthy')
        // Not a 401/403, so no gate — the admin keeps the page.
        expect(wrapper.find('.gate').exists()).toBe(false)
    })
})

describe('UsersView behind the gate', () => {
    it('keeps the gate up and fetches nothing for a signed-out visitor', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionStatus: 401 })

        expect(userUrls).toEqual([])
        const gate = wrapper.find('.gate')
        expect(gate.exists()).toBe(true)
        expect(gate.text()).toContain('admin only')
        expect(gate.text()).toContain('this listing needs a signed-in admin')
        // Sign-in moved into the app: the gate links back to the
        // dashboard, never out to the classic login.php page.
        expect(gate.find('a[href="/login.php"]').exists()).toBe(false)
        expect(gate.find('a[href="#/"]').exists()).toBe(true)
        expect(wrapper.find('table').exists()).toBe(false)
    })

    it('tells a signed-in non-admin why the table stays hidden', async () => {
        const { wrapper, userUrls } = await mountUsers({
            sessionBody: { status: 'success', username: 'read-only', is_admin: 0, exp: null }
        })

        expect(userUrls).toEqual([])
        const gate = wrapper.find('.gate')
        expect(gate.text()).toContain('admin only')
        expect(gate.text()).toContain('signed in as read-only')
        expect(gate.text()).toContain('needs an admin token')
        expect(wrapper.find('table').exists()).toBe(false)
    })

    it('reports a failed session check instead of guessing', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionFails: new Error('connection refused') })

        expect(userUrls).toEqual([])
        const gate = wrapper.find('.gate')
        expect(gate.text()).toContain('session check failed (connection refused)')
        expect(gate.text()).toContain('the listing stays hidden rather than guessed')
        expect(wrapper.find('table').exists()).toBe(false)
    })

    it('folds back to the gate when the listing api refuses an admin', async () => {
        const { wrapper } = await mountUsers({
            sessionBody: adminSession(),
            failUsers: new Error('HTTP 401')
        })

        // The probe said admin, the listing disagreed — the api wins.
        const gate = wrapper.find('.gate')
        expect(gate.exists()).toBe(true)
        expect(gate.text()).toContain('the listing API refused this request')
        expect(gate.text()).toContain('signed in as ops-admin')
        expect(wrapper.find('table').exists()).toBe(false)
        // A refusal is not a fetch failure — no error banner on top.
        expect(wrapper.find('.banner').exists()).toBe(false)
    })
})