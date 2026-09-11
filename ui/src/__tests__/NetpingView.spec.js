/*
 * NetpingView is now the whole install story: the perl script comes
 * from the jwt-gated /cgi-bin/api/netping-script endpoint and is
 * shown, copied and downloaded in place, while the docker and cron
 * notes stay server-name aware and password-placeholder-only. These
 * specs pin the session gate on the script fetch, the copy/download
 * wiring, the mirrored container name, and the honest behavior when
 * lookups fail — plus that no link back to the classic netping.php
 * survives anywhere in the page.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import NetpingView from '../components/NetpingView.vue'
import { jsonReply } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
    // The clipboard stub rides on the navigator instance, not on a
    // vi.stubGlobal, so it is unhooked by hand.
    try { delete window.navigator.clipboard } catch { /* was never set */ }
})

/* Detail ids are char(36) uuids and the api rejects anything else,
 * so the fixture carries the same shape the router would hand over. */
const AGENT_ID = 'bbbbbbbb-0000-4000-8000-000000000001'

const SCRIPT_TEXT = [
    '#!/usr/bin/env perl',
    'use strict;',
    'use warnings;',
    'print "probe\\n";',
    ''
].join('\n')

/* Full record as the (public) agents endpoint returns it. The fixture
 * carries a password even though the endpoint omits one, so the spec
 * can prove the page never echoes secret material it is handed. */
function agentReply() {
    return {
        status: 'success',
        agent: {
            id: AGENT_ID,
            name: 'Edge-A',
            address: '192.0.2.10',
            is_active: 1,
            last_seen: '2026-09-07 18:00:00',
            password: 'should-not-appear'
        }
    }
}

/* Shape of the script endpoint: success envelope, the on-disk file
 * name, and the perl source as a string. */
function scriptReply() {
    return { status: 'success', filename: 'netping-agent.pl', content: SCRIPT_TEXT }
}

/* Mount through the real api + session modules: one keyed fetch stub
 * answers the session probe, the agent lookup, and the script
 * endpoint. The options object is read on every call, so a test can
 * fail a door without a second mount helper. */
async function mountNetping(id = AGENT_ID, options = {}) {
    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/session') {
            if (options.sessionStatus === 401) {
                return { ok: false, status: 401, json: async () => ({}) }
            }
            if (options.sessionFails) throw options.sessionFails
            return jsonReply({ status: 'success', username: 'ops', is_admin: 1, exp: null })
        }
        if (url === '/cgi-bin/api/agents/' + AGENT_ID) {
            if (options.failAgent) throw options.failAgent
            return jsonReply(options.agentBody || agentReply())
        }
        if (url === '/cgi-bin/api/netping-script') {
            if (options.failScript) throw options.failScript
            return jsonReply(options.scriptBody || scriptReply())
        }
        throw new Error('unexpected url: ' + url)
    })
    vi.stubGlobal('fetch', stub)

    const wrapper = mount(NetpingView, { props: { id } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, urls: () => stub.mock.calls.map((c) => c[0]) }
}

/* jsdom ships no clipboard, so the copy wiring is exercised against a
 * stubbed navigator.clipboard; defineProperty is used (not
 * stubGlobal) because navigator is a live jsdom object. */
function stubClipboard() {
    const writeText = vi.fn(() => Promise.resolve())
    Object.defineProperty(window.navigator, 'clipboard', {
        value: { writeText },
        configurable: true
    })
    return writeText
}

/* Blob url support is stubbed on the URL constructor: the component
 * must build its blob url through them, and the returned stubs let the
 * spec inspect the Blob itself. */
function stubBlobUrls() {
    const createObjectURL = vi.fn(() => 'blob:stub-url')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(window.URL, 'createObjectURL', { value: createObjectURL, configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: revokeObjectURL, configurable: true })
    return { createObjectURL, revokeObjectURL }
}

