/*
 * Light/dark theme for the SPA. Dark is the default (the :root tokens
 * in styles/base.css); light is opt-in via html[data-theme="light"].
 * The localStorage key is the one the classic console already writes
 * (htdocs/footer.php's dark-mode toggle, 'wanportal-theme' with
 * 'dark'/'light' values), so a choice made on /classic follows the
 * visitor into the SPA and vice versa — classic sets data-bs-theme
 * where the SPA sets data-theme, but both persist under the same key.
 *
 * Every localStorage access is wrapped: Safari private mode and some
 * embedded WebViews throw on getItem, and the page must not error out
 * over a theme preference.
 */

/* Shared with htdocs/footer.php and htdocs/lib/page.php — do not drift. */
export const THEME_KEY = 'wanportal-theme'

/* The persisted choice, or null when unset / storage unavailable. */
export function storedTheme() {
    try {
        return localStorage.getItem(THEME_KEY)
    } catch (e) {
        return null
    }
}

/* Light is carried by the data-theme attribute; anything else is dark. */
export function isLight() {
    return document.documentElement.getAttribute('data-theme') === 'light'
}

/* Boot-time restore, called from main.js before the shell mounts so
 * the first paint already carries the visitor's choice. 'light' turns
 * the light tokens on; every other value (absent, 'dark', junk left
 * by an older build) normalizes to the dark default. */
export function applyStoredTheme() {
    if (storedTheme() === 'light') {
        document.documentElement.setAttribute('data-theme', 'light')
    } else {
        document.documentElement.removeAttribute('data-theme')
    }
}

/* Apply a mode and persist it — the same values footer.php's toggle
 * writes, so the two consoles agree on what the key means. */
export function setTheme(light) {
    if (light) {
        document.documentElement.setAttribute('data-theme', 'light')
    } else {
        document.documentElement.removeAttribute('data-theme')
    }
    try {
        localStorage.setItem(THEME_KEY, light ? 'light' : 'dark')
    } catch (e) { /* storage may be disabled; the page still themes */ }
    return light
}

/* Flip the current mode and return the new one. */
export function toggleTheme() {
    return setTheme(!isLight())
}