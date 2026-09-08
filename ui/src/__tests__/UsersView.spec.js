/*
 * UsersView sits behind the login wall: a session probe runs first and
 * the table only ever wires up for an admin. Filters ride as query
 * params exactly like the classic page (empty values dropped), typing
 * is debounced with Enter applying at once, and a 401/403 from the
 * listing itself folds back to the gate instead of a generic error.
 * Both doors — the probe and the listing — are answered by one stubbed
 * fetch, keyed by url, same as the other specs.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import UsersView from '../components/UsersView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
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
 * the router-links resolve for real. */
async function mountUsers(options = {}) {
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
    it('loads the listing with default filters left off the url', async () => {
        const { wrapper, stub, userUrls } = await mountUsers({ sessionBody: adminSession() })

        // The probe answers first, then the listing — once, unfiltered.
        expect(stub.mock.calls[0][0]).toBe('/cgi-bin/api/session')
        expect(userUrls).toEqual(['/cgi-bin/api/users'])

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

    it('debounces typing and applies the term on enter', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })
        expect(userUrls).toEqual(['/cgi-bin/api/users'])

        // Typing alone waits for the debounce — nothing is fetched yet.
        const input = wrapper.find('input[type=search]')
        await input.setValue('ops')
        expect(userUrls).toHaveLength(1)

        await input.trigger('keydown.enter')
        await flushPromises()
        await flushPromises()
        expect(userUrls).toEqual(['/cgi-bin/api/users', '/cgi-bin/api/users?q=ops'])
    })

    it('rides the role and inactive filters as query params', async () => {
        const { wrapper, userUrls } = await mountUsers({ sessionBody: adminSession() })
        const select = wrapper.find('select')

        await select.setValue('1')
        await flushPromises()
        await flushPromises()
        expect(userUrls[1]).toBe('/cgi-bin/api/users?is_admin=1')

        // Back to every role: the empty value is dropped, not sent.
        await select.setValue('')
        await flushPromises()
        await flushPromises()
        expect(userUrls[2]).toBe('/cgi-bin/api/users')

        // Unchecking "show inactive" narrows the call to active rows.
        await wrapper.find('input[type=checkbox]').setValue(false)
        await flushPromises()
        await flushPromises()
        expect(userUrls[3]).toBe('/cgi-bin/api/users?is_active=1')

        // Both filters together, in the order the url builder sets them.
        await select.setValue('0')
        await flushPromises()
        await flushPromises()
        expect(userUrls[4]).toBe('/cgi-bin/api/users?is_admin=0&is_active=1')
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
        expect(gate.find('a[href="/login.php"]').exists()).toBe(true)
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