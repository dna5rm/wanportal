/*
 * ServiceEditView is the create/edit door for HTTP/S service checks
 * (spec §3.1), one component for both doors like the monitor editor.
 * The spec drives a create through a fully-populated form and expects
 * the POST to carry the exact §3.1 body — schedule fields included on
 * create only — and an edit to PUT the config back without them, since
 * the rrd file is sized by the schedule. The auth section must render
 * a credential picker and never a secret field: the service stores a
 * vault reference (§5.4), so the picker options expose id, name and
 * site and nothing else. Field gates (send/body, body/encoding, header
 * auth) are caught client-side before the api has to answer 400.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ServiceEditView from '../components/ServiceEditView.vue'
import { A1, T5, jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    // jsdom keeps one window per spec file, so a history state left
    // behind would flip the next leaveForm call into its back branch.
    window.history.replaceState(null, '')
    vi.unstubAllGlobals()
})

/* One service plus the vault entries the auth picker reads. The uuid
 * letters extend the sibling fakes: monitors a-, agents b-, targets
 * c-, so services are d- and credentials e-. C4 is deliberately
 * inactive — the picker must drop it. */
const S9 = 'dddddddd-0000-4000-8000-000000000009'
const C3 = 'eeeeeeee-0000-4000-8000-000000000003'
const C4 = 'eeeeeeee-0000-4000-8000-000000000004'
const A2 = 'bbbbbbbb-0000-4000-8000-000000000002'

/* Admin session claims, the shape GET /session answers with — the
 * view's gate needs authenticated to show the form. */
function adminSession() {
    return { status: 'success', username: 'ops-admin', is_admin: 1, exp: null }
}

/* The full §3.1 record as the detail api hands it back: https on an
 * override port, headers as the decoded json object, the F5 triple
 * with one regex flag set, tls verification OFF (the recorded case),
 * and a bearer credential by reference only. */
function serviceBody() {
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
            follow_redirects: 1, verify_tls: 0, timeout: 15,
            auth_type: 'bearer', auth_header_name: null,
            auth_credential_id: C3, credential_name: 'api token',
            pollcount: 1, pollinterval: 300, is_active: 1
        }
    }
}

/* Mount the editor the way the router would: create mode pushes the
 * new door, edit mode passes the record id as the prop. calls records
 * every fetch (url, method, raw body) so payloads are assertable
 * verbatim; options read on every call let a test flip one endpoint to
 * failing without touching the others. */
