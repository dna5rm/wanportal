/*
 * rrdChart.js carries the monitor graph's geometry, and one fix that
 * motivated the extraction: the /rrd api stamps samples in epoch
 * seconds while the chart window is milliseconds, so every point used
 * to clamp onto the left edge and the graph drew as a vertical
 * scribble. These specs pin the unit conversion, the window spread
 * and the gap-aware path rules without mounting the page.
 */
import { describe, expect, it } from 'vitest'
import { buildChartView, pointsFromRrd } from '../rrdChart'

const CW = 760, CH = 250
const PADS = { left: 46, right: 46, top: 12, bottom: 24 }

/* A fixed window instead of Date.now() so expectations never drift
 * with whatever clock the test box happens to run. */
const WIN_START = Date.parse('2026-09-07T20:00:00Z')
const WIN_END = WIN_START + 3 * 3600 * 1000

/* The epoch-seconds shape the /rrd api actually sends. */
const sec = (iso) => Math.round(Date.parse(iso) / 1000)

/* Pull (x, y) pairs out of a path string like ' M 46.0 130.5 L 92.1
 * 120.0' so the specs can reason about coordinates, not text. */
function coordsOf(d) {
    return [...d.matchAll(/(-?[\d.]+) (-?[\d.]+)/g)].map((m) => ({
        x: Number(m[1]), y: Number(m[2])
    }))
}

const xsOf = (d) => coordsOf(d).map((c) => c.x)
const subpathCount = (d) => (d.match(/ M /g) || []).length

/* Build a view over raw rows with the standard fixed window; tests
 * override single fields instead of restating the whole box. */
function buildView(rows, overrides = {}) {
    return buildChartView({
        rows, startMs: WIN_START, endMs: WIN_END, CW, CH, pads: PADS,
        ...overrides
    })
}

/* Samples every ten minutes across the whole window; rtt ramps so the
 * line has visible slope, loss stays flat except where a gap is
 * wanted. Timestamps go out in api units (seconds). */
function spanningRows({ stepMin = 10, gapAt = [] } = {}) {
    const rows = []
    for (let m = 0; m <= 180; m += stepMin) {
        const gap = gapAt.includes(m)
        rows.push({
            timestamp: sec(new Date(WIN_START + m * 60000).toISOString()),
            rtt: gap ? null : 30 + m,
            loss: gap ? null : m % 20
        })
    }
    return rows
}

describe('pointsFromRrd unit handling', () => {
    it('rescales epoch seconds into the ms the chart works in', () => {
        const pts = pointsFromRrd([{ timestamp: sec('2026-09-07T20:10:00Z'), rtt: 41, loss: 0 }])
        expect(pts).toHaveLength(1)
        expect(pts[0].t).toBe(Date.parse('2026-09-07T20:10:00Z'))
    })

    it('leaves timestamps that are already ms alone', () => {
        const ms = WIN_START + 60000
        const pts = pointsFromRrd([{ timestamp: ms, rtt: 41, loss: 0 }])
        expect(pts[0].t).toBe(ms)
    })
})

describe('pointsFromRrd coercion', () => {
    it('sorts by time and coerces numeric strings', () => {
        const pts = pointsFromRrd([
            { timestamp: String(sec('2026-09-07T20:20:00Z')), rtt: '55.5', loss: '0' },
            { timestamp: sec('2026-09-07T20:10:00Z'), rtt: 41, loss: 0 }
        ])
        expect(pts.map((p) => p.t)).toEqual([
            Date.parse('2026-09-07T20:10:00Z'),
            Date.parse('2026-09-07T20:20:00Z')
        ])
        expect(pts[1].rtt).toBe(55.5)
        expect(pts[1].loss).toBe(0)
    })

    it('keeps rrd gaps as nulls and drops unreadable timestamps', () => {
        const pts = pointsFromRrd([
            { timestamp: sec('2026-09-07T20:10:00Z'), rtt: null, loss: '' },
            { timestamp: 'garbage', rtt: 1, loss: 1 },
            { rtt: 2, loss: 2 },
            { timestamp: sec('2026-09-07T20:20:00Z'), rtt: 'not-a-number', loss: null }
        ])
        expect(pts).toHaveLength(2)
        expect(pts[0].rtt).toBeNull()
        expect(pts[0].loss).toBeNull()
        expect(pts[1].rtt).toBeNull()
        expect(pts[1].loss).toBeNull()
    })
})

