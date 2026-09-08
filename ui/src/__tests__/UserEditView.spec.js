/*
 * UserEditView is the ported user_edit.php: one component for both
 * doors — no id prop means create (POST /cgi-bin/api/users), an id
 * prop means edit (GET then PUT /cgi-bin/api/users/:id). The fetch
 * stub answers per url+method like the other specs, and a memory
 * router mirrors the production table so the back/cancel router-links
 * resolve and the post-save push is assertable. The api's error
 * messages are part of the contract here: a 400 body must reach the
 * form as words, while a dead token folds the page back to the gate.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import UserEditView from '../components/UserEditView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

/* The api hands ids back as char(36) uuids; users get their own
 * d-prefix so the fixture estate stays unambiguous. */
const U1 = 'dddddddd-0000-4000-8000-000000000001'

/* One loaded record for edit mode — the shape GET /users/:id answers
 * with (ints for the flags, nulls where nothing happened yet). */
function userBody(overrides = {}) {
    return {
        id: U1,
        username: 'ops',
        full_name: 'Ops Person',
        email: 'ops@example',
        is_admin: 1,
        is_active: 1,
        last_login: '2026-09-07 18:00:00',
        created_at: '2026-01-01 10:00:00',
        created_by: 'system',
        updated_at: null,
        updated_by: null,
        failed_attempts: 0,
        locked_until: null,
        ...overrides
    }
}

function adminSession() {
    return { status: 'success', username: 'ops-admin', is_admin: 1, exp: null }
}

/* Mount the editor the way the router would: create mode passes no
 * id, edit mode passes the uuid as a prop. calls records every fetch
 * (url, method, raw body) so payloads are assertable verbatim. */