async function mountEditor(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/login', name: 'login', component: { render: () => null } },
            { path: '/services', name: 'services', component: { render: () => null } },
            { path: '/services/new', name: 'service-new', component: { render: () => null } },
            { path: '/services/:id/edit', name: 'service-edit', component: { render: () => null }, props: true },
            { path: '/services/:id', name: 'service', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/services/new')
    await router.isReady()

    const calls = []
    const stub = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET'
        calls.push({ url, method, body: init.body })
        if (url === '/cgi-bin/api/session') {
            return jsonReply(adminSession(), true, 200)
        }
        if (url === '/cgi-bin/api/agents') {
            const fail = (options.failLists || []).includes('agents')
            if (fail) throw new Error('HTTP 503')
            return jsonReply({
                status: 'success',
                agents: [
                    { id: A1, name: 'edge-a', is_active: 1 },
                    { id: A2, name: 'sleepy', is_active: 0 }
                ]
            })
        }
        if (url === '/cgi-bin/api/targets') {
            const fail = (options.failLists || []).includes('targets')
            if (fail) throw new Error('HTTP 503')
            return jsonReply({
                status: 'success',
                targets: [{ id: T5, address: 'branch-gw.example', is_active: 1 }]
            })
        }
        if (url === '/cgi-bin/api/credentials') {
            const fail = (options.failLists || []).includes('credentials')
            if (fail) throw new Error('HTTP 503')
            return jsonReply({
                status: 'success',
                credentials: [
                    { id: C3, name: 'api token', site: 'llm endpoint', is_active: 1 },
                    { id: C4, name: 'old cert', site: '', is_active: 0 }
                ]
            })
        }
        if (url === '/cgi-bin/api/service' && method === 'POST') {
            if (options.saveFails) {
                return jsonReply(options.saveFails.body || { status: 'error', message: 'nope' },
                    false, options.saveFails.status ?? 400)
            }
            return jsonReply({ status: 'success', message: 'Service created successfully', id: S9 })
        }
        if (url === '/cgi-bin/api/service/' + S9 && method === 'GET') {
            return jsonReply(serviceBody())
        }
        if (url === '/cgi-bin/api/service/' + S9 && method === 'PUT') {
            if (options.saveFails) {
                return jsonReply({ status: 'error', message: 'nope' }, false, options.saveFails.status ?? 400)
            }
            return jsonReply({ status: 'success', id: S9 })
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(ServiceEditView, {
        props: options.editId ? { id: options.editId } : {},
        global: { plugins: [router] }
    })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, calls, router }
}

/* The every-field create body, asserted verbatim below. The blanks post
 * as null (the api's NULL columns mean "not configured"), the port as
 * 0 (scheme default), and the schedule rides out on create only. */
function fullCreateBody() {
    return {
        description: 'llm probe',
        agent_id: A1,
        target_id: T5,
        scheme: 'https',
        port: 8443,
        uri_path: '/v1/chat',
        uri_query: 'model=a',
        http_method: 'POST',
        http_headers: { 'X-Ping': 'pong' },
        body_encoding: 'json',
        body: '{"ok":1}',
        send_string: null,
        receive_string: 'ok',
        receive_regex: true,
        disable_string: null,
        disable_regex: false,
        expected_status: '200-299',
        follow_redirects: false,
        verify_tls: true,
        timeout: 15,
        auth_type: 'bearer',
        auth_header_name: null,
        auth_credential_id: C3,
        pollcount: 1,
        pollinterval: 300
    }
}

/* Fill the selects the way a user would: open every door first, then
 * submit. Only agent and target are hard-required. */
async function fillRequired(wrapper) {
    await wrapper.find('#service-agent').setValue(A1)
    await wrapper.find('#service-target').setValue(T5)
}

describe('ServiceEditView creating a service', () => {
    it('renders the create form with the §3.1 defaults and loads the three pickers', async () => {
        const { wrapper, calls } = await mountEditor()

        expect(wrapper.find('h1').text()).toContain('new service')
        expect(wrapper.find('#service-desc').element.value).toBe('')
        expect(wrapper.find('#service-scheme').element.value).toBe('http')
        expect(wrapper.find('#service-port').element.value).toBe('')
        expect(wrapper.find('#service-path').element.value).toBe('/')
        expect(wrapper.find('#service-method').element.value).toBe('GET')
        expect(wrapper.find('#service-encoding').element.value).toBe('none')
        expect(wrapper.find('#service-tls').element.checked).toBe(true)
        expect(wrapper.find('#service-timeout').element.value).toBe('10')
        expect(wrapper.find('#service-pollcount').element.value).toBe('1')
        expect(wrapper.find('#service-pollinterval').element.value).toBe('300')

        // Inactive records drop out of the pickers — a check must not
        // point at something switched off.
        const agentTexts = wrapper.find('#service-agent').findAll('option').map((o) => o.text())
        expect(agentTexts).toContain('edge-a')
        expect(agentTexts).not.toContain('sleepy')

        // The auth picker is not on the page until an auth type wants
        // it — there is no credential field on a service that runs
        // unauthenticated.
        expect(wrapper.find('#service-auth-cred').exists()).toBe(false)

        // Three lists minus the session probe; no record fetch in
        // create mode.
        expect(calls.filter((c) => c.url.startsWith('/cgi-bin/api/service'))).toHaveLength(0)
        expect(calls.filter((c) => c.url === '/cgi-bin/api/agents')).toHaveLength(1)
        expect(calls.filter((c) => c.url === '/cgi-bin/api/targets')).toHaveLength(1)
        expect(calls.filter((c) => c.url === '/cgi-bin/api/credentials')).toHaveLength(1)
    })

    it('posts the §3.1 body to /cgi-bin/api/service and lands on the listing', async () => {
        const { wrapper, calls, router } = await mountEditor()
        await fillRequired(wrapper)

        await wrapper.find('#service-desc').setValue('llm probe')
        await wrapper.find('#service-scheme').setValue('https')
        await wrapper.find('#service-port').setValue('8443')
        await wrapper.find('#service-path').setValue('/v1/chat')
        await wrapper.find('#service-query').setValue('model=a')
        await wrapper.find('#service-method').setValue('POST')
        await wrapper.find('input[aria-label="Header name 1"]').setValue('X-Ping')
        await wrapper.find('input[aria-label="Header value 1"]').setValue('pong')
        await wrapper.find('#service-encoding').setValue('json')
        await wrapper.find('#service-body').setValue('{"ok":1}')
        await wrapper.find('#service-receive').setValue('ok')
        await wrapper.find('#service-receive-regex').setValue(true)
        await wrapper.find('#service-status').setValue('200-299')
        await wrapper.find('#service-timeout').setValue('15')
        await wrapper.find('#service-auth-type').setValue('bearer')
        await wrapper.find('#service-auth-cred').setValue(C3)

        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        expect(wrapper.find('.err-note').exists()).toBe(false)
        const post = calls.find((c) => c.method === 'POST')
        expect(post.url).toBe('/cgi-bin/api/service')
        expect(JSON.parse(post.body)).toEqual(fullCreateBody())

        // A bare mount has no history, so the create exit falls back
        // to the services listing.
        expect(router.currentRoute.value.path).toBe('/services')
    })

    it('posts the defaults when only the required picks are made', async () => {
        const { wrapper, calls } = await mountEditor()
        await fillRequired(wrapper)

        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        expect(wrapper.find('.err-note').exists()).toBe(false)
        const post = calls.find((c) => c.method === 'POST')
        expect(JSON.parse(post.body)).toEqual({
            description: '',
            agent_id: A1,
            target_id: T5,
            scheme: 'http',
            port: 0,                 // blank box = scheme default
            uri_path: '/',
            uri_query: '',
            http_method: 'GET',
            http_headers: null,      // blank name row drops out
            body_encoding: null,
            body: null,
            send_string: null,
            receive_string: null,
            receive_regex: false,
            disable_string: null,
            disable_regex: false,
            expected_status: null,   // any 2xx/3xx
            follow_redirects: false,
            verify_tls: true,
            timeout: 10,
            auth_type: 'none',
            auth_header_name: null,
            auth_credential_id: null,
            pollcount: 1,
            pollinterval: 300
        })
    })

    it('shows the admin banner when the api refuses the save', async () => {
        const { wrapper } = await mountEditor({ saveFails: { status: 403 } })
        await fillRequired(wrapper)

        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('admin rights required')
        // The failed save must not navigate anywhere.
        expect(wrapper.find('form').exists()).toBe(true)
    })

    it('says which lists failed to load instead of silently stale picks', async () => {
        const { wrapper } = await mountEditor({ failLists: ['credentials'] })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-warn')
        expect(banner.text()).toContain('could not load credentials')
        // Agents and targets still loaded — the form is usable.
        expect(wrapper.find('#service-agent').exists()).toBe(true)
    })
})

describe('ServiceEditView field gates', () => {
    it('asks for an agent and a target before touching the api', async () => {
        const { wrapper, calls } = await mountEditor()

        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.text()).toContain('Agent is required')
        expect(wrapper.text()).toContain('Target is required')
        expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0)
    })

    it('catches the send-string/body conflict before the api does', async () => {
        const { wrapper, calls } = await mountEditor()
        await fillRequired(wrapper)

        await wrapper.find('#service-send').setValue('alive check')
        await wrapper.find('#service-body').setValue('{"up":1}')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.text()).toContain('Send string and body are the same slot')
        expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0)
    })

    it('catches a body posted without an encoding', async () => {
        const { wrapper, calls } = await mountEditor()
        await fillRequired(wrapper)

        await wrapper.find('#service-body').setValue('ping')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.text()).toContain('Body needs an encoding')
        expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0)
    })

    it('catches header auth without a header name', async () => {
        const { wrapper, calls } = await mountEditor()
        await fillRequired(wrapper)

        await wrapper.find('#service-auth-type').setValue('header')
        expect(wrapper.find('#service-auth-header').exists()).toBe(true)
        await wrapper.find('#service-auth-cred').setValue(C3)
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.text()).toContain('Header name is required for header auth')
        expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0)
    })
})

