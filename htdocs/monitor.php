<?php
require_once 'config.php';
session_start();

$monitor_id = $_GET['id'] ?? '';
if (!$monitor_id) die("No monitor id specified.");

// Fetch monitor data with joins to agent and target
$res = $mysqli->query("SELECT 
    m.*, t.address AS target_address, t.description AS target_description,
    a.name AS agent_name, a.address AS agent_address, a.description AS agent_description
FROM monitors m
JOIN targets t ON m.target_id = t.id
JOIN agents a ON m.agent_id = a.id
WHERE m.id='" . $mysqli->escape_string($monitor_id) . "'");

if (!$res || $res->num_rows == 0) die("Monitor not found");
$monitor = $res->fetch_assoc();
$res->close();

// Assign color classes based on thresholds (similar to previous code)
$current_median = floatval($monitor['current_median']);
$avg_median = floatval($monitor['avg_median']);
$avg_min = floatval($monitor['avg_min']);
$avg_max = floatval($monitor['avg_max']);
$avg_stddev = floatval($monitor['avg_stddev']);
$avg_loss = floatval($monitor['avg_loss']);
$current_loss = floatval($monitor['current_loss']);
?><!DOCTYPE html>
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
            <h3><span title="<?= htmlspecialchars($monitor['id']) ?>" data-toggle="tooltip">
                <?= htmlspecialchars($monitor['description']) ?>
            </span></h3>

            <ul class="list-group">
              <li><strong>ID:</strong><br /><?= htmlspecialchars($monitor['id']) ?></li>
              <li><strong>Protocol:</strong><br /><?= htmlspecialchars($monitor['protocol']) ?></li>
              <li><strong>Port:</strong><br /><?= htmlspecialchars($monitor['port']) ?></li>
              <li><strong>DSCP:</strong><br /><?= htmlspecialchars($monitor['dscp']) ?></li>
              <li><strong>Last Cleared:</strong><br /><?= $monitor['last_clear'] ?></li>
              <li><strong>Last Down:</strong><br /><?= htmlspecialchars($monitor['last_down']) ?></li>
            </ul>

            <a href="index.php" class="btn btn-secondary mt-3">Back</a>
        </div>

        <!-- Agent Monitoring -->
        <div class="col"><h1>&nbsp;</h1>
            <div class="d-flex align-items-end mb-4">

                <!-- Spacer -->
                <div style="width: 2%;">&nbsp;</div>

                <!-- Details -->
                <div style="width: 640px;">
                  <svg width="640" height="180" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                      <marker id="arrow" refX='0' refY='2' markerWidth='4' markerHeight='4' orient='auto'>
                        <path d='M 0 0 L 4 2 L 0 4 z' fill='#5a5a5a' />
                      </marker>
                    </defs>
                    <g transform="translate(10,20)">
                      <rect rx="10" ry="10" width="250" height="40" stroke="#000" stroke-width="3" fill="#fff" opacity="0.5"></rect>
                      <a data-toggle="tooltip" style="color:black; text-decoration: none;" href="/agent.php?id=<?= htmlspecialchars($monitor['agent_id']) ?>">
                        <text x="125" y="20" alignment-baseline="middle" font-family="monospace" font-size="16" fill="blue" stroke-width="0" stroke="#000" text-anchor="middle">
                            <?= htmlspecialchars($monitor['agent_name']) ?>
                        </text>
                      </a>
                    </g>
                    <g transform="translate(380,20)">
                      <rect rx="10" ry="10" width="250" height="40" stroke="#000" stroke-width="3" fill="#fff" opacity="0.5"></rect>
                      <a data-toggle="tooltip" style="color:black; text-decoration: none;" href="/target.php?id=<?= htmlspecialchars($monitor['target_id']) ?>">
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
                    Sorry, your browser does not support inline SVG.
                  </svg>

                  <table class="table table-dark table-bordered table-hover">
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
                        <td><span class="badge <?= $monitor['average_minimum_color'] ?>"><?= htmlspecialchars($monitor['avg_min']) ?></span></td>
                        <td><span class="badge <?= $monitor['average_maximum_color'] ?>"><?= htmlspecialchars($monitor['avg_max']) ?></span></td>
                        <td><span class="badge <?= $monitor['average_stddev_color'] ?>"><?= htmlspecialchars($monitor['avg_stddev']) ?></span></td>
                        <td><span class="badge <?= $monitor['average_loss_color'] ?>"><?= htmlspecialchars($monitor['avg_loss']) ?>%</span></td>
                      </tr>
                    </tbody>
                  </table>

                  <!-- <div class="d-flex justify-content-end">
                    <button title="Last Cleared: {{ monitor.last_clear }}" id="clearStat_button" class="btn btn-danger" data-toggle="tooltip" value="{{ monitor.id }}">Clear Statistics</button>
                  </div> -->

                  <br />

                  <div class="h-100 d-flex align-items-center justify-content-center">
                    <img src="#" alt="latency graph" />
                  </div>

                  <br />

                  <div class="h-100 d-flex align-items-center justify-content-center">
                    <img src="#" alt="loss graph" />
                  </div>

                  <br />

                  <!-- <div class="col" style="width: 695px;">
                    <form action="#" method="GET">
                      <div class="input-group">
                        <span title="Server Timezone" class="input-group-text" data-toggle="tooltip">{{ timezone }}</span>
                        <input type="datetime-local" class="form-control" name="start" value="{{ start }}" />
                        <input type="datetime-local" class="form-control" name="end" value="{{ end }}" />
                        <input class="btn btn-primary" type="submit" value="Submit" />
                      </div>
                    </form>
                  </div> -->

                </div>

              </div>
          </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>