describe('the seconds-vs-ms regression', () => {
    it('spreads a seconds-scale sample across the ms window instead of clamping left', () => {
        const view = buildView(spanningRows())
        expect(view).not.toBeNull()
        const xs = xsOf(view.rttPath)
        // Nineteen samples ten minutes apart must land on nineteen
        // distinct x values.
        expect(xs).toHaveLength(19)
        expect(new Set(xs).size).toBe(19)
        // First sample sits on the window start, last on the window
        // end: the full plot width, not a vertical scribble at the
        // left pad.
        expect(xs[0]).toBeCloseTo(PADS.left, 6)
        expect(xs[xs.length - 1]).toBeCloseTo(CW - PADS.right, 6)
    })

    it('spreads the loss line the same way', () => {
        const xs = xsOf(buildView(spanningRows()).lossPath)
        expect(new Set(xs).size).toBeGreaterThan(2)
        expect(xs[0]).toBeCloseTo(PADS.left, 6)
        expect(xs[xs.length - 1]).toBeCloseTo(CW - PADS.right, 6)
    })

    it('clamps samples outside the window to the plot edges', () => {
        const rows = [
            { timestamp: sec('2026-09-07T19:00:00Z'), rtt: 20, loss: 0 }, // before start
            { timestamp: sec('2026-09-07T21:00:00Z'), rtt: 40, loss: 0 }, // inside
            { timestamp: sec('2026-09-07T23:30:00Z'), rtt: 90, loss: 0 }  // after end
        ]
        const xs = xsOf(buildView(rows).rttPath)
        expect(xs[0]).toBeCloseTo(PADS.left, 6)
        expect(xs[1]).toBeGreaterThan(PADS.left)
        expect(xs[2]).toBeCloseTo(CW - PADS.right, 6)
    })
})

describe('gap-aware paths', () => {
    it('lifts the pen at null samples: M, then a later M, never an L across the gap', () => {
        const d = buildView(spanningRows({ gapAt: [10] })).rttPath
        // One gap in the middle restarts the path: two M commands, and
        // the two subpaths start at different x, not a shared clamp.
        expect(subpathCount(d)).toBe(2)
        expect(d).toContain(' L ')
        const xs = xsOf(d)
        expect(xs[0]).toBeCloseTo(PADS.left, 6)
        expect(xs[1]).toBeGreaterThan(PADS.left)
    })

    it('draws nothing for an all-gap series while the other series still draws', () => {
        const rows = spanningRows().map((r) => ({ ...r, rtt: null }))
        const view = buildView(rows)
        expect(view.rttPath).toBe('')
        expect(subpathCount(view.lossPath)).toBe(1)
    })
})

describe('scales, grid and stats', () => {
    it('rounds the rtt scale up to the next 50 with a floor of 50', () => {
        expect(buildView(spanningRows()).rttMax).toBe(250) // max sample 210
        const withRtt = (v) => spanningRows().map((r) => ({ ...r, rtt: v, loss: 0 }))
        expect(buildView(withRtt(10)).rttMax).toBe(50)
        expect(buildView(withRtt(51)).rttMax).toBe(100)
        // All-gap rtt still yields the 50 floor instead of a zero scale.
        const allNull = spanningRows().map((r) => ({ ...r, rtt: null }))
        expect(buildView(allNull).rttMax).toBe(50)
    })

    it('computes min/max/avg/last over the non-null values in time order', () => {
        const rows = [
            { timestamp: sec('2026-09-07T20:00:00Z'), rtt: 40, loss: 2 },
            { timestamp: sec('2026-09-07T20:10:00Z'), rtt: null, loss: null },
            { timestamp: sec('2026-09-07T20:20:00Z'), rtt: '60', loss: '4' },
            { timestamp: sec('2026-09-07T20:30:00Z'), rtt: 50, loss: 0 }
        ]
        const view = buildView(rows)
        expect(view.rttStats).toEqual({ min: '40.0', max: '60.0', avg: '50.0', last: '50.0' })
        expect(view.lossStats).toEqual({ min: '0.0', max: '4.0', avg: '2.0', last: '0.0' })
    })

    it('lays five grid lines across the box with the two scales', () => {
        const view = buildView(spanningRows())
        expect(view.grid).toHaveLength(5)
        expect(view.grid[0]).toEqual({ y: PADS.top, rtt: 250, loss: 100 })
        expect(view.grid[4].y).toBeCloseTo(CH - PADS.bottom, 6)
        expect(view.grid[4].rtt).toBe(0)
        expect(view.grid.map((g) => g.loss)).toEqual([100, 75, 50, 25, 0])
    })

    it('labels the window edges and midpoint in UTC', () => {
        const view = buildView(spanningRows())
        expect(view.xLabels).toHaveLength(3)
        expect(view.xLabels[0].text).toBe('09-07 20:00')
        expect(view.xLabels[1].text).toBe('09-07 21:30')
        expect(view.xLabels[2].text).toBe('09-07 23:00')
        expect(view.xLabels[0].x).toBeCloseTo(PADS.left, 6)
        expect(view.xLabels[2].x).toBeCloseTo(CW - PADS.right, 6)
    })

    it('returns null when there is nothing drawable', () => {
        expect(buildView([])).toBeNull()
        expect(buildView([{ timestamp: 'junk' }])).toBeNull()
        expect(buildView(undefined)).toBeNull()
    })
})