<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$pdo = db();

// Handle clear form submissions (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($user['role'] ?? '') === 'admin') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'clear_form') {
        $fid = (int)($_POST['form_id'] ?? 0);
        if ($fid > 0) {
            $sidStmt = $pdo->prepare("SELECT site_id FROM forms WHERE id = ?");
            $sidStmt->execute([$fid]);
            $sid = (int)$sidStmt->fetchColumn();
            $pdo->prepare('DELETE FROM submissions WHERE form_id = ?')->execute([$fid]);
            $pdo->prepare('UPDATE forms SET submission_count = 0, last_submission_at = NULL WHERE id = ?')->execute([$fid]);
            flash('All submissions for this form have been cleared.', 'success');
        }
        redirect('/forms.php');
    }
}

$siteFilter = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$pluginFilter = $_GET['plugin'] ?? '';

$where = []; $params = [];
if ($siteFilter) { $where[] = 'f.site_id = ?'; $params[] = $siteFilter; }
if ($pluginFilter) { $where[] = 'f.plugin = ?'; $params[] = $pluginFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$forms = $pdo->prepare(
    "SELECT f.*, s.name AS site_name, s.url AS site_url
     FROM forms f
     JOIN sites s ON s.id = f.site_id
     $whereSql
     ORDER BY f.last_submission_at DESC, s.name"
);
$forms->execute($params);
$forms = $forms->fetchAll();

$sites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();

$page = 'Forms';
$active = 'forms';
require __DIR__ . '/../src/layout.php';
?>

<div class="page-head">
    <div>
        <h1>Forms</h1>
        <div class="sub"><?= count($forms) ?> form<?= count($forms)===1?'':'s' ?> tracked</div>
    </div>
</div>

<form method="get" class="toolbar">
    <select name="site_id" onchange="this.form.submit()">
        <option value="0">All sites</option>
        <?php foreach ($sites as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $siteFilter===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="plugin" onchange="this.form.submit()" style="width: 200px;">
        <option value="">All plugins</option>
        <?php foreach (['forminator','cf7','gravity','wpforms','fluent','elementor','web3forms'] as $p): ?>
        <option value="<?= $p ?>" <?= $pluginFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="grow"></div>
</form>

<?php if (!$forms): ?>
    <div class="empty">No forms yet. They'll appear here as soon as a submission comes in from any site.</div>
<?php else: ?>
<table class="t">
    <thead>
        <tr>
            <th>Form</th><th>Site</th><th>Plugin</th>
            <th>Submissions</th><th>Last submission</th><th>First seen</th><th></th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($forms as $f): ?>
    <tr>
        <td><strong><?= e($f['title']) ?></strong>
            <div style="color:var(--text-faint); font-size:11px;" class="mono">id <?= e($f['remote_form_id']) ?></div>
        </td>
        <td><?= e($f['site_name']) ?></td>
        <td><span class="pill plugin-<?= e($f['plugin']) ?>"><?= e($f['plugin']) ?></span></td>
        <td><?= number_format((int)$f['submission_count']) ?></td>
        <td style="color:var(--text-dim); font-size:12px;">
            <?= $f['last_submission_at'] ? date('M j, Y H:i', strtotime($f['last_submission_at'])) : '—' ?>
        </td>
        <td style="color:var(--text-dim); font-size:12px;"><?= date('M j, Y', strtotime($f['first_seen_at'])) ?></td>
        <td><a href="/submissions.php?form_id=<?= (int)$f['id'] ?>" class="btn">View</a></td>
        <td>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete all <?= number_format((int)$f['submission_count']) ?> submissions for this form? This cannot be undone.')">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="clear_form">
                <input type="hidden" name="form_id" value="<?= (int)$f['id'] ?>">
                <button class="btn danger" style="font-size:12px;">Clear</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
