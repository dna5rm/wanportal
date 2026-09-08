/*
 * getJson is the only door between the page and the CGI API, so its
 * failure modes matter: a clean payload passes through, a bad HTTP
 * status and a bad envelope both throw, and a hung request gets cut
 * off by the timeout.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { delJson, getJson, postJson, putJson } from '../api'
import { getToken, setToken } from '../session'

afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
    sessionStorage.clear() // keep the token tests from leaking on each other
})

describe('getJson', () => {
    it('returns the parsed payload and sends the accept header', async () => {
        const body = { status: 'success', dashboard: { total: 4 } }
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => body
        }))
        vi.stubGlobal('fetch', fetchMock)

        await expect(getJson('/cgi-bin/api/dashboard')).resolves.toEqual(body)

        expect(fetchMock).toHaveBeenCalledTimes(1)
        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/cgi-bin/api/dashboard')
        expect(opts.headers).toEqual({ Accept: 'application/json' })
        // The abort signal is wired up even though nothing aborted here.
        expect(opts.signal.aborted).toBe(false)
    })

    it('throws the http status when the response is not ok', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 503, json: async () => ({})
        })))

        await expect(getJson('/cgi-bin/api/dashboard')).rejects.toThrow('HTTP 503')
    })

    it('rejects an envelope whose status is not success', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'error', message: 'nope' })
        })))

        await expect(getJson('/cgi-bin/api/agents')).rejects.toThrow('bad payload')
    })

    it('rejects a null body as a bad payload too', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true, status: 200, json: async () => null
        })))

        await expect(getJson('/cgi-bin/api/monitors')).rejects.toThrow('bad payload')
    })

    it('aborts a hanging request when the timeout fires', async () => {
        vi.useFakeTimers()
        let seenSignal = null
        // A fetch that never answers on its own; it only dies when the
        // abort signal the api wires up finally fires.
        vi.stubGlobal('fetch', vi.fn((url, opts) => new Promise((resolve, reject) => {
            seenSignal = opts.signal
            opts.signal.addEventListener('abort', () => reject(new Error('request aborted')))
        })))

        const pending = getJson('/cgi-bin/api/dashboard', { timeoutMs: 2000 })
        const settled = expect(pending).rejects.toThrow('request aborted')

        await vi.advanceTimersByTimeAsync(1999)
        expect(seenSignal.aborted).toBe(false)

        await vi.advanceTimersByTimeAsync(1)
        expect(seenSignal.aborted).toBe(true)
        await settled
    })

    it('defaults to a ten second timeout when none is given', async () => {
        vi.useFakeTimers()
        let seenSignal = null
        vi.stubGlobal('fetch', vi.fn((url, opts) => new Promise((resolve, reject) => {
            seenSignal = opts.signal
            opts.signal.addEventListener('abort', () => reject(new Error('request aborted')))
        })))

        const pending = getJson('/cgi-bin/api/agents')
        const settled = expect(pending).rejects.toThrow('request aborted')

        await vi.advanceTimersByTimeAsync(9999)
        expect(seenSignal.aborted).toBe(false)

        await vi.advanceTimersByTimeAsync(1)
        expect(seenSignal.aborted).toBe(true)
        await settled
    })

    it('sends the bearer header only when a token is stored', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'success' })
        }))
        vi.stubGlobal('fetch', fetchMock)

        // Signed out first: the public endpoints go out bare.
        await getJson('/cgi-bin/api/dashboard')
        expect(fetchMock.mock.calls[0][1].headers).toEqual({ Accept: 'application/json' })

        // With a token parked, the same call carries it as Bearer.
        setToken('jwt-x')
        await getJson('/cgi-bin/api/users')
        expect(fetchMock.mock.calls[1][1].headers).toEqual({
            Accept: 'application/json',
            Authorization: 'Bearer jwt-x'
        })
    })

    it('drops a stored token when a call comes back 401', async () => {
        setToken('jwt-expired')
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        })))

        await expect(getJson('/cgi-bin/api/session')).rejects.toThrow('HTTP 401')
        expect(getToken()).toBeNull()
    })

    it('leaves storage alone on a 401 that went out bare', async () => {
        // No token to begin with, so there is nothing to clean up —
        // the endpoint merely refused an anonymous caller.
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        })))

        await expect(getJson('/cgi-bin/api/dashboard')).rejects.toThrow('HTTP 401')
        expect(getToken()).toBeNull()
    })
})

describe('postJson', () => {
    it('posts the json body with the content-type header', async () => {
        const body = { status: 'success', token: 'jwt-y' }
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => body
        }))
        vi.stubGlobal('fetch', fetchMock)

        await expect(postJson('/cgi-bin/api/login', { username: 'ops', password: 'secret' }))
            .resolves.toEqual(body)

        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/cgi-bin/api/login')
        expect(opts.method).toBe('POST')
        expect(opts.credentials).toBe('omit')
        expect(opts.headers).toEqual({
            Accept: 'application/json',
            'Content-Type': 'application/json'
        })
        expect(opts.body).toBe(JSON.stringify({ username: 'ops', password: 'secret' }))
    })

    it('sends the bearer header when a token is already stored', async () => {
        setToken('jwt-z')
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'success' })
        }))
        vi.stubGlobal('fetch', fetchMock)

        await postJson('/cgi-bin/api/something', { a: 1 })
        expect(fetchMock.mock.calls[0][1].headers).toEqual({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: 'Bearer jwt-z'
        })
    })

    it('applies the same envelope check as getJson', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'error', message: 'nope' })
        })))

        await expect(postJson('/cgi-bin/api/login', {})).rejects.toThrow('bad payload')
    })
})

describe('putJson', () => {
    it('saves with method put and the content-type header', async () => {
        const body = { status: 'success', saved: true }
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => body
        }))
        vi.stubGlobal('fetch', fetchMock)

        await expect(putJson('/cgi-bin/api/monitors/7', { name: 'edge' }))
            .resolves.toEqual(body)

        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/cgi-bin/api/monitors/7')
        expect(opts.method).toBe('PUT')
        expect(opts.credentials).toBe('omit')
        expect(opts.headers).toEqual({
            Accept: 'application/json',
            'Content-Type': 'application/json'
        })
        expect(opts.body).toBe(JSON.stringify({ name: 'edge' }))
    })

    it('carries the bearer header and still applies the envelope check', async () => {
        setToken('jwt-save')
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'error', message: 'nope' })
        }))
        vi.stubGlobal('fetch', fetchMock)

        await expect(putJson('/cgi-bin/api/agents/3', { a: 1 })).rejects.toThrow('bad payload')
        expect(fetchMock.mock.calls[0][1].headers).toEqual({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: 'Bearer jwt-save'
        })
    })
})

describe('delJson', () => {
    it('sends a bodyless delete and takes a success envelope on 200', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'success', deleted: 1 })
        }))
        vi.stubGlobal('fetch', fetchMock)

        await expect(delJson('/cgi-bin/api/monitors/7'))
            .resolves.toEqual({ status: 'success', deleted: 1 })

        const [url, opts] = fetchMock.mock.calls[0]
        expect(url).toBe('/cgi-bin/api/monitors/7')
        expect(opts.method).toBe('DELETE')
        expect(opts.credentials).toBe('omit')
        expect(opts.headers).toEqual({ Accept: 'application/json' })
        expect(opts.body).toBeUndefined()
    })

    it('accepts a bare 204 with no envelope at all', async () => {
        // A 204 carries no body, so any json() call here would blow up;
        // delJson must not even try.
        const jsonMock = vi.fn(async () => ({}))
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true, status: 204, json: jsonMock
        })))

        await expect(delJson('/cgi-bin/api/monitors/7')).resolves.toBeNull()
        expect(jsonMock).not.toHaveBeenCalled()
    })

    it('still demands the envelope on a plain 200', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true, status: 200, json: async () => ({ status: 'error', message: 'nope' })
        })))

        await expect(delJson('/cgi-bin/api/monitors/7')).rejects.toThrow('bad payload')
    })

    it('drops a stored token when the delete comes back 401', async () => {
        setToken('jwt-expired')
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false, status: 401, json: async () => ({})
        })))

        await expect(delJson('/cgi-bin/api/monitors/7')).rejects.toThrow('HTTP 401')
        expect(getToken()).toBeNull()
    })
})