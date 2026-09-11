/*
 * GuideView specs. The guide reader is the SPA-native stand-in for the
 * classic Parsedown door: it fetches the bare *.md file straight from
 * Apache as text, parses it with the bundled marked, and renders into
 * a panel. These specs pin the wire contract (same-origin GET with
 * credentials omitted, a 10s abort signal, no index.php anywhere), the
 * rendering (the guide's own h1 heading inside the panel, code spans
 * escaped by marked's defaults), the name guard (separators, dots,
 * non-md and empty names are refused before any fetch), the honesty
 * rules (a 404 or dead fetch shows a banner and never a body), the
 * slim chrome (no h1 of the view's own — the markdown carries the
 * title — and the back door to /api), and the reload when the file
 * prop changes under a reused route.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import GuideView from '../components/GuideView.vue'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
})

/* Shape of a same-origin markdown reply: the status line plus the
 * text() reader the view consumes. Nothing else is offered — anything
 * the view needs beyond these is a bug in the view. */
function textReply(text, ok = true, status = 200) {
    return { ok, status, text: async () => text }
}

/* The guide the stub serves by default: an h1 title plus fenced and
 * inline code, so one render proves heading, fence and escaping. */
const MD = [
    '# Agent image capture',
    '',
    'Pull the image, then run it:',
    '',
    '```',
    'docker pull netping:latest',
    '```',
    '',
    'Inline `code <tag>` stays escaped.'
].join('\n')

/* A memory-history router backs the back door, so no real navigation
 * runs and the sibling router.js (with its full view imports) stays
 * out of this spec entirely. */
function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/api', name: 'api', component: { template: '<div>api</div>' } }]
    })
}

/* One keyed fetch stub. The default answers every guide url with MD;
 * a reply function may answer per-url, and a reject throws from the
 * network itself. The calls stay inspectable for the wire contract. */
