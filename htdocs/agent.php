<?php
require_once 'config.php';
session_start();

$agent_id = $_GET['id'] ?? '';
if (!$agent_id) die("No agent ID");

// Fetch agent
$res = $mysqli->query("SELECT * FROM agents WHERE id='" . $mysqli->escape_string($agent_id) . "'");
if (!$res || $res->num_rows == 0) die("Agent not found");
$agent = $res->fetch_assoc();
$res->close();

// Fetch monitors joined with targets
$query = "
SELECT 
  m.*, 
  t.address AS target_address, t.description AS target_description
FROM monitors m
JOIN targets t ON m.target_id = t.id
WHERE m.agent_id='" . $mysqli->escape_string($agent_id) . "'
ORDER BY m.description";
$res = $mysqli->query($query);

$monitor = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        // Convert values to float
        $avg_median = floatval($row['avg_median']);
        $avg_min = floatval($row['avg_min']);
        $avg_max = floatval($row['avg_max']);
        $avg_stddev = floatval($row['avg_stddev']);
        $avg_loss = floatval($row['avg_loss']);
        $current_median = floatval($row['current_median']);
        $current_loss = floatval($row['current_loss']);

        // Compute thresholds
        // Current median vs average median + 2*stddev
        if ($current_median > $avg_median + 2*$avg_stddev)
            $row['current_median_color'] = 'bg-danger';
        elseif ($current_median > $avg_median)
            $row['current_median_color'] = 'bg-warning';
        elseif ($current_median == $avg_median)
            $row['current_median_color'] = 'bg-info';
        else
            $row['current_median_color'] = 'bg-success';

        // Current loss
        if ($current_loss >= 75)
            $row['current_loss_color'] = 'bg-danger';
        elseif ($current_loss >= 50)
            $row['current_loss_color'] = 'bg-warning';
        elseif ($current_loss >= 2)
            $row['current_loss_color'] = 'bg-info';
        else
            $row['current_loss_color'] = 'bg-success';

        // Average median comparison
        $avg_threshold = $avg_median + 2 * $avg_stddev;
        if ($current_median > $avg_threshold)
            $row['avg_median_color'] = 'bg-danger';
        elseif ($current_median > $avg_median + $avg_stddev)
            $row['avg_median_color'] = 'bg-warning';
        elseif ($current_median >= $avg_median)
            $row['avg_median_color'] = 'bg-info';
        else
            $row['avg_median_color'] = 'bg-success';

        // Average Min
        if ($avg_min <= ($avg_median - 3*$avg_stddev))
            $row['avg_minimum_color'] = 'bg-danger';
        elseif ($avg_min <= ($avg_median - 2*$avg_stddev))
            $row['avg_minimum_color'] = 'bg-warning';
        elseif ($avg_min <= ($avg_median - $avg_stddev))
            $row['avg_minimum_color'] = 'bg-info';
        else
            $row['avg_minimum_color'] = 'bg-success';

        // Average Max
        if ($avg_max >= ($avg_median + 3*$avg_stddev))
            $row['avg_maximum_color'] = 'bg-danger';
        elseif ($avg_max >= ($avg_median + 2*$avg_stddev))
            $row['avg_maximum_color'] = 'bg-warning';
        elseif ($avg_max >= ($avg_median + $avg_stddev))
            $row['avg_maximum_color'] = 'bg-info';
        else
            $row['avg_maximum_color'] = 'bg-success';

        // Average StdDev
        $avg_stddev_threshold = abs(($avg_max - $avg_min) / 2);
        $row['avg_stddev_color'] = ($avg_stddev > $avg_stddev_threshold) ? 'bg-info' : 'bg-success';

        // Average Loss
        if ($avg_loss < 2)
            $row['avg_loss_color'] = 'bg-success';
        elseif ($avg_loss < 5)
            $row['avg_loss_color'] = 'bg-info';
        elseif ($avg_loss < 13)
            $row['avg_loss_color'] = 'bg-warning';
        else
            $row['avg_loss_color'] = 'bg-danger';

        $monitor[] = $row;
    }
    $res->close();
}

