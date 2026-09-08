/*
 * Shared fakes for the UI specs. The components only ever talk to
 * plain /cgi-bin/api/... GETs, so one keyed fetch stub covers every
 * endpoint, and a small stamp helper keeps the age-based color tests
 * honest no matter what timezone the test box runs in.
 */
import { vi } from 'vitest'

/* "N ms ago" as the local 'YYYY-MM-DD HH:MM:SS' stamp the API hands
 * back. Built from the current clock so it round-trips through the
 * same Date parsing the formatters use. */
export function localStamp(msAgo, base = Date.now()) {
    const d = new Date(base - msAgo)
    const p = (n) => String(n).padStart(2, '0')
    return (
        d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
        ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds())
    )
}

/* The smallest object getJson() accepts as a response. */
export function jsonReply(body, ok = true, status = 200) {
    return { ok, status, json: async () => body }
}

/* The api hands ids back as char(36) uuids, so the fixture bodies
 * below carry uuid-shaped keys. The prefixes mirror the sibling
 * specs: monitors are aaaaaaaa-, agents bbbbbbbb-, targets cccccccc-,
 * and the letters line up with the same fake estate (A1 is the agent
 * the monitors call edge-a, T5 is branch-gw, T9 is hq-gw). */
export const M1 = 'aaaaaaaa-0000-4000-8000-000000000001' // hq uplink
export const M2 = 'aaaaaaaa-0000-4000-8000-000000000002' // top-slow, no description
export const M7 = 'aaaaaaaa-0000-4000-8000-000000000007' // branch vpn
export const M8 = 'aaaaaaaa-0000-4000-8000-000000000008' // long dead link
export const A1 = 'bbbbbbbb-0000-4000-8000-000000000001' // core / edge-a
export const A2 = 'bbbbbbbb-0000-4000-8000-000000000002' // sleepy
export const T5 = 'cccccccc-0000-4000-8000-000000000005' // branch-gw.example
export const T8 = 'cccccccc-0000-4000-8000-000000000008' // legacy-gw.example
export const T9 = 'cccccccc-0000-4000-8000-000000000009' // hq-gw.example

/* Shape of the dashboard rollup. Numbers chosen so the card
 * percentages are easy to eyeball in the assertions. */
export function dashBody() {
    return {
        status: 'success',
        dashboard: {
            total: 10, up: 7, degraded: 2, down: 1,
            percent_up: 70, percent_degraded: 20, percent_down: 10,
            top_slow: [
                { id: M1, description: 'hq uplink', agent_id: A1, agent_name: 'edge-a', target_id: T9, target_address: 'hq-gw.example', current_median: 42.5, current_loss: 0 },
                { id: M2, description: null, agent_id: A1, agent_name: 'edge-a', target_id: T5, target_address: 'branch-gw.example', current_median: 118.4, current_loss: 100 }
            ]
        }
    }
}

/* Two agents: one active and fresh, one retired — the view must drop
 * the retired one from the chip row. */
export function agentsBody() {
    return {
        status: 'success',
        agents: [
            { id: A1, name: 'core', description: 'primary site', is_active: 1, last_seen: localStamp(5 * 60000) },
            { id: A2, name: 'sleepy', description: 'retired', is_active: 0, last_seen: localStamp(2 * 3600000) }
        ]
    }
}

/* One monitor down for four hours (warn color) and one for six
 * (danger color), matching the thresholds in format.js. Takes the
 * reference clock so tests can build exact expected strings. */
export function downBody(base = Date.now()) {
    return {
        status: 'success',
        monitors: [
            { id: M7, description: 'branch vpn', agent_id: A1, agent_name: 'edge-a', target_id: T5, target_address: 'branch-gw.example', last_down: localStamp(4 * 3600000, base) },
            { id: M8, description: 'long dead link', agent_id: A1, agent_name: 'edge-a', target_id: T8, target_address: 'legacy-gw.example', last_down: localStamp(6 * 3600000, base) }
        ]
    }
}

/*
 * A fetch stub that answers per endpoint. Keys of options.fail make
 * that endpoint throw instead (options.fail.every fails all three at
 * once). The options object is read on every call, so a test can flip
 * endpoints to failing between two refreshes.
 */
export function makeFetchStub(options = {}) {
    return vi.fn(async (url) => {
        const fail = options.fail || {}
        if (fail.every) throw fail.every
        if (url.startsWith('/cgi-bin/api/dashboard')) {
            if (fail.dashboard) throw fail.dashboard
            return jsonReply(options.dashboard || dashBody())
        }
        if (url.startsWith('/cgi-bin/api/agents')) {
            if (fail.agents) throw fail.agents
            return jsonReply(options.agents || agentsBody())
        }
        if (url.startsWith('/cgi-bin/api/monitors')) {
            if (fail.down) throw fail.down
            return jsonReply(options.down || downBody())
        }
        throw new Error('unexpected url: ' + url)
    })
}