describe('NetpingView with the api answering a signed-in tab', () => {
    it('renders the script from the api and keeps the docker notes', async () => {
        const { wrapper, urls } = await mountNetping()

        // Three calls: the session probe (the script endpoint is
        // jwt-gated), the public agent lookup for the container name,
        // and the script itself.
        expect(urls()).toContain('/cgi-bin/api/session')
        expect(urls()).toContain('/cgi-bin/api/agents/' + AGENT_ID)
        expect(urls()).toContain('/cgi-bin/api/netping-script')

        expect(wrapper.find('.cols .col-side').exists()).toBe(true)
        expect(wrapper.find('.col-main pre.script-body').exists()).toBe(true)
        expect(wrapper.find('.col-main pre.script-body').text()).toContain('#!/usr/bin/env perl')

        // Container commands mirror the established netping-<name>
        // naming (lowercased agent name), with the id and the api base
        // filled in but the password left as a placeholder.
        const allPre = wrapper.findAll('pre').map((n) => n.text()).join('\n')
        expect(allPre).toContain('netping-edge-a')
        expect(allPre).toContain('AGENT_ID="' + AGENT_ID + '"')
        expect(allPre).toContain('/cgi-bin/api')
        expect(allPre).toContain('gunzip -c netping_latest.tar.gz | docker load')

        // The cron line runs the same perl with the same env vars.
        expect(allPre).toContain('perl netping-agent.pl')
        expect(allPre).toContain('SERVER="')

        // The tarball download stays; the password rides the api
        // payload but never the page.
        expect(wrapper.find('a[href="/assets/netping_latest.tar.gz"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('PASSWORD="***"')
        expect(wrapper.text()).not.toContain('should-not-appear')
    })

    it('hands the whole script to the clipboard api on copy', async () => {
        const { wrapper } = await mountNetping()
        const writeText = stubClipboard()

        const btn = wrapper.findAll('button').find((b) => b.text() === 'copy script')
        expect(btn).toBeTruthy()
        await btn.trigger('click')
        await flushPromises()

        expect(writeText).toHaveBeenCalledTimes(1)
        expect(writeText).toHaveBeenCalledWith(SCRIPT_TEXT)
        // The button flashes the receipt instead of leaving a toast.
        expect(wrapper.findAll('button').find((b) => b.text() === 'copied ✓')).toBeTruthy()
    })

    it('downloads the same text as netping-agent.pl through a blob url', async () => {
        const { createObjectURL, revokeObjectURL } = stubBlobUrls()
        const { wrapper } = await mountNetping()

        const dl = wrapper.findAll('button').find((b) => b.text() === 'download netping-agent.pl')
        expect(dl).toBeTruthy()
        await dl.trigger('click')

        expect(createObjectURL).toHaveBeenCalledTimes(1)
        const blob = createObjectURL.mock.calls[0][0]
        expect(blob).toBeInstanceOf(Blob)
        expect(blob.type).toBe('text/x-perl')
        // Read the blob back through FileReader: jsdom's Blob has no
        // async text() reader, and the downloaded file must carry the
        // exact script text.
        const text = await new Promise((resolve, reject) => {
            const fr = new FileReader()
            fr.onload = () => resolve(String(fr.result))
            fr.onerror = () => reject(new Error('blob read failed'))
            fr.readAsText(blob)
        })
        expect(text).toBe(SCRIPT_TEXT)
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:stub-url')
    })

    it('keeps no classic netping.php links anywhere', async () => {
        const { wrapper } = await mountNetping()
        expect(wrapper.html()).not.toContain('netping.php')
    })

    it('keeps the docker notes when the agent lookup misbehaves', async () => {
        const { wrapper, urls } = await mountNetping(AGENT_ID, { failAgent: new Error('HTTP 404') })

        expect(wrapper.find('.banner').text()).toContain('agent not found')
        // The id came from the route, not the lookup, so the generic
        // container name still works — and the script fetch still ran.
        expect(urls()).toContain('/cgi-bin/api/netping-script')
        expect(wrapper.text()).toContain('netping-agent')
        expect(wrapper.find('.col-main pre.script-body').text()).toContain('#!/usr/bin/env perl')
    })

    it('warns in the script panel when the script endpoint misbehaves', async () => {
        const { wrapper } = await mountNetping(AGENT_ID, { failScript: new Error('HTTP 404') })

        expect(wrapper.findAll('.panel')[0].text()).toContain('script fetch: script not served')
        // The install notes survive a missing script.
        expect(wrapper.text()).toContain('docker load')
        expect(wrapper.text()).not.toContain('#!/usr/bin/env perl')
    })
})

describe('NetpingView behind the jwt gate', () => {
    it('hides the script behind a sign-in note when the tab is signed out', async () => {
        const { wrapper, urls } = await mountNetping(AGENT_ID, { sessionStatus: 401 })

        // The gated endpoint is never asked for a bare token; the
        // public agent lookup still runs.
        expect(urls()).not.toContain('/cgi-bin/api/netping-script')
        expect(urls()).toContain('/cgi-bin/api/agents/' + AGENT_ID)

        expect(wrapper.text()).toContain('sign in')
        expect(wrapper.find('a[href="#/login"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('#!/usr/bin/env perl')
        expect(wrapper.find('button').exists()).toBe(false)
    })

    it('says the session check failed instead of guessing, when the probe dies', async () => {
        const { wrapper, urls } = await mountNetping(AGENT_ID, {
            sessionFails: new Error('connection refused')
        })

        expect(urls()).not.toContain('/cgi-bin/api/netping-script')
        expect(wrapper.text()).toContain('session check failed (connection refused)')
        expect(wrapper.text()).toContain('hidden rather than guessed')
        expect(wrapper.text()).not.toContain('#!/usr/bin/env perl')
    })
})

describe('NetpingView without an id', () => {
    it('skips the agent lookup but still serves the script', async () => {
        const { wrapper, urls } = await mountNetping('')

        expect(urls()).not.toContain('/cgi-bin/api/agents/' + AGENT_ID)
        expect(wrapper.find('.banner').text()).toContain('no agent id found in the url')

        // The script is agent-independent, so it still loads.
        expect(urls()).toContain('/cgi-bin/api/netping-script')
        expect(wrapper.find('.col-main pre.script-body').text()).toContain('#!/usr/bin/env perl')

        // The generic container name and the id placeholder keep the
        // commands copy-paste ready.
        expect(wrapper.text()).toContain('netping-agent')
        expect(wrapper.text()).toContain('<AGENT_ID>')
    })
})