// Count active/disabled monitors
$active_count=0; $disabled_count=0;
foreach($monitor as $m) {
    if ((int)$m['is_active']) $active_count++;
    else $disabled_count++;
} ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Agent: <?= htmlspecialchars($agent['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body><?php include 'navbar.php'; ?>

<div class="container-fluid">
    <div class="row">

        <!-- Agent Info -->
        <div class="col-2">
            <h3><span title="<?= htmlspecialchars($agent['address']) ?>" data-toggle="tooltip">
                <?= htmlspecialchars($agent['name']) ?>
            </span></h3>
            <p><?= htmlspecialchars($agent['description']) ?></p>
            <br />

            <!-- Monitors summary badge -->
            <div class="card mb-4 shadow-sm border border-secondary rounded" style="background-color: #FFFDD0;">
                <div class="card-body p-3">
                    <h5>Monitors</h5>
                    <p class="mb-0">Active: <?= $active_count ?></p>
                    <p class="mb-0">Disabled: <?= $disabled_count ?></p>
                    <p class="mb-0">Total: <?= count($monitor) ?></p>
                </div>
            </div>

            <a href="index.php" class="btn btn-secondary mt-3">Back</a>
        </div>

        <!-- Agent Monitoring -->
        <div class="col"><h1>&nbsp;</h1>
            <div class="d-flex align-items-end mb-4">

                <!-- Spacer -->
                <div style="width: 2%;">&nbsp;</div>

                <div style="width: 95%;">
                    <!-- Detail table -->
                    <table class="table table-light table-bordered table-striped table-hover">
                        <thead>
                            <tr>
                                <th colspan="3" class="text-center" scope="col"></th>
                                <th colspan="2" class="table-primary text-center" scope="col">Current</th>
                                <th colspan="5" class="table-secondary text-center" scope="col">Average</th>
                                <th colspan="1" class="text-center" scope="col"></th>
                            </tr>
                            <tr>
                                <th>Monitor</th>
                                <th>Address</th>
                                <th>Protocol</th>
                                <th class="text-center table-primary">Median</th>
                                <th class="text-center table-primary">Loss</th>
                                <th class="text-center table-secondary">Median (Avg)</th>
                                <th class="text-center table-secondary">Min</th>
                                <th class="text-center table-secondary">Max</th>
                                <th class="text-center table-secondary">Std Dev</th>
                                <th class="text-center table-secondary">Loss (Avg)</th>
                                <th class="text-center">Up/Down</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monitor as $m): ?>
                            <tr>
                                <td>
                                    <a href="/monitor.php?id=<?= htmlspecialchars($m['id']) ?>">
                                    <?= !empty($m['description']) ? htmlspecialchars($m['description']) : htmlspecialchars($m['id']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span title="<?= urlencode($m['target_id']) ?>" data-toggle="tooltip">
                                        <?= htmlspecialchars($m['target_address']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span title="DSCP: <?= htmlspecialchars($m['dscp']) ?>" data-toggle="tooltip">
                                    <?php if (strtoupper($m['protocol']) == 'ICMP'): ?>
                                        <?= strtoupper($m['protocol']) ?>
                                    <?php else: ?>
                                        <?= strtoupper($m['protocol']) ?>/<?= htmlspecialchars($m['port']) ?>
                                    <?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-center table-primary">
                                    <span class="badge <?= $m['current_median_color'] ?>"><?= htmlspecialchars($m['current_median']) ?></span>
                                </td>
                                <td class="text-center table-primary">
                                    <span class="badge <?= $m['current_loss_color'] ?>"><?= htmlspecialchars($m['current_loss']) ?>%</span>
                                </td>
                                <td class="text-center table-secondary">
                                    <span class="badge <?= $m['avg_median_color'] ?>"><?= htmlspecialchars($m['avg_median']) ?></span>
                                </td>
                                <td class="text-center table-secondary">
                                    <span class="badge <?= $m['avg_minimum_color'] ?>"><?= htmlspecialchars($m['avg_min']) ?></span>
                                </td>
                                <td class="text-center table-secondary">
                                    <span class="badge <?= $m['avg_maximum_color'] ?>"><?= htmlspecialchars($m['avg_max']) ?></span>
                                </td>
                                <td class="text-center table-secondary">
                                    <span class="badge <?= $m['avg_stddev_color'] ?>"><?= htmlspecialchars($m['avg_stddev']) ?></span>
                                </td>
                                <td class="text-center table-secondary">
                                    <span class="badge <?= $m['avg_loss_color'] ?>"><?= htmlspecialchars($m['avg_loss']) ?>%</span>
                                </td>
                                <td class="text-center">
                                    <span title="Last Updated: <?= htmlspecialchars($m['last_update']) ?>"><?= htmlspecialchars($m['last_down']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                </div>

            </div>
        </div>

    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>