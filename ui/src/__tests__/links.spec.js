/*
 * Link-underline consistency specs. base.css used to color bare `a`
 * without touching text-decoration, so the user-agent stylesheet
 * underlined table name links while .btn and .nav-link stayed flat —
 * a mixed-underline look. The shared rule now flattens every link,
 * and these specs keep it that way: the mounted listing checks the
 * computed decoration of a name router-link and a .btn door, and the
 * on-disk checks pin base.css and sweep ui/src for any surviving
 * underline (scoped or not) outside this directory.
 *
 * jsdom caveat: with vitest's default css handling the compiled
 * stylesheets are not injected into the jsdom document, so
 * getComputedStyle().textDecorationLine often reads as ''. The mount
 * test asserts 'none' whenever a real cascade is present and falls
 * back to the on-disk rule assertions — which is also where the
 * grep-style sweep carries the guarantee.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TargetsView from '../components/TargetsView.vue'
import { getSession } from '../session'
import { jsonReply } from './stubs'

const SRC_DIR = join(dirname(fileURLToPath(import.meta.url)), '..')
const BASE_CSS = readFileSync(join(SRC_DIR, 'styles', 'base.css'), 'utf8')

/* The session module is mocked at its door like the sibling listing
 * specs; write doors are signed-in only, so the .btn New link renders. */
vi.mock('../session', async (importOriginal) => ({
    ...await importOriginal(),
    getSession: vi.fn()
}))

beforeEach(() => {
    getSession.mockResolvedValue({ authenticated: true, isAdmin: true })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getSession.mockReset()
    localStorage.clear()
})

enableAutoUnmount(afterEach)

async function mountListing() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'dashboard', component: { render: () => null } },
            { path: '/targets', name: 'targets', component: { render: () => null } },
            { path: '/targets/new', name: 'target-new', component: { render: () => null } },
            { path: '/targets/:id', name: 'target', component: { render: () => null }, props: true },
            { path: '/targets/:id/edit', name: 'target-edit', component: { render: () => null }, props: true }
        ]
    })
    await router.push('/')
    await router.isReady()

    vi.stubGlobal('fetch', vi.fn(async (url) => {
        if (url === '/cgi-bin/api/targets') {
            return jsonReply({
                status: 'success',
                targets: [
                    { id: 'cccccccc-0000-4000-8000-000000000005', address: 'branch-gw.example', description: 'branch site', is_active: 1 }
                ]
            })
        }
        throw new Error('unexpected url: ' + url)
    }))

    const wrapper = mount(TargetsView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return wrapper
}

describe('link underline consistency', () => {
    it('mounts a listing where the name link and the .btn agree on no underline', async () => {
        const wrapper = await mountListing()

        const name = wrapper.find('tbody td a')
        expect(name.exists()).toBe(true)
        expect(name.text()).toBe('branch-gw.example')

        const btn = wrapper.find('.bar-right a.btn')
        expect(btn.exists()).toBe(true)

        // When jsdom can compute a cascade, both elements must read
        // flat. With the default (unprocessed) css the property comes
        // back '' and the on-disk assertions below carry the check.
        for (const [label, el] of [
            ['table name link', name.element],
            ['bar .btn link', btn.element]
        ]) {
            const line = getComputedStyle(el).textDecorationLine
            if (line) expect(line, label).toBe('none')
        }
    })

    it('flattens the shared a rule and its hover state in base.css', () => {
        // The bare-element rule must carry the color AND the flat
        // decoration; `a\s*\{` cannot match `a:hover {`, so this pins
        // the base rule alone.
        const base = BASE_CSS.match(/a\s*\{[^}]*\}/)
        expect(base, 'an `a { … }` rule exists in base.css').not.toBeNull()
        expect(base[0]).toMatch(/color:\s*var\(--up\)/)
        expect(base[0]).toMatch(/text-decoration:\s*none/)

        // The hover state stays flat too — no third, underlined state.
        const hover = BASE_CSS.match(/a:hover\s*\{[^}]*\}/)
        expect(hover, 'an `a:hover { … }` rule exists in base.css').not.toBeNull()
        expect(hover[0]).toMatch(/text-decoration:\s*none/)
    })

    it('leaves no underline anywhere in ui/src outside the specs', () => {
        const offenders = []
        const walk = (dir) => {
            for (const entry of readdirSync(dir, { withFileTypes: true })) {
                const path = join(dir, entry.name)
                if (entry.isDirectory()) walk(path)
                else if (/\.(vue|js|css)$/.test(entry.name)) {
                    const text = readFileSync(path, 'utf8')
                    const lines = text.split('\n')
                    lines.forEach((text, i) => {
                        if (/text-decoration:\s*underline/i.test(text)) {
                            offenders.push(path.replace(SRC_DIR + '/', '') + ':' + (i + 1) + ' ' + text.trim())
                        }
                    })
                }
            }
        }
        walk(SRC_DIR)

        // Anything surfacing here needs to either go flat (the
        // consistent look) or live behind an explicit, justified
        // exemption (e.g. pre.code), not re-introduce the mixed look.
        expect(offenders).toEqual([])
    })
})