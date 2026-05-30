<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();
$pdo = db();

// Single submission detail mode
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare(
        "SELECT sub.*, f.title AS form_title, f.plugin, s.name AS site_name, s.url AS site_url
         FROM submissions sub
         JOIN forms f ON f.id = sub.form_id
         JOIN sites s ON s.id = sub.site_id
         WHERE sub.id = ?"
    );
    $stmt->execute([(int)$_GET['id']]);
    $sub = $stmt->fetch();
    if (!$sub) { http_response_code(404); exit('Not found'); }
    $fields = json_decode($sub['payload_json'], true) ?: [];

    $page = 'Submission #' . $sub['id'];
    $active = 'submissions';
    require __DIR__ . '/../src/layout.php';
    ?>
    <div class="page-head">
        <div>
            <h1>Submission #<?= (int)$sub['id'] ?></h1>
            <div class="sub"><?= e($sub['form_title']) ?> · <?= e($sub['site_name']) ?></div>
        </div>
        <a href="/submissions.php" class="btn">← Back</a>
    </div>

    <div class="card" style="margin-bottom: 18px;">
        <div class="kvgrid">
            <div class="k">Submitted</div><div class="v"><?= e(date('M j, Y · H:i:s', strtotime($sub['submitted_at']))) ?></div>
            <div class="k">Received</div><div class="v"><?= e(date('M j, Y · H:i:s', strtotime($sub['received_at']))) ?></div>
            <div class="k">Form</div><div class="v"><?= e($sub['form_title']) ?> <span class="pill plugin-<?= e($sub['plugin']) ?>"><?= e($sub['plugin']) ?></span></div>
            <div class="k">Site</div><div class="v"><a href="<?= e($sub['site_url']) ?>" target="_blank"><?= e($sub['site_name']) ?></a></div>
            <?php if ($sub['remote_entry_id']): ?>
                <div class="k">Remote entry ID</div><div class="v mono"><?= e($sub['remote_entry_id']) ?></div>
            <?php endif; ?>
            <?php if ($sub['ip_address']): ?>
                <div class="k">IP</div><div class="v mono"><?= e($sub['ip_address']) ?></div>
            <?php endif; ?>
            <?php if ($sub['user_agent']): ?>
                <div class="k">User agent</div><div class="v" style="font-size:11px;"><?= e($sub['user_agent']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3 style="margin:0 0 14px; font-size:13px; color:var(--text-dim); text-transform:uppercase; letter-spacing:0.06em;">Fields</h3>
        <div class="kvgrid">
            <?php foreach ($fields as $k => $v): ?>
                <div class="k"><?= e($k) ?></div>
                <div class="v"><?= is_scalar($v) ? nl2br(e((string)$v)) : '<pre style="margin:0;font-size:12px;">' . e(json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</pre>' ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    require __DIR__ . '/../src/layout_foot.php';
    exit;
}

// List + filters
$siteFilter = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$formFilter = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;
$pluginFilter = $_GET['plugin'] ?? '';
$q = trim($_GET['q'] ?? '');
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';

$where = []; $params = [];
if ($siteFilter) { $where[] = 'sub.site_id = ?'; $params[] = $siteFilter; }
if ($formFilter) { $where[] = 'sub.form_id = ?'; $params[] = $formFilter; }
if ($pluginFilter) { $where[] = 'f.plugin = ?'; $params[] = $pluginFilter; }
if ($q !== '') {
    $where[] = '(sub.payload_json LIKE ? OR sub.email LIKE ? OR sub.name LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($dateFrom) { $where[] = 'sub.submitted_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo)   { $where[] = 'sub.submitted_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare(
        "SELECT sub.id, sub.submitted_at, s.name AS site_name, f.title AS form_title, f.plugin,
                sub.email, sub.name, sub.ip_address, sub.payload_json
         FROM submissions sub
         JOIN forms f ON f.id = sub.form_id
         JOIN sites s ON s.id = sub.site_id
         $whereSql
         ORDER BY sub.submitted_at DESC
         LIMIT 100000"
    );
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="submissions-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Submitted','Site','Form','Plugin','Email','Name','IP','Fields (JSON)']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'], $row['submitted_at'], $row['site_name'], $row['form_title'],
            $row['plugin'], $row['email'], $row['name'], $row['ip_address'], $row['payload_json'],
        ]);
    }
    fclose($out);
    exit;
}

// Pagination
$perPage = 50;
$page_n = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page_n - 1) * $perPage;

$count = $pdo->prepare("SELECT COUNT(*) FROM submissions sub JOIN forms f ON f.id = sub.form_id $whereSql");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT sub.*, f.title AS form_title, f.plugin, s.name AS site_name
     FROM submissions sub
     JOIN forms f ON f.id = sub.form_id
     JOIN sites s ON s.id = sub.site_id
     $whereSql
     ORDER BY sub.submitted_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();

$page = 'Submissions';
$active = 'submissions';
require __DIR__ . '/../src/layout.php';
?>
<div class="page-head">
    <div>
        <h1>Submissions</h1>
        <div class="sub"><?= number_format($total) ?> result<?= $total===1?'':'s' ?></div>
    </div>
    <a href="?<?= e(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>" class="btn primary">Export CSV</a>
</div>

<form method="get" class="toolbar" style="flex-wrap:wrap;">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name, email, any field…" style="flex: 1; min-width: 200px;">
    <select name="site_id" style="width:auto;">
        <option value="0">All sites</option>
        <?php foreach ($sites as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $siteFilter===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="plugin" style="width: 140px;">
        <option value="">All plugins</option>
        <?php foreach (['forminator','cf7','gravity','wpforms','fluent','elementor'] as $p): ?>
        <option value="<?= $p ?>" <?= $pluginFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($dateFrom) ?>" style="width: 150px;">
    <input type="date" name="to"   value="<?= e($dateTo) ?>" style="width: 150px;">
    <button class="btn primary">Filter</button>
    <a href="/submissions.php" class="btn">Reset</a>
</form>

<?php if (!$rows): ?>
    <div class="empty">No submissions match these filters.</div>
<?php else: ?>
<table class="t">
    <thead>
        <tr><th>When</th><th>Site</th><th>Form</th><th>Plugin</th><th>From</th><th>Preview</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td class="mono" style="white-space:nowrap; font-size:12px;"><?= date('M j H:i', strtotime($r['submitted_at'])) ?></td>
        <td><?= e($r['site_name']) ?></td>
        <td><?= e($r['form_title']) ?></td>
        <td><span class="pill plugin-<?= e($r['plugin']) ?>"><?= e($r['plugin']) ?></span></td>
        <td style="font-size:12px;">
            <?= e($r['email'] ?: $r['name'] ?: '—') ?>
        </td>
        <td style="color:var(--text-dim); font-size:12px; max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            <?= e($r['summary'] ?? '') ?>
        </td>
        <td><a href="/submissions.php?id=<?= (int)$r['id'] ?>" class="btn">Open</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($pages > 1):
    $qs = $_GET; unset($qs['page']);
    $base = '?' . http_build_query($qs);
?>
<div class="pager">
    <?php for ($p = 1; $p <= $pages; $p++):
        if ($p > 5 && $p < $pages - 1 && abs($p - $page_n) > 2) {
            if ($p === 6) echo '<span>…</span>';
            continue;
        }
    ?>
        <?php if ($p === $page_n): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="<?= e($base . '&page=' . $p) ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
