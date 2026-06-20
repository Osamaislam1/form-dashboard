<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$pdo = db();

// Show secret only on new-site creation
$justCreated = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $url  = trim($_POST['url'] ?? '');
        if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            flash('Name and a valid URL are required.', 'error');
            redirect('/sites.php?new=1');
        }
        $apiKey = bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO sites (name, url, api_key, secret_hash) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, rtrim($url, '/'), $apiKey, $secret]);
        $sid = (int)$pdo->lastInsertId();
        flash('Site added. Copy the credentials below into the WP plugin — the secret is shown only once.', 'success');
        $justCreated = ['id' => $sid, 'api_key' => $apiKey, 'secret' => $secret];
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE sites SET status = IF(status='active','paused','active') WHERE id = ?")->execute([$id]);
        flash('Status updated.', 'success');
        redirect('/sites.php');
    } elseif ($action === 'rotate') {
        $id = (int)($_POST['id'] ?? 0);
        $secret = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE sites SET secret_hash = ? WHERE id = ?')->execute([$secret, $id]);
        $apiKey = $pdo->query('SELECT api_key FROM sites WHERE id = ' . $id)->fetchColumn();
        flash('Secret rotated. Update the plugin on the site.', 'success');
        $justCreated = ['id' => $id, 'api_key' => $apiKey, 'secret' => $secret];
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
        flash('Site deleted.', 'success');
        redirect('/sites.php');
    } elseif ($action === 'request_resync') {
        $id   = (int)($_POST['id'] ?? 0);
        $mode = ($_POST['resync_mode'] ?? 'append') === 'clear' ? 'clear' : 'append';
        if ($mode === 'clear' && empty($_POST['confirm_clear'])) {
            flash('Tick the confirmation checkbox to clear submissions.', 'error');
            redirect('/sites.php');
        }
        if ($mode === 'clear') {
            $pdo->prepare('DELETE FROM submissions WHERE site_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE forms SET submission_count = 0, last_submission_at = NULL WHERE site_id = ?')->execute([$id]);
        }
        $pdo->prepare('UPDATE sites SET resync_requested_at = NOW() WHERE id = ?')->execute([$id]);
        $msg = $mode === 'clear'
            ? 'Submissions cleared and resync requested. Trigger bulk sync from WP admin, or wait for the next hourly heartbeat.'
            : 'Resync (append) requested. New/missing entries will be added without duplicates. Trigger from WP admin or wait for next hourly heartbeat.';
        flash($msg, 'success');
        redirect('/sites.php');
    } elseif ($action === 'cancel_resync') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE sites SET resync_requested_at = NULL WHERE id = ?')->execute([$id]);
        flash('Resync request cancelled.', 'success');
        redirect('/sites.php');
    }
}

$sites = $pdo->query(
    "SELECT s.*,
            (SELECT COUNT(*) FROM forms WHERE site_id = s.id) AS form_count,
            (SELECT COUNT(*) FROM submissions WHERE site_id = s.id) AS sub_count
     FROM sites s ORDER BY s.name"
)->fetchAll();
$baseUrl = rtrim((string)cfg('app.base_url'), '/');

$showNew = isset($_GET['new']);
$page = 'Sites';
$active = 'sites';
require __DIR__ . '/../src/layout.php';
?>

<div class="page-head">
    <div>
        <h1>Sites</h1>
        <div class="sub"><?= count($sites) ?> registered</div>
    </div>
    <?php if ($user['role']==='admin'): ?>
        <a href="/sites.php?new=1" class="btn primary">+ Add site</a>
    <?php endif; ?>
</div>

