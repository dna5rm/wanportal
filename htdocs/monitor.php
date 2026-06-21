<?php
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
$id = $_GET['id'] ?? '';
if (!$id) die("No monitor ID specified");

// All times UTC so the chart and the "last update" labels line up
// regardless of where the server runs.
date_default_timezone_set('UTC');

$now = new DateTime('now', new DateTimeZone('UTC'));
$three_hours_ago = (clone $now)->modify('-3 hours');

$start = $_GET['start'] ?? $three_hours_ago->format('Y-m-d\TH:i');
$end   = $_GET['end']   ?? $now->format('Y-m-d\TH:i');

$show_inactive = wanportal_get_show_inactive();

try {
    $stmt = $mysqli->prepare("
        SELECT
            m.*,
            t.address as target_address,
            t.description as target_description,
            t.is_active as target_is_active,
            a.name as agent_name,
            a.description as agent_description,
            a.address as agent_address,
            a.is_active as agent_is_active
        FROM monitors m
        JOIN targets t ON m.target_id = t.id
        JOIN agents a ON m.agent_id = a.id
        WHERE m.id = ?
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("s", $id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        throw new Exception("Monitor not found");
    }

    $monitor = $result->fetch_assoc();
    $stmt->close();

    $monitor_stats = [
        'total_samples' => $monitor['sample'],
        'total_down' => $monitor['total_down'],
        'current_status' => $monitor['current_loss'] >= 100 ? 'down' : 'up',
        'effectively_active' => $monitor['is_active'] && $monitor['agent_is_active'] && $monitor['target_is_active']
    ];

    // Mirror onto the row so the template's $monitor['effectively_active']
    // reads are well-defined (the ternaries downstream fall through to
    // the bg-secondary fallback without this).
    $monitor['effectively_active'] = $monitor_stats['effectively_active'];

    // Color classes for the row. Thresholds in lib/monitor_metrics.php.
    monitor_color_classes($monitor);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Actions: Raw Data (always), Reset Stats (non-LOCAL, auth),
// Edit (auth). No "Agent" button -- monitor.php is the canonical
// page for the monitor itself.
$actions = [];

// Raw Data link -- always available, opens the RRD endpoint in a
// new tab.
$actions[] = [
    'url'     => '/cgi-bin/api/rrd?id=' . htmlspecialchars($monitor['id'], ENT_QUOTES, 'UTF-8'),
    'icon'    => 'bi bi-code-slash',
    'label'   => 'Raw Data',
    'variant' => 'info',
];

if (isset($_SESSION['user'])) {
    // Reset Stats -- gated on auth. We don't gate on agent name
    // here (the original page didn't either).
    $actions[] = [
        'click'  => 'confirmReset()',
        'icon'    => 'bi bi-arrow-counterclockwise',
        'label'   => 'Reset Stats',
        'variant' => 'warning',
    ];
    $actions[] = [
        'url'     => '/monitors_edit.php?id=' . htmlspecialchars($monitor['id'], ENT_QUOTES, 'UTF-8'),
        'icon'    => 'bi bi-pencil',
        'label'   => 'Edit',
        'variant' => 'danger',
    ];
}

$title = 'Monitor: ' . (!empty($monitor['description']) ? $monitor['description'] : $monitor['id']);

// Legacy Graph toggle. Doesn't fit the action[] shape (which is
// for <a>/<button> elements), so we pass it through 'extra_buttons'
// as raw HTML. The matching JS lives in the inline script at the
// bottom of this page.
$extra_buttons = '<div class="btn btn-secondary btn-sm d-flex align-items-center" style="gap: 5px;">' . "\n"
    . '                    <div class="form-check form-switch mb-0">' . "\n"
    . '                        <input class="form-check-input" type="checkbox" id="graphToggle">' . "\n"
    . '                        <label class="form-check-label" for="graphToggle">Legacy Graph</label>' . "\n"
    . '                    </div>' . "\n"
    . '                </div>';

// Page-specific head extras: chart.js + date-fns adapter (loaded
// in <head> so they're ready before the inline chart script at
// the bottom of the page) and the page's custom CSS.
$head_extras  = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>' . "\n";
$head_extras .= '    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>' . "\n";
$head_extras .= '    <style>' . "\n";
$head_extras .= '        .chart-container { position: relative; height: 400px; width: 100%; }' . "\n";
$head_extras .= '        .time-range-card { max-width: 640px; margin: 0 auto; }' . "\n";
$head_extras .= '        .time-range-card .card-body { padding: 0.75rem 1rem; }' . "\n";
$head_extras .= '        .time-presets { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center; margin-top: 1.5rem; margin-bottom: 1.25rem; }' . "\n";
$head_extras .= '        .time-presets .btn { font-size: 0.85rem; }' . "\n";
$head_extras .= '        .time-range-form { margin-top: 0.5rem; margin-bottom: 2.5rem; }' . "\n";
$head_extras .= '    </style>';

wanportal_render_head($title, ['head_extras' => $head_extras]);
wanportal_render_header_row($title, $actions, ['extra_buttons' => $extra_buttons]);
?>

    <div class="row">
        <!-- Monitor Info Column -->
        <div class="col-md-3">
            <!-- Monitor Details Card --><!-- Monitor Details Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Details</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>ID:</strong><br/>
                            <?= htmlspecialchars($monitor['id']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Agent:</strong><br/>
                            <a href="/agent.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>"
                               title="<?= htmlspecialchars($monitor['agent_id']) ?>"
                               data-bs-toggle="tooltip">
                                <?= htmlspecialchars($monitor['agent_name']) ?>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <strong>Target:</strong><br/>
                            <a href="/target.php?id=<?= htmlspecialchars($monitor['target_id']) ?>"
                               title="<?= htmlspecialchars($monitor['target_id']) ?>"
                               data-bs-toggle="tooltip">
                                <?= htmlspecialchars($monitor['target_address']) ?>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <strong>Protocol:</strong><br/>
                            <?= htmlspecialchars($monitor['protocol']) ?>
                            <?php if ($monitor['protocol'] === 'TCP'): ?>
                                (Port <?= htmlspecialchars($monitor['port']) ?>)
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item">
                            <strong>DSCP:</strong><br/>
                            <?= htmlspecialchars($monitor['dscp']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Status:</strong><br/>
                            <?php if ($monitor['is_active'] && $monitor['agent_is_active'] && $monitor['target_is_active']): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Inactive</span>
                                <sup>
                                    <?php if (!$monitor['is_active']): ?>
                                        <small>(Monitor disabled)</small>
                                    <?php endif; ?>
                                    <?php if (!$monitor['agent_is_active']): ?>
                                        <small>(Agent disabled)</small>
                                    <?php endif; ?>
                                    <?php if (!$monitor['target_is_active']): ?>
                                        <small>(Target disabled)</small>
                                    <?php endif; ?>
                                </sup>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Monitor Statistics Card. This card mixes numeric
                 stats (Total Samples, Total Down Events) with
                 timestamp rows (Last Update, Last Down, Last
                 Clear). The wanportal_render_stats_card partial
                 assumes every row is a labeled badge, so it
                 doesn't fit this card's mixed layout. The
                 hand-rolled markup below is preserved verbatim. -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Statistics</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Samples
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle rounded-pill">
                                <?= $monitor_stats['total_samples'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Down Events
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle rounded-pill">
                                <?= $monitor_stats['total_down'] ?>
                            </span>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Update:</strong><br/>
                            <?= htmlspecialchars($monitor['last_update']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Down:</strong><br/>
                            <?= htmlspecialchars($monitor['last_down']) ?>
                        </li>
                        <li class="list-group-item">
                            <strong>Last Clear:</strong><br/>
                            <?= htmlspecialchars($monitor['last_clear']) ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Monitor Data Column -->
        <div class="col-md-9">
            <!-- Lifetime Average Card -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Lifetime Average</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th class="text-center">Median</th>
                                    <th class="text-center">Min</th>
                                    <th class="text-center">Max</th>
                                    <th class="text-center">Std Dev</th>
                                    <th class="text-center">Loss</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_median_color'] : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>">
                                            <?= htmlspecialchars($monitor['avg_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_minimum_color'] : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>">
                                            <?= htmlspecialchars($monitor['avg_min']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_maximum_color'] : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>">
                                            <?= htmlspecialchars($monitor['avg_max']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_stddev_color'] : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>">
                                            <?= htmlspecialchars($monitor['avg_stddev']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_loss_color'] : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>">
                                            <?= htmlspecialchars($monitor['avg_loss']) ?>%
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end">
                        <?= htmlspecialchars($monitor['pollcount']) ?>x/<?= htmlspecialchars($monitor['pollinterval']) ?>sec interval
                    </div>
                </div>
            </div>

            <!-- Network Performance Graph -->
            <div class="mb-3">
                <!-- The chart/legacy container is given a min-height
                     so the modern chart has a usable area on first
                     paint (before data arrives) and so the layout
                     doesn't collapse around an empty canvas. The
                     wrapper itself is *not* height-constrained —
                     letting it grow to fit the legacy images means
                     the time-range form sits below whatever the
                     graph produces, with its 2.5rem margin-bottom
                     providing real breathing room above the page
                     footer instead of being clipped by a 60vh
                     column's edge. -->
                <div class="d-flex flex-column">
                    <div class="flex-grow-1 d-flex flex-column">
                        <div id="chartContainer" class="flex-grow-1" style="min-height: 60vh;">
                            <canvas id="networkChart"></canvas>
                        </div>

                        <div id="legacyGraphContainer" class="flex-grow-1 text-center" style="display: none;">
                            <br />
                            <img id="legacyRttGraph" src="" alt="Legacy RTT Graph" class="img-fluid" width="800">
                            <br /><br />
                            <img id="legacyLossGraph" src="" alt="Legacy Loss Graph" class="img-fluid" width="800">
                        </div>
                    </div>

                    <!-- Quick time-range presets. Each button sets
                         the start/end inputs to a relative range
                         and re-fetches the chart data via the API
                         with no page reload. The chart title (which
                         lives inside the Chart.js options) and the
                         legacy-graph toggle persistence are
                         preserved across range changes. -->
                    <div class="time-presets" id="timePresets">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-range-hours="1">Last 1h</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-range-hours="6">Last 6h</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-range-hours="24">Last 24h</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-range-hours="72">Last 3d</button>
                    </div>

                    <form action="#" method="GET" id="dateTimeForm" class="time-range-form">
                        <div class="card time-range-card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-calendar3 me-2"></i>
                                    <span class="fw-semibold">Time range (UTC)</span>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text">From</span>
                                    <input type="datetime-local" class="form-control" name="start" id="startTime" value="<?= htmlspecialchars($start) ?>">
                                    <span class="input-group-text">To</span>
                                    <input type="datetime-local" class="form-control" name="end" id="endTime" value="<?= htmlspecialchars($end) ?>">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                                    <button type="submit" class="btn btn-primary">Apply</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php wanportal_render_page_end(); ?>

<script>
const MONITOR_TITLE = <?= json_encode(
    !empty($monitor['description']) ? $monitor['description'] : $monitor['id'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
</script>
<script>
// Format a Date as the value expected by <input type="datetime-local">:
// "YYYY-MM-DDTHH:MM" in *local* time. The input ignores trailing
// seconds and treats the value as local; the chart adapter parses the
// same string back into a Date in the same zone, so we keep them in sync
// by sending the user's local clock to the input.
function formatLocalDatetime(date) {
    const pad = n => String(n).padStart(2, '0');
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
         + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
}
let chart;

function debugLog(message) {
    console.log(message);
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const monitorId = urlParams.get('id');
    let start = urlParams.get('start');
    let end = urlParams.get('end');

    if (!start) {
        start = new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString().slice(0, 16); // 3 hours ago
    }
    if (!end) {
        end = new Date().toISOString().slice(0, 16); // now
    }

    const dataUrl = `/cgi-bin/api/rrd?id=${monitorId}&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;

    // Legacy-graph preference: mirror the dark-mode pattern in
    // footer.php (read from localStorage, apply on init, write
    // back on change). Default is "modern graph".
    const LEGACY_GRAPH_KEY = 'wanportal-legacy-graph';
    let useLegacyGraph = false;
    try {
        useLegacyGraph = localStorage.getItem(LEGACY_GRAPH_KEY) === '1';
    } catch (e) { /* localStorage may be disabled; default to modern */ }

    const graphToggle = document.getElementById('graphToggle');
    const chartContainer = document.getElementById('chartContainer');
    const legacyContainer = document.getElementById('legacyGraphContainer');

    // Apply the persisted preference on load: check the box and
    // show the matching container, then trigger the initial render
    // for whichever mode the user last chose.
    graphToggle.checked = useLegacyGraph;
    if (useLegacyGraph) {
        chartContainer.style.display = 'none';
        legacyContainer.style.display = 'block';
        updateLegacyGraphs(monitorId, start, end);
    } else {
        chartContainer.style.display = 'block';
        legacyContainer.style.display = 'none';
        fetchDataAndCreateChart(dataUrl);
    }

    // Handle toggle switch
    graphToggle.addEventListener('change', function() {
        if (this.checked) {
            // Switch to legacy graph
            chartContainer.style.display = 'none';
            legacyContainer.style.display = 'block';
            updateLegacyGraphs(monitorId, start, end);
            try { localStorage.setItem(LEGACY_GRAPH_KEY, '1'); } catch (e) {}
        } else {
            // Switch to new chart
            chartContainer.style.display = 'block';
            legacyContainer.style.display = 'none';
            fetchDataAndCreateChart(dataUrl);
            try { localStorage.setItem(LEGACY_GRAPH_KEY, '0'); } catch (e) {}
        }
    });

    // Handle form submission - keep everything in UTC
    const dateTimeForm = document.getElementById('dateTimeForm');
    if (dateTimeForm) {
        dateTimeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(dateTimeForm);
            const queryString = new URLSearchParams(formData).toString();
            window.location.href = window.location.pathname + '?' + queryString;
        });
    }

    // Quick time-range preset buttons. Each sets the start/end
    // inputs to "now minus N hours" and re-fetches the chart
    // (or legacy graphs) without a page reload. The legacy-graph
    // toggle state is honored: if the user is in legacy mode, the
    // preset re-fetches the legacy images instead.
    document.querySelectorAll('#timePresets [data-range-hours]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const hours = parseFloat(this.dataset.rangeHours);
            if (!isFinite(hours) || hours <= 0) return;
            const endDate = new Date();
            const startDate = new Date(endDate.getTime() - hours * 60 * 60 * 1000);
            const startStr = formatLocalDatetime(startDate);
            const endStr = formatLocalDatetime(endDate);
            document.getElementById('startTime').value = startStr;
            document.getElementById('endTime').value = endStr;
            // Reflect the new range in the URL so a hard refresh
            // or a shared link preserves the selected preset.
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.set('start', startStr);
            newUrl.searchParams.set('end', endStr);
            window.history.replaceState({}, '', newUrl);
            // Update module-scoped start/end so the chart re-fetch
            // uses the new range, then re-render the active view.
            start = startStr;
            end = endStr;
            const newDataUrl = `/cgi-bin/api/rrd?id=${monitorId}&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
            if (graphToggle.checked) {
                updateLegacyGraphs(monitorId, start, end);
            } else {
                fetchDataAndCreateChart(newDataUrl);
            }
        });
    });
});

function updateLegacyGraphs(monitorId, start, end) {
    const rttGraphUrl = `/cgi-bin/api/rrd?id=${monitorId}&cmd=graph&ds=rtt&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
    const lossGraphUrl = `/cgi-bin/api/rrd?id=${monitorId}&cmd=graph&ds=loss&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
    
    document.getElementById('legacyRttGraph').src = rttGraphUrl;
    document.getElementById('legacyLossGraph').src = lossGraphUrl;
}

function fetchDataAndCreateChart(dataUrl) {
    showLoading(true);
    fetch(dataUrl)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                throw new Error(data.message);
            }
            if (data.data && Array.isArray(data.data)) {
                data.data = data.data.map(item => ({
                    datetime: item.datetime,
                    rtt: parseFloat(item.rtt),
                    loss: parseFloat(item.loss)
                }));
                createChart(data);
            } else {
                throw new Error('Invalid data structure received from API');
            }
        })
        .catch(error => {
            console.error('Error fetching data:', error);
            showError('Error fetching data: ' + error.message);
        })
        .finally(() => {
            showLoading(false);
        });
}

function createChart(data) {
    const ctx = document.getElementById('networkChart').getContext('2d');
    
    if (chart) {
        chart.destroy();
    }

    const maxRTT = Math.max(...data.data.map(item => item.rtt));
    const rttAxisMax = Math.ceil(maxRTT / 50) * 50;

    chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.data.map(item => item.datetime),
            datasets: [
                {
                    label: 'RTT (ms)',
                    data: data.data.map(item => item.rtt),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.15)',
                    yAxisID: 'y',
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true
                },
                {
                    label: 'Loss (%)',
                    data: data.data.map(item => item.loss),
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.10)',
                    yAxisID: 'y1',
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            stacked: false,
            plugins: {
                title: {
                    display: true,
                    text: MONITOR_TITLE,
                    font: {
                        size: 16,
                        weight: '600'
                    },
                    padding: {
                        top: 4,
                        bottom: 12
                    }
                },
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        boxHeight: 8,
                        padding: 16
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(33, 37, 41, 0.95)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 6,
                    callbacks: {
                        title: function(context) {
                            return context[0].label;
                        },
                        label: function(context) {
                            const value = context.parsed.y;
                            if (context.dataset.yAxisID === 'y') {
                                return '  RTT:  ' + value + ' ms';
                            }
                            return '  Loss: ' + value + ' %';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        parser: 'yyyy-MM-dd HH:mm:ss',
                        unit: 'hour',
                        displayFormats: {
                            hour: 'MMM d, HH:mm'
                        }
                    },
                    title: {
                        display: true,
                        text: 'Time (UTC)'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.06)'
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'RTT (ms)'
                    },
                    min: 0,
                    max: rttAxisMax,
                    ticks: {
                        stepSize: rttAxisMax / 8
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.06)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Loss (%)'
                    },
                    min: 0,
                    max: 100,
                    ticks: {
                        stepSize: 25
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                }
            }
        }
    });
}

function showLoading(isLoading) {
    const loadingElement = document.getElementById('loading');
    if (loadingElement) {
        loadingElement.style.display = isLoading ? 'block' : 'none';
    }
}

function showError(message) {
    const errorElement = document.getElementById('errorMessage');
    if (errorElement) {
        errorElement.textContent = message;
        errorElement.style.display = 'block';
    }
    console.error(`Error: ${message}`);
}
</script>
<?php $mysqli->close(); ?>
