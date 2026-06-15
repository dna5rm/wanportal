<?php
// Both helpers below return a full Bootstrap 5.3 class string
// (including the `bg-` prefix) so the call sites can be
// `class="badge BADGE_CLASS_STRING"`. The `-subtle` background
// + matching text + matching border flips cleanly with the
// `data-bs-theme="dark"` toggle on <html>. The solid
// `bg-{color}` variants stay vivid in both themes and look out
// of place on a dark page.
function getBadgeColor($type) {
    $colors = [
        'ACCOUNT'     => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        'CERTIFICATE' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
        'API'         => 'bg-info-subtle text-info-emphasis border border-info-subtle',
        'PSK'         => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'CODE'        => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
    ];
    return $colors[$type] ?? 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
}

function getSensitivityColor($sensitivity) {
    $colors = [
        'LOW'    => 'bg-success-subtle text-success-emphasis border border-success-subtle',
        'MEDIUM' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'HIGH'   => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        // CRITICAL used to be `bg-dark` (pure black). That
        // disappears against a dark page background, defeating
        // the point of marking something as the highest
        // sensitivity. `bg-danger-subtle` is the most severe of
        // the visible-in-both-modes tokens and conveys "this is
        // the worst level" without becoming invisible.
        'CRITICAL' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
    ];
    return $colors[$sensitivity] ?? 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
}

// credential_view.php
session_start();
require_once 'check_session.php';
require_once 'config.php';
require_once __DIR__ . '/lib/page.php';

// Check authentication
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: /credentials.php');
    exit;
}

// Fetch credential details
$ch = curl_init("http://localhost/cgi-bin/api/credentials/$id");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $_SESSION['token'],
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($status !== 200) {
    header('Location: /credentials.php');
    exit;
}

$data = json_decode($response, true);
if ($data['status'] !== 'success' || !isset($data['credential'])) {
    header('Location: /credentials.php');
    exit;
}

$cred = $data['credential'];

// Page-specific CSS: the password-field / toggle-password
// styles for the credential viewer. Inlined via head_extras so
// it lives in <head> alongside the rest of the page chrome.
$head_extras  = '    <style>' . "\n";
$head_extras .= '        .password-field { position: relative; }' . "\n";
$head_extras .= '        .password-field .toggle-password { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; }' . "\n";
$head_extras .= '    </style>';

wanportal_render_head('View Credential', ['head_extras' => $head_extras]);
wanportal_render_header_row('View Credential: ' . htmlspecialchars($cred['name'], ENT_QUOTES, 'UTF-8'), [
    [
        'url'     => '/credentials.php',
        'icon'    => 'bi bi-arrow-left',
        'label'   => 'Back',
        'variant' => 'secondary',
    ],
    [
        'url'     => '/credential_edit.php?id=' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
        'icon'    => 'bi bi-pencil',
        'label'   => 'Edit',
        'variant' => 'danger',
    ],
]);
?>

    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Basic Information</h5>
                    <dl class="row">
                        <dt class="col-sm-3">Name</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['name']) ?></dd>

                        <dt class="col-sm-3">Site</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['site'] ?? 'N/A') ?></dd>

                        <dt class="col-sm-3">Type</dt>
                        <dd class="col-sm-9"><span class="badge <?= getBadgeColor($cred['type']) ?>"><?= htmlspecialchars($cred['type']) ?></span></dd>

                        <dt class="col-sm-3">Username</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['username']): ?>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($cred['username']) ?>" readonly>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToClipboard(this.previousElementSibling)">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Password</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['password']): ?>
                                <div class="input-group">
                                    <input type="password" class="form-control" value="<?= htmlspecialchars($cred['password']) ?>" readonly>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="togglePassword(this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyToClipboard(this.parentElement.querySelector('input'))">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">URL</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['url']): ?>
                                <a href="<?= htmlspecialchars($cred['url']) ?>" target="_blank">
                                    <?= htmlspecialchars($cred['url']) ?>
                                </a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Owner</dt>
                        <dd class="col-sm-9"><?= htmlspecialchars($cred['owner'] ?? 'N/A') ?></dd>

                        <dt class="col-sm-3">Sensitivity</dt>
                        <dd class="col-sm-9">
                            <span class="badge <?= getSensitivityColor($cred['sensitivity']) ?>">
                                <?= htmlspecialchars($cred['sensitivity']) ?>
                            </span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title">Additional Information</h5>
                    <dl class="row">
                        <dt class="col-sm-3">Comment</dt>
                        <dd class="col-sm-9"><?= nl2br(htmlspecialchars($cred['comment'] ?? 'N/A')) ?></dd>

                        <dt class="col-sm-3">Metadata</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['metadata']): ?>
                                <pre class="bg-light p-2 rounded"><code><?= htmlspecialchars(json_encode(json_decode($cred['metadata']), JSON_PRETTY_PRINT)) ?></code></pre>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">System Information</h5>
                    <dl class="row">
                        <dt class="col-sm-3">Created</dt>
                        <dd class="col-sm-9">
                            <?= htmlspecialchars($cred['created_at']) ?>
                            by <?= htmlspecialchars($cred['created_by']) ?>
                        </dd>

                        <dt class="col-sm-3">Updated</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['updated_at']): ?>
                                <?= htmlspecialchars($cred['updated_at']) ?>
                                by <?= htmlspecialchars($cred['updated_by']) ?>
                            <?php else: ?>
                                Never
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Last Accessed</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['last_accessed_at']): ?>
                                <?= htmlspecialchars($cred['last_accessed_at']) ?>
                                by <?= htmlspecialchars($cred['last_accessed_by']) ?>
                            <?php else: ?>
                                Never
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            <?php if ($cred['is_active']): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Inactive</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<?php wanportal_render_page_end(); ?>

<script>
// Password visibility toggle
function togglePassword(button) {
    const input = button.parentElement.querySelector('input');
    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
    input.setAttribute('type', type);
    button.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
}

// Copy to clipboard function
function copyToClipboard(element) {
    element.select();
    document.execCommand('copy');

    // Show feedback on the clipboard button
    const button = element.parentElement.querySelector('.bi-clipboard').parentElement;
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="bi bi-check"></i>';
    setTimeout(() => {
        button.innerHTML = originalHTML;
    }, 1000);
}
</script>
