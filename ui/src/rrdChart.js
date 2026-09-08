/*
 * Geometry for the monitor performance graph, pulled out of
 * MonitorDetailView so it can be unit-tested without mounting a page.
 * Two exports:
 *
 *   pointsFromRrd(rows)  - raw /rrd samples -> sorted {t, rtt, loss}
 *                          points, with the epoch-units fix (the api
 *                          sends seconds, the chart works in ms)
 *   buildChartView(...)  - points + time window + pixel box -> the
 *                          path strings, grid and legend stats the
 *                          template draws
 *
 * Deliberately no chart library: this is a couple of linear scales
 * and a gap-aware polyline, the same math the page always had.
 */

/*
 * The /rrd endpoint stamps samples in epoch seconds (rrdtool's native
 * unit) while every window on the page is a Date.now()-style ms value.
 * Feeding seconds into ms math puts every sample decades before the
 * window, so the x clamp pins the whole line to the left edge and the
 * graph reads as a vertical scribble. A ms epoch for any plausible
 * "now" is above 1e12, so anything below that is seconds and gets
 * scaled; values already at ms scale pass through untouched.
 */
function toChartMs(t) {
    return t < 1e12 ? t * 1000 : t
}

/*
 * rtt/loss arrive as numbers, numeric strings from DBI, or null for
 * rrd gaps. Junk that will not parse reads as a gap too, so the path
 * builder lifts the pen instead of crashing on it.
 */
function numOrNull(v) {
    if (v === null || v === undefined || v === '') return null
    const n = Number(v)
    return Number.isFinite(n) ? n : null
}

/* Coerce api rows into drawable points, dropping rows whose timestamp
 * is missing or unreadable, sorted oldest first. */
export function pointsFromRrd(rows) {
    const pts = []
    for (const row of Array.isArray(rows) ? rows : []) {
        const t = toChartMs(Number(row && row.timestamp))
        if (!Number.isFinite(t)) continue
        pts.push({ t, rtt: numOrNull(row.rtt), loss: numOrNull(row.loss) })
    }
    /* The endpoint returns ascending time today; sort anyway so the
     * line stays sane even if the api's ordering drifts. */
    pts.sort((a, b) => a.t - b.t)
    return pts
}

/*
 * Build everything the template renders. rows are raw /rrd samples;
 * startMs/endMs bracket the visible window in ms; CW/CH/pads describe
 * the pixel box (pads: {left, right, top, bottom}). Returns null when
 * nothing is drawable so the page can show "no graph data" instead of
 * an empty box.
 */
export function buildChartView({ rows, startMs, endMs, CW, CH, pads }) {
    const pts = pointsFromRrd(rows)
    if (!pts.length) return null

    const padL = pads.left, padR = pads.right, padT = pads.top, padB = pads.bottom
    const IW = CW - padL - padR
    const IH = CH - padT - padB

    const t0 = startMs
    const span = Math.max(1, endMs - t0)
    /* Clamp stray samples into the window so one outlier outside it
     * pins to an edge rather than escaping the box. */
    const xFor = (t) => padL + ((Math.min(Math.max(t, t0), t0 + span) - t0) / span) * IW

    /* Same headroom rule as the classic chart: round the rtt scale up
     * to the next 50 ms, never below 50. */
    const rttVals = pts.map((p) => p.rtt).filter((v) => v !== null)
    const rttMax = Math.max(50, Math.ceil((rttVals.length ? Math.max(...rttVals) : 0) / 50) * 50)
    const yRtt = (v) => padT + (1 - Math.min(Math.max(v, 0), rttMax) / rttMax) * IH
    const yLoss = (v) => padT + (1 - Math.min(Math.max(v, 0), 100) / 100) * IH

    /* Polyline that lifts the pen on gaps, so a dead stretch reads as a
     * gap rather than a plunge to zero. */
    const path = (pick, yFn) => {
        let d = ''
        let pen = false
        for (const p of pts) {
            const v = pick(p)
            if (v === null || !Number.isFinite(v)) { pen = false; continue }
            d += (pen ? ' L ' : ' M ') + xFor(p.t).toFixed(1) + ' ' + yFn(v).toFixed(1)
            pen = true
        }
        return d
    }

    /* Avg/Min/Max/Last over the visible window, like the legacy rrd
     * graph prints in its legend. */
    const stats = (pick) => {
        const vals = pts.map(pick).filter((v) => v !== null)
        if (!vals.length) return null
        const sum = vals.reduce((a, v) => a + v, 0)
        return {
            min: Math.min(...vals).toFixed(1),
            max: Math.max(...vals).toFixed(1),
            avg: (sum / vals.length).toFixed(1),
            last: vals[vals.length - 1].toFixed(1)
        }
    }

    /* Five horizontal grid lines; left labels follow the rtt scale,
     * right labels the fixed 0-100 loss scale. */
    const grid = []
    for (let i = 0; i <= 4; i++) {
        grid.push({
            y: padT + (i / 4) * IH,
            rtt: Math.round((rttMax * (4 - i)) / 4),
            loss: 100 - i * 25
        })
    }

    const fmtX = (ms) => new Date(ms).toISOString().slice(5, 16).replace('T', ' ')
    const xLabels = [t0, t0 + span / 2, t0 + span].map((ms) => ({
        x: Math.min(xFor(ms), CW - padR),
        text: fmtX(ms)
    }))

    return {
        rttPath: path((p) => p.rtt, yRtt),
        lossPath: path((p) => p.loss, yLoss),
        grid, xLabels,
        rttStats: stats((p) => p.rtt),
        lossStats: stats((p) => p.loss),
        rttMax
    }
}