<?php if ($justCreated): ?>
<div class="card" style="background:rgba(163,230,53,0.06); border-color:rgba(163,230,53,0.3); margin-bottom: 20px;">
    <div style="font-weight:500; margin-bottom:10px;">⚡ Save these credentials — the secret is only shown now</div>
    <div class="kvgrid">
        <div class="k">API key</div><div class="v mono"><?= e($justCreated['api_key']) ?></div>
        <div class="k">Secret</div><div class="v mono"><?= e($justCreated['secret']) ?></div>
        <div class="k">Ingest URL</div><div class="v mono"><?= e(cfg('app.base_url')) ?>/ingest.php</div>
        <div class="k">Web3Forms Webhook</div><div class="v mono"><?= e(cfg('app.base_url')) ?>/web3forms.php?key=<?= e($justCreated['api_key']) ?></div>
    </div>
    <p style="color:var(--text-dim); font-size:13px; margin: 12px 0 0; line-height:1.5;">
        <strong>WordPress:</strong> Install the <code>form-dashboard-bridge</code> plugin and paste the API key and Secret.<br>
        <strong>Web3Forms:</strong> Paste the Web3Forms Webhook URL into your Web3Forms Pro settings.
    </p>
</div>
<?php endif; ?>

<?php if ($showNew && $user['role']==='admin'): ?>
<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0; font-size:14px;">Add a new site</h3>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <div class="field">
            <label>Site name</label>
            <input type="text" name="name" required placeholder="e.g. Acme Corp marketing site">
        </div>
        <div class="field">
            <label>Site URL</label>
            <input type="url" name="url" required placeholder="https://example.com">
        </div>
        <button class="btn primary">Generate credentials</button>
        <a href="/sites.php" class="btn">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php if (!$sites): ?>
    <div class="empty">
        No sites registered yet.<br>
        <?php if ($user['role']==='admin'): ?>
            <a href="/sites.php?new=1">Add your first site</a>
        <?php endif; ?>
    </div>
<?php else: ?>
<style>
.copy-btn{cursor:pointer;font-size:10px;padding:2px 6px;border:1px solid var(--border);border-radius:4px;background:var(--bg-2);color:var(--text-dim);margin-left:4px;}
.copy-btn:hover{background:var(--bg-3);}
.resync-panel{display:none;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;padding:14px 16px;margin:8px 0 4px;}
</style>
<script>
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function(){
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function(){ btn.textContent = orig; }, 1500);
    });
}
function togglePanel(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'block' ? 'none' : 'block';
}
</script>

