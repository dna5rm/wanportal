/*
 * NetpingView is now the whole install story: the perl script comes
 * from the jwt-gated /cgi-bin/api/netping-script endpoint and is
 * shown, copied and downloaded in place, and the docker and cron notes
 * stay server-name aware. The PASSWORD slot in those notes is the
 * other secret surface: a signed-in admin gets the real value fetched
 * from the singular jwt-gated /cgi-bin/api/agent/:id route, while
 * signed-out and non-admin tabs keep the *** placeholder — and a
 * gated fetch that fails or answers without the field falls back
 * silently to that placeholder, never an empty flag. Both commands
 * quote the value through shellSingleQuote: agent passwords are
 * randomly generated and can carry shell metacharacters, and double
 * quotes would let the shell expand $ and friends into a silently
 * different secret — a real install answered 401 on exactly that.
 * This describe pins the single-quoted (and escaped) form, plus the
 * session gate on the script fetch, the
 * copy/download wiring, the mirrored container name, the docker tag
 * parsed from the fetched script (netping:<version>, falling back to
 * netping:latest when nothing parsable arrives), the declared
 * agent_version in the header (and "version not reported" in words
 * when the agent never declared one), the monitor-only note for an
 * agent that cannot take service checks, and the honest behavior when
 * lookups fail — plus that no link back to the classic netping.php
 * survives anywhere in the page.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import NetpingView from '../components/NetpingView.vue'
import { jsonReply } from './stubs'
import { shellSingleQuote } from '../shellQuote'

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

/* Full record as the (public) agents endpoint returns it. agent_version
 * and supports_services are the agent's self-declared capability
 * fields (null + 0 mean it never declared, i.e. the old agent); the
 * fixture carries a modern declared agent so the header spec can pin
 * the version token and a quiet capability note. The fixture also
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
            agent_version: '0.3.0',
            supports_services: 1,
            password: 'should-not-appear'
        }
    }
}

/* Shape of the script endpoint: success envelope, the on-disk file
 * name, and the perl source as a string. */
function scriptReply() {
    return { status: 'success', filename: 'netping-agent.pl', content: SCRIPT_TEXT }
}

/* Shape of the jwt-gated singular agent route: the same envelope as
 * the public lookup, plus the password field an admin token unlocks.
 * The value is deliberately different from the public fixture's decoy
 * 'should-not-appear', so every assertion below proves which payload
 * it is talking about. */
const REAL_PASSWORD='r3al-4g3nt-pass'

function gatedReply() {
    return {
        status: 'success',
        agent: {
            id: AGENT_ID,
            name: 'Edge-A',
            address: '192.0.2.10',
            is_active: 1,
            last_seen: '2026-09-07 18:00:00',
            agent_version: '0.3.0',
            supports_services: 1,
            password: REAL_PASSWORD
        }
    }
}

/* Mount through the real api + session modules: one keyed fetch stub
 * answers the session probe, the public agent lookup, the jwt-gated
 * singular agent route, and the script endpoint. The options object is
 * read on every call, so a test can fail a door or flip the session's
 * is_admin bit without a second mount helper. The mounted session is a
 * signed-in admin by default — the flip for non-admin / signed-out
 * tabs is explicit. */
