/*
 * The placeholder is what visitors land on for pages that have not
 * been ported yet, so it must name the page and say so plainly —
 * there is no hand-off link to a classic console page here anymore.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlaceholderView from '../components/PlaceholderView.vue'

describe('PlaceholderView', () => {
    it('names the page and stays in the app, with no classic hand-off link', () => {
        const wrapper = mount(PlaceholderView, {
            props: { name: 'Monitors', legacy: '/monitors.php' }
        })

        expect(wrapper.find('h2').text()).toBe('Monitors')
        expect(wrapper.text()).toContain('Not migrated yet')

        // The legacy prop is accepted for compatibility but nothing is
        // done with it: no link out to the classic page is rendered.
        expect(wrapper.find('.placeholder-body a').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('/monitors.php')
    })

    it('shows the optional note a wrapper passes in', () => {
        const wrapper = mount(PlaceholderView, {
            props: { name: 'Reports', note: 'waiting on the rrd exporter' }
        })

        expect(wrapper.find('h2').text()).toBe('Reports')
        expect(wrapper.text()).toContain('waiting on the rrd exporter')
    })
})