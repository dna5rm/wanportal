/*
 * NetpingView is an install explainer, not a file server: the script
 * and the ready-made docker command stay on the classic netping.php,
 * and this page only links there. These specs pin that hand-off — the
 * classic links, the placeholder password, the mirrored container
 * name — plus honest behavior when the lookup fails or the url has no
 * id at all.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { getJson } from '../api'
import NetpingView from '../components/NetpingView.vue'

vi.mock('../api', () => ({ getJson: vi.fn() }))

enableAutoUnmount(afterEach)

/* Detail ids are char(36) uuids and the api rejects anything else,
 * so the fixture carries the same shape the router would hand over. */
const AGENT_ID = 'bbbbbbbb-0000-4000-8000-000000000001'

function agentReply() {
    return {
        status: 'success',
        agent: {
            id: AGENT_ID,
            name: 'Edge-A',
            address: '192.0.2.10',
            is_active: 1,
            last_seen: '2026-09-07 18:00:00',
            /* The detail endpoint hands the password to admins; the
             * page must not echo it anywhere. */
            password: 'should-not-appear'
        }
    }
}

/* Mount the way the router does — the id arrives as a prop — then let
 * the request and Vue's updates settle. */
async function mountNetping(id = AGENT_ID) {
    const wrapper = mount(NetpingView, { props: { id } })
    await flushPromises()
    return wrapper
}

describe('NetpingView with the agent answering', () => {
    it('links the classic page for the script and keeps the run command placeholder-only', async () => {
        getJson.mockImplementation(async (url) => {
            if (url === '/cgi-bin/api/agents/' + AGENT_ID) return agentReply()
            throw new Error('unexpected url: ' + url)
        })
        const wrapper = await mountNetping()

        // One fetch only — anything else would mean the page started
        // serving files itself.
        expect(getJson).toHaveBeenCalledTimes(1)
        expect(getJson.mock.calls[0][0]).toBe('/cgi-bin/api/agents/' + AGENT_ID)

        // The hand-off links: classic netping.php carrying the agent
        // id, and the static image tarball.
        expect(wrapper.find('a[href="/netping.php?id=' + AGENT_ID + '"]').exists()).toBe(true)
        expect(wrapper.find('a[href="/assets/netping_latest.tar.gz"]').exists()).toBe(true)

        // Container commands mirror the classic page's netping-<name>
        // naming (lowercased agent name), with the id and the api base
        // filled in but the password left as a placeholder.
        const pre = wrapper.findAll('pre').map((n) => n.text()).join('\n')
        expect(pre).toContain('netping-edge-a')
        expect(pre).toContain('AGENT_ID="' + AGENT_ID + '"')
        expect(pre).toContain('/cgi-bin/api')
        expect(pre).toContain('gunzip -c netping_latest.tar.gz | docker load')

        // The password rides the api payload but never the page.
        expect(wrapper.text()).toContain('PASSWORD="<PASSWORD>"')
        expect(wrapper.text()).not.toContain('should-not-appear')
    })
})

describe('NetpingView when the agent lookup misbehaves', () => {
    it('warns but keeps the steps built from the url id', async () => {
        getJson.mockImplementation(async () => { throw new Error('HTTP 404') })
        const wrapper = await mountNetping()

        expect(wrapper.find('.banner').text()).toContain('agent not found')
        // The id came from the route, not the lookup, so the links and
        // the generic container name still work.
        expect(wrapper.find('a[href="/netping.php?id=' + AGENT_ID + '"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('netping-agent')
    })
})

describe('NetpingView without an id', () => {
    it('asks for nothing and offers the generic classic page', async () => {
        const wrapper = await mountNetping('')

        expect(getJson).not.toHaveBeenCalled()
        expect(wrapper.find('.banner').text()).toContain('no agent id found in the url')
        expect(wrapper.find('a[href="/netping.php"]').exists()).toBe(true)
    })
})