async function mountNetping(id = AGENT_ID, options = {}) {
    const stub = vi.fn(async (url) => {
        if (url === '/cgi-bin/api/session') {
            if (options.sessionStatus === 401) {
                return { ok: false, status: 401, json: async () => ({}) }
            }
            if (options.sessionFails) throw options.sessionFails
            const admin = options.isAdmin === undefined ? 1 : (options.isAdmin ? 1 : 0)
            return jsonReply({ status: 'success', username: 'ops', is_admin: admin, exp: null })
        }
        if (url === '/cgi-bin/api/agents/' + AGENT_ID) {
            if (options.failAgent) throw options.failAgent
            return jsonReply(options.agentBody || agentReply())
        }
        if (url === '/cgi-bin/api/agent/' + AGENT_ID) {
            if (options.failGated) throw options.failGated
            return jsonReply(options.gatedBody === undefined ? gatedReply() : options.gatedBody)
        }
        if (url === '/cgi-bin/api/netping-script') {
            if (options.failScript) throw options.failScript
            // holdScript parks the response unresolved so a test can
            // pin what the page shows before the fetch resolves.
            if (options.holdScript) return options.holdScript
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

        // Four calls: the session probe, the public agent lookup for
        // the container name, the script itself, and — this tab being
        // a signed-in admin — the jwt-gated singular agent route for
        // the password.
        expect(urls()).toContain('/cgi-bin/api/session')
        expect(urls()).toContain('/cgi-bin/api/agents/' + AGENT_ID)
        expect(urls()).toContain('/cgi-bin/api/netping-script')
        expect(urls()).toContain('/cgi-bin/api/agent/' + AGENT_ID)

        expect(wrapper.find('.cols .col-side').exists()).toBe(true)
        expect(wrapper.find('.col-main pre.script-body').exists()).toBe(true)
        expect(wrapper.find('.col-main pre.script-body').text()).toContain('#!/usr/bin/env perl')

        // Container commands mirror the established netping-<name>
        // naming (lowercased agent name), with the id and the api base
        // filled in. The password slot's split is pinned in the
        // password describe below; this block only asserts the shape.
        const allPre = wrapper.findAll('pre').map((n) => n.text()).join('\n')
        expect(allPre).toContain('netping-edge-a')
        expect(allPre).toContain('AGENT_ID="' + AGENT_ID + '"')
        expect(allPre).toContain('/cgi-bin/api')
        expect(allPre).toContain('gunzip -c netping_latest.tar.gz | docker load')
        expect(allPre).toContain("-e PASSWORD='" + REAL_PASSWORD + "'")

        // The cron line runs the same perl with the same env vars.
        expect(allPre).toContain('perl netping-agent.pl')
        expect(allPre).toContain('SERVER="')

        // The tarball download stays; the public lookup's decoy
        // password never rides the page — the admin tab's real value
        // only ever comes from the gated route (the password describe
        // pins that).
        expect(wrapper.find('a[href="/assets/netping_latest.tar.gz"]').exists()).toBe(true)
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

/*
 * The docker image tag is parsed from the script the page already
 * fetched (`our $VERSION = '…';` in the perl source) instead of being
 * maintained as a second copy on the page: a version bump in the agent
 * re-renders the run command on the next load, which is the exact
 * staleness the hardcoded netping:latest used to suffer. Anything that
 * is not a parsable declaration — fetch failed, empty payload, a
 * version in quotes the page does not read — keeps the honest
 * netping:latest fallback, and no tag ever renders with nothing after
 * the colon. The shared SCRIPT_TEXT fixture carries no VERSION line,
 * so the describes above already exercise the fallback; this one pins
 * both sides plus the flip the moment the script lands.
 */
describe('NetpingView image tag (parsed from the fetched script)', () => {
    const VERSIONED_SCRIPT = SCRIPT_TEXT + "\nour $VERSION = '0.2.0';\n"

    function runPre(wrapper) {
        return wrapper.findAll('pre').map((n) => n.text()).find((t) => t.includes('docker run'))
    }

    it('runs netping:<parsed version> when the fetched script declares one', async () => {
        const body = scriptReply()
        body.content = VERSIONED_SCRIPT
        const { wrapper } = await mountNetping(AGENT_ID, { scriptBody: body })

        const run = runPre(wrapper)
        expect(run).toBeTruthy()
        // The image line carries the version the script declared — the
        // same tag build_agent.sh applies — not a hardcoded latest.
        expect(run).toContain('netping:0.2.0')
        // Once a version parsed, the fallback tag is gone from the page
        // (the load line's netping_latest.tar.gz is a file name, not a
        // tag), and nothing renders as a bare `netping:`.
        expect(wrapper.text()).not.toContain('netping:latest')
        expect(wrapper.text()).not.toMatch(/netping:(?![\w.-])/)
    })

    it('falls back to netping:latest when the script fails or carries no parsable version', async () => {
        for (const mode of [
            { failScript: new Error('HTTP 401') },
            // The default fixture: a real script body, no VERSION line.
            { scriptBody: scriptReply() },
            // Double-quoted declaration: the page reads the single
            // quoted form the agent source ships and nothing else.
            { scriptBody: { status: 'success', filename: 'netping-agent.pl', content: SCRIPT_TEXT + "\nour $VERSION = \"0.2.0\";\n" } }
        ]) {
            const { wrapper } = await mountNetping(AGENT_ID, mode)
            const run = runPre(wrapper)
            expect(run).toBeTruthy()
            expect(run).toContain('netping:latest')
            // The fallback is still a full tag, never a bare netping:.
            expect(wrapper.text()).not.toMatch(/netping:(?![\w.-])/)
        }
    })

    it('starts the run command at netping:latest and flips to the parsed tag once the script lands', async () => {
        // Deferred response: the page is observed mid-flight, before
        // the script fetch resolves, and then after — the flip is the
        // reactivity that keeps the command from going stale.
        let release
        const held = new Promise((resolve) => { release = resolve })
        const { wrapper } = await mountNetping(AGENT_ID, { holdScript: held })

        let run = runPre(wrapper)
        expect(run).toBeTruthy()
        expect(run).toContain('netping:latest')

        release(jsonReply({ status: 'success', filename: 'netping-agent.pl', content: VERSIONED_SCRIPT }))
        await flushPromises()
        await flushPromises()

        run = runPre(wrapper)
        expect(run).toContain('netping:0.2.0')
    })
})

describe('NetpingView header version', () => {
    it('shows the version the agent declared next to the name', async () => {
        const { wrapper } = await mountNetping()

        const muted = wrapper.find('.bar-title .muted')
        expect(muted.text()).toBe('Edge-A · v0.3.0')
    })

    it('trims a sloppy version instead of printing the padding', async () => {
        const body = agentReply()
        body.agent.agent_version = '  0.3.1 '
        const { wrapper } = await mountNetping(AGENT_ID, { agentBody: body })

        expect(wrapper.find('.bar-title .muted').text()).toBe('Edge-A · v0.3.1')
    })

    it('states plainly when the agent never declared a version', async () => {
        const body = agentReply()
        body.agent.agent_version = null
        const { wrapper } = await mountNetping(AGENT_ID, { agentBody: body })

        // An agent that never declared reads as absent data: the header
        // announces that in words (matching the agent detail page) rather
        // than leaving silence that looks like a broken page — and it
        // never invents a version number.
        expect(wrapper.find('.bar-title .muted').text()).toBe('Edge-A · version not reported')
        expect(wrapper.text()).not.toMatch(/· v\d/)
    })
})

describe('NetpingView monitor-only note', () => {
    it('keeps quiet when the agent supports service checks', async () => {
        const { wrapper } = await mountNetping()

        const right = wrapper.find('.bar-right')
        expect(right.text()).not.toContain('monitor-only')
        // The version still shows; the note is the only thing skipped.
        expect(wrapper.text()).toContain(' · v0.3.0')
    })

    it('flags the old agent so the upgrade trip is worth it upfront', async () => {
        // Both shapes that read as monitor-only: the flag off, and a
        // payload from before the capability columns existed at all.
        for (const supports of [0, undefined]) {
            const body = agentReply()
            if (supports === undefined) delete body.agent.supports_services
            else body.agent.supports_services = supports
            const { wrapper } = await mountNetping(AGENT_ID, { agentBody: body })

            expect(wrapper.find('.bar-right').text())
                .toContain('monitor-only agent (no service checks)')
        }
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

describe('NetpingView password slot', () => {
    it('fetches the real password for a signed-in admin and renders it in both blocks', async () => {
        const { wrapper, urls } = await mountNetping()

        // The singular, jwt-gated route is asked; the public plural
        // lookup for the page name runs separately.
        expect(urls()).toContain('/cgi-bin/api/agent/' + AGENT_ID)
        expect(urls()).toContain('/cgi-bin/api/agents/' + AGENT_ID)

        const allPre = wrapper.findAll('pre').map((n) => n.text()).join('\n')
        expect(allPre).toContain("-e PASSWORD='" + REAL_PASSWORD + "'")
        expect(allPre).toContain("PASSWORD='" + REAL_PASSWORD + "' perl netping-agent.pl")
        // Once the real value landed, the placeholder is gone from the
        // whole page — not just from the run block.
        expect(wrapper.text()).not.toContain("PASSWORD='***'")

        // The note flips from "replace before starting" to why the
        // value is on screen at all.
        expect(wrapper.text()).toContain('signed in as admin')
        expect(wrapper.text()).toContain('treat it as a secret')
        expect(wrapper.text()).not.toContain('replace before starting')
    })

    it('single-quotes a metacharacter password so the shell cannot expand it away', async () => {
        // A real install pasted a double-quoted command whose randomly
        // generated password carried $ and ) — the shell expanded the $
        // stretch before docker ever ran, the agent received a silently
        // corrupted secret, and answered 401. The value below stands in
        // for that class (expansion plus parens); the rendered token
        // must be the whole value inside single quotes, byte for byte.
        const pw = "p4ss$(id)w0rd)x"
        const body = gatedReply()
        body.agent.password = pw
        const { wrapper } = await mountNetping(AGENT_ID, { gatedBody: body })

        const allPre = wrapper.findAll('pre').map((n) => n.text()).join('\n')
        expect(allPre).toContain("-e PASSWORD='" + pw + "'")
        expect(allPre).toContain("PASSWORD='" + pw + "' perl netping-agent.pl")
        // No double-quoted slot survives anywhere — that shape is what
        // let the shell rewrite the value before docker saw it.
        expect(wrapper.text()).not.toContain('PASSWORD="' + pw + '"')
    })

    it('never asks the gated route for a signed-in non-admin and keeps the placeholder', async () => {
        const { wrapper, urls } = await mountNetping(AGENT_ID, { isAdmin: false })

        expect(urls()).not.toContain('/cgi-bin/api/agent/' + AGENT_ID)
        expect(wrapper.text()).toContain("PASSWORD='***'")
        expect(wrapper.text()).not.toContain(REAL_PASSWORD)
        expect(wrapper.text()).toContain('replace before starting')
        expect(wrapper.text()).not.toContain('treat it as a secret')
    })

    it('keeps the placeholder for a signed-out tab and never asks the gated route', async () => {
        const { wrapper, urls } = await mountNetping(AGENT_ID, { sessionStatus: 401 })

        expect(urls()).not.toContain('/cgi-bin/api/agent/' + AGENT_ID)
        expect(wrapper.text()).toContain("PASSWORD='***'")
        expect(wrapper.text()).not.toContain(REAL_PASSWORD)
        expect(wrapper.text()).toContain('replace before starting')
    })

    it('falls back to the placeholder, never an empty flag, when the gated fetch misbehaves', async () => {
        // Three ways it can go wrong: the transport failing, an
        // envelope without the password field (an expired token asking
        // mid-session gets a 200 sans password), and an empty-string
        // field. All three end at the same place.
        for (const mode of [
            { failGated: new Error('HTTP 401') },
            { gatedBody: { status: 'success', agent: { id: AGENT_ID, name: 'Edge-A' } } },
            { gatedBody: { status: 'success', agent: { id: AGENT_ID, name: 'Edge-A', password: '' } } }
        ]) {
            const { wrapper, urls } = await mountNetping(AGENT_ID, mode)

            expect(urls()).toContain('/cgi-bin/api/agent/' + AGENT_ID)
            const allPre = wrapper.findAll('pre').map((n) => n.text()).join('\n')
            expect(allPre).toContain("-e PASSWORD='***'")
            expect(allPre).toContain("PASSWORD='***' perl netping-agent.pl")
            expect(allPre).not.toContain("PASSWORD=''")
            expect(wrapper.text()).not.toContain(REAL_PASSWORD)
            // The fallback is silent: no swap to the admin note, and
            // the placeholder wording stays.
            expect(wrapper.text()).toContain('replace before starting')
            expect(wrapper.text()).not.toContain('treat it as a secret')
        }
    })
})

/*
 * The quoting helper itself, unit-pinned apart from any mount: the
 * token must survive a POSIX shell byte for byte, so these are exact
 * string assertions, not substrings.
 */
describe('shellSingleQuote', () => {
    it('wraps the value so metacharacters stay literal inside the token', () => {
        // The classes that made the real password dangerous:
        // expansion, command substitution, parens.
        expect(shellSingleQuote('p4ss$(id)w0rd)x')).toBe("'p4ss$(id)w0rd)x'")
        expect(shellSingleQuote('$VAR `id` \\ "dq"')).toBe("'$VAR `id` \\ \"dq\"'")
        // The *** placeholder rides the same helper and quotes fine.
        expect(shellSingleQuote('***')).toBe("'***'")
    })

    it('escapes a single quote the standard way: close, escape, reopen', () => {
        expect(shellSingleQuote("a'b")).toBe("'a'\\''b'")
        expect(shellSingleQuote("'")).toBe("''\\'''")
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