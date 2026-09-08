/*
 * Router construction is the SPA's boot. A bad alias (undefined)
 * used to throw "aliases is not iterable" and leave #app empty.
 */
import { describe, expect, it } from 'vitest'
import router from '../router.js'

describe('router boot', () => {
    it('creates without throwing', () => {
        expect(router).toBeTruthy()
        expect(router.hasRoute('dashboard')).toBe(true)
        expect(router.hasRoute('credential')).toBe(true)
        expect(router.hasRoute('monitor')).toBe(true)
    })
})
