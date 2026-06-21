<?php
require_once '/srv/htdocs/config.php';
require_once '/srv/htdocs/lib/page.php';
wanportal_session_start();

// SwaggerUI CSS/JS via head_extras (loaded only on this page).
$head_extras  = '    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@latest/swagger-ui.css" />' . "\n";
$head_extras .= '    <style>' . "\n";
$head_extras .= '        /* SwaggerUI renders its own full-page layout. Give it room.' . "\n";
$head_extras .= '           The navbar sits above; the swagger-ui div fills below. */' . "\n";
$head_extras .= '        #swagger-ui { padding-bottom: 2rem; }' . "\n";
$head_extras .= '        .swagger-ui .topbar { display: none; }' . "\n";
$head_extras .= '    </style>' . "\n";
$head_extras .= '    <script src="https://unpkg.com/swagger-ui-dist@latest/swagger-ui-bundle.js"></script>' . "\n";

wanportal_render_head('API Documentation', ['head_extras' => $head_extras]);
?>

<div id="swagger-ui"></div>

<script>
    window.addEventListener('DOMContentLoaded', function () {
        window.ui = SwaggerUIBundle({
            url: '/api-docs/openapi.yaml',
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIBundle.SwaggerUIStandalonePreset
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl
            ],
        });
    });
</script>

<?php wanportal_render_page_end(); ?>