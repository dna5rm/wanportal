<?php
// Include Parsedown
require_once '/srv/htdocs/config.php';
require_once '/srv/api-docs/Parsedown.php';

// Get the filename from different possible sources
$filename = null;

// Check query string first
if (isset($_GET['file'])) {
    $filename = $_GET['file'];
}
// Check PATH_INFO if query string is empty
else if (isset($_SERVER['PATH_INFO'])) {
    $filename = trim($_SERVER['PATH_INFO'], '/');
}
// Check if there's a default file to show
else {
    header('Location: /api-docs/redirect.html');
    exit;}

// Basic security check - prevent directory traversal
$filename = basename($filename);

// Make sure we're only accepting .md files
if (!preg_match('/\.md$/', $filename)) {
    die("Only .md files are allowed");
}

// Check if file exists and is readable
if (!file_exists($filename) || !is_readable($filename)) {
    die("Error: File '$filename' does not exist or is not readable.");
}

// Read the markdown file
$markdown_content = file_get_contents($filename);

// Create Parsedown instance
$parsedown = new Parsedown();

// Convert markdown to HTML
$html_content = $parsedown->text($markdown_content);

// Add IDs to headers and extract for TOC
$html_content = preg_replace_callback('/<h([1-6])>(.*?)<\/h[1-6]>/i', function($matches) {
    $level = $matches[1];
    $title = strip_tags($matches[2]);
    $id = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
    return "<h$level id=\"$id\">$title</h$level>";
}, $html_content);

// Extract all headers for TOC
preg_match_all('/<h([1-6]) id="(.*?)">(.*?)<\/h[1-6]>/i', $html_content, $header_matches, PREG_SET_ORDER);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
        <title><?= isset($_SERVER['SERVER_NAME']) ? 
        strtoupper(explode('.', $_SERVER['SERVER_NAME'])[0]) : 
        'NETPING'; ?> :: <?php echo htmlspecialchars($filename); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Prism CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/base.css">
</head>
<body>
<?php include '/srv/htdocs/navbar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- TOC Column -->
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Table of Contents</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($header_matches as $match): ?>
                            <?php
                            $level = $match[1];
                            $id = $match[2];
                            $title = strip_tags($match[3]);
                            $padding = ($level - 1) * 1.5;
                            ?>
                            <a href="#<?php echo $id; ?>" 
                                class="list-group-item list-group-item-action border-0"
                                style="padding-left: <?php echo $padding; ?>rem">
                                <?php echo htmlspecialchars($title); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Column -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-body">
                    <?php echo $html_content; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '/srv/htdocs/footer.php'; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Prism JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
<script>
    // Initialize Prism
    if (typeof Prism !== 'undefined') {
        Prism.highlightAll();
    }

    // Active state for TOC links
    document.addEventListener('DOMContentLoaded', function() {
        const tocLinks = document.querySelectorAll('.toc-link');
        
        // Add click handler for smooth scrolling
        tocLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href').substring(1);
                const targetElement = document.getElementById(targetId);
                if (targetElement) {
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Update active state on scroll
        window.addEventListener('scroll', function() {
            const fromTop = window.scrollY + 100;

            tocLinks.forEach(link => {
                const section = document.querySelector(link.hash);
                
                if (section &&
                    section.offsetTop <= fromTop &&
                    section.offsetTop + section.offsetHeight > fromTop
                ) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
    });
</script>
</body>
</html>