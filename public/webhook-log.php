<?php
// public/webhook-log.php — Webhook request log viewer
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$pdo  = db();

$siteFilter   = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$statusFilter = in_array($_GET['status'] ?? '', ['ok', 'rejected', 'error']) ? $_GET['status'] : '';
$perPage      = 100;
$pageNum      = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($pageNum - 1) * $perPage;

$where  = [];
$params = [];
if ($siteFilter) {
    $where[]  = 'w.site_id = ?';
    $params[] = $siteFilter;
}
if ($statusFilter !== '') {
    $where[]  = 'w.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM webhook_log w $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$rows = $pdo->prepare(
    "SELECT w.*, s.name AS site_name
     FROM webhook_log w
     LEFT JOIN sites s ON s.id = w.site_id
     $whereSql
     ORDER BY w.received_at DESC
     LIMIT $perPage OFFSET $offset"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$sites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();

// Summary counts for the filter bar
$counts = $pdo->query(
    "SELECT status, COUNT(*) AS n FROM webhook_log
     WHERE received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$page   = 'Webhook Log';
$active = 'webhook-log';
require __DIR__ . '/../src/layout.php';
?>

<div class="page-head">
    <div>
        <h1>Webhook Log</h1>
        <div class="sub">All ingest requests from WordPress sites — last <?= number_format($totalRows) ?> events</div>
    </div>
</div>

<!-- 24h summary pills -->
<div style="display:flex;gap:10px;margin-bottom:20px;align-items:center;">
    <span style="font-size:12px;color:var(--text-dim)">Last 24 h:</span>
    <span class="pill ok"><?= (int)($counts['ok'] ?? 0) ?> ok</span>
    <span class="pill warn"><?= (int)($counts['rejected'] ?? 0) ?> rejected</span>
    <span class="pill err"><?= (int)($counts['error'] ?? 0) ?> error</span>
</div>

<!-- Quick filter shortcuts -->
<div style="display:flex;gap:8px;margin-bottom:12px;align-items:center;">
    <span style="font-size:12px;color:var(--text-dim);">Quick filter:</span>
    <a href="?<?= http_build_query(array_merge($_GET, ['status'=>'error','page'=>1])) ?>"
       class="btn <?= $statusFilter==='error' ? 'primary' : '' ?>" style="font-size:12px;padding:3px 10px;">
        Errors
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status'=>'rejected','page'=>1])) ?>"
       class="btn <?= $statusFilter==='rejected' ? 'primary' : '' ?>" style="font-size:12px;padding:3px 10px;">
        Rejected
    </a>
    <?php if ($statusFilter): ?>
        <a href="?<?= http_build_query(array_diff_key($_GET, ['status'=>'','page'=>''])) ?>" class="btn" style="font-size:12px;padding:3px 10px;">
            Show all
        </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<form method="get" class="toolbar" style="margin-bottom:16px;">
    <select name="site_id" style="width:200px;" onchange="this.form.submit()">
        <option value="">All sites</option>
        <?php foreach ($sites as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $siteFilter === (int)$s['id'] ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="status" style="width:150px;" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="ok"       <?= $statusFilter==='ok'       ? 'selected':'' ?>>ok</option>
        <option value="rejected" <?= $statusFilter==='rejected' ? 'selected':'' ?>>rejected</option>
        <option value="error"    <?= $statusFilter==='error'    ? 'selected':'' ?>>error</option>
    </select>
    <?php if ($siteFilter || $statusFilter): ?>
        <a href="/webhook-log.php" class="btn">Clear filters</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <div class="empty">No webhook events found for the selected filters.</div>
<?php else: ?>
<table class="t">
    <thead>
        <tr>
            <th>Time</th>
            <th>Site</th>
            <th>Status</th>
            <th>Message</th>
            <th>IP</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row):
        $statusClass = match($row['status']) {
            'ok'       => 'ok',
            'rejected' => 'warn',
            'error'    => 'err',
            default    => '',
        };
        $rowBg = match($row['status']) {
            'rejected' => 'background:rgba(234,179,8,0.06);',
            'error'    => 'background:rgba(239,68,68,0.07);',
            default    => '',
        };
    ?>
        <tr style="<?= $rowBg ?>">
            <td class="mono" style="white-space:nowrap;font-size:12px;color:var(--text-dim);">
                <?= e($row['received_at']) ?>
            </td>
            <td><?= e($row['site_name'] ?? '—') ?></td>
            <td><span class="pill <?= $statusClass ?>"><?= e($row['status']) ?></span></td>
            <td style="max-width:500px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;<?= $row['status']!=='ok'?'font-weight:500;':''; ?>">
                <?= e($row['message'] ?? '') ?>
            </td>
            <td class="mono" style="font-size:12px;color:var(--text-dim);">
                <?= e($row['ip'] ?? '') ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pager">
    <?php for ($p = 1; $p <= min($totalPages, 20); $p++):
        $qs = http_build_query(array_merge($_GET, ['page' => $p]));
    ?>
        <?php if ($p === $pageNum): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="?<?= $qs ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($totalPages > 20): ?>
        <span style="color:var(--text-faint);font-size:12px;">… <?= $totalPages ?> pages total</span>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Dead letter section -->
<?php
$deadLetters = $pdo->query(
    "SELECT dl.*, s.name AS site_name
     FROM dead_letter_log dl
     LEFT JOIN sites s ON s.id = dl.site_id
     ORDER BY dl.received_at DESC
     LIMIT 50"
)->fetchAll();
if ($deadLetters):
?>
<h2 style="margin-top:36px;font-size:16px;font-weight:600;color:var(--err);">
    Dead Letters
    <span style="font-size:12px;font-weight:400;color:var(--text-dim);margin-left:8px;">submissions permanently failed after 5 retry attempts</span>
</h2>
<table class="t" style="margin-top:12px;">
    <thead>
        <tr>
            <th>Received</th>
            <th>Site</th>
            <th>Attempts</th>
            <th>First Queued</th>
            <th>Payload Preview</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($deadLetters as $dl): ?>
        <tr>
            <td class="mono" style="font-size:12px;color:var(--text-dim);white-space:nowrap;"><?= e($dl['received_at']) ?></td>
            <td><?= e($dl['site_name'] ?? '—') ?></td>
            <td style="color:var(--err);"><?= (int)$dl['attempts'] ?></td>
            <td class="mono" style="font-size:12px;color:var(--text-dim);white-space:nowrap;"><?= e($dl['first_queued_at']) ?></td>
            <td style="font-size:12px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-dim);">
                <?= e($dl['payload_json']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
