/*
 * LoginView specs: the form posts to the JSON login endpoint, parks
 * the token in sessionStorage (never localStorage), re-probes the
 * session through the probe App provides (so the account menu lights
 * before the redirect), flips to the dashboard on success, and shows
 * a clean message on bad credentials. A memory-history router backs
 * the push, so no real navigation runs.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import LoginView from '../components/LoginView.vue'

afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
    localStorage.clear()
})

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { template: '<div>dash</div>' } },
            { path: '/login', name: 'login', component: LoginView }
        ]
    })
}

async function mountLogin() {
    const router = makeRouter()
    await router.push('/login')
    await router.isReady()
    const wrapper = mount(LoginView, { global: { plugins: [router] } })
    return { wrapper, router }
}

describe('LoginView', () => {
    it('renders the fields with no classic log-in door linked', async () => {
        const { wrapper } = await mountLogin()

        expect(wrapper.find('#login-user').exists()).toBe(true)
        expect(wrapper.find('#login-pass').attributes('type')).toBe('password')
        // Sign-in is SPA-only: neither classic door (/login.php or
        // /classic/login.php) is linked from here.
        const classic = wrapper.findAll('a').find(a =>
            /^\/?(classic\/)?login\.php$/.test(a.attributes('href') || ''))
        expect(classic).toBeUndefined()
    })

    it('signs in: token lands in sessionStorage and the dashboard takes over', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ status: 'success', token: 'jwt-lv', username: 'ops', is_admin: 1, exp: 1781 })
        }))
        vi.stubGlobal('fetch', fetchMock)

        const { wrapper, router } = await mountLogin()
        await wrapper.find('#login-user').setValue('ops')
        await wrapper.find('#login-pass').setValue('secret')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/cgi-bin/api/login')
        expect(opts.method).toBe('POST')
        expect(opts.body).toBe(JSON.stringify({ username: 'ops', password: 'secret' }))

        expect(sessionStorage.getItem('wanportal.jwt')).toBe('jwt-lv')
        expect(localStorage.length).toBe(0)
        // The secret does not linger in the form state after success.
        expect(wrapper.find('#login-pass').element.value).toBe('')
        // Success flips to the dashboard, no error line left behind.
        expect(router.currentRoute.value.path).toBe('/')
        expect(wrapper.find('.err-note').exists()).toBe(false)
    })

    it('re-probes the session on success, before the redirect', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ status: 'success', token: 'jwt-lv', username: 'ops', is_admin: 1, exp: 1781 })
        }))
        vi.stubGlobal('fetch', fetchMock)

        // The probe runs while the form still sits on /login — that is
        // the call that lights the account menu without a reload, even
        // when the redirect itself does not move the route.
        let probedAt = null
        const probe = vi.fn(() => { probedAt = router.currentRoute.value.path })
        const router = makeRouter()
        await router.push('/login')
        await router.isReady()
        const wrapper = mount(LoginView, {
            global: { plugins: [router], provide: { sessionProbe: probe } }
        })

        await wrapper.find('#login-user').setValue('ops')
        await wrapper.find('#login-pass').setValue('secret')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(probe).toHaveBeenCalledTimes(1)
        expect(probedAt).toBe('/login')
        expect(router.currentRoute.value.path).toBe('/')
    })

    it('shows the wrong-credentials message on 401 and stores nothing', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        })))

        const { wrapper } = await mountLogin()
        await wrapper.find('#login-user').setValue('ops')
        await wrapper.find('#login-pass').setValue('wrong')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.find('.err-note').text()).toBe('Wrong username or password.')
        expect(sessionStorage.getItem('wanportal.jwt')).toBeNull()
        expect(localStorage.length).toBe(0)
    })

    it('surfaces transport failures as-is instead of guessing', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new Error('connection refused')
        }))

        const { wrapper } = await mountLogin()
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.find('.err-note').text()).toBe('connection refused')
    })
})