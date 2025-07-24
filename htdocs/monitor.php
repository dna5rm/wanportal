<?php
session_start();
require_once 'config.php';

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

    // Calculate status colors
    $current_median = floatval($monitor['current_median']);
    $current_loss = floatval($monitor['current_loss']);
    $avg_median = floatval($monitor['avg_median']);
    $avg_min = floatval($monitor['avg_min']);
    $avg_max = floatval($monitor['avg_max']);
    $avg_stddev = floatval($monitor['avg_stddev']);
    $avg_loss = floatval($monitor['avg_loss']);

    // Add color classes based on thresholds
    if ($current_median > $avg_median + 2*$avg_stddev)
        $monitor['current_median_color'] = 'bg-danger';
    elseif ($current_median > $avg_median)
        $monitor['current_median_color'] = 'bg-warning';
    elseif ($current_median == $avg_median)
        $monitor['current_median_color'] = 'bg-info';
    else
        $monitor['current_median_color'] = 'bg-success';

    // Current loss thresholds
    if ($current_loss >= 75)
        $monitor['current_loss_color'] = 'bg-danger';
    elseif ($current_loss >= 50)
        $monitor['current_loss_color'] = 'bg-warning';
    elseif ($current_loss >= 2)
        $monitor['current_loss_color'] = 'bg-info';
    else
        $monitor['current_loss_color'] = 'bg-success';

    // Average metrics colors
    if ($avg_median > $avg_median + 2*$avg_stddev)
        $monitor['avg_median_color'] = 'bg-danger';
    elseif ($avg_median > $avg_median + $avg_stddev)
        $monitor['avg_median_color'] = 'bg-warning';
    elseif ($avg_median >= $avg_median)
        $monitor['avg_median_color'] = 'bg-info';
    else
        $monitor['avg_median_color'] = 'bg-success';

    if ($avg_min <= ($avg_median - 3*$avg_stddev))
        $monitor['avg_minimum_color'] = 'bg-danger';
    elseif ($avg_min <= ($avg_median - 2*$avg_stddev))
        $monitor['avg_minimum_color'] = 'bg-warning';
    elseif ($avg_min <= ($avg_median - $avg_stddev))
        $monitor['avg_minimum_color'] = 'bg-info';
    else
        $monitor['avg_minimum_color'] = 'bg-success';

    if ($avg_max >= ($avg_median + 3*$avg_stddev))
        $monitor['avg_maximum_color'] = 'bg-danger';
    elseif ($avg_max >= ($avg_median + 2*$avg_stddev))
        $monitor['avg_maximum_color'] = 'bg-warning';
    elseif ($avg_max >= ($avg_median + $avg_stddev))
        $monitor['avg_maximum_color'] = 'bg-info';
    else
        $monitor['avg_maximum_color'] = 'bg-success';

    $avg_stddev_threshold = abs(($avg_max - $avg_min) / 2);
    $monitor['avg_stddev_color'] = ($avg_stddev > $avg_stddev_threshold) ? 'bg-info' : 'bg-success';

    if ($avg_loss < 2)
        $monitor['avg_loss_color'] = 'bg-success';
    elseif ($avg_loss < 5)
        $monitor['avg_loss_color'] = 'bg-info';
    elseif ($avg_loss < 13)
        $monitor['avg_loss_color'] = 'bg-warning';
    else
        $monitor['avg_loss_color'] = 'bg-danger';

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/base.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <!-- Header Row -->
    <div class="row mb-3">
        <div class="col">
            <h3>
                Monitor:
                <?= htmlspecialchars(!empty($monitor['description']) ? $monitor['description'] : $monitor['id']) ?>
            </h3>
        </div>
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
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-warning">Inactive</span>
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
                            <span class="badge bg-primary rounded-pill">
                                <?= $monitor_stats['total_samples'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Down Events
                            <span class="badge bg-danger rounded-pill">
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
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_minimum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_min']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_maximum_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_max']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_stddev_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($monitor['avg_stddev']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $monitor['effectively_active'] ? $monitor['avg_loss_color'] : 'bg-secondary' ?>">
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
                    
                    <form action="monitor_graph.php" method="GET" id="dateTimeForm" class="mt-3">
                        <div class="input-group">
                            <span class="input-group-text">UTC</span>
                            <input type="datetime-local" class="form-control" name="start" value="<?= htmlspecialchars($start) ?>">
                            <input type="datetime-local" class="form-control" name="end" value="<?= htmlspecialchars($end) ?>">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                            <button type="submit" class="btn btn-primary">Submit</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script>
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

    // Initialize with the new chart
    fetchDataAndCreateChart(dataUrl);

    // Handle toggle switch
    const graphToggle = document.getElementById('graphToggle');
    graphToggle.addEventListener('change', function() {
        if (this.checked) {
            // Switch to legacy graph
            document.getElementById('chartContainer').style.display = 'none';
            document.getElementById('legacyGraphContainer').style.display = 'block';
            updateLegacyGraphs(monitorId, start, end);
        } else {
            // Switch to new chart
            document.getElementById('chartContainer').style.display = 'block';
            document.getElementById('legacyGraphContainer').style.display = 'none';
            fetchDataAndCreateChart(dataUrl);
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

    // Auto-refresh page every 60 seconds if monitor is active
    <?php if ($monitor['effectively_active']): ?>
        setTimeout(function() {
            location.reload();
        }, 60000);
    <?php endif; ?>
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
                    backgroundColor: 'rgba(75, 192, 192, 0.5)',
                    yAxisID: 'y',
                    pointRadius: 0,
                    borderWidth: 3,
                    tension: 0.1
                },
                {
                    label: 'Loss (%)',
                    data: data.data.map(item => item.loss),
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.5)',
                    yAxisID: 'y1',
                    pointRadius: 0,
                    borderWidth: 3,
                    tension: 0.1
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
                    text: 'Network Performance: RTT and Loss',
                    font: {
                        size: 18
                    }
                },
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            return context[0].label;
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

// Auto-refresh page every 60 seconds if monitor is active
<?php if ($monitor['effectively_active']): ?>
    setTimeout(function() {
        location.reload();
    }, 60000);
<?php endif; ?>
</script>
</body>
</html>
<?php $mysqli->close(); ?>