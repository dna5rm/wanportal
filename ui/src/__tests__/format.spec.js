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
    lossClass
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