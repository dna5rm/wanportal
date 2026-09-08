/*
 * Shared timing constants and formatting helpers. The thresholds are the
 * same ones the look-only preview used, so colors keep meaning the same
 * thing after the move to Vue.
 *
 *   down monitors:  warn once past 3h down, danger past 5h
 *   agents:         stale once no heartbeat for 1h
 *   loss:           full loss is danger, any partial loss is warn
 */
export const REFRESH_SECONDS = 30;
export const WARN_HOURS = 3;
export const DANGER_HOURS = 5;
export const AGENT_STALE_MS = 60 * 60 * 1000;

export function fmtClock(ts) {
    return new Date(ts).toTimeString().slice(0, 8);
}

/* "2026-08-03 01:03:04" from the API becomes 08/03 01:03:04 for the table. */
export function fmtDownSince(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(s || '');
    return m ? m[2] + '/' + m[3] + ' ' + m[4] + ':' + m[5] + ':' + m[6] : (s || '-');
}

/* Human duration since a down event: minutes, then hours, then days. */
export function downAge(s) {
    const t = Date.parse((s || '').replace(' ', 'T'));
    if (isNaN(t)) return '-';
    const mins = Math.max(0, Math.round((Date.now() - t) / 60000));
    if (mins < 60) return mins + 'm';
    const hours = Math.floor(mins / 60);
    if (hours < 48) return hours + 'h ' + (mins % 60) + 'm';
    return Math.floor(hours / 24) + 'd ' + (hours % 24) + 'h';
}

export function downRowClass(m) {
    const t = Date.parse((m.last_down || '').replace(' ', 'T'));
    if (isNaN(t)) return '';
    const hours = (Date.now() - t) / 3600000;
    if (hours >= DANGER_HOURS) return 'row-danger';
    if (hours >= WARN_HOURS) return 'row-warn';
    return '';
}

export function agentClass(a) {
    const t = Date.parse((a.last_seen || '').replace(' ', 'T'));
    if (!isNaN(t) && (Date.now() - t) > AGENT_STALE_MS) return 'chip-stale';
    return 'chip-ok';
}

export function lossClass(loss) {
    loss = Number(loss) || 0;
    if (loss >= 100) return 'chip-danger';
    if (loss >= 1) return 'chip-warn';
    return 'chip-ok';
}