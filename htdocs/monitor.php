<?php
require_once 'config.php';
wanportal_session_start();
$id = $_GET['id'] ?? '';
if (!$id) die("No monitor ID specified");

// Use UTC for all server-side operations
date_default_timezone_set('UTC');

// Get current time in UTC
$now = new DateTime('now', new DateTimeZone('UTC'));
$three_hours_ago = new DateTime('now', new DateTimeZone('UTC'));
$three_hours_ago->modify('-3 hours');

// Default values in UTC
$start = isset($_GET['start']) ? $_GET['start'] : $three_hours_ago->format('Y-m-d\TH:i');
$end = isset($_GET['end']) ? $_GET['end'] : $now->format('Y-m-d\TH:i');

// Get show_inactive preference from GET or session
$show_inactive = isset($_GET['show_inactive']) ?
    filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN) :
    ($_SESSION['show_inactive'] ?? false);
$_SESSION['show_inactive'] = $show_inactive;

try {
    // Fetch monitor info with prepared statement
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

    // Calculate monitor statistics
    $monitor_stats = [
        'total_samples' => $monitor['sample'],
        'total_down' => $monitor['total_down'],
        'current_status' => $monitor['current_loss'] >= 100 ? 'down' : 'up',
        'effectively_active' => $monitor['is_active'] && $monitor['agent_is_active'] && $monitor['target_is_active']
    ];

    // Mirror `effectively_active` onto the monitor row itself. The
    // template uses `$monitor['effectively_active']` in several
    // places (lifetime-average badges at lines ~265-285, the
    // auto-refresh block at line ~394); without this, those reads
    // emit "Undefined array key" warnings and the ternaries fall
    // through to the `bg-secondary` (inactive) fallback for every
    // monitor, on every page load.
    $monitor['effectively_active'] = $monitor_stats['effectively_active'];

    // Compute the color classes used by the lifetime-average table
    // and the "current" badges (current vs average comparisons, and
    // range-based comparisons for the lifetime averages). See
    // lib/monitor_metrics.php for the threshold definitions.
    monitor_color_classes($monitor);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= htmlspecialchars($monitor['description']) ?></title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/base.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }
        /* Time-range card: frame the start/end inputs and the preset
           buttons in a single card so the form sits cleanly under the
           chart instead of floating bare against the page background. */
        .time-range-card {
            max-width: 640px;
            margin: 0 auto;
        }
        .time-range-card .card-body {
            padding: 0.75rem 1rem;
        }
        /* Quick-preset button row above the chart. Each button is a
           "Last Nh/Nd" shortcut that re-fetches the data via the
           existing API endpoint with no page reload. */
        .time-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
            /* Visual breathing room: a real gap above (between the
               chart and the buttons) and a slightly larger one
               below (between the buttons and the time-range card).
               1.5rem above, 1.25rem below. */
            margin-top: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .time-presets .btn {
            font-size: 0.85rem;
        }
        /* Extra space below the time-range card so the form doesn't
           sit hard against whatever follows (the closing column /
           row of the page). */
        .time-range-form {
            margin-top: 0.5rem;
            margin-bottom: 2.5rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <!-- Header Row -->
    <div class="row mb-3">
        <!--
        <div class="col">
            <h3>
                Monitor:
                <?= htmlspecialchars(!empty($monitor['description']) ? $monitor['description'] : $monitor['id']) ?>
            </h3>
        </div>
        -->
        <div class="col text-end">
            <div class="btn-group" role="group">
                <?php if (isset($_SERVER['HTTP_REFERER'])): ?>
                    <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER']) ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                <?php endif; ?>  
                <a href="/index.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-house-door"></i> Home
                </a>
                <!-- Legacy Graph Switch -->
                <div class="btn btn-secondary btn-sm d-flex align-items-center" style="gap: 5px;">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="graphToggle">
                        <label class="form-check-label" for="graphToggle">Legacy Graph</label>
                    </div>
                </div>

                <a href="/cgi-bin/api/rrd?id=<?= htmlspecialchars($monitor['id']) ?>"  class="btn btn-info btn-sm" target="_blank">
                    <i class="bi bi-code-slash"></i> Raw Data
                </a>
                <?php if (isset($_SESSION['user'])): ?>
                    <button onclick="confirmReset('<?= htmlspecialchars($monitor['id']) ?>', '<?= htmlspecialchars($_SESSION['token']) ?>')" class="btn btn-warning btn-sm">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset Stats
                    </button>
                    <a href="/monitors_edit.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="btn btn-danger btn-sm">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

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

            <!-- Monitor Statistics Card -->
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
                <div class="d-flex flex-column" style="height: 60vh;">
                    <div class="flex-grow-1 d-flex flex-column">
                        <div id="chartContainer" class="flex-grow-1">
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

                    <form action="monitor_graph.php" method="GET" id="dateTimeForm" class="time-range-form">
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

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script>
// Expose the monitor's display name to JS so the Chart.js title
// can show it (the title is rendered inside the chart canvas
// itself, not in the surrounding HTML). Falls back to the monitor
// id if description is empty.
const MONITOR_TITLE = <?= json_encode(
    !empty($monitor['description']) ? $monitor['description'] : $monitor['id'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
</script>
<script>
// Format a Date as the value expected by an <input type="datetime-local">:
// "YYYY-MM-DDTHH:MM" in *local* time. The input ignores the trailing
// seconds and treats its value as local time; we keep things consistent
// by sending the user's local clock to the input, then letting the form
// submit (or the API URL build) re-encode the range. The chart adapter
// below parses the same string back into a Date in the same local zone.
function toLocalDatetimeInputValue(d) {
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
         + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
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

    // Legacy-graph preference persistence. We mirror the dark-mode
    // pattern from footer.php: read the user's choice from
    // localStorage, apply it on init, and write it back on change.
    // The toggle's default is "modern graph" (the persisted value
    // is missing or unrecognized). Persisting the modern-graph case
    // too is intentional — if the user explicitly switches back, we
    // remember that across navigations.
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
            const startStr = toLocalDatetimeInputValue(startDate);
            const endStr = toLocalDatetimeInputValue(endDate);
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
</body>
</html>
<?php $mysqli->close(); ?>
