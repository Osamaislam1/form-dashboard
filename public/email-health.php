<?php
// public/email-health.php — Email health monitoring dashboard
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$pdo = db();

// Summary counts
$summary = $pdo->query(
    "SELECT
        SUM(email_status = 'ok') AS ok_count,
        SUM(email_status = 'fail') AS fail_count,
        SUM(email_status = 'unknown') AS unknown_count,
        COUNT(*) AS total
     FROM sites"
)->fetch();

// Failing sites
$failing = $pdo->query(
    "SELECT s.id, s.name, s.url, s.email_error, s.email_checked_at,
            (SELECT mailer FROM email_health_log WHERE site_id = s.id ORDER BY checked_at DESC LIMIT 1) AS mailer
     FROM sites s
     WHERE s.email_status = 'fail'
     ORDER BY s.email_checked_at DESC"
)->fetchAll();

// Site filter for log
$siteFilter = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$statusFilter = $_GET['status'] ?? '';

// Pagination
$perPage = 30;
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$offset = ($pageNum - 1) * $perPage;

// Build log query
$where = [];
$params = [];
if ($siteFilter) { $where[] = 'e.site_id = ?'; $params[] = $siteFilter; }
if ($statusFilter && in_array($statusFilter, ['ok', 'fail'])) { $where[] = 'e.status = ?'; $params[] = $statusFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_health_log e $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$logs = $pdo->prepare(
    "SELECT e.*, s.name AS site_name
     FROM email_health_log e
     JOIN sites s ON s.id = e.site_id
     $whereSql
     ORDER BY e.checked_at DESC
     LIMIT $perPage OFFSET $offset"
);
$logs->execute($params);
$logs = $logs->fetchAll();

$sites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();

$page = 'Email health';
$active = 'email-health';
require __DIR__ . '/../src/layout.php';
?>

<div class="page-head">
    <div>
        <h1>Email Health</h1>
        <div class="sub">Monitor email delivery across all connected sites</div>
    </div>
    <a href="/alerts.php" class="btn primary">+ Configure alerts</a>
</div>

<!-- Summary cards -->
<div class="cards" style="grid-template-columns: repeat(3, 1fr);">
    <div class="card">
        <div class="label">Healthy</div>
        <div class="value" style="color:var(--ok);"><?= (int)$summary['ok_count'] ?></div>
        <div class="delta">sites with working email</div>
    </div>
    <div class="card" <?= (int)$summary['fail_count'] > 0 ? 'style="border-color:rgba(248,113,113,0.4);"' : '' ?>>
        <div class="label">Failing</div>
        <div class="value" style="color:var(--err);"><?= (int)$summary['fail_count'] ?></div>
        <div class="delta">sites with broken email</div>
    </div>
    <div class="card">
        <div class="label">Unknown</div>
        <div class="value" style="color:var(--text-faint);"><?= (int)$summary['unknown_count'] ?></div>
        <div class="delta">not yet checked</div>
    </div>
</div>

<!-- Failing sites -->
<?php if ($failing): ?>
<div style="margin-bottom: 24px;">
    <h3 style="font-size:13px; text-transform:uppercase; color:var(--text-dim); letter-spacing:0.06em; margin:0 0 10px;">
        ⚠ Sites with failing email
    </h3>
    <table class="t">
        <thead>
            <tr><th>Site</th><th>Error</th><th>Mailer</th><th>Last checked</th></tr>
        </thead>
        <tbody>
        <?php foreach ($failing as $f): ?>
        <tr>
            <td>
                <strong><?= e($f['name']) ?></strong>
                <div style="color:var(--text-faint); font-size:11px;"><?= e($f['url']) ?></div>
            </td>
            <td style="color:var(--err); font-size:12px;"><?= e($f['email_error'] ?: 'Unknown error') ?></td>
            <td style="font-size:12px; color:var(--text-dim);"><?= e($f['mailer'] ?: '—') ?></td>
            <td style="font-size:12px; color:var(--text-dim);">
                <?= $f['email_checked_at'] ? date('M j, Y H:i', strtotime($f['email_checked_at'])) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Health check log -->
<h3 style="font-size:13px; text-transform:uppercase; color:var(--text-dim); letter-spacing:0.06em; margin:0 0 10px;">
    Check history
</h3>

<form method="get" class="toolbar">
    <select name="site_id" onchange="this.form.submit()">
        <option value="0">All sites</option>
        <?php foreach ($sites as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $siteFilter===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()" style="width: 160px;">
        <option value="">All statuses</option>
        <option value="ok" <?= $statusFilter==='ok'?'selected':'' ?>>✓ OK</option>
        <option value="fail" <?= $statusFilter==='fail'?'selected':'' ?>>✕ Fail</option>
    </select>
    <div class="grow"></div>
    <span style="font-size:12px; color:var(--text-dim);"><?= number_format($totalRows) ?> records</span>
</form>

<?php if (!$logs): ?>
    <div class="empty">No email health checks recorded yet. Enable monitoring in the WordPress plugin settings.</div>
<?php else: ?>
<table class="t">
    <thead>
        <tr><th>Site</th><th>Status</th><th>Mailer</th><th>Error</th><th>Checked at</th></tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
        <td><?= e($l['site_name']) ?></td>
        <td>
            <span class="pill <?= $l['status']==='ok'?'ok':'err' ?>"><?= $l['status']==='ok'?'✓ ok':'✕ fail' ?></span>
        </td>
        <td style="font-size:12px; color:var(--text-dim);"><?= e($l['mailer'] ?: '—') ?></td>
        <td style="font-size:12px; color:var(--err);"><?= $l['error_msg'] ? e($l['error_msg']) : '—' ?></td>
        <td style="font-size:12px; color:var(--text-dim);"><?= date('M j, Y H:i:s', strtotime($l['checked_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($totalPages > 1): ?>
<div class="pager">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php
        $qs = http_build_query(array_filter([
            'site_id' => $siteFilter ?: null,
            'status' => $statusFilter ?: null,
            'page' => $p,
        ]));
        ?>
        <?php if ($p === $pageNum): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="?<?= $qs ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