async function mountGuide(file, options = {}) {
    const stub = vi.fn(async (url, init) => {
        if (options.reject) throw options.reject
        const reply = typeof options.reply === 'function' ? options.reply(url) : options.reply
        return reply || textReply(MD)
    })
    vi.stubGlobal('fetch', stub)

    const router = makeRouter()
    const wrapper = mount(GuideView, { props: { file }, global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()
    return { wrapper, stub, calls: () => stub.mock.calls }
}

describe('GuideView rendering', () => {
    it('fetches the bare *.md file from apache and renders the heading into the panel', async () => {
        const { wrapper, calls } = await mountGuide('agent-image.md')

        // The wire: one same-origin static GET of the file itself — no
        // query string, no Parsedown renderer in the loop.
        expect(calls()).toHaveLength(1)
        const [url, init] = calls()[0]
        expect(url).toBe('/api-docs/agent-image.md')
        expect(url).not.toContain('index.php')
        expect(init.method).toBe('GET')
        expect(init.credentials).toBe('omit')
        expect(init.signal).toBeInstanceOf(AbortSignal)

        // markdown → html: the guide's own h1 renders inside the panel.
        const body = wrapper.find('.guide-body')
        expect(body.exists()).toBe(true)
        expect(body.find('h1').text()).toBe('Agent image capture')
        expect(wrapper.html()).toContain('<h1>Agent image capture</h1>')
    })

    it('renders through marked with code escaped by the defaults', async () => {
        const { wrapper } = await mountGuide('agent-image.md')

        // The fenced block renders as a code block...
        expect(wrapper.find('.guide-body pre code').text()).toContain('docker pull netping:latest')
        // ...and the inline span keeps its angle brackets escaped —
        // the raw tag never appears as markup.
        const span = wrapper.find('.guide-body p code')
        expect(span.html()).toContain('&lt;tag&gt;')
        expect(wrapper.html()).not.toContain('<tag>')
    })

    it('keeps the slim chrome: no h1 of its own and a back door to /api', async () => {
        const { wrapper } = await mountGuide('agent-image.md')

        // The guide's title is the markdown's first heading; the bar
        // adds no second h1 of its own.
        expect(wrapper.find('.bar h1').exists()).toBe(false)

        const back = wrapper.find('.bar a.btn')
        expect(back.exists()).toBe(true)
        expect(back.text()).toMatch(/back/i)
        // Resolved under hash history this is '#/api' — either way the
        // door lands on the api page, same-origin only.
        expect(back.attributes('href')).toMatch(/\/api$/)

        // No renderer doors, no CDN refs anywhere in the page.
        expect(wrapper.html()).not.toContain('index.php')
        expect(wrapper.html()).not.toContain('cdn.')
    })
})

describe('GuideView name guard', () => {
    it('rejects path traversal before any byte goes on the wire', async () => {
        const { wrapper, calls } = await mountGuide('../../x.md')

        expect(calls()).toHaveLength(0)
        expect(wrapper.find('.banner').exists()).toBe(true)
        expect(wrapper.text()).toContain('rejected')
        expect(wrapper.find('.guide-body').exists()).toBe(false)
    })

    it('rejects separators, non-md names and empty names — every one with a banner', async () => {
        for (const bad of ['sub/notes.md', 'notes.txt', '', '\\..\\win.md', 'a..b.md']) {
            const { wrapper, calls } = await mountGuide(bad)
            expect(calls(), bad).toHaveLength(0)
            expect(wrapper.find('.banner').exists(), bad).toBe(true)
            expect(wrapper.find('.guide-body').exists(), bad).toBe(false)
        }
    })
})

describe('GuideView honesty', () => {
    it('shows a banner, and no fabricated body, on a 404', async () => {
        const { wrapper } = await mountGuide('agent-image.md', {
            reply: textReply('fabricated body', false, 404)
        })

        const banner = wrapper.find('.banner')
        expect(banner.exists()).toBe(true)
        expect(banner.text()).toContain('not found')
        expect(banner.text()).toContain('404')
        expect(wrapper.text()).not.toContain('fabricated body')
        expect(wrapper.find('.guide-body').exists()).toBe(false)
    })

    it('names the status of any other refused answer', async () => {
        const { wrapper } = await mountGuide('agent-image.md', {
            reply: textReply('', false, 500)
        })

        expect(wrapper.find('.banner').text()).toContain('HTTP 500')
        expect(wrapper.find('.guide-body').exists()).toBe(false)
    })

    it('says so when the fetch dies instead of rendering nothing quietly', async () => {
        const { wrapper } = await mountGuide('agent-image.md', {
            reject: new Error('connection refused')
        })

        expect(wrapper.find('.banner').exists()).toBe(true)
        expect(wrapper.find('.banner').text()).toContain('connection refused')
        expect(wrapper.find('.guide-body').exists()).toBe(false)
    })

    it('calls an empty file honestly rather than painting a blank panel', async () => {
        const { wrapper } = await mountGuide('empty.md', { reply: textReply('', true, 200) })

        expect(wrapper.find('.guide-body').exists()).toBe(false)
        expect(wrapper.find('.banner').exists()).toBe(false)
        expect(wrapper.text()).toContain('no content')
    })

    it('aborts a stalled fetch after ten seconds and says so', async () => {
        vi.useFakeTimers()
        // A fetch that honours the signal, as a real apache read would:
        // the abort kills it instead of leaving the panel loading.
        const stub = vi.fn((url, init) => new Promise((resolve, reject) => {
            init.signal.addEventListener('abort', () =>
                reject(new DOMException('The operation was aborted.', 'AbortError')))
        }))
        vi.stubGlobal('fetch', stub)
        const wrapper = mount(GuideView, { props: { file: 'slow.md' }, global: { plugins: [makeRouter()] } })
        await vi.advanceTimersByTimeAsync(0)

        expect(wrapper.find('.banner').exists()).toBe(false)
        expect(wrapper.text()).toContain('loading guide…')

        await vi.advanceTimersByTimeAsync(9999)
        expect(wrapper.find('.banner').exists()).toBe(false)

        await vi.advanceTimersByTimeAsync(1)
        const banner = wrapper.find('.banner')
        expect(banner.exists()).toBe(true)
        expect(banner.text()).toContain('timed out')
        expect(wrapper.find('.guide-body').exists()).toBe(false)
    })
})

describe('GuideView reloads', () => {
    it('re-fetches and repaints when the file prop changes under the route', async () => {
        const { wrapper, calls } = await mountGuide('agent-image.md', {
            reply: (url) => textReply(url.endsWith('tcpdump.md')
                ? '# Tcpdump\n\nsniff the wire.'
                : MD)
        })
        expect(calls()[0][0]).toBe('/api-docs/agent-image.md')
        expect(wrapper.find('.guide-body h1').text()).toBe('Agent image capture')

        await wrapper.setProps({ file: 'tcpdump.md' })
        await flushPromises()
        await flushPromises()

        expect(calls()[1][0]).toBe('/api-docs/tcpdump.md')
        expect(wrapper.find('.guide-body h1').text()).toBe('Tcpdump')
        expect(wrapper.find('.banner').exists()).toBe(false)
    })

    it('drops a superseded read: a failed slow guide never wins over a later one', async () => {
        // First guide stalls forever; the prop change must still land
        // the second guide and leave no banner from the stale read.
        const stalled = new Promise(() => {})
        const stub = vi.fn((url) => url.endsWith('slow.md')
            ? stalled
            : textReply('# Fast\n\nquick guide.'))
        vi.stubGlobal('fetch', stub)
        const wrapper = mount(GuideView, { props: { file: 'slow.md' }, global: { plugins: [makeRouter()] } })
        await flushPromises()
        expect(wrapper.text()).toContain('loading guide…')

        await wrapper.setProps({ file: 'fast.md' })
        await flushPromises()
        await flushPromises()

        expect(wrapper.find('.guide-body h1').text()).toBe('Fast')
        expect(wrapper.find('.banner').exists()).toBe(false)
    })
})