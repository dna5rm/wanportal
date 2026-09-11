/*
 * CredentialDetailView is the ported credential_view.php: identity
 * fields, reveal/copy for the stored secret (admins only — the api
 * omits the password for anyone else, so the row simply does not
 * render), metadata pretty-printed, and the record stamps. The
 * session probe gates the page and signed-out visitors are walked to
 * /login before the detail api is ever called.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import CredentialDetailView from '../components/CredentialDetailView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

const C1 = 'dddddddd-0000-4000-8000-000000000001'

/* Full record as an admin fetch returns it: secret present, metadata
 * as the json string the DB column hands back, no last-access yet. */
function detailBody(withSecret = true) {
    const credential = {
        id: C1,
        site: 'hq',
        name: 'core router',
        type: 'ACCOUNT',
        username: 'admin',
        url: 'https://hq.example',
        owner: 'netops',
        comment: 'core ssh login',
        expiry_date: '2027-01-01 00:00:00',
        is_active: 1,
        sensitivity: 'HIGH',
        metadata: '{"rack":"R12"}',
        created_at: '2026-01-05 09:30:00',
        created_by: 'ops-admin',
        updated_at: '2026-09-01 12:00:00',
        updated_by: 'ops-admin',
        last_accessed_at: null,
        last_accessed_by: null
    }
    if (!withSecret) delete credential.password
    else credential.password = 's3cret-value'
    return { status: 'success', credential }
}

