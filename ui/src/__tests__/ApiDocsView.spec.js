/*
 * ApiDocsView specs. The view is a thin host around the bundled
 * swagger-ui-dist console: the module is mocked, and the specs pin the
 * three things the view owns — the boot config (live same-origin spec,
 * deep links, try it out on, mounted into the host node), the slim
 * chrome (no second title — the account bar names the page, raw spec
 * door, nothing cross-origin), and the unmount reset. There is no yaml parser in the view anymore and no
 * endpoint list in code, so there is no fixture spec here either — the
 * document is the source of truth and swagger-ui renders whatever it
 * carries.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'

vi.mock('swagger-ui-dist/swagger-ui-bundle', () => ({ default: vi.fn() }))

import ApiDocsView from '../components/ApiDocsView.vue'
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

describe('ApiDocsView boot', () => {
    it('boots the bundled console once, on the live same-origin spec', async () => {
        mount(ApiDocsView)
        await flushPromises()

        expect(SwaggerUIBundle).toHaveBeenCalledTimes(1)
        const cfg = SwaggerUIBundle.mock.calls[0][0]
        expect(cfg.url).toBe('/api-docs/openapi.yaml')
        expect(cfg.dom_id).toBe('#swagger-ui')
        expect(cfg.deepLinking).toBe(true)
        expect(cfg.tryItOutEnabled).toBe(true)
    })

    it('renders the host node the console mounts into', () => {
        const wrapper = mount(ApiDocsView)
        expect(wrapper.find('#swagger-ui').exists()).toBe(true)
    })

    it('empties the console host on unmount — no stacked console on revisit', async () => {
        // Stand in for the console: it paints into the host node.
        SwaggerUIBundle.mockImplementation(() => {
            const node = document.querySelector('#swagger-ui')
            if (node) node.innerHTML = '<div class="swagger-ui">rendered ops</div>'
        })
        const wrapper = mount(ApiDocsView, { attachTo: document.body })
        await flushPromises()

        const host = wrapper.find('#swagger-ui').element
        expect(host.innerHTML).toContain('rendered ops')

        wrapper.unmount()
        // The console host is emptied by the view itself — without that
        // hook the detached node would keep the whole painted console.
        expect(host.innerHTML).toBe('')
    })
})

describe('ApiDocsView chrome', () => {
    it('keeps the slim bar: no second title, raw spec door', () => {
        const wrapper = mount(ApiDocsView)

        // The account bar already links this page as API, so no h1.
        expect(wrapper.find('h1').exists()).toBe(false)

        // The raw spec stays one plain same-origin hop.
        const yamlLink = wrapper.find('a[href="/api-docs/openapi.yaml"]')
        expect(yamlLink.exists()).toBe(true)
        expect(yamlLink.text()).toBe('openapi.yaml')

        // No classic php doors, no CDN refs of any kind — the console
        // comes from the bundle, the spec from the same origin.
        expect(wrapper.findAll('a[href*=".php"]')).toHaveLength(0)
        expect(wrapper.findAll('a[href^="http"]')).toHaveLength(0)
        expect(wrapper.html()).not.toContain('unpkg')
        expect(wrapper.html()).not.toContain('cdn.jsdelivr')
        expect(wrapper.html()).not.toContain('cdnjs')
    })

    it('has no parser of its own — no endpoint tables to fall out of date', () => {
        const wrapper = mount(ApiDocsView)
        // The old hand-rolled parser rendered one table per tag; the
        // console owns the operation list now, so none of that exists.
        expect(wrapper.find('table').exists()).toBe(false)
        expect(wrapper.findAll('section.panel')).toHaveLength(1)
    })
})