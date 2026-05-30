<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$pdo = db();

$totals = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM sites) AS site_count,
        (SELECT COUNT(*) FROM sites WHERE status='active') AS active_sites,
        (SELECT COUNT(*) FROM forms) AS form_count,
        (SELECT COUNT(*) FROM submissions) AS sub_total,
        (SELECT COUNT(*) FROM submissions WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS sub_24h,
        (SELECT COUNT(*) FROM submissions WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS sub_7d
    "
)->fetch();

// Last 30 days for chart
$daily = $pdo->query(
    "SELECT DATE(submitted_at) AS d, COUNT(*) AS c
     FROM submissions
     WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(submitted_at)
     ORDER BY d"
)->fetchAll();

$bySite = $pdo->query(
    "SELECT s.id, s.name, s.url, s.status, s.last_seen_at,
            COUNT(DISTINCT f.id) AS forms,
            COALESCE(SUM(f.submission_count), 0) AS subs
     FROM sites s
     LEFT JOIN forms f ON f.site_id = s.id
     GROUP BY s.id
     ORDER BY subs DESC, s.name
     LIMIT 10"
)->fetchAll();

$recent = $pdo->query(
    "SELECT sub.id, sub.submitted_at, sub.summary, sub.email, sub.name AS sub_name,
            f.title AS form_title, f.plugin, s.name AS site_name
     FROM submissions sub
     JOIN forms f ON f.id = sub.form_id
     JOIN sites s ON s.id = sub.site_id
     ORDER BY sub.submitted_at DESC
     LIMIT 10"
)->fetchAll();

// Build chart data (fill missing days with 0)
$chartMap = [];
foreach ($daily as $row) $chartMap[$row['d']] = (int)$row['c'];
$chartData = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartData[] = ['date' => $d, 'count' => $chartMap[$d] ?? 0];
}
$maxCount = max(1, max(array_column($chartData, 'count')));

try {
    $failingEmail = $pdo->query("SELECT name FROM sites WHERE email_status = 'fail'")->fetchAll();
} catch (\Throwable $e) {
    $failingEmail = [];
}

$page = 'Overview';
$active = 'overview';
require __DIR__ . '/../src/layout.php';
?>

<?php if ($failingEmail): ?>
<div class="card" style="background:rgba(248,113,113,0.08); border-color:rgba(248,113,113,0.3); margin-bottom: 20px;">
    <div style="display:flex; align-items:center; gap:10px;">
        <span style="font-size:20px;">⚠</span>
        <div>
            <div style="font-weight:600; color:var(--err); margin-bottom:4px;">Email delivery failing</div>
            <div style="font-size:13px; color:var(--text-dim);">
                <?= implode(', ', array_map(fn($s) => '<strong>' . e($s['name']) . '</strong>', $failingEmail)) ?>
                — <a href="/email-health.php">View details →</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1>Overview</h1>
        <div class="sub">All sites, all forms, at a glance</div>
    </div>
    <a href="/sites.php?new=1" class="btn primary">+ Add site</a>
</div>

<div class="cards">
    <div class="card">
        <div class="label">Sites</div>
        <div class="value"><?= (int)$totals['active_sites'] ?> <span style="color:var(--text-faint);font-size:14px;">/ <?= (int)$totals['site_count'] ?></span></div>
        <div class="delta">active / total</div>
    </div>
    <div class="card">
        <div class="label">Forms</div>
        <div class="value"><?= (int)$totals['form_count'] ?></div>
        <div class="delta">across all sites</div>
    </div>
    <div class="card">
        <div class="label">Submissions (24h)</div>
        <div class="value"><?= (int)$totals['sub_24h'] ?></div>
        <div class="delta"><?= (int)$totals['sub_7d'] ?> in last 7 days</div>
    </div>
    <div class="card">
        <div class="label">Total submissions</div>
        <div class="value"><?= number_format((int)$totals['sub_total']) ?></div>
        <div class="delta">all time</div>
    </div>
</div>

<div class="chart">
    <h3>Submissions · last 30 days</h3>
    <svg viewBox="0 0 900 200" style="width:100%; height:200px;" preserveAspectRatio="none">
        <?php
        $w = 900; $h = 200; $pad = 24;
        $cw = $w - $pad*2; $ch = $h - $pad*2;
        $bw = $cw / 30 * 0.7;
        foreach ($chartData as $i => $pt):
            $x = $pad + ($cw / 30) * $i + ($cw / 30 - $bw) / 2;
            $bh = ($pt['count'] / $maxCount) * $ch;
            $y = $h - $pad - $bh;
        ?>
        <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $bw ?>" height="<?= max($bh, 1) ?>"
              fill="#a3e635" opacity="<?= $pt['count'] > 0 ? 1 : 0.15 ?>" rx="2">
            <title><?= $pt['date'] ?>: <?= $pt['count'] ?> submissions</title>
        </rect>
        <?php endforeach; ?>
        <line x1="<?= $pad ?>" y1="<?= $h - $pad ?>" x2="<?= $w - $pad ?>" y2="<?= $h - $pad ?>" stroke="#262b3a"/>
    </svg>
    <div style="display:flex; justify-content:space-between; color:var(--text-faint); font-size:11px; margin-top:6px;">
        <span><?= $chartData[0]['date'] ?></span>
        <span><?= end($chartData)['date'] ?></span>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 18px;">
    <div>
        <h3 style="font-size:13px;text-transform:uppercase;color:var(--text-dim);letter-spacing:0.06em;margin:0 0 10px;">Top sites by submissions</h3>
        <?php if (!$bySite): ?>
            <div class="empty">No sites yet. <a href="/sites.php?new=1">Add your first site</a>.</div>
        <?php else: ?>
        <table class="t">
            <thead><tr><th>Site</th><th>Forms</th><th>Submissions</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($bySite as $s): ?>
            <tr>
                <td><a href="/submissions.php?site_id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a></td>
                <td><?= (int)$s['forms'] ?></td>
                <td><?= number_format((int)$s['subs']) ?></td>
                <td><span class="pill <?= $s['status']==='active'?'ok':'warn' ?>"><?= e($s['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div>
        <h3 style="font-size:13px;text-transform:uppercase;color:var(--text-dim);letter-spacing:0.06em;margin:0 0 10px;">Recent submissions</h3>
        <?php if (!$recent): ?>
            <div class="empty">No submissions yet.</div>
        <?php else: ?>
        <table class="t">
            <thead><tr><th>When</th><th>Site</th><th>Form</th><th>From</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
                <td class="mono" style="white-space:nowrap;font-size:12px;"><?= date('M j H:i', strtotime($r['submitted_at'])) ?></td>
                <td><?= e($r['site_name']) ?></td>
                <td><a href="/submissions.php?id=<?= (int)$r['id'] ?>"><?= e($r['form_title']) ?></a></td>
                <td style="color:var(--text-dim);font-size:12px;"><?= e($r['email'] ?: $r['sub_name'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
