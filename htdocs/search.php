<?php
session_start();
require_once 'config.php';

$search = trim($_GET['q'] ?? '');
if (empty($search)) {
    header('Location: /index.php');
    exit;
}

// Get show_inactive preference from GET or session
$show_inactive = isset($_GET['show_inactive']) ?
    filter_var($_GET['show_inactive'], FILTER_VALIDATE_BOOLEAN) :
    ($_SESSION['show_inactive'] ?? false);
$_SESSION['show_inactive'] = $show_inactive;

try {
    // Search query with prepared statement
    $stmt = $mysqli->prepare("
        SELECT 
            m.*,
            a.name as agent_name,
            a.address as agent_address,
            a.description as agent_description,
            a.is_active as agent_is_active,
            t.address as target_address,
            t.description as target_description,
            t.is_active as target_is_active
        FROM monitors m
        JOIN agents a ON m.agent_id = a.id
        JOIN targets t ON m.target_id = t.id
        WHERE 
            m.description LIKE ? OR
            a.name LIKE ? OR
            a.address LIKE ? OR
            t.address LIKE ? OR
            t.description LIKE ?
        ORDER BY m.description, a.name, t.address
    ");

    $search_param = "%$search%";
    $stmt->bind_param("sssss", 
        $search_param, $search_param, $search_param, 
        $search_param, $search_param
    );

    $stmt->execute();
    $result = $stmt->get_result();
    $monitors = [];

    // Calculate statistics while fetching
    $stats = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'effectively_inactive' => 0
    ];

    while ($row = $result->fetch_assoc()) {
        // Calculate status colors (same as in monitor.php)
        $current_median = floatval($row['current_median']);
        $current_loss = floatval($row['current_loss']);
        $avg_median = floatval($row['avg_median']);
        $avg_stddev = floatval($row['avg_stddev']);

        // Add color classes based on thresholds (same as monitor.php)
        if ($current_median > $avg_median + 2*$avg_stddev)
            $row['current_median_color'] = 'bg-danger';
        elseif ($current_median > $avg_median)
            $row['current_median_color'] = 'bg-warning';
        elseif ($current_median == $avg_median)
            $row['current_median_color'] = 'bg-info';
        else
            $row['current_median_color'] = 'bg-success';

        if ($current_loss >= 75)
            $row['current_loss_color'] = 'bg-danger';
        elseif ($current_loss >= 50)
            $row['current_loss_color'] = 'bg-warning';
        elseif ($current_loss >= 2)
            $row['current_loss_color'] = 'bg-info';
        else
            $row['current_loss_color'] = 'bg-success';

        // Calculate effective status
        $row['effectively_active'] = $row['is_active'] && 
                                   $row['agent_is_active'] && 
                                   $row['target_is_active'];

        // Update statistics
        $stats['total']++;
        if ($row['effectively_active']) {
            $stats['active']++;
        } else {
            $stats['effectively_inactive']++;
            if (!$row['is_active']) {
                $stats['inactive']++;
            }
        }

        $monitors[] = $row;
    }
    $stmt->close();

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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: Search Results</title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid">
    <!-- Header Row -->
    <div class="row mb-3">
        <div class="col">
            <h3>
                Search: <?= htmlspecialchars($search) ?>
            </h3>
        </div>
        <div class="col text-end">
            <div class="d-flex justify-content-end align-items-center gap-2">
            <!-- Header Button Group -->
            <div class="btn-group" role="group">
                <!-- BACK -->
                <?php if (isset($_SERVER['HTTP_REFERER'])): ?>
                    <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER']) ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                <?php endif; ?>  
                <!-- HOME -->                
                <a href="/index.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-house-door"></i> Home
                </a>
                <!-- Show Inactive Switch -->
                <div class="btn btn-secondary btn-sm d-flex align-items-center" style="gap: 5px;">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="showInactive" 
                            <?= $show_inactive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showInactive">
                            Inactive
                        </label>
                    </div>
                </div>
            </div>

                <!-- Search Form -->
                <form action="/search.php" method="GET" class="d-flex align-items-center">
                    <div class="input-group input-group-sm">
                        <input type="text" 
                            name="q" 
                            class="form-control" 
                            value="<?= htmlspecialchars($search) ?>" 
                            placeholder="Search monitors..."
                            aria-label="Search monitors">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Statistics Column -->
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Search Statistics</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Active Monitors
                            <span class="badge bg-success rounded-pill">
                                <?= $stats['active'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Inactive Monitors
                            <span class="badge bg-warning rounded-pill">
                                <?= $stats['inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Effectively Inactive
                            <span class="badge bg-secondary rounded-pill">
                                <?= $stats['effectively_inactive'] ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Total Results
                            <span class="badge bg-primary rounded-pill">
                                <?= $stats['total'] ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Results Column -->
        <div class="col-md-9">
            <!-- Results Table -->
            <div class="table-responsive">
                <table id="tablePager" class="table table-light table-bordered table-striped table-hover">
                    <thead>                     
                        <tr>
                            <th>Monitor</th>
                            <th>Agent</th>
                            <th>Target</th>
                            <th>Protocol</th>
                            <th class="text-center table-primary">Median</th>
                            <th class="text-center table-primary">Loss</th>
                            <th class="text-center">Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monitors)): ?>
                            <tr>
                                <td colspan="7" class="text-center">No results found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($monitors as $m): ?>
                                <?php
                                // Skip if inactive and not showing inactive
                                if (!$m['effectively_active'] && !$show_inactive) {
                                    continue;
                                }
                                ?>
                                <tr class="<?= $m['effectively_active'] ? '' : 'table-secondary' ?>">
                                    <td>
                                        <a href="/monitor.php?id=<?= htmlspecialchars($m['id']) ?>"
                                           class="<?= $m['effectively_active'] ? 'text-decoration-none' : 'text-muted' ?>"
                                           title="<?= htmlspecialchars($m['id']) ?>"
                                           data-bs-toggle="tooltip">
                                            <?= !empty($m['description']) ? htmlspecialchars($m['description']) : htmlspecialchars($m['id']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="/agent.php?id=<?= htmlspecialchars($m['agent_id']) ?>"
                                           class="<?= $m['effectively_active'] ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['agent_name']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="/target.php?id=<?= htmlspecialchars($m['target_id']) ?>"
                                           class="<?= $m['effectively_active'] ? 'text-decoration-none' : 'text-muted' ?>">
                                            <?= htmlspecialchars($m['target_address']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="<?= $m['effectively_active'] ? '' : 'text-muted' ?>"
                                              title="DSCP: <?= htmlspecialchars($m['dscp']) ?>"
                                              data-bs-toggle="tooltip">
                                            <?php if (strtoupper($m['protocol']) == 'ICMP'): ?>
                                                <?= strtoupper($m['protocol']) ?>
                                            <?php else: ?>
                                                <?= strtoupper($m['protocol']) ?>/<?= htmlspecialchars($m['port']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-primary">
                                        <span class="badge <?= $m['effectively_active'] ? $m['current_median_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_median']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center table-primary">
                                        <span class="badge <?= $m['effectively_active'] ? $m['current_loss_color'] : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($m['current_loss']) ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="<?= $m['effectively_active'] ? '' : 'text-muted' ?>"
                                              title="Last Down: <?= htmlspecialchars($m['last_down']) ?>"
                                              data-bs-toggle="tooltip">
                                            <?= htmlspecialchars($m['last_update']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
<?php $mysqli->close(); ?>