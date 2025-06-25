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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/assets/base.css">
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

                <script>
                function confirmReset(monitorId, token) {
                    if (confirm('Are you sure you want to reset all statistics for this monitor?\n\nThis will reset all current values, averages, and counters to 0.\n\nThis action cannot be undone.')) {
                        fetch(`/cgi-bin/api/monitor/${monitorId}/reset`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Authorization': `Bearer ${token}`
                            }
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                location.reload();
                            } else {
                                alert('Error: ' + (data.message || 'Failed to reset statistics'));
                            }
                        })
                        .catch(error => {
                            alert('Error: ' + error.message);
                        });
                    }
                }
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Monitor Info Column -->
        <div class="col-md-3">
            <!-- Monitor Details Card -->
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

            <!--
            <div class="container text-center">
                <svg width="640" height="180" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <marker id="arrow" refX="0" refY="2" markerWidth="4" markerHeight="4" orient="auto">
                            <path d="M 0 0 L 4 2 L 0 4 z" fill="#5a5a5a" />
                        </marker>
                    </defs>
                    
                    <g transform="translate(10,20)">
                        <rect rx="10" ry="10" width="250" height="40" 
                            stroke="#000" stroke-width="3" fill="#fff" opacity="0.5" />
                        <a data-bs-toggle="tooltip" class="text-decoration-none" 
                        href="/agent.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>">
                            <text x="125" y="20" alignment-baseline="middle" 
                                font-family="monospace" font-size="16" fill="blue" 
                                stroke-width="0" stroke="#000" text-anchor="middle">
                                <?= htmlspecialchars($monitor['agent_name']) ?>
                            </text>
                        </a>
                    </g>
                    
                    <g transform="translate(380,20)">
                        <rect rx="10" ry="10" width="250" height="40" 
                            stroke="#000" stroke-width="3" fill="#fff" opacity="0.5" />
                        <a data-bs-toggle="tooltip" class="text-decoration-none" 
                        href="/target.php?id=<?= htmlspecialchars($monitor['target_id']) ?>">
                            <text x="125" y="20" alignment-baseline="middle" 
                                font-family="monospace" font-size="16" fill="blue" 
                                stroke-width="0" stroke="#000" text-anchor="middle">
                                <?= htmlspecialchars($monitor['target_address']) ?>
                            </text>
                        </a>
                    </g>
                    
                    <g transform="translate(65,105)">
                        <text x="5" y="-20" alignment-baseline="middle" 
                            font-family="monospace" font-size="16" fill="grey" 
                            stroke-width="0" stroke="#000" text-anchor="right">
                            <?= htmlspecialchars($monitor['description']) ?>
                        </text>
                        <path d="M 0 0 500 0" fill="none" stroke="#5a5a5a" 
                            stroke-linejoin="round" stroke-width="4" marker-end="url(#arrow)" />
                        <text x="310" y="20" alignment-baseline="middle" 
                            font-family="monospace" font-size="16" fill="grey" 
                            stroke-width="0" stroke="#000" text-anchor="right">
                            <?= htmlspecialchars($monitor['pollcount']) ?>x/<?= htmlspecialchars($monitor['pollinterval']) ?>sec interval
                        </text>
                    </g>
                </svg>
            </div>
            -->

            <div class="container text-center">
                <div class="row justify-content-center">
                    <div class="col-12 col-lg-8">
                        <br />
                        <!-- Performance Graphs -->
                        <img src="/cgi-bin/api/rrd?id=<?= htmlspecialchars($monitor['id']) ?>&cmd=graph&ds=rtt&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" alt="Latency Graph" width="100%" />
                        <br /><br />
                        <img src="/cgi-bin/api/rrd?id=<?= htmlspecialchars($monitor['id']) ?>&cmd=graph&ds=loss&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" alt="Loss Graph" width="100%" />
                        <br /><br />
                        <!-- DateTime Form -->
                        <form action="monitor.php" method="GET" id="dateTimeForm">
                            <div class="input-group">
                                <span class="input-group-text">Date</span>
                                <input type="datetime-local" class="form-control" name="start" value="<?= htmlspecialchars($start) ?>" />
                                <input type="datetime-local" class="form-control" name="end" value="<?= htmlspecialchars($end) ?>" />
                                <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>" />
                                <button type="submit" class="btn btn-primary">Submit</button>
                            </div>
                        </form><br />
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Convert UTC times to local timezone for display
    const dateInputs = document.querySelectorAll('input[type="datetime-local"]');
    dateInputs.forEach(input => {
        if (input.value) {
            const utcDate = new Date(input.value + 'Z'); // Add Z to indicate UTC
            const localDate = new Date(utcDate.toLocaleString());
            input.value = localDate.toISOString().slice(0, 16);
        }
    });

    // Convert local time to UTC when submitting
    const dateTimeForm = document.querySelector('#dateTimeForm');  // Use specific ID
    if (dateTimeForm) {  // Only add listener if it's the datetime form
        dateTimeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(dateTimeForm);
            const start = new Date(formData.get('start'));
            const end = new Date(formData.get('end'));
            
            // Convert to UTC
            formData.set('start', start.toISOString().slice(0, 16));
            formData.set('end', end.toISOString().slice(0, 16));
            
            // Submit with UTC times
            const queryString = new URLSearchParams(formData).toString();
            window.location.href = window.location.pathname + '?' + queryString;
        });
    }
});

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