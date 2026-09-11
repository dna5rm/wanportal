/*
 * prefs module specs: the SPA's stand-in for the classic console's
 * show-inactive session flag (lib/page.php's wanportal_get_show_inactive
 * plus the URL round-trip hook it renders). Every rule mirrors the
 * classic contract — URL query wins, stored choice next, false last,
 * resolution written back to the store, toggle round-tripped through
 * the URL — and storage is exercised both as jsdom's real localStorage
 * and as a throwing fake, the way Safari private mode fails. The URL
 * side is driven through history.replaceState, the same call the
 * toggle itself makes.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
    SHOW_INACTIVE_KEY,
    parseShowInactive,
    resolveShowInactive,
    setShowInactive,
    showInactiveInUrl,
    storedShowInactive
} from '../prefs'

afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
    // Drop whatever the toggle round-trip left in the address bar.
    window.history.replaceState(null, '', window.location.pathname)
})

/* Land the tab on a URL the way a visit or a navigation would. */
function landOn(url) {
    window.history.replaceState(null, '', url)
}

/* A storage that throws like Safari private mode / some WebViews. */
function blockedStorage() {
    vi.stubGlobal('localStorage', {
        getItem() { throw new Error('blocked') },
        setItem() { throw new Error('blocked') },
        removeItem() {},
        clear() {}
    })
}

describe('parseShowInactive matches FILTER_VALIDATE_BOOLEAN', () => {
    it('reads the truthy spellings, case-insensitively', () => {
        for (const raw of ['1', 'true', 'TRUE', 'on', 'On', 'yes', 'YES']) {
            expect(parseShowInactive(raw)).toBe(true)
        }
    })

    it('reads everything else as false, junk included', () => {
        for (const raw of ['0', 'false', 'FALSE', 'off', 'no', '', '2', 'maybe', null, undefined]) {
            expect(parseShowInactive(raw)).toBe(false)
        }
    })
})

describe('showInactiveInUrl (the $_GET override)', () => {
    it('is null when no param is present anywhere', () => {
        landOn('/#/agents/b1')
        expect(showInactiveInUrl()).toBe(null)
    })

    it('reads the real query string both ways', () => {
        landOn('/?show_inactive=true#/agents/b1')
        expect(showInactiveInUrl()).toBe(true)
        landOn('/?show_inactive=false#/agents/b1')
        expect(showInactiveInUrl()).toBe(false)
    })

    it('reads the hash query, like the detail-page id does', () => {
        landOn('/#/agents/b1?show_inactive=true')
        expect(showInactiveInUrl()).toBe(true)
        landOn('/#/agents/b1?show_inactive=false')
        expect(showInactiveInUrl()).toBe(false)
    })

    it('lets the real query string win over the hash', () => {
        landOn('/?show_inactive=false#/agents/b1?show_inactive=true')
        expect(showInactiveInUrl()).toBe(false)
    })

    it('treats an empty value as false, like isset + empty string in PHP', () => {
        landOn('/?show_inactive=')
        expect(showInactiveInUrl()).toBe(false)
    })
})

describe('storedShowInactive (the $_SESSION stand-in)', () => {
    it('is null when nothing is stored', () => {
        expect(storedShowInactive()).toBe(null)
    })

    it('round-trips the stored strings', () => {
        localStorage.setItem(SHOW_INACTIVE_KEY, 'true')
        expect(storedShowInactive()).toBe(true)
        localStorage.setItem(SHOW_INACTIVE_KEY, 'false')
        expect(storedShowInactive()).toBe(false)
    })

    it('normalizes junk to the default', () => {
        localStorage.setItem(SHOW_INACTIVE_KEY, 'banana')
        expect(storedShowInactive()).toBe(false)
    })

    it('is null when storage throws on read', () => {
        blockedStorage()
        expect(storedShowInactive()).toBe(null)
    })
})

describe('resolveShowInactive (the classic resolution + write-back)', () => {
    it('defaults false on a first visit and writes the flag back like the session does', () => {
        landOn('/#/agents/b1')
        expect(resolveShowInactive()).toBe(false)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('false')
    })

    it('falls back to the stored choice when the url is silent', () => {
        landOn('/#/agents/b1')
        localStorage.setItem(SHOW_INACTIVE_KEY, 'true')
        expect(resolveShowInactive()).toBe(true)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('true')
    })

    it('lets the URL query win over the stored choice and writes it back', () => {
        landOn('/?show_inactive=false#/agents/b1')
        localStorage.setItem(SHOW_INACTIVE_KEY, 'true')
        expect(resolveShowInactive()).toBe(false)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('false')
        // The URL itself is untouched on load — no reload, no rewrite.
        expect(window.location.search).toBe('?show_inactive=false')
    })

    it('counts the hash query as the URL override', () => {
        landOn('/#/agents/b1?show_inactive=true')
        expect(resolveShowInactive()).toBe(true)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('true')
    })

    it('resolves without throwing when storage is blocked', () => {
        blockedStorage()
        landOn('/?show_inactive=true')
        expect(resolveShowInactive()).toBe(true)
    })
})

describe('setShowInactive (the toggle round-trip)', () => {
    it('persists the choice as the true/false strings', () => {
        landOn('/#/users')
        setShowInactive(true)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('true')
        setShowInactive(false)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('false')
    })

    it('replaceStates the query without losing the other params or the hash', () => {
        landOn('/?q=edge#/agents/b1')
        setShowInactive(true)
        expect(window.location.search).toBe('?q=edge&show_inactive=true')
        expect(window.location.hash).toBe('#/agents/b1')
        expect(window.location.pathname).toBe('/')
    })

    it('rewrites the param in place in both directions, like the classic hook', () => {
        landOn('/?q=edge#/users')
        setShowInactive(true)
        setShowInactive(false)
        // The param is set to 'false', never deleted — the classic
        // toggle round-trips the explicit value through the URL.
        expect(window.location.search).toBe('?q=edge&show_inactive=false')
    })

    it('can persist without touching the address bar (the load-time write-back)', () => {
        landOn('/#/users')
        setShowInactive(true, { url: false })
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('true')
        expect(window.location.search).toBe('')
    })

    it('still applies the choice when storage refuses to persist it', () => {
        blockedStorage()
        landOn('/#/users')
        expect(setShowInactive(true)).toBe(true)
        expect(storedShowInactive()).toBe(null)
        expect(window.location.search).toBe('?show_inactive=true')
    })

    it('still applies the choice when history refuses the URL update', () => {
        landOn('/#/users')
        vi.stubGlobal('history', {
            replaceState() { throw new Error('blocked') }
        })
        expect(setShowInactive(true)).toBe(true)
        expect(localStorage.getItem(SHOW_INACTIVE_KEY)).toBe('true')
    })
})

describe('the choice survives leaving the page', () => {
    it('rides the shared key from the users listing to the agent detail', () => {
        // Toggle on the users listing: stored AND carried in the url.
        landOn('/#/users')
        setShowInactive(true)
        expect(window.location.search).toBe('?show_inactive=true')

        // Leaving the listing only changes the hash — the query rides
        // along, and the agent detail resolves the same choice.
        window.location.hash = '#/agents/b1'
        expect(resolveShowInactive()).toBe(true)

        // A fresh tab shares the storage even without the query.
        landOn('/#/agents/b1')
        expect(resolveShowInactive()).toBe(true)
    })

    it('unchecking on one page carries the off choice to the next', () => {
        localStorage.setItem(SHOW_INACTIVE_KEY, 'true')
        landOn('/#/agents/b1')
        setShowInactive(false)
        landOn('/#/users')
        expect(resolveShowInactive()).toBe(false)
    })
})