<table class="t">
    <thead>
        <tr>
            <th>Name</th><th>URL</th><th>Forms</th><th>Submissions</th>
            <th>Status</th><th>Email</th><th>Last seen</th><th>Plugin</th><?php if ($user['role']==='admin'): ?><th></th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sites as $s):
        $web3url   = $baseUrl . '/web3forms.php?key=' . $s['api_key'];
        $ingestUrl = $baseUrl . '/ingest.php';
        $hasPendingResync = !empty($s['resync_requested_at']);
    ?>
    <tr <?= $hasPendingResync ? 'style="background:rgba(99,102,241,0.05);"' : '' ?>>
        <td>
            <strong><?= e($s['name']) ?></strong>
            <?php if ($hasPendingResync): ?>
                <span class="pill warn" title="Resync requested at <?= e($s['resync_requested_at']) ?>" style="font-size:10px;margin-left:4px;">resync pending</span>
            <?php endif; ?>
            <div style="margin-top:4px;">
                <span class="mono" style="font-size:10px;color:var(--text-faint);">
                    <?= e(substr($s['api_key'], 0, 12)) ?>…
                    <button class="copy-btn" onclick="copyText('<?= e($s['api_key']) ?>', this)">Copy key</button>
                </span>
            </div>
        </td>
        <td>
            <a href="<?= e($s['url']) ?>" target="_blank" style="color:var(--text-dim);font-size:12px;"><?= e($s['url']) ?></a>
            <div style="margin-top:4px;">
                <span style="font-size:10px;color:var(--text-faint);">Web3Forms:
                    <span class="mono" style="font-size:10px;"><?= e(substr($web3url, 0, 40)) ?>…</span>
                    <button class="copy-btn" onclick="copyText('<?= e($web3url) ?>', this)">Copy</button>
                </span>
            </div>
        </td>
        <td><?= (int)$s['form_count'] ?></td>
        <td><?= number_format((int)$s['sub_count']) ?></td>
        <td><span class="pill <?= $s['status']==='active'?'ok':'warn' ?>"><?= e($s['status']) ?></span></td>
        <td>
            <?php
            $es = $s['email_status'] ?? 'unknown';
            $eClass = $es === 'ok' ? 'ok' : ($es === 'fail' ? 'err' : '');
            $eLabel = $es === 'ok' ? '✓ ok' : ($es === 'fail' ? '✕ fail' : '—');
            $eTitle = '';
            if ($es === 'fail' && !empty($s['email_error'])) $eTitle = e($s['email_error']);
            if (!empty($s['email_checked_at'])) $eTitle .= ($eTitle ? ' · ' : '') . 'Checked: ' . date('M j H:i', strtotime($s['email_checked_at']));
            ?>
            <span class="pill <?= $eClass ?>" <?= $eTitle ? 'title="' . $eTitle . '"' : '' ?>><?= $eLabel ?></span>
        </td>
        <td style="color:var(--text-dim); font-size:12px;">
            <?= $s['last_seen_at'] ? date('M j H:i', strtotime($s['last_seen_at'])) : '—' ?>
        </td>
        <td>
            <?php if (!empty($s['plugin_version'])): ?>
                <span class="pill ok" title="Seen: <?= e($s['plugin_version_seen_at'] ?? '') ?>">
                    v<?= e($s['plugin_version']) ?>
                </span>
            <?php else: ?>
                <span style="color:var(--text-faint)">—</span>
            <?php endif; ?>
        </td>
        <?php if ($user['role']==='admin'): ?>
        <td style="text-align:right;min-width:220px;">
            <form method="post" style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button name="action" value="toggle" class="btn" title="Pause/resume"><?= $s['status']==='active'?'Pause':'Resume' ?></button>
                <button name="action" value="rotate" class="btn" title="Rotate secret">Rotate</button>
                <button type="button" class="btn" onclick="togglePanel('resync-<?= (int)$s['id'] ?>')">Resync</button>
                <button name="action" value="delete" class="btn danger"
                    onclick="return confirm('Delete site and all its submissions?')">Delete</button>
            </form>
            <?php if ($hasPendingResync): ?>
            <form method="post" style="display:inline;margin-top:4px;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button name="action" value="cancel_resync" class="btn" style="font-size:11px;">Cancel resync</button>
            </form>
            <?php endif; ?>

            <!-- Resync panel -->
            <div id="resync-<?= (int)$s['id'] ?>" class="resync-panel">
                <p style="margin:0 0 10px;font-size:13px;font-weight:500;">Request Resync for <?= e($s['name']) ?></p>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="request_resync">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <div style="margin-bottom:8px;">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:6px;">
                            <input type="radio" name="resync_mode" value="append" checked style="margin-top:2px;">
                            <span>
                                <strong>Append</strong> — add missing entries only.<br>
                                <span style="font-size:12px;color:var(--text-dim);">Safe. Deduplication is automatic. No data lost.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                            <input type="radio" name="resync_mode" value="clear" style="margin-top:2px;">
                            <span>
                                <strong>Clear &amp; Resync</strong> — delete all submissions first, then re-ingest.<br>
                                <span style="font-size:12px;color:var(--text-dim);">Use this to fix corrupted data. All <?= number_format((int)$s['sub_count']) ?> submissions will be deleted.</span>
                            </span>
                        </label>
                    </div>
                    <label id="confirm-label-<?= (int)$s['id'] ?>" style="display:none;margin-bottom:8px;font-size:12px;color:var(--err);">
                        <input type="checkbox" name="confirm_clear" value="1">
                        I understand all submissions for this site will be permanently deleted
                    </label>
                    <button class="btn primary" style="font-size:12px;">Request Resync</button>
                    <button type="button" class="btn" style="font-size:12px;" onclick="togglePanel('resync-<?= (int)$s['id'] ?>')">Cancel</button>
                </form>
            </div>
            <script>
            (function(){
                var panel = document.getElementById('resync-<?= (int)$s['id'] ?>');
                if (!panel) return;
                panel.querySelectorAll('input[name="resync_mode"]').forEach(function(r){
                    r.addEventListener('change', function(){
                        var lbl = document.getElementById('confirm-label-<?= (int)$s['id'] ?>');
                        lbl.style.display = r.value === 'clear' ? 'block' : 'none';
                    });
                });
            })();
            </script>

        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
