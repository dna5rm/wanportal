/*
 * TargetEditView is the ported targets_edit.php: one component for
 * both doors — no id prop means create (POST /cgi-bin/api/target),
 * an id prop means edit (GET then PUT /cgi-bin/api/target/:id). The
 * address field must accept the classic trio — ipv4, ipv6, hostname —
 * without complaint, so the spec drives submit through one of each
 * and expects the POST to leave, not an err-note. The ipv6 pattern
 * lives in a string-built RegExp because the classic literal does not
 * compile as JS; a broken one would take the whole module down with
 * it, which is exactly what these mounts would catch.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TargetEditView from '../components/TargetEditView.vue'
import { T5, jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    // jsdom keeps one window per spec file, so a history state left
    // behind would flip the next leaveForm call into its back branch.
    window.history.replaceState(null, '')
    vi.unstubAllGlobals()
})

/* Mount the editor the way the router would: create mode passes no
 * id prop. calls records every fetch (url, method, raw body) so
 * payloads are assertable verbatim. */
async function mountEditor(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/login', name: 'login', component: { render: () => null } },
            { path: '/targets', name: 'targets', component: { render: () => null } },
            { path: '/targets/new', name: 'target-new', component: { render: () => null } },
            { path: '/targets/:id/edit', name: 'target-edit', component: { render: () => null }, props: true },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true }
        ]
    })
    /* A prior route (via) stands in for wherever the operator opened
     * the form from, so router.back() has somewhere to land. */
    if (options.via) await router.push(options.via)
    await router.push('/targets/new')
    await router.isReady()

    const calls = []
    const stub = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET'
        calls.push({ url, method, body: init.body })
        if (url === '/cgi-bin/api/session') {
            if (options.sessionFails) throw options.sessionFails
            return jsonReply(options.sessionBody || adminSession(), true, options.sessionStatus ?? 200)
        }
        if (url === '/cgi-bin/api/target' && method === 'POST') {
            if (options.saveFails) {
                return jsonReply(options.saveFails.body || { status: 'error', message: 'nope' },
                    false, options.saveFails.status ?? 400)
            }
            return jsonReply({ status: 'success', message: 'Target created successfully', id: T5 })
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(TargetEditView, {
        props: options.editId ? { id: options.editId } : {},
        global: { plugins: [router] }
    })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, calls, router }
}

/* Admin session claims, the shape GET /session answers with — the
 * view's gate needs authenticated + isAdmin to show the form. */
function adminSession() {
    return { status: 'success', username: 'ops-admin', is_admin: 1, exp: null }
}

describe('TargetEditView creating a target', () => {
    it('renders the new-target form with an empty address field', async () => {
        const { wrapper, calls } = await mountEditor()

        expect(wrapper.find('h1').text()).toContain('new target')
        expect(wrapper.find('#address').exists()).toBe(true)
        expect(wrapper.find('#address').element.value).toBe('')
        expect(wrapper.find('#description').element.value).toBe('')
        expect(wrapper.find('#is_active').element.checked).toBe(true)
        // Create mode has no record to load.
        expect(calls.filter(c => c.url.startsWith('/cgi-bin/api/target'))).toHaveLength(0)
    })

    it('accepts an ipv4, ipv6, and hostname without an address err-note', async () => {
        for (const addr of ['192.0.2.1', '::1', 'example.com']) {
            const { wrapper, calls, router } = await mountEditor()

            await wrapper.find('#address').setValue(addr)
            await wrapper.find('form').trigger('submit')
            await flushPromises()
            await flushPromises()

            // The address sailed past all three checks and left as a
            // POST; this bare mount has no history, so the form exit
            // falls back to the targets listing.
            expect(wrapper.find('.err-note').exists()).toBe(false)
            const post = calls.find(c => c.method === 'POST')
            expect(post.url).toBe('/cgi-bin/api/target')
            expect(JSON.parse(post.body)).toEqual({
                address: addr,
                description: '',
                is_active: 1
            })
            expect(router.currentRoute.value.path).toBe('/targets')
        }
    })

    it('returns to the page that opened the form when history has one', async () => {
        const { wrapper, router } = await mountEditor({ via: '/' })

        // Production's hash history mirrors window.history and carries
        // the previous SPA entry; the memory history here never writes
        // it, so the same shape is stubbed in for the back branch.
        window.history.replaceState({ back: '/' }, '')
        await wrapper.find('#address').setValue('192.0.2.1')
        await wrapper.find('form').trigger('submit')
        await flushPromises()
        await flushPromises()

        expect(router.currentRoute.value.path).toBe('/')
    })

    it('cancel obeys the same exit: back with history, list without', async () => {
        // No history in a bare mount: the listing fallback.
        const fresh = await mountEditor()
        const freshCancel = fresh.wrapper.findAll('button')
            .find((b) => b.text() === 'cancel')
        await freshCancel.trigger('click')
        await flushPromises()
        expect(fresh.router.currentRoute.value.path).toBe('/targets')

        // A real previous page: back to it, not the fallback.
        const withHistory = await mountEditor({ via: '/' })
        window.history.replaceState({ back: '/' }, '')
        const backCancel = withHistory.wrapper.findAll('button')
            .find((b) => b.text() === 'cancel')
        await backCancel.trigger('click')
        await flushPromises()
        expect(withHistory.router.currentRoute.value.path).toBe('/')
    })

    it('asks for an address before touching the api', async () => {
        const { wrapper, calls } = await mountEditor()

        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.find('.err-note').text()).toBe('address is required')
        expect(calls.filter(c => c.method === 'POST')).toHaveLength(0)
    })

    it('rejects a non-address without touching the api', async () => {
        const { wrapper, calls } = await mountEditor()

        await wrapper.find('#address').setValue('not an address!')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.find('.err-note').text())
            .toBe('address must be an ipv4, ipv6 address, or hostname')
        expect(calls.filter(c => c.method === 'POST')).toHaveLength(0)
    })
})