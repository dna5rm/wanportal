/*
 * MonitorSearch is the shared sweep component: SearchView renders it
 * as the /search page and the dashboard embeds it compact. This spec
 * pins the input's own copy — the visible placeholder and the
 * aria-label both have to name what the term really covers, monitors
 * AND services (the component fires /monitors?q= and /services?q=
 * together), so the label never lags the behaviour again. Rendering
 * and fetch wiring are covered by SearchView.spec and the dashboard
 * spec; nothing here fetches at all.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import MonitorSearch from '../components/MonitorSearch.vue'
import { makeFetchStub } from './stubs'

enableAutoUnmount(afterEach)

afterEach(() => {
    vi.unstubAllGlobals()
})

async function mountSearch(stub, props = {}) {
    vi.stubGlobal('fetch', stub)
    const wrapper = mount(MonitorSearch, { props })
    await flushPromises()
    return wrapper
}

describe('MonitorSearch', () => {
    it('labels the input for the full two-estate sweep', async () => {
        const stub = makeFetchStub()
        const wrapper = await mountSearch(stub)

        const input = wrapper.find('input[type=search]')
        expect(input.attributes('placeholder')).toBe('search monitors and services...')
        expect(input.attributes('aria-label')).toBe('Search monitors and services')

        // Labels only: nothing is fetched until a term is submitted.
        expect(stub).not.toHaveBeenCalled()
    })

    it('keeps the same labels on the compact dashboard embed', async () => {
        const stub = makeFetchStub()
        const wrapper = await mountSearch(stub, { compact: true })

        const input = wrapper.find('input[type=search]')
        expect(input.attributes('placeholder')).toBe('search monitors and services...')
        expect(input.attributes('aria-label')).toBe('Search monitors and services')
        expect(wrapper.find('.search-compact').exists()).toBe(true)
        expect(stub).not.toHaveBeenCalled()
    })
})