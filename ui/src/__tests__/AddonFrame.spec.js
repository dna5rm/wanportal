/*
 * AddonFrame embeds a sidecar page inside the SPA shell: one
 * borderless iframe under the topnav, the src carrying embed=1 so
 * sidecar pages drop their own chrome, and no target=_blank anywhere
 * — a sidecar door that pops a new tab is exactly what these routes
 * must not do. The addon routes are exercised here too: they
 * resolve to AddonFrame with their sidecar srcs and stay public (no
 * meta.auth), so the router gate never asks the session API for them.
 * The theme contract is exercised here too: data-theme on <html> is
 * the SPA's mode carrier (ThemeToggle sets/removes it), and every
 * change is postMessage'd into the frame as wanportal-theme.
 */
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { mount } from '@vue/test-utils'
import AddonFrame from '../components/AddonFrame.vue'
import router from '../router.js'

const SRC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..')

/* MutationObserver callbacks are microtasks; a macrotask turn
 * guarantees they have run before the assertions look. */
async function flushObserver() {
    await new Promise((resolve) => setTimeout(resolve, 0))
}

/* Stub the frame's contentWindow.postMessage — jsdom's real one just
 * loops the message back to the same window, and the test wants to
 * see the envelope and the targetOrigin exactly. */
function stubFramePostMessage(wrapper) {
    const postMessage = vi.fn()
    Object.defineProperty(wrapper.find('iframe').element, 'contentWindow', {
        value: { postMessage },
        configurable: true
    })
    return postMessage
}

describe('AddonFrame', () => {
    it('mounts with src /nb/ and renders the iframe with embed=1 appended', () => {
        const wrapper = mount(AddonFrame, { props: { src: '/nb/' } })

        const frame = wrapper.find('iframe')
        expect(frame.exists()).toBe(true)
        expect(frame.attributes('src')).toBe('/nb/?embed=1')
        expect(frame.attributes('src')).toContain('embed=1')
        // The accessible title defaults in; the route passes the
        // sidecar name when it wants a specific one.
        expect(frame.attributes('title')).toBe('wanportal addon')
    })

    it('leaves a src that already carries embed untouched', () => {
        const wrapper = mount(AddonFrame, { props: { src: '/nb/?embed=1' } })

        expect(wrapper.find('iframe').attributes('src')).toBe('/nb/?embed=1')
    })

    it('appends embed=1 to a src that has its own query', () => {
        const wrapper = mount(AddonFrame, { props: { src: '/nb/reports/sites.php?page=2' } })

        expect(wrapper.find('iframe').attributes('src')).toBe('/nb/reports/sites.php?page=2&embed=1')
    })

    it('carries no target=_blank anywhere', () => {
        const wrapper = mount(AddonFrame, { props: { src: '/nb/' } })

        expect(wrapper.html()).not.toContain('target=')
        // Nothing in the frame chrome links out of the SPA at all.
        expect(wrapper.findAll('a')).toHaveLength(0)
    })

    it('fills the space under the topnav without overflowing the body padding', () => {
        const wrapper = mount(AddonFrame, { props: { src: '/nb/' } })

        // The chrome classes are the contract jsdom can see — the
        // sizing ships in the scoped style and the spec stylesheet is
        // not injected into the jsdom document. The wrap owns the
        // height; the borderless frame fills it.
        expect(wrapper.find('.addon-frame-wrap > iframe.addon-frame').exists()).toBe(true)

        // The scoped style must pin the no-overflow sizing: the wrap
        // is the viewport minus the real topnav block and margin
        // (62.4px live) and the body's 30px bottom padding — 93px —
        // clipped so the SPA page never scrolls on an addon route,
        // and the frame fills the wrap at 100%. The old
        // calc(100vh - 56px) ignored the bottom padding and left the
        // page with a ~36px scrollbar.
        const source = readFileSync(join(SRC_DIR, 'components', 'AddonFrame.vue'), 'utf8')
        expect(source).toContain('height: calc(100vh - 93px)')
        expect(source).toContain('overflow: hidden')
        expect(source).not.toContain('calc(100vh - 56px)')
    })

    it('pushes theme light into the frame when data-theme flips to light', async () => {
        // Dark is the default: the attribute is absent until a flip.
        document.documentElement.removeAttribute('data-theme')
        const wrapper = mount(AddonFrame, { props: { src: '/nb/' } })
        const postMessage = stubFramePostMessage(wrapper)

        document.documentElement.setAttribute('data-theme', 'light')
        await flushObserver()

        expect(postMessage).toHaveBeenCalledWith(
            { type: 'wanportal-theme', theme: 'light' },
            window.location.origin
        )
        wrapper.unmount()
    })

    it('pushes theme dark into the frame when the data-theme attribute is removed', async () => {
        // ThemeToggle carries light on the attribute and drops it for
        // dark; the drop must reach the frame as theme 'dark'.
        document.documentElement.setAttribute('data-theme', 'light')
        const wrapper = mount(AddonFrame, { props: { src: '/nb/' } })
        const postMessage = stubFramePostMessage(wrapper)

        document.documentElement.removeAttribute('data-theme')
        await flushObserver()

        expect(postMessage).toHaveBeenCalledWith(
            { type: 'wanportal-theme', theme: 'dark' },
            window.location.origin
        )
        wrapper.unmount()
    })

    it('pushes the current theme on the frame load event', async () => {
        document.documentElement.setAttribute('data-theme', 'light')
        const wrapper = mount(AddonFrame, { props: { src: '/nb/' } })
        const postMessage = stubFramePostMessage(wrapper)

        // jsdom does not fetch the src, so simulate the document
        // arriving: the load listener must push the active mode.
        wrapper.find('iframe').element.dispatchEvent(new Event('load'))
        await flushObserver()

        expect(postMessage).toHaveBeenCalledWith(
            { type: 'wanportal-theme', theme: 'light' },
            window.location.origin
        )
        wrapper.unmount()
    })
})

describe('addon routes', () => {
    it('resolves the two addon doors to AddonFrame with their sidecar srcs', () => {
        const doors = [
            ['/addons/certs', 'addon-certs', '/nb/'],
            ['/addons/sites', 'addon-sites', '/nb/reports/sites.php']
        ]
        for (const [path, name, src] of doors) {
            const resolved = router.resolve(path)
            expect(resolved.name, path).toBe(name)
            expect(resolved.href, path).toBe('#' + path)
            const record = resolved.matched[resolved.matched.length - 1]
            expect(record.components.default, path).toBe(AddonFrame)
            expect(record.props.default, path).toEqual({ src })
        }
    })

    it('keeps the addon doors public — no meta.auth anywhere on them', () => {
        for (const path of ['/addons/certs', '/addons/sites']) {
            const resolved = router.resolve(path)
            expect(resolved.meta.auth, path).toBeUndefined()
            // No matched segment may carry the gate: the sidecars sit
            // outside check_session like the dashboard drill-down.
            for (const record of resolved.matched) {
                expect(record.meta.auth, path).toBeUndefined()
            }
        }
    })
})