describe('ServiceEditView editing a service', () => {
    it('fills the form from the detail api and PUTs the config without the schedule', async () => {
        const { wrapper, calls, router } = await mountEditor({ editId: S9 })

        expect(wrapper.find('h1').text()).toContain('edit service')
        expect(wrapper.find('#service-desc').element.value).toBe('llm backend probe')
        expect(wrapper.find('#service-scheme').element.value).toBe('https')
        expect(wrapper.find('#service-port').element.value).toBe('8443')
        expect(wrapper.find('#service-path').element.value).toBe('/v1/chat')
        expect(wrapper.find('#service-query').element.value).toBe('model=a')
        expect(wrapper.find('#service-method').element.value).toBe('POST')
        expect(wrapper.find('#service-encoding').element.value).toBe('json')
        expect(wrapper.find('#service-body').element.value).toBe('{"ok":1}')
        expect(wrapper.find('#service-receive').element.value).toBe('"ok"')
        expect(wrapper.find('#service-receive-regex').element.checked).toBe(true)
        expect(wrapper.find('#service-disable').element.value).toBe('maintenance')
        expect(wrapper.find('#service-disable-regex').element.checked).toBe(false)
        expect(wrapper.find('#service-status').element.value).toBe('200-299')
        expect(wrapper.find('#service-redirects').element.checked).toBe(true)
        // verify_tls 0 in the record: the box must read OFF, exactly
        // the recorded-unverified case the spec wants visible.
        expect(wrapper.find('#service-tls').element.checked).toBe(false)
        expect(wrapper.find('#service-timeout').element.value).toBe('15')
        expect(wrapper.find('#service-auth-type').element.value).toBe('bearer')
        expect(wrapper.find('#service-auth-cred').element.value).toBe(C3)

        // Both header rows decoded from the stored object.
        expect(wrapper.find('input[aria-label="Header name 1"]').element.value).toBe('Content-Type')
        expect(wrapper.find('input[aria-label="Header value 1"]').element.value).toBe('application/json')
        expect(wrapper.find('input[aria-label="Header name 2"]').element.value).toBe('X-Ping')

        // The schedule is create-only: no inputs, just the fixed note.
        expect(wrapper.find('#service-pollcount').exists()).toBe(false)
        expect(wrapper.find('#service-pollinterval').exists()).toBe(false)
        expect(wrapper.text()).toContain('poll schedule is fixed after create (1x every 300s)')

        // Touch exactly one field, then PUT.
        await wrapper.find('#service-desc').setValue('llm backend probe v2')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        const put = calls.find((c) => c.method === 'PUT')
        expect(put.url).toBe('/cgi-bin/api/service/' + S9)
        const body = JSON.parse(put.body)
        expect(body).toEqual({
            description: 'llm backend probe v2',
            agent_id: A1,
            target_id: T5,
            scheme: 'https',
            port: 8443,
            uri_path: '/v1/chat',
            uri_query: 'model=a',
            http_method: 'POST',
            http_headers: { 'Content-Type': 'application/json', 'X-Ping': 'pong' },
            body_encoding: 'json',
            body: '{"ok":1}',
            send_string: null,
            receive_string: '"ok"',
            receive_regex: true,
            disable_string: 'maintenance',
            disable_regex: false,
            expected_status: '200-299',
            follow_redirects: true,
            verify_tls: false,
            timeout: 15,
            auth_type: 'bearer',
            auth_header_name: null,
            auth_credential_id: C3
        })
        // The schedule keys must be absent, not zeroed — a PUT carrying
        // them would let an edit resize the rrd file's clock.
        expect('pollcount' in body).toBe(false)
        expect('pollinterval' in body).toBe(false)

        // An edit lands on the record's detail page.
        expect(router.currentRoute.value.path).toBe('/services/' + S9)
    })
})