async function mountDetail(options = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/credentials', name: 'credentials', component: { render: () => null } },
            { path: '/credentials/new', name: 'credential-new', component: { render: () => null } },
            { path: '/credentials/:id', name: 'credential', component: { render: () => null }, props: true },
            { path: '/credentials/:id/edit', name: 'credential-edit', component: { render: () => null }, props: true },
            { path: '/login', name: 'login', component: { render: () => null } }
        ]
    })
    await router.push('/credentials/' + C1)
    await router.isReady()

    let credFetched = false
    const stub = vi.fn(async (url, opts = {}) => {
        if (url === '/cgi-bin/api/session') {
            if (options.sessionFails) throw options.sessionFails
            return jsonReply(
                options.sessionBody || { status: 'success', username: 'ops-admin', is_admin: 1, exp: null },
                true,
                options.sessionStatus ?? 200
            )
        }
        if (url === '/cgi-bin/api/credentials/' + C1) {
            // DELETE rides the same url as the detail GET; the fetch
            // options are what tell the two apart.
            if (opts.method === 'DELETE') {
                if (options.failDelete) throw options.failDelete
                return jsonReply({ status: 'success', message: 'Credential soft deleted' })
            }
            credFetched = true
            if (options.failCred) throw options.failCred
            return jsonReply(options.detailBody || detailBody(options.withSecret !== false))
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(CredentialDetailView, {
        props: { id: C1 },
        global: { plugins: [router] }
    })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, wasFetched: () => credFetched, router }
}

describe('CredentialDetailView for an admin', () => {
    it('renders the record with reveal and copy on the secret', async () => {
        const { wrapper } = await mountDetail()

        expect(wrapper.find('h1').text()).toContain('credential')
        expect(wrapper.text()).toContain('core router')

        // The secret starts masked in a readonly input.
        const secret = wrapper.find('input[aria-label="stored secret"]')
        expect(secret.exists()).toBe(true)
        expect(secret.attributes('type')).toBe('password')

        // Reveal flips only the input type, never the value's home.
        await wrapper.findAll('button').filter(b => ['show', 'hide'].includes(b.text()))[0].trigger('click')
        expect(secret.attributes('type')).toBe('text')

        // Username rides its own copy row.
        expect(wrapper.find('.copy-row input[type=text]').element.value).toBe('admin')

        // Metadata arrives as a json string and comes out pretty-printed.
        expect(wrapper.find('.meta-block').text()).toBe(JSON.stringify({ rack: 'R12' }, null, 2))

        // Stamps: no last access yet reads as Never; expiry verbatim.
        expect(wrapper.text()).toContain('2027-01-01 00:00:00')
        expect(wrapper.text()).toContain('Never')

        // Edit stays in the app, back goes to the listing.
        expect(wrapper.find('a[href="/credentials/' + C1 + '/edit"]').exists()).toBe(true)
        expect(wrapper.find('a[href="/credentials"]').exists()).toBe(true)
    })

    it('shows no secret row when the api omits the password', async () => {
        const { wrapper } = await mountDetail({ withSecret: false })

        // Non-admin fetch: no password in the payload, no input to show.
        expect(wrapper.find('input[aria-label="stored secret"]').exists()).toBe(false)
        expect(wrapper.text()).toContain('admin')
        expect(wrapper.find('.meta-block').text()).toContain('"rack"')
    })

    it('maps a 404 to a plain not-found note', async () => {
        const { wrapper } = await mountDetail({ failCred: new Error('HTTP 404') })

        const banner = wrapper.find('.banner')
        expect(banner.classes()).toContain('banner-error')
        expect(banner.text()).toContain('credential not found')
        expect(wrapper.find('.panel h2').exists()).toBe(false)
    })
})

describe('CredentialDetailView behind the login wall', () => {
    it('walks a signed-out visitor to /login before any fetch', async () => {
        const { wrapper, router, wasFetched } = await mountDetail({ sessionStatus: 401 })

        expect(router.currentRoute.value.name).toBe('login')
        expect(wasFetched()).toBe(false)
        // Nothing loaded, so no edit door either.
        expect(wrapper.find('a[href="/credentials/' + C1 + '/edit"]').exists()).toBe(false)
    })

    it('keeps the gate up when the session probe dies', async () => {
        const { wrapper, wasFetched } = await mountDetail({ sessionFails: new Error('connection refused') })

        expect(wasFetched()).toBe(false)
        expect(wrapper.find('.gate').text()).toContain('session check failed (connection refused)')
        // A dead probe is not an authenticated session: edit stays hidden.
        expect(wrapper.find('a[href="/credentials/' + C1 + '/edit"]').exists()).toBe(false)
    })
})

describe('CredentialDetailView delete', () => {
    it('rides next to edit for an admin, confirms, DELETEs the plural path, and returns to the listing', async () => {
        const { wrapper, router, stub } = await mountDetail()
        vi.stubGlobal('confirm', () => true)

        // Admin token: the delete button sits in the bar next to edit.
        const del = wrapper.findAll('button').find((b) => b.text().startsWith('delete'))
        expect(del).toBeTruthy()
        await del.trigger('click')
        await flushPromises()
        await flushPromises()

        const deleteCall = stub.mock.calls.find((c) => c[1] && c[1].method === 'DELETE')
        expect(deleteCall).toBeTruthy()
        expect(deleteCall[0]).toBe('/cgi-bin/api/credentials/' + C1)
        // Success walks back to the vault listing.
        expect(router.currentRoute.value.path).toBe('/credentials')
        // The detail GET ran exactly once — no refetch on the way out.
        const detailGets = stub.mock.calls.filter((c) => c[0] === '/cgi-bin/api/credentials/' + C1 && !(c[1] && c[1].method))
        expect(detailGets.length).toBe(1)
    })

    it('hides delete from a signed-in non-admin', async () => {
        const { wrapper } = await mountDetail({
            sessionBody: { status: 'success', username: 'ops', is_admin: 0, exp: null }
        })
        const labels = wrapper.findAll('button').map((b) => b.text())
        expect(labels).not.toContain('delete')
        // Edit stays: the vault listing is readable by any signed-in user.
        expect(wrapper.find('a[href="/credentials/' + C1 + '/edit"]').exists()).toBe(true)
    })

    it('leaves the record alone when confirm is declined', async () => {
        const { wrapper, stub } = await mountDetail()
        vi.stubGlobal('confirm', () => false)

        const del = wrapper.findAll('button').find((b) => b.text().startsWith('delete'))
        expect(del).toBeTruthy()
        await del.trigger('click')
        await flushPromises()
        await flushPromises()

        expect(stub.mock.calls.some((c) => c[1] && c[1].method === 'DELETE')).toBe(false)
    })

    it('keeps the record on screen and says so when the api refuses', async () => {
        const { wrapper, router } = await mountDetail({ failDelete: new Error('HTTP 403') })
        vi.stubGlobal('confirm', () => true)

        const del = wrapper.findAll('button').find((b) => b.text().startsWith('delete'))
        await del.trigger('click')
        await flushPromises()
        await flushPromises()

        const warn = wrapper.find('.banner.banner-warn')
        expect(warn.exists()).toBe(true)
        expect(warn.text()).toContain('delete failed')
        expect(warn.text()).toContain('HTTP 403')
        expect(warn.text()).toContain('the record is still there')
        // The failed delete navigates nowhere.
        expect(router.currentRoute.value.path).toBe('/credentials/' + C1)
    })
})