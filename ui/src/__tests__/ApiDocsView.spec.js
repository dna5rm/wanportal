/*
 * ApiDocsView specs. The view is a thin host around the bundled
 * swagger-ui-dist console: the module is mocked, and the specs pin the
 * things the view owns — the boot config (live same-origin spec, deep
 * links, try it out on, mounted into the host node), the markdown
 * guide doors the bar lists beside the raw-spec door (in-app
 * router-links to the guide route, built from the /cgi-bin/api/docs
 * glob, labels from title or filename-minus-.md, never invented), the
 * muted note when that call fails, and the unmount reset. There is no
 * yaml parser in the view and no endpoint list in code — the document
 * is the source of truth and swagger-ui renders whatever it carries.
 *
 * Mounts go through the real app router so the :to binding is
 * exercised for real: a door must come out as a hash href into
 * /guides/:file, never a /api-docs/index.php link.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'

vi.mock('swagger-ui-dist/swagger-ui-bundle', () => ({ default: vi.fn() }))
vi.mock('../api', () => ({ getJson: vi.fn() }))

import ApiDocsView from '../components/ApiDocsView.vue'
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle'
import { getJson } from '../api'
import router from '../router.js'

enableAutoUnmount(afterEach)

/* The app router is the plugin, so every router-link resolves against
 * the real route table — the doors come out as '#/guides/<file>'. */
const mountView = (options = {}) => mount(ApiDocsView, {
    ...options,
    global: { plugins: [router] }
})

beforeEach(() => {
    // The guide glob answers with an empty list by default; each its
    // overrides the body or the outcome as it needs.
    getJson.mockResolvedValue({ status: 'success', files: [] })
})

afterEach(() => {
    vi.unstubAllGlobals()
    getJson.mockReset()
})

describe('ApiDocsView boot', () => {
    it('boots the bundled console once, on the live same-origin spec', async () => {
        mountView()
        await flushPromises()

        expect(SwaggerUIBundle).toHaveBeenCalledTimes(1)
        const cfg = SwaggerUIBundle.mock.calls[0][0]
        expect(cfg.url).toBe('/api-docs/openapi.yaml')
        expect(cfg.dom_id).toBe('#swagger-ui')
        expect(cfg.deepLinking).toBe(true)
        expect(cfg.tryItOutEnabled).toBe(true)
    })

    it('renders the host node the console mounts into', () => {
        const wrapper = mountView()
        expect(wrapper.find('#swagger-ui').exists()).toBe(true)
    })

    it('empties the console host on unmount — no stacked console on revisit', async () => {
        // Stand in for the console: it paints into the host node.
        SwaggerUIBundle.mockImplementation(() => {
            const node = document.querySelector('#swagger-ui')
            if (node) node.innerHTML = '<div class="swagger-ui">rendered ops</div>'
        })
        const wrapper = mountView({ attachTo: document.body })
        await flushPromises()

        const host = wrapper.find('#swagger-ui').element
        expect(host.innerHTML).toContain('rendered ops')

        wrapper.unmount()
        // The console host is emptied by the view itself — without that
        // hook the detached node would keep the whole painted console.
        expect(host.innerHTML).toBe('')
    })
})

describe('ApiDocsView guide doors', () => {
    /* One glob reply, three entry shapes: a titled object, a bare
     * object, and a plain filename. Titles win; otherwise the filename
     * sheds its .md. */
    function docsBody() {
        return {
            status: 'success',
            files: [
                { name: 'agent-image.md', title: 'Agent image capture' },
                { name: 'db_schema.md' },
                'tcpdump.md'
            ]
        }
    }

    it('asks the CGI glob for the guide list', async () => {
        getJson.mockResolvedValue(docsBody())
        mount(ApiDocsView)
        await flushPromises()

        expect(getJson).toHaveBeenCalledWith('/cgi-bin/api/docs')
    })

    it('lists the markdown guides as in-app doors beside the raw spec', async () => {
        getJson.mockResolvedValue(docsBody())
        const wrapper = mountView()
        await flushPromises()

        // Title wins when the glob hands one over...
        const titled = wrapper.find('a[href="#/guides/agent-image.md"]')
        expect(titled.exists()).toBe(true)
        expect(titled.text()).toBe('Agent image capture')

        // ...otherwise the label is the filename minus .md, for both
        // object and plain-string entries.
        expect(wrapper.find('a[href="#/guides/db_schema.md"]').text()).toBe('db_schema')
        expect(wrapper.find('a[href="#/guides/tcpdump.md"]').text()).toBe('tcpdump')

        // Every door is the in-app guide route in the same tab — no
        // index.php hrefs, no new-tab chrome.
        const doors = wrapper.findAll('a[href^="#/guides/"]')
        expect(doors).toHaveLength(3)
        for (const door of doors) {
            expect(door.attributes('href')).not.toContain('index.php')
            expect(door.attributes('target')).toBeUndefined()
        }

        expect(wrapper.findAll('.doc-sep')).toHaveLength(2)
    })

    it('says so, muted, when the glob call fails — and fakes no files', async () => {
        // Route missing, apache hiccup, bad envelope: all one outcome.
        getJson.mockRejectedValue(new Error('HTTP 404'))
        const wrapper = mountView()
        await flushPromises()

        const note = wrapper.find('.bar-right .muted')
        expect(note.exists()).toBe(true)
        expect(note.text()).toContain('unavailable')

        // Not a single invented door anywhere.
        expect(wrapper.findAll('a[href^="#/guides/"]')).toHaveLength(0)
    })

    it('lists nothing — and no failure note — when the glob succeeds empty', async () => {
        const wrapper = mountView()
        await flushPromises()

        expect(wrapper.findAll('a[href^="#/guides/"]')).toHaveLength(0)
        expect(wrapper.find('.bar-right .muted').exists()).toBe(false)
    })
})

describe('ApiDocsView chrome', () => {
    it('keeps the slim bar: no second title, raw spec door, same-origin only', async () => {
        getJson.mockResolvedValue({ status: 'success', files: [] })
        const wrapper = mountView()
        await flushPromises()

        // The account bar already links this page as API, so no h1.
        expect(wrapper.find('h1').exists()).toBe(false)
        expect(wrapper.find('a[href="/api-docs/openapi.yaml"]').exists()).toBe(false)

        // The classic Parsedown renderer is out of the loop: no index.php
        // link anywhere, and the guides (when any exist) go through the
        // in-app guide route instead.
        expect(wrapper.html()).not.toContain('index.php')
        expect(wrapper.findAll('a[href^="#/guides/"]')).toHaveLength(0)

        // No cross-origin refs of any kind — the console comes from the
        // bundle, the spec from the same origin.
        expect(wrapper.findAll('a[href^="http"]')).toHaveLength(0)
        expect(wrapper.html()).not.toContain('unpkg')
        expect(wrapper.html()).not.toContain('cdn.jsdelivr')
        expect(wrapper.html()).not.toContain('cdnjs')
    })

    it('has no parser of its own — no endpoint tables to fall out of date', () => {
        const wrapper = mountView()
        // The old hand-rolled parser rendered one table per tag; the
        // console owns the operation list now, so none of that exists.
        expect(wrapper.find('table').exists()).toBe(false)
        expect(wrapper.findAll('section.panel')).toHaveLength(1)
    })
})