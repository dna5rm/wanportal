<?php
// netping.php
session_start();
require_once 'config.php';

// Allow Authenticated users to view the script.

$filename = '/srv/netping-agent.pl';
$imagename = '/srv/htdocs/assets/netping_latest.tar.gz';


// Get the server name
$server_name = $_SERVER['SERVER_NAME'];

// Initialize agent details
$agent_details = null;

// Check if ID parameter exists and fetch agent details
if (isset($_GET['id']) && isset($_SESSION['user'])) {
    $agent_id = $_GET['id'];
    $api_url = "https://{$server_name}/cgi-bin/api/agent/{$agent_id}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $_SESSION['token']  // Add the session token
        ]
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Decode the JSON response
    $agent_data = json_decode($response, true);
    if ($agent_data && $agent_data['status'] === 'success') {
        $agent = $agent_data['agent'];
    }
}

// Check if the request is from curl
$is_curl = (strpos(strtolower($_SERVER['HTTP_USER_AGENT'] ?? ''), 'curl') !== false);

try {
    if (!file_exists($filename) || !is_readable($filename)) {
        throw new Exception("Unable to read script file: $filename");
    }
    
    $content = file_get_contents($filename);
    
    // If it's a curl request, output raw content and exit
    if ($is_curl) {
        header('Content-Type: text/plain');
        echo $content;
        exit;
    }
    
    $fileInfo = stat($filename);
    
    // Get Docker image information
    if (!file_exists($imagename) || !is_readable($imagename)) {
        $imageInfo = false;
    } else {
        $imageInfo = stat($imagename);
    }
    
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
    <title><?= strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0] ?? 'NETPING') ?> :: <?= basename(htmlspecialchars($filename)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism-okaidia.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />
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
                Script: <?= basename(htmlspecialchars($filename)) ?>
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
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <!-- Script Info Column -->
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Script Details</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <strong>Size:</strong><br/>
                            <?= number_format($fileInfo['size']) ?> bytes
                        </li>
                        <li class="list-group-item">
                            <strong>Last Modified:</strong><br/>
                            <?= date('Y-m-d H:i:s', $fileInfo['mtime']) ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Docker Info Card -->
            <?php if ($imageInfo): ?>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Docker Image</h5>
                        <p class="mb-3">
                            <a href="/assets/netping_latest.tar.gz" class="btn btn-primary btn-sm">
                                <i class="bi bi-download"></i> Download 
                            </a>
                        </p>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item">
                                <strong>Size:</strong><br/>
                                <?= number_format($imageInfo['size']) ?> bytes
                            </li>
                            <li class="list-group-item">
                                <strong>Last Modified:</strong><br/>
                                <?= date('Y-m-d H:i:s', $imageInfo['mtime']) ?>
                            </li>
                            <li class="list-group-item">
                                <strong>MD5 Hash:</strong><br/>
                                <span class="text-muted" style="font-size: 0.9em">
                                    <?= md5_file($imagename) ?>
                                </span>
                            </li>
                        </ul>
                        <hr />
                        <div class="installation-steps">
                            <p><strong>To load the image:</strong></p>
                            <pre class="bg-light p-2 rounded text-wrap"><code>gunzip -c netping_latest.tar.gz | docker load</code></pre>
                            
                            <p><strong>To run the container:</strong></p>
                            <?php if ($agent): ?>
                                <pre class="bg-light p-2 rounded text-wrap"><code>docker run -d --name netping-<?= strtolower(htmlspecialchars($agent['name'])) ?> --network host --restart unless-stopped -e SERVER="https://<?= htmlspecialchars($server_name) ?>/cgi-bin/api" -e AGENT_ID="<?= htmlspecialchars($agent['id']) ?>" -e PASSWORD="<?= htmlspecialchars($agent['password']) ?>" netping:latest</code></pre>
                            <?php else: ?>
                                <pre class="bg-light p-2 rounded text-wrap"><code>docker run -d --name netping-agent --network host --restart unless-stopped -e SERVER="https://<?= htmlspecialchars($server_name) ?>/cgi-bin/api" -e AGENT_ID="&lt;AGENT_ID&gt;" -e PASSWORD="&lt;PASSWORD&gt;" netping:latest</code></pre>
                            <?php endif; ?>

                            <p><strong>To verify the container is running:</strong></p>
                            <?php if ($agent): ?>
                            <pre class="bg-light p-2 rounded text-wrap"><code>docker ps | grep netping-<?= strtolower(htmlspecialchars($agent['name'])) ?></code></pre>
                            <?php else: ?>
                            <pre class="bg-light p-2 rounded text-wrap"><code>docker ps | grep netping-agent</code></pre>
                            <?php endif; ?>

                            <p><strong>To view container logs:</strong></p>
                            <?php if ($agent): ?>
                            <pre class="bg-light p-2 rounded text-wrap"><code>docker logs netping-<?= strtolower(htmlspecialchars($agent['name'])) ?></code></pre>
                            <?php else: ?>
                            <pre class="bg-light p-2 rounded text-wrap"><code>docker logs netping-agent</code></pre>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Script Content Column -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-body p-0">
                    <!-- Hidden input for copy functionality -->
                    <input type="hidden" id="codeContent" value="<?= htmlspecialchars($content) ?>" />
                    
                    <!-- Displayed code with syntax highlighting -->
                    <pre class="m-0"><code class="language-perl line-numbers"><?= htmlspecialchars($content) ?></code></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<!-- Include Prism.js and its plugins -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/components/prism-perl.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.js"></script>
</body>
</html>