async function mountEditor(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/login', name: 'login', component: { render: () => null } },
            { path: '/users', name: 'users', component: { render: () => null } },
            { path: '/users/new', name: 'user-new', component: { render: () => null } },
            { path: '/users/:id/edit', name: 'user-edit', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/users/new')
    await router.isReady()

    const calls = []
    const stub = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET'
        calls.push({ url, method, body: init.body })
        if (url === '/cgi-bin/api/session') {
            if (options.sessionFails) throw options.sessionFails
            return jsonReply(options.sessionBody || {}, true, options.sessionStatus ?? 200)
        }
        if (url === '/cgi-bin/api/users' && method === 'POST') {
            if (options.saveFails) {
                return jsonReply(options.saveFails.body || { status: 'error', message: 'nope' },
                    false, options.saveFails.status ?? 400)
            }
            return jsonReply({ status: 'success', message: 'User created successfully', id: U1 })
        }
        if (url === '/cgi-bin/api/users/' + options.userId) {
            if (method === 'GET') {
                if (options.getFails) {
                    return jsonReply(options.getFails.body || { status: 'error', message: 'User not found' },
                        false, options.getFails.status ?? 404)
                }
                return jsonReply({ status: 'success', user: options.user || userBody() })
            }
            if (method === 'PUT') {
                if (options.saveFails) {
                    return jsonReply(options.saveFails.body || { status: 'error', message: 'nope' },
                        false, options.saveFails.status ?? 400)
                }
                return jsonReply({ status: 'success', message: 'User updated successfully', id: options.userId })
            }
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(UserEditView, {
        props: options.userId ? { id: options.userId } : {},
        global: { plugins: [router] }
    })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, calls, router }
}

describe('UserEditView creating a user', () => {
    it('renders an empty form and fetches nothing until saved', async () => {
        const { wrapper, calls } = await mountEditor({ sessionBody: adminSession() })

        expect(wrapper.find('h1').text()).toBe('new user')
        expect(wrapper.find('#f-username').element.value).toBe('')
        expect(wrapper.find('#f-is_active').element.checked).toBe(true)
        expect(wrapper.find('#f-is_admin').element.checked).toBe(false)
        // Create mode has no record to load and no info panel.
        expect(calls.filter(c => c.url.startsWith('/cgi-bin/api/users'))).toHaveLength(0)
        expect(wrapper.text()).not.toContain('account info')
    })

    it('posts the form fields once and returns to the listing', async () => {
        const { wrapper, calls, router } = await mountEditor({ sessionBody: adminSession() })

        await wrapper.find('#f-username').setValue('newbie')
        await wrapper.find('#f-password').setValue('plain-secret')
        await wrapper.find('#f-full_name').setValue('New Bie')
        await wrapper.find('#f-email').setValue('n@example')
        await wrapper.find('#f-is_admin').setValue(true)
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        const post = calls.find(c => c.method === 'POST')
        expect(post.url).toBe('/cgi-bin/api/users')
        expect(JSON.parse(post.body)).toEqual({
            username: 'newbie',
            full_name: 'New Bie',
            email: 'n@example',
            is_admin: true,
            is_active: true,
            password: 'plain-secret'
        })

        // Success lands back on the listing, and the secret is gone
        // from the form state already.
        expect(router.currentRoute.value.path).toBe('/users')
        expect(wrapper.find('#f-password').element.value).toBe('')
    })

    it('asks for a username and a password before touching the api', async () => {
        const { wrapper, calls } = await mountEditor({ sessionBody: adminSession() })

        // No username: the form says so and no request leaves.
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        expect(wrapper.find('.err-note').text()).toContain('username is required')
        expect(calls.filter(c => c.method === 'POST')).toHaveLength(0)

        // Username filled but no password: new users need one.
        await wrapper.find('#f-username').setValue('newbie')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        expect(wrapper.find('.err-note').text()).toContain('password is required for new users')
        expect(calls.filter(c => c.method === 'POST')).toHaveLength(0)
    })
})

describe('UserEditView editing a user', () => {
    it('loads the record, prefills the form, and PUTs without a blank password', async () => {
        const { wrapper, calls, router } = await mountEditor({
            sessionBody: adminSession(),
            userId: U1
        })

        expect(wrapper.find('h1').text()).toBe('edit user')
        expect(calls.some(c => c.method === 'GET' && c.url === '/cgi-bin/api/users/' + U1)).toBe(true)
        expect(wrapper.find('#f-username').element.value).toBe('ops')
        expect(wrapper.find('#f-full_name').element.value).toBe('Ops Person')
        expect(wrapper.find('#f-email').element.value).toBe('ops@example')
        expect(wrapper.find('#f-is_admin').element.checked).toBe(true)
        expect(wrapper.find('#f-is_active').element.checked).toBe(true)

        // The record facts panel is up and reads the loaded stamps.
        expect(wrapper.text()).toContain('account info')
        expect(wrapper.text()).toContain('2026-01-01 10:00')
        expect(wrapper.text()).toContain('by system')

        // Save with the password left blank: no password key rides
        // along, which is how the api knows to keep the current one.
        await wrapper.find('#f-full_name').setValue('Renamed Person')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        const put = calls.find(c => c.method === 'PUT')
        expect(put.url).toBe('/cgi-bin/api/users/' + U1)
        expect(JSON.parse(put.body)).toEqual({
            username: 'ops',
            full_name: 'Renamed Person',
            email: 'ops@example',
            is_admin: true,
            is_active: true
        })
        expect(router.currentRoute.value.path).toBe('/users')
    })

    it('sends a typed password on edit so the api rotates it', async () => {
        const { wrapper, calls } = await mountEditor({
            sessionBody: adminSession(),
            userId: U1
        })

        await wrapper.find('#f-password').setValue('Fresh-Pass1')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        const put = calls.find(c => c.method === 'PUT')
        expect(JSON.parse(put.body).password).toBe('Fresh-Pass1')
    })

    it('surfaces the api error message on the form and stays put', async () => {
        const { wrapper, calls, router } = await mountEditor({
            sessionBody: adminSession(),
            userId: U1,
            saveFails: { status: 400, body: { status: 'error', message: 'Password does not meet complexity requirements' } }
        })

        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        expect(calls.filter(c => c.method === 'PUT')).toHaveLength(1)
        expect(wrapper.find('.err-note').text()).toBe('save failed — Password does not meet complexity requirements')
        expect(wrapper.find('.gate').exists()).toBe(false)
        expect(router.currentRoute.value.path).toBe('/users/new')
    })

    it('keeps the form for policy refusals but gates on a dead token', async () => {
        // Policy refusal: the api says why in words, no gate.
        const policy = await mountEditor({
            sessionBody: adminSession(),
            userId: U1,
            saveFails: { status: 403, body: { status: 'error', message: 'Cannot modify admin user' } }
        })
        await policy.wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()
        expect(policy.wrapper.find('.err-note').text()).toContain('Cannot modify admin user')
        expect(policy.wrapper.find('.gate').exists()).toBe(false)

        // Dead token: the save folds the page back to the admin gate.
        const dead = await mountEditor({
            sessionBody: adminSession(),
            userId: U1,
            saveFails: { status: 401, body: { status: 'error', message: 'Unauthorized' } }
        })
        await dead.wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()
        expect(dead.wrapper.find('.gate').text()).toContain('the users API refused this request')
    })
})

describe('UserEditView protecting the built-in admin', () => {
    it('freezes the name and the switches when editing admin', async () => {
        const { wrapper } = await mountEditor({
            sessionBody: adminSession(),
            userId: U1,
            user: userBody({ username: 'admin', full_name: 'System Administrator', email: '' })
        })

        expect(wrapper.find('#f-username').attributes('readonly')).toBeDefined()
        expect(wrapper.find('#f-is_admin').attributes('disabled')).toBeDefined()
        expect(wrapper.find('#f-is_active').attributes('disabled')).toBeDefined()
        expect(wrapper.text()).toContain('built-in admin account is protected')

        // A normal user stays fully editable.
        const normal = await mountEditor({ sessionBody: adminSession(), userId: U1 })
        expect(normal.wrapper.find('#f-username').attributes('readonly')).toBeUndefined()
        expect(normal.wrapper.find('#f-is_admin').attributes('disabled')).toBeUndefined()
    })
})

describe('UserEditView behind the gate', () => {
    it('sends a signed-out visitor to the login route', async () => {
        const { router, calls } = await mountEditor({ sessionStatus: 401 })

        expect(router.currentRoute.value.path).toBe('/login')
        expect(calls.filter(c => c.url.startsWith('/cgi-bin/api/users'))).toHaveLength(0)
    })

    it('keeps a signed-in non-admin out of the form', async () => {
        const { wrapper, calls } = await mountEditor({
            sessionBody: { status: 'success', username: 'read-only', is_admin: 0, exp: null },
            userId: U1
        })

        const gate = wrapper.find('.gate')
        expect(gate.exists()).toBe(true)
        expect(gate.text()).toContain('admin only')
        expect(gate.text()).toContain('signed in as read-only')
        expect(gate.text()).toContain('needs an admin token')
        expect(wrapper.find('form').exists()).toBe(false)
        // The editor never even asks for the record.
        expect(calls.filter(c => c.url.startsWith('/cgi-bin/api/users'))).toHaveLength(0)
    })

    it('reports a failed session check instead of guessing', async () => {
        const { wrapper } = await mountEditor({ sessionFails: new Error('connection refused') })

        const gate = wrapper.find('.gate')
        expect(gate.exists()).toBe(true)
        expect(gate.text()).toContain('session check failed (connection refused)')
        expect(gate.text()).toContain('the form stays hidden rather than guessed')
        expect(wrapper.find('form').exists()).toBe(false)
    })

    it('folds to the gate when the detail api refuses an admin', async () => {
        const { wrapper } = await mountEditor({
            sessionBody: adminSession(),
            userId: U1,
            getFails: { status: 401, body: { status: 'error', message: 'Unauthorized' } }
        })

        const gate = wrapper.find('.gate')
        expect(gate.exists()).toBe(true)
        expect(gate.text()).toContain('the users API refused this request')
        expect(gate.text()).toContain('signed in as ops-admin')
        expect(wrapper.find('form').exists()).toBe(false)
    })
})

describe('UserEditView when the record is gone', () => {
    it('says the user was not found and hides the form', async () => {
        const { wrapper } = await mountEditor({
            sessionBody: adminSession(),
            userId: U1,
            getFails: { status: 404, body: { status: 'error', message: 'User not found' } }
        })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toBe('user fetch failed — user not found')
        expect(wrapper.find('form').exists()).toBe(false)
    })
})