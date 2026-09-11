/*
 * Theme module specs: boot restore, toggle, and the shared classic
 * localStorage key. The key is the one htdocs/classic/footer.php's dark-mode
 * toggle writes ('wanportal-theme', 'dark'/'light' values, dark sets
 * data-bs-theme where the SPA sets data-theme) — these specs pin the
 * values so the two consoles cannot drift. Storage is exercised both
 * as jsdom's real localStorage and as a throwing fake, the way Safari
 * private mode and embedded WebViews fail.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
    THEME_KEY,
    applyStoredTheme,
    isLight,
    setTheme,
    storedTheme,
    toggleTheme
} from '../theme'

afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
})

describe('boot restore (applyStoredTheme)', () => {
    it('turns the light tokens on when the classic key says light', () => {
        localStorage.setItem(THEME_KEY, 'light')
        applyStoredTheme()
        expect(document.documentElement.getAttribute('data-theme')).toBe('light')
        expect(isLight()).toBe(true)
    })

    it('keeps the dark default for every other persisted value', () => {
        for (const value of [null, 'dark', 'Dark', 'blue', '']) {
            if (value !== null) localStorage.setItem(THEME_KEY, value)
            applyStoredTheme()
            expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
            expect(isLight()).toBe(false)
        }
    })

    it('normalizes a stale data-theme attribute back to dark', () => {
        // e.g. an older build or another console left the attribute set
        document.documentElement.setAttribute('data-theme', 'light')
        applyStoredTheme()
        expect(isLight()).toBe(false)
    })

    it('survives a localStorage that throws on read', () => {
        vi.stubGlobal('localStorage', {
            getItem() { throw new Error('blocked') },
            setItem() { throw new Error('blocked') },
            removeItem() {},
            clear() {}
        })
        expect(storedTheme()).toBe(null)
        expect(() => applyStoredTheme()).not.toThrow()
        expect(isLight()).toBe(false)
    })
})

describe('setTheme / toggleTheme', () => {
    it('goes light: sets the attribute and persists light', () => {
        expect(setTheme(true)).toBe(true)
        expect(isLight()).toBe(true)
        expect(localStorage.getItem(THEME_KEY)).toBe('light')
    })

    it('goes dark: removes the attribute and persists dark like the classic toggle', () => {
        setTheme(true)
        expect(toggleTheme()).toBe(false)
        expect(isLight()).toBe(false)
        expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
        expect(localStorage.getItem(THEME_KEY)).toBe('dark')
    })

    it('toggles from the dark default on first click', () => {
        expect(toggleTheme()).toBe(true)
        expect(isLight()).toBe(true)
        expect(localStorage.getItem(THEME_KEY)).toBe('light')
    })

    it('applies the mode even when storage refuses to persist it', () => {
        vi.stubGlobal('localStorage', {
            getItem() { return null },
            setItem() { throw new Error('blocked') },
            removeItem() {},
            clear() {}
        })
        expect(toggleTheme()).toBe(true)
        expect(isLight()).toBe(true)
        expect(toggleTheme()).toBe(false)
        expect(isLight()).toBe(false)
    })
})