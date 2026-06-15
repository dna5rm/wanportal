<?php
// netping.php

require_once 'config.php';
require_once __DIR__ . '/lib/page.php';
wanportal_session_start();
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
// Prism syntax highlighting: the CSS lives in <head>, the JS
// after the page content. We pass both through head_extras
// because that's the only place to inject <script src> in the
// page chrome.
$head_extras  = '    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism-okaidia.min.css" rel="stylesheet" />' . "\n";
$head_extras .= '    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />' . "\n";
$head_extras .= '    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/prism.min.js"></script>' . "\n";
$head_extras .= '    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/components/prism-perl.min.js"></script>' . "\n";
$head_extras .= '    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/line-numbers/prism-line-numbers.min.js"></script>' . "\n";

wanportal_render_head(basename(htmlspecialchars($filename)), ['head_extras' => $head_extras]);
wanportal_render_header_row('Script: ' . basename(htmlspecialchars($filename, ENT_QUOTES, 'UTF-8')));
?>
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
                            <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>gunzip -c netping_latest.tar.gz | docker load</code></pre>

                            <p><strong>To run the container:</strong></p>
                            <?php if ($agent): ?>
                                <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>docker run -d --name netping-<?= strtolower(htmlspecialchars($agent['name'])) ?> --network host --restart unless-stopped -e SERVER="https://<?= htmlspecialchars($server_name) ?>/cgi-bin/api" -e AGENT_ID="<?= htmlspecialchars($agent['id']) ?>" -e PASSWORD="<?= htmlspecialchars($agent['password']) ?>" netping:latest</code></pre>
                            <?php else: ?>
                                <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>docker run -d --name netping-agent --network host --restart unless-stopped -e SERVER="https://<?= htmlspecialchars($server_name) ?>/cgi-bin/api" -e AGENT_ID="&lt;AGENT_ID&gt;" -e PASSWORD="&lt;PASSWORD&gt;" netping:latest</code></pre>
                            <?php endif; ?>

                            <p><strong>To verify the container is running:</strong></p>
                            <?php if ($agent): ?>
                            <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>docker ps | grep netping-<?= strtolower(htmlspecialchars($agent['name'])) ?></code></pre>
                            <?php else: ?>
                            <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>docker ps | grep netping-agent</code></pre>
                            <?php endif; ?>

                            <p><strong>To view container logs:</strong></p>
                            <?php if ($agent): ?>
                            <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>docker logs netping-<?= strtolower(htmlspecialchars($agent['name'])) ?></code></pre>
                            <?php else: ?>
                            <pre class="bg-secondary-subtle p-2 rounded text-wrap"><code>docker logs netping-agent</code></pre>
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
                    
                    <!-- Displayed code with syntax highlighting.
                         The <pre> needs a "language-" class for
                         Prism's okaidia theme to apply its dark
                         background -- without it, the <code>'s
                         light foreground renders against the page
                         background and the script is unreadable in
                         light mode. With language-perl on both
                         elements, the dark code block stands out in
                         both themes (the standard "code block" look)
                         and Prism's Perl grammar highlights it. -->
                    <pre class="m-0 language-perl"><code class="language-perl line-numbers"><?= htmlspecialchars($content) ?></code></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<?php wanportal_render_page_end(); ?>
