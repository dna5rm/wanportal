<?php
require_once 'config.php';
session_start();

$monitor_id = $_GET['id'] ?? '';
if (!$monitor_id) die("No monitor ID specified");

// Fetch monitor data with joins to agent and target using prepared statement
$stmt = $mysqli->prepare("
    SELECT 
        m.*,
        t.address AS target_address,
        t.description AS target_description,
        t.is_active AS target_is_active,
        a.name AS agent_name,
        a.address AS agent_address,
        a.description AS agent_description,
        a.is_active AS agent_is_active
    FROM monitors m
    JOIN targets t ON m.target_id = t.id
    JOIN agents a ON m.agent_id = a.id
    WHERE m.id = ?
");

$stmt->bind_param("s", $monitor_id);
if (!$stmt->execute()) {
    die("Error fetching monitor: " . $mysqli->error);
}

$result = $stmt->get_result();
if (!$result || $result->num_rows == 0) die("Monitor not found");
$monitor = $result->fetch_assoc();
$stmt->close();

// Convert values to float for calculations
$current_median = floatval($monitor['current_median']);
$current_loss = floatval($monitor['current_loss']);
$avg_median = floatval($monitor['avg_median']);
$avg_min = floatval($monitor['avg_min']);
$avg_max = floatval($monitor['avg_max']);
$avg_stddev = floatval($monitor['avg_stddev']);
$avg_loss = floatval($monitor['avg_loss']);

// Assign color classes based on thresholds
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
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <h3>Monitor: 
            <span title="<?= htmlspecialchars($monitor['id']) ?>" data-bs-toggle="tooltip">
                <?= htmlspecialchars($monitor['description']) ?>
            </span>
        </h3>

        <!-- Monitor Info -->
        <div class="col-2">
            <p><?= htmlspecialchars($monitor['description']) ?></p>
            <ul>
                <li class="list-group-item"><strong>ID:</strong><br /><?= htmlspecialchars($monitor['id']) ?></li>
                <li class="list-group-item"><strong>Protocol:</strong> <?= htmlspecialchars($monitor['protocol']) ?></li>
                <li class="list-group-item"><strong>Port:</strong> <?= htmlspecialchars($monitor['port']) ?></li>
                <li class="list-group-item"><strong>DSCP:</strong> <?= htmlspecialchars($monitor['dscp']) ?></li>
                <li class="list-group-item"><strong>Active:</strong> <?= ($monitor['is_active'] && $monitor['agent_is_active'] && $monitor['target_is_active']) ? 'True' : 'False' ?></li>
                <li class="list-group-item"><strong>Last Cleared:</strong><br /><?= $monitor['last_clear'] ?></li>
                <li class="list-group-item"><strong>Last Down:</strong><br /><?= htmlspecialchars($monitor['last_down']) ?></li>
            </ul>
            <br />

            <div class="col text-center">
                <div class="btn-group" role="group">
                    <a href="/index.php" class="btn btn-secondary">
                        <i class="bi bi-house-door"></i> Home
                    </a>
                    <?php if (isset($_SESSION['user'])): ?>
                        <a href="/<?= basename($_SERVER['PHP_SELF'], '.php') ?>s_edit.php?id=<?= htmlspecialchars($monitor['id']) ?>" class="btn btn-danger">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Monitor Details -->
        <div class="col">
            <div class="d-flex align-items-end mb-4">
                <!-- Spacer -->
                <div style="width: 2%;">&nbsp;</div>

                <div style="width: 640px;">
                    <!-- SVG Diagram -->
                    <svg width="640" height="180" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <marker id="arrow" refX='0' refY='2' markerWidth='4' markerHeight='4' orient='auto'>
                                <path d='M 0 0 L 4 2 L 0 4 z' fill='#5a5a5a' />
                            </marker>
                        </defs>
                        <g transform="translate(10,20)">
                            <rect rx="10" ry="10" width="250" height="40" stroke="#000" stroke-width="3" fill="#fff" opacity="0.5"></rect>
                            <a data-bs-toggle="tooltip" style="color:black; text-decoration: none;" href="/agent.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>">
                                <text x="125" y="20" alignment-baseline="middle" font-family="monospace" font-size="16" fill="blue" stroke-width="0" stroke="#000" text-anchor="middle">
                                    <?= htmlspecialchars($monitor['agent_name']) ?>
                                </text>
                            </a>
                        </g>
                        <g transform="translate(380,20)">
                            <rect rx="10" ry="10" width="250" height="40" stroke="#000" stroke-width="3" fill="#fff" opacity="0.5"></rect>
                            <a data-bs-toggle="tooltip" style="color:black; text-decoration: none;" href="/target.php?id=<?= htmlspecialchars($monitor['target_id']) ?>">
                                <text x="125" y="20" alignment-baseline="middle" font-family="monospace" font-size="16" fill="blue" stroke-width="0" stroke="#000" text-anchor="middle">
                                    <?= htmlspecialchars($monitor['target_address']) ?>
                                </text>
                            </a>
                        </g>
                        <g transform="translate(65,105)">
                            <text x="5" y="-20" alignment-baseline="middle" font-family="monospace" font-size="16" fill="grey" stroke-width="0" stroke="#000" text-anchor="right">
                                <?= htmlspecialchars($monitor['description']) ?>
                            </text>
                            <path d="M 0 0 500 0" fill="none" stroke="#5a5a5a" stroke-linejoin="round" stroke-width="4" marker-end="url(#arrow)" />
                            <text x="310" y="20" alignment-baseline="middle" font-family="monospace" font-size="16" fill="grey" stroke-width="0" stroke="#000" text-anchor="right">
                                <?= htmlspecialchars($monitor['pollcount']) ?>x/<?= htmlspecialchars($monitor['pollinterval']) ?>sec interval
                            </text>
                        </g>
                    </svg>

                    <!-- Statistics Table -->
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Median</th>
                                <th>Min</th>
                                <th>Max</th>
                                <th>Std Dev</th>
                                <th>Loss</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge <?= $monitor['current_median_color'] ?>"><?= htmlspecialchars($monitor['current_median']) ?></span></td>
                                <td><span class="badge <?= $monitor['avg_minimum_color'] ?>"><?= htmlspecialchars($monitor['avg_min']) ?></span></td>
                                <td><span class="badge <?= $monitor['avg_maximum_color'] ?>"><?= htmlspecialchars($monitor['avg_max']) ?></span></td>
                                <td><span class="badge <?= $monitor['avg_stddev_color'] ?>"><?= htmlspecialchars($monitor['avg_stddev']) ?></span></td>
                                <td><span class="badge <?= $monitor['avg_loss_color'] ?>"><?= htmlspecialchars($monitor['avg_loss']) ?>%</span></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Performance Graphs -->
                    <div class="text-center">
                        <img src="/cgi-bin/api/rrd?id=<?= htmlspecialchars($monitor['id']) ?>&cmd=graph&ds=rtt" alt="Latency Graph" width="640" />
                        <br /><br />
                        <img src="/cgi-bin/api/rrd?id=<?= htmlspecialchars($monitor['id']) ?>&cmd=graph&ds=loss" alt="Loss Graph" width="640" />
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
<?php $mysqli->close(); ?>