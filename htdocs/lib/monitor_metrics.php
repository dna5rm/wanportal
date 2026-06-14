<?php
// lib/monitor_metrics.php
//
// Helper for computing Bootstrap color classes for monitor metrics.
// Used by agent.php, target.php, monitor.php, and search.php.
//
// This dedupes ~200 lines of color-threshold logic that was
// previously copy-pasted across four files (with subtle drift
// between copies). It also fixes a real bug that was in all
// three "full" copies:
//
//     // The "avg_median_color" calc in agent.php, target.php,
//     // monitor.php was:
//     if ($avg_median > $avg_median + 2*$avg_stddev) { ... }
//     elseif ($avg_median > $avg_median + $avg_stddev) { ... }
//     elseif ($avg_median >= $avg_median) { ... }
//
//     Algebraically, $X > $X + N is always false for N >= 0,
//     and $X >= $X is always true. So that block always returned
//     'bg-info', regardless of the actual numbers. The fix is to
//     compare the avg_median against the (avg_min, avg_max) range
//     instead — "is the average near the top of its own range?"
//     is a meaningful question.

/**
 * Compute Bootstrap color classes for a monitor's current and
 * lifetime-average metrics, and add them as *_color fields on the
 * supplied array (passed by reference, so the caller's array
 * is mutated in place).
 *
 * Required input fields (all numeric, may be NULL/0):
 *   current_median, current_loss,
 *   avg_median, avg_min, avg_max, avg_stddev, avg_loss
 *
 * Fields added to the array:
 *   current_median_color, current_loss_color,
 *   avg_median_color, avg_minimum_color, avg_maximum_color,
 *   avg_stddev_color, avg_loss_color
 *
 * Each color is one of: 'bg-success', 'bg-info', 'bg-warning', 'bg-danger'.
 *
 * @param array $row Monitor row, by reference
 */
function monitor_color_classes(array &$row): void
{
    $current_median = (float) ($row['current_median'] ?? 0);
    $current_loss   = (float) ($row['current_loss']   ?? 0);
    $avg_median     = (float) ($row['avg_median']     ?? 0);
    $avg_min        = (float) ($row['avg_min']        ?? 0);
    $avg_max        = (float) ($row['avg_max']        ?? 0);
    $avg_stddev     = (float) ($row['avg_stddev']     ?? 0);
    $avg_loss       = (float) ($row['avg_loss']       ?? 0);

    // ---- Current value colors ----
    // Compare current to the lifetime average ± N stddev. Highlights
    // when a single sample is way out of the historical norm.
    //
    // We use the `-subtle` variants of bg-{color} so the cell tint
    // flips with `data-bs-theme="dark"`. The solid `bg-danger`/
    // `bg-warning`/etc. are vivid in both modes and look out of
    // place on a dark page. The class string is the full Bootstrap
    // 5.3 dark-mode-aware trio: subtle background + emphasis text +
    // matching subtle border. Callers drop it straight into the
    // badge class attribute without any extra wrapping. The
    // `-emphasis` on the text is what makes the label readable in
    // BOTH light and dark mode; plain `text-{color}` is washed out
    // on the subtle background in light mode.
    if ($current_median > $avg_median + 2 * $avg_stddev) {
        $row['current_median_color'] = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    } elseif ($current_median > $avg_median) {
        $row['current_median_color'] = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    } elseif ($current_median >= $avg_median) {
        $row['current_median_color'] = 'bg-info-subtle text-info-emphasis border border-info-subtle';
    } else {
        $row['current_median_color'] = 'bg-success-subtle text-success-emphasis border border-success-subtle';
    }

    if ($current_loss >= 75) {
        $row['current_loss_color'] = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    } elseif ($current_loss >= 50) {
        $row['current_loss_color'] = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    } elseif ($current_loss >= 2) {
        $row['current_loss_color'] = 'bg-info-subtle text-info-emphasis border border-info-subtle';
    } else {
        $row['current_loss_color'] = 'bg-success-subtle text-success-emphasis border border-success-subtle';
    }

    // ---- Average value colors ----
    // avg_median relative to the (avg_min, avg_max) range. A high
    // avg_median near avg_max means the link is consistently slow;
    // a low avg_median near avg_min means it's consistently fast.
    $avg_range = $avg_max - $avg_min;
    if ($avg_range > 0 && $avg_median >= $avg_min + 0.75 * $avg_range) {
        $row['avg_median_color'] = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    } elseif ($avg_range > 0 && $avg_median >= $avg_min + 0.50 * $avg_range) {
        $row['avg_median_color'] = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    } elseif ($avg_median >= $avg_min) {
        $row['avg_median_color'] = 'bg-info-subtle text-info-emphasis border border-info-subtle';
    } else {
        $row['avg_median_color'] = 'bg-success-subtle text-success-emphasis border border-success-subtle';
    }

    // avg_min relative to (avg_median - N*stddev): how far below the
    // average is the best-ever sample?
    if ($avg_min <= $avg_median - 3 * $avg_stddev) {
        $row['avg_minimum_color'] = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    } elseif ($avg_min <= $avg_median - 2 * $avg_stddev) {
        $row['avg_minimum_color'] = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    } elseif ($avg_min <= $avg_median - $avg_stddev) {
        $row['avg_minimum_color'] = 'bg-info-subtle text-info-emphasis border border-info-subtle';
    } else {
        $row['avg_minimum_color'] = 'bg-success-subtle text-success-emphasis border border-success-subtle';
    }

    // avg_max relative to (avg_median + N*stddev): how far above the
    // average is the worst-ever sample?
    if ($avg_max >= $avg_median + 3 * $avg_stddev) {
        $row['avg_maximum_color'] = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    } elseif ($avg_max >= $avg_median + 2 * $avg_stddev) {
        $row['avg_maximum_color'] = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    } elseif ($avg_max >= $avg_median + $avg_stddev) {
        $row['avg_maximum_color'] = 'bg-info-subtle text-info-emphasis border border-info-subtle';
    } else {
        $row['avg_maximum_color'] = 'bg-success-subtle text-success-emphasis border border-success-subtle';
    }

    // stddev vs half the range: high stddev relative to the spread
    // means the link is inconsistent.
    $avg_stddev_threshold = abs($avg_range / 2);
    $row['avg_stddev_color'] = ($avg_stddev > $avg_stddev_threshold)
        ? 'bg-info-subtle text-info-emphasis border border-info-subtle'
        : 'bg-success-subtle text-success-emphasis border border-success-subtle';

    // Average loss: <2 good, <5 info, <13 warning, else danger
    if ($avg_loss < 2) {
        $row['avg_loss_color'] = 'bg-success-subtle text-success-emphasis border border-success-subtle';
    } elseif ($avg_loss < 5) {
        $row['avg_loss_color'] = 'bg-info-subtle text-info-emphasis border border-info-subtle';
    } elseif ($avg_loss < 13) {
        $row['avg_loss_color'] = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    } else {
        $row['avg_loss_color'] = 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    }
}
