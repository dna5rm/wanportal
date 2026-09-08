/*
 * The token is the crown jewel here: login() must park it in
 * sessionStorage and nowhere else, getSession must send it as a Bearer
 * header, and an expired token (401) must be dropped so the next call
 * goes out bare. localStorage must stay empty, full stop — the token
 * dying with the tab is the whole point.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { getJson } from '../api'
import { clearToken, getToken, getSession, login, logout, setToken } from '../session'

afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
    localStorage.clear()
})

describe('token storage', () => {
    it('round-trips through sessionStorage only', () => {
        expect(getToken()).toBeNull()

        setToken('jwt-a')
        expect(getToken()).toBe('jwt-a')
        expect(sessionStorage.getItem('wanportal.jwt')).toBe('jwt-a')

        // The sticky shelf stays empty — that is the security rule.
        expect(localStorage.getItem('wanportal.jwt')).toBeNull()
        expect(localStorage.length).toBe(0)

        clearToken()
        expect(getToken()).toBeNull()
        expect(sessionStorage.getItem('wanportal.jwt')).toBeNull()
    })

    it('logout clears whatever was stored', () => {
        setToken('jwt-b')
        logout()
        expect(getToken()).toBeNull()
    })
})

describe('login', () => {
    it('posts the credentials and parks the returned token', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ status: 'success', token: 'jwt-c', username: 'ops', is_admin: 1, exp: 1780 })
        }))
        vi.stubGlobal('fetch', fetchMock)

        const claims = await login('ops', 'secret')

        expect(claims).toEqual({ username: 'ops', isAdmin: true, exp: 1780 })
        expect(getToken()).toBe('jwt-c')
        expect(localStorage.length).toBe(0)

        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/cgi-bin/api/login')
        expect(opts.method).toBe('POST')
        expect(opts.body).toBe(JSON.stringify({ username: 'ops', password: 'secret' }))
    })

    it('propagates the failure for bad credentials', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        })))

        await expect(login('ops', 'wrong')).rejects.toThrow('HTTP 401')
        // A failed login must not leave a phantom token behind.
        expect(getToken()).toBeNull()
    })
})

describe('getSession', () => {
    it('sends the stored token as a bearer header', async () => {
        setToken('jwt-d')
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ status: 'success', username: 'ops', is_admin: 0, exp: null })
        }))
        vi.stubGlobal('fetch', fetchMock)

        const result = await getSession()

        expect(result).toEqual({
            authenticated: true,
            reason: 'ok',
            username: 'ops',
            isAdmin: false,
            exp: null
        })
        expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer jwt-d')
        // Cookies are not part of this auth model.
        expect(fetchMock.mock.calls[0][1].credentials).toBe('omit')
    })

    it('goes out bare when no token is stored', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        }))
        vi.stubGlobal('fetch', fetchMock)

        await expect(getSession()).resolves.toEqual({ authenticated: false, reason: 'signed-out' })
        expect(fetchMock.mock.calls[0][1].headers).toEqual({ Accept: 'application/json' })
        // Nothing was stored, so there was nothing to drop either.
        expect(getToken()).toBeNull()
    })

    it('drops an expired token on 401 and reports signed out', async () => {
        setToken('jwt-expired')
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        })))

        await expect(getSession()).resolves.toEqual({ authenticated: false, reason: 'signed-out' })
        expect(getToken()).toBeNull()
    })

    it('keeps a dead API separate from signed out', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new Error('connection refused')
        }))

        await expect(getSession()).resolves.toEqual({
            authenticated: false,
            reason: 'unavailable',
            error: 'connection refused'
        })
    })
})

/* getJson reads the same storage, so one focused call pins both sides
 * of its header rule: bare while signed out, Bearer once a token is
 * parked. The api.spec suite owns the rest of its behavior. */
describe('getJson', () => {
    it('carries the stored token as a bearer header, bare without one', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'success' })
        }))
        vi.stubGlobal('fetch', fetchMock)

        await getJson('/cgi-bin/api/dashboard')
        expect(fetchMock.mock.calls[0][1].headers).toEqual({ Accept: 'application/json' })

        setToken('jwt-e')
        await getJson('/cgi-bin/api/users')
        expect(fetchMock.mock.calls[1][1].headers).toEqual({
            Accept: 'application/json',
            Authorization: 'Bearer jwt-e'
        })
    })
})