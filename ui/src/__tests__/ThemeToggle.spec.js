/*
 * ThemeToggle specs: the button in the .nav-end cluster offers the
 * mode it would switch to (the classic footer toggle's sun/moon
 * convention), and each click flips html[data-theme] and persists the
 * classic 'wanportal-theme' key — 'light' after going light, 'dark'
 * after going back — so /classic keeps the same choice.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import ThemeToggle from '../components/ThemeToggle.vue'
import { THEME_KEY } from '../theme'

enableAutoUnmount(afterEach)

afterEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
})

describe('ThemeToggle', () => {
    it('offers light while the dark default is active', () => {
        const wrapper = mount(ThemeToggle)
        const btn = wrapper.find('button')
        expect(btn.classes()).toEqual(expect.arrayContaining(['btn', 'theme-toggle']))
        // The sun glyph is a numeric entity in the template; this pins
        // that Vue decodes it rather than shipping the raw '&amp;#...'.
        expect(btn.text()).toBe('\u2600 Light')
        expect(btn.attributes('aria-label')).toBe('Toggle light/dark theme')
        // The dark default carries no attribute at all.
        expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
    })

    it('flips to light on click and persists the shared key', async () => {
        const wrapper = mount(ThemeToggle)
        await wrapper.find('button').trigger('click')

        expect(document.documentElement.getAttribute('data-theme')).toBe('light')
        expect(localStorage.getItem(THEME_KEY)).toBe('light')
        expect(wrapper.find('button').text()).toBe('\u263d Dark')
    })

    it('flips back to dark, clearing the attribute and persisting dark', async () => {
        const wrapper = mount(ThemeToggle)
        await wrapper.find('button').trigger('click')
        await wrapper.find('button').trigger('click')

        expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
        expect(localStorage.getItem(THEME_KEY)).toBe('dark')
        expect(wrapper.find('button').text()).toBe('\u2600 Light')
    })

    it('opens in light mode when the boot restore already ran', () => {
        localStorage.setItem(THEME_KEY, 'light')
        document.documentElement.setAttribute('data-theme', 'light')

        const wrapper = mount(ThemeToggle)
        expect(wrapper.find('button').text()).toContain('Dark')
    })
})