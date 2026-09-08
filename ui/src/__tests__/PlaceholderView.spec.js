/*
 * The placeholder is what visitors land on for pages that have not
 * been ported yet, so it must name the page and link back to the
 * classic console instead of pretending to work.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlaceholderView from '../components/PlaceholderView.vue'

describe('PlaceholderView', () => {
    it('names the page and hands back to the classic console', () => {
        const wrapper = mount(PlaceholderView, {
            props: { name: 'Monitors', legacy: '/monitors.php' }
        })

        expect(wrapper.find('h2').text()).toBe('Monitors')
        expect(wrapper.text()).toContain('Not migrated yet')

        const link = wrapper.find('.placeholder-body a')
        expect(link.attributes('href')).toBe('/monitors.php')
        expect(link.text()).toBe('/monitors.php')
    })
})