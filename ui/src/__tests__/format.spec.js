/*
 * Color/threshold rules in format.js are the contract between the API
 * and the page, so they get the closest reading. The clock is pinned
 * so "3 hours ago" stays exactly 3 hours ago for the whole run.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
    AGENT_STALE_MS,
    DANGER_HOURS,
    REFRESH_SECONDS,
    WARN_HOURS,
    agentClass,
    downAge,
    downRowClass,
    fmtDownSince,
    lossClass,
    parseApiUtc
} from '../format'
import { localStamp } from './stubs'

beforeEach(() => {
    vi.useFakeTimers({ now: Date.parse('2026-09-07T12:00:00') })
})
afterEach(() => {
    vi.useRealTimers()
})

describe('format.js threshold constants', () => {
    // Guard the documented rules so a drive-by edit cannot silently
    // change what the colors mean.
    it('keep the documented warn/danger/stale values', () => {
        expect(WARN_HOURS).toBe(3)
        expect(DANGER_HOURS).toBe(5)
        expect(AGENT_STALE_MS).toBe(60 * 60 * 1000)
        expect(REFRESH_SECONDS).toBe(30)
    })
})

describe('downRowClass', () => {
    it('warns once a monitor has been down three hours or more', () => {
        expect(downRowClass({ last_down: localStamp(2 * 3600000) })).toBe('')
        expect(downRowClass({ last_down: localStamp(3 * 3600000) })).toBe('row-warn')
        expect(downRowClass({ last_down: localStamp(4 * 3600000) })).toBe('row-warn')
    })

    it('goes danger at five hours and stays there', () => {
        expect(downRowClass({ last_down: localStamp(5 * 3600000) })).toBe('row-danger')
        expect(downRowClass({ last_down: localStamp(30 * 3600000) })).toBe('row-danger')
    })

    it('colors nothing when the down time is missing or unreadable', () => {
        expect(downRowClass({})).toBe('')
        expect(downRowClass({ last_down: '' })).toBe('')
        expect(downRowClass({ last_down: 'not-a-date' })).toBe('')
    })
})

describe('agentClass', () => {
    it('marks an agent stale after an hour of silence', () => {
        expect(agentClass({ last_seen: localStamp(59 * 60000) })).toBe('chip-ok')
        expect(agentClass({ last_seen: localStamp(AGENT_STALE_MS + 60000) })).toBe('chip-stale')
        expect(agentClass({ last_seen: localStamp(26 * 3600000) })).toBe('chip-stale')
    })

    it('treats a missing heartbeat as fine rather than stale', () => {
        // Missing data should not paint an agent red; the api layer is
        // where a missing heartbeat gets flagged, not here.
        expect(agentClass({})).toBe('chip-ok')
    })
})

describe('lossClass', () => {
    it('warns from one percent and dangers at full loss', () => {
        expect(lossClass(0)).toBe('chip-ok')
        expect(lossClass(0.99)).toBe('chip-ok')
        expect(lossClass(1)).toBe('chip-warn')
        expect(lossClass(37.5)).toBe('chip-warn')
        expect(lossClass(100)).toBe('chip-danger')
        expect(lossClass(100.0)).toBe('chip-danger')
    })

    it('coerces sloppy input instead of crashing on it', () => {
        expect(lossClass('100')).toBe('chip-danger')
        expect(lossClass('0')).toBe('chip-ok')
        expect(lossClass(undefined)).toBe('chip-ok')
        expect(lossClass(null)).toBe('chip-ok')
    })
})

describe('down-age formatting', () => {
    it('shows minutes, then hours, then days as down time grows', () => {
        expect(downAge(localStamp(5 * 60000))).toBe('5m')
        expect(downAge(localStamp(90 * 60000))).toBe('1h 30m')
        expect(downAge(localStamp(50 * 3600000))).toBe('2d 2h')
    })

    it('falls back to a dash for junk and never goes negative', () => {
        expect(downAge('garbage')).toBe('-')
        expect(downAge('')).toBe('-')
        // A timestamp slightly in the future clamps to zero minutes.
        expect(downAge(localStamp(-3 * 60000))).toBe('0m')
    })
})

describe('api stamps are read as UTC, not as the viewer zone', () => {
    // The fake clock sits exactly on the literal stamp 2026-09-07
    // 12:00:00 UTC, so every expected duration below is a round
    // number of the api contract — no dependence on the box's zone.
    const NOW_UTC = Date.parse('2026-09-07T12:00:00Z')
    const priorTz = process.env.TZ
    beforeEach(() => {
        vi.useFakeTimers({ now: NOW_UTC })
    })
    afterEach(() => {
        // Put the runner zone back exactly as found (unset stays
        // unset) so nothing leaks into another spec file's worker.
        if (priorTz === undefined) delete process.env.TZ
        else process.env.TZ = priorTz
    })

    it('a stamp that is right now reads 0m from a Manila runner too', () => {
        // The old local reading put this same literal 8 hours in the
        // past for UTC+8 and answered '8h 0m' — that was the bug.
        process.env.TZ = 'Asia/Manila'
        expect(downAge('2026-09-07 12:00:00')).toBe('0m')
        expect(downRowClass({ last_down: '2026-09-07 12:00:00' })).toBe('')
        process.env.TZ = 'UTC'
        expect(downAge('2026-09-07 12:00:00')).toBe('0m')
    })

    it('ages and rows land on the same answers from Manila as from UTC', () => {
        process.env.TZ = 'Asia/Manila'
        expect(downAge('2026-09-07 10:30:00')).toBe('1h 30m')
        expect(downRowClass({ last_down: '2026-09-07 10:00:00' })).toBe('')
        expect(downRowClass({ last_down: '2026-09-07 09:00:00' })).toBe('row-warn')
        expect(downRowClass({ last_down: '2026-09-07 07:00:00' })).toBe('row-danger')
        expect(agentClass({ last_seen: '2026-09-07 11:01:00' })).toBe('chip-ok')
        expect(agentClass({ last_seen: '2026-09-07 10:59:00' })).toBe('chip-stale')
        process.env.TZ = 'UTC'
        expect(downAge('2026-09-07 10:30:00')).toBe('1h 30m')
        expect(downRowClass({ last_down: '2026-09-07 10:00:00' })).toBe('')
        expect(downRowClass({ last_down: '2026-09-07 09:00:00' })).toBe('row-warn')
        expect(downRowClass({ last_down: '2026-09-07 07:00:00' })).toBe('row-danger')
        expect(agentClass({ last_seen: '2026-09-07 11:01:00' })).toBe('chip-ok')
        expect(agentClass({ last_seen: '2026-09-07 10:59:00' })).toBe('chip-stale')
    })

    it('parseApiUtc tags the zone and refuses what the api would never send', () => {
        // Every flavor of the api shape lands on the same epoch, the
        // one UTC says the stamp names.
        const epoch = Date.parse('2026-08-03T01:03:04Z')
        expect(parseApiUtc('2026-08-03 01:03:04')).toBe(epoch)
        expect(parseApiUtc('2026-08-03T01:03:04')).toBe(epoch)
        expect(parseApiUtc('2026-08-03T01:03:04Z')).toBe(epoch)
        expect(parseApiUtc('2026-08-03T01:03:04+05:30')).toBe(Date.parse('2026-08-03T01:03:04+05:30'))
        // Junk keeps the callers' dash/no-color fallbacks alive.
        expect(parseApiUtc('garbage')).toBeNaN()
        expect(parseApiUtc('not-a-date')).toBeNaN()
        expect(parseApiUtc('')).toBeNaN()
        expect(parseApiUtc(null)).toBeNaN()
        expect(parseApiUtc(undefined)).toBeNaN()
    })
})

describe('fmtDownSince', () => {
    it('compresses the api timestamp for the table column', () => {
        expect(fmtDownSince('2026-08-03 01:03:04')).toBe('08/03 01:03:04')
        expect(fmtDownSince('2026-08-03T01:03:04')).toBe('08/03 01:03:04')
    })

    it('leaves junk alone and shows a dash for nothing', () => {
        expect(fmtDownSince('soon')).toBe('soon')
        expect(fmtDownSince('')).toBe('-')
        expect(fmtDownSince(null)).toBe('-')
    })
})