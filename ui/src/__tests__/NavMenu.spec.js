/*
 * NavMenu specs: the site-config menu that App renders after the
 * built-in public pages. A flat item is a router-link when it carries
 * `to`, an external door (target=_blank rel=noopener) when it carries
 * `href`, and an item with children opens a hover/click dropdown that
 * recurses into NavMenu for the children. The component is pure — it
 * takes the items as a prop and touches no fetch — so the specs mount
 * it against a memory router like the other chrome specs do.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import NavMenu from '../components/NavMenu.vue'

enableAutoUnmount(afterEach)

async function mountMenu(items) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: ['/', '/latency', '/guides'].map((path) => ({ path, component: { render: () => null } }))
    })
    await router.push('/')
    await router.isReady()
    return mount(NavMenu, {
        props: { items },
        global: { plugins: [router] }
    })
}

describe('NavMenu renders flat config items', () => {
    it('makes a `to` item a router-link and an `href` item an external door', async () => {
        const wrapper = await mountMenu([
            { label: 'Guides', to: '/guides' },
            { label: 'Status page', href: 'https://status.example.net' },
            { label: 'Just a label' }
        ])

        // The router-link resolves through the memory router to a
        // plain anchor with the route href.
        const links = wrapper.findAll('a')
        expect(links).toHaveLength(2)
        expect(links[0].attributes('href')).toBe('/guides')
        expect(links[0].text()).toBe('Guides')

        // The href item is a real external door: new tab, no
        // window.opener, and never a router-link.
        expect(links[1].attributes('href')).toBe('https://status.example.net')
        expect(links[1].attributes('target')).toBe('_blank')
        expect(links[1].attributes('rel')).toBe('noopener')
        expect(links[1].classes()).toContain('nav-link')

        // An item with neither to nor href stays an inert label —
        // no dead link in the bar.
        expect(wrapper.text()).toContain('Just a label')
        expect(wrapper.findAll('a')).toHaveLength(2)
        expect(wrapper.findAll('button')).toHaveLength(0)
    })
})

describe('NavMenu renders children as a dropdown', () => {
    const nested = [
        {
            label: 'Docs',
            children: [
                { label: 'Guide', to: '/guides' },
                { label: 'Upstream', href: 'https://docs.example.net' }
            ]
        }
    ]

    it('keeps the children hidden until the parent is hovered or clicked', async () => {
        const wrapper = await mountMenu(nested)

        // The parent label is a button, not a link — it toggles the
        // panel — and the panel starts closed.
        expect(wrapper.findAll('button')).toHaveLength(1)
        expect(wrapper.find('.nav-drop-label').text()).toContain('Docs')
        expect(wrapper.find('.nav-drop-menu').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Upstream')

        // Hover opens it the way a pointer would...
        await wrapper.find('.nav-drop').trigger('mouseenter')
        expect(wrapper.find('.nav-drop-menu').exists()).toBe(true)
        expect(wrapper.text()).toContain('Guide')
        expect(wrapper.text()).toContain('Upstream')
        expect(wrapper.find('.nav-drop-menu a[href="/guides"]').exists()).toBe(true)
        const external = wrapper.find('.nav-drop-menu a[href="https://docs.example.net"]')
        expect(external.attributes('target')).toBe('_blank')
        expect(external.attributes('rel')).toBe('noopener')

        // ...and leaving the parent closes it again.
        await wrapper.find('.nav-drop').trigger('mouseleave')
        expect(wrapper.find('.nav-drop-menu').exists()).toBe(false)
    })

    it('toggles the panel on click for touch and keyboard users', async () => {
        const wrapper = await mountMenu(nested)

        await wrapper.find('.nav-drop-label').trigger('click')
        expect(wrapper.find('.nav-drop-menu').exists()).toBe(true)

        // A second click closes it — no stuck-open panel after a
        // mis-tap.
        await wrapper.find('.nav-drop-label').trigger('click')
        expect(wrapper.find('.nav-drop-menu').exists()).toBe(false)

        // Click re-opens after the mouse already opened and closed it.
        await wrapper.find('.nav-drop').trigger('mouseenter')
        await wrapper.find('.nav-drop').trigger('mouseleave')
        await wrapper.find('.nav-drop-label').trigger('click')
        expect(wrapper.find('.nav-drop-menu').exists()).toBe(true)
    })

    it('recurses: a child with its own children opens a nested panel', async () => {
        const wrapper = await mountMenu([
            {
                label: 'Docs',
                children: [
                    {
                        label: 'Reference',
                        children: [{ label: 'Schema', href: 'https://schema.example.net' }]
                    }
                ]
            }
        ])

        // The first level opens with the parent hover and shows the
        // second level's label...
        await wrapper.find('.nav-drop').trigger('mouseenter')
        expect(wrapper.text()).toContain('Reference')
        expect(wrapper.findAll('.nav-drop-menu')).toHaveLength(1)

        // ...and the grandchild sits behind its own hover at the
        // second level, same component, same rules.
        await wrapper.findAll('.nav-drop')[1].trigger('mouseenter')
        expect(wrapper.findAll('.nav-drop-menu')).toHaveLength(2)
        expect(wrapper.text()).toContain('Schema')
        expect(wrapper.find('a[href="https://schema.example.net"]').attributes('rel')).toBe('noopener')
    })
})

describe('NavMenu tolerates a missing or empty menu', () => {
    it('renders nothing for an empty list or a missing items prop', async () => {
        const wrapper = await mountMenu([])
        expect(wrapper.findAll('a')).toHaveLength(0)
        expect(wrapper.findAll('button')).toHaveLength(0)

        // A config with no menu key at all still mounts clean.
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', component: { render: () => null } }]
        })
        await router.push('/')
        await router.isReady()
        const bare = mount(NavMenu, { global: { plugins: [router] } })
        expect(bare.findAll('a')).toHaveLength(0)
        expect(bare.findAll('button')).toHaveLength(0)
    })
})