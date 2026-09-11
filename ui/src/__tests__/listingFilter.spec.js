/*
 * listingFilter module specs: the persistent text filter behind the
 * agents/targets/monitors listings. Storage rules mirror prefs.js —
 * every access wrapped, junk storage reads as the empty default, and
 * a blocked storage (Safari private mode / some WebViews) must fail
 * silently instead of taking the page down. The match rule is shared
 * by all three views, so it is pinned here rather than drifted.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
    clearFilter,
    loadFilter,
    matchesFilter,
    saveFilter
} from '../listingFilter'

afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
})

/* A storage that throws like Safari private mode / some WebViews. */
function blockedStorage() {
    vi.stubGlobal('localStorage', {
        getItem() { throw new Error('blocked') },
        setItem() { throw new Error('blocked') },
        removeItem() { throw new Error('blocked') },
        clear() {}
    })
}

describe('loadFilter', () => {
    it('defaults to an empty filter when nothing is stored', () => {
        expect(loadFilter('agents')).toEqual({ q: '' })
    })

    it('reads back what saveFilter wrote', () => {
        saveFilter('agents', { q: 'core' })
        expect(loadFilter('agents')).toEqual({ q: 'core' })
    })

    it('keeps each page in its own key', () => {
        saveFilter('agents', { q: 'core' })
        saveFilter('monitors', { q: 'branch' })
        expect(loadFilter('agents')).toEqual({ q: 'core' })
        expect(loadFilter('monitors')).toEqual({ q: 'branch' })
        expect(loadFilter('targets')).toEqual({ q: '' })
    })

    it('defaults on junk JSON instead of throwing', () => {
        localStorage.setItem('wanportal-filter-agents', '{not json')
        expect(loadFilter('agents')).toEqual({ q: '' })
    })

    it('drops a stored shape without a string q', () => {
        localStorage.setItem('wanportal-filter-agents', '{"q":443,"sort":"desc"}')
        expect(loadFilter('agents')).toEqual({ q: '' })
        localStorage.setItem('wanportal-filter-agents', '{"rows":99}')
        expect(loadFilter('agents')).toEqual({ q: '' })
    })

    it('defaults when storage itself is unavailable', () => {
        blockedStorage()
        expect(loadFilter('agents')).toEqual({ q: '' })
    })
})

describe('saveFilter', () => {
    it('writes the wanportal-filter-<page> key as JSON {q}', () => {
        saveFilter('targets', { q: 'branch' })
        expect(localStorage.getItem('wanportal-filter-targets')).toBe('{"q":"branch"}')
    })

    it('coerces the q field to a string', () => {
        saveFilter('monitors', { q: 443 })
        expect(JSON.parse(localStorage.getItem('wanportal-filter-monitors'))).toEqual({ q: '443' })
        saveFilter('monitors', {})
        expect(JSON.parse(localStorage.getItem('wanportal-filter-monitors'))).toEqual({ q: '' })
    })

    it('is a no-op when storage is unavailable', () => {
        blockedStorage()
        expect(() => saveFilter('agents', { q: 'core' })).not.toThrow()
    })
})

describe('clearFilter', () => {
    it('removes the stored key', () => {
        saveFilter('agents', { q: 'core' })
        clearFilter('agents')
        expect(localStorage.getItem('wanportal-filter-agents')).toBeNull()
        expect(loadFilter('agents')).toEqual({ q: '' })
    })

    it('is a no-op when the key is absent or storage is unavailable', () => {
        expect(() => clearFilter('agents')).not.toThrow()
        saveFilter('targets', { q: 'branch' })
        blockedStorage()
        expect(() => clearFilter('targets')).not.toThrow()
    })
})

describe('matchesFilter — the one rule all three listings share', () => {
    const row = {
        id: 'aaaaaaaa-0000-4000-8000-000000000007',
        description: 'branch vpn',
        agent_name: 'edge-a',
        target_address: 'branch-gw.example',
        protocol: 'tcp',
        port: 443,
        is_active: 1,
        dscp: null
    }

    it('shows every row when the filter is empty', () => {
        expect(matchesFilter(row, '')).toBe(true)
        expect(matchesFilter(row, undefined)).toBe(true)
        expect(matchesFilter(null, '')).toBe(true)
    })

    it('matches any string field, case-insensitively', () => {
        expect(matchesFilter(row, 'BRANCH')).toBe(true)
        expect(matchesFilter(row, 'edge')).toBe(true)
        expect(matchesFilter(row, 'VPN')).toBe(true)
        expect(matchesFilter(row, '.example')).toBe(true)
    })

    it('stringifies every field, so numbers match as typed', () => {
        // Every field rides String(v): the numeric port and the active
        // flag match exactly as if typed into any string field.
        expect(matchesFilter(row, '443')).toBe(true)
        expect(matchesFilter(row, '1')).toBe(true)
    })

    it('matches an ip address over a partial octet, the shared spec', () => {
        expect(matchesFilter({ address: '8.8.8.8' }, '8.8')).toBe(true)
        expect(matchesFilter({ address: '8.8.8.8' }, '8.8.8.8')).toBe(true)
        expect(matchesFilter({ address: '8.8.8.8' }, '8.9')).toBe(false)
    })

    it('skips null and empty fields, so their string forms never hit', () => {
        // String(null) reads 'null' and String('') reads '' — neither
        // may hit, or a filter of 'null' would light every sparse row.
        expect(matchesFilter(row, 'null')).toBe(false)
        expect(matchesFilter({ description: '', port: 443 }, 'null')).toBe(false)
    })

    it('never matches nothing', () => {
        expect(matchesFilter(row, 'zzz')).toBe(false)
    })
})