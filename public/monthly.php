<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$pdo = db();

// Build the list of last 12 calendar months (oldest first)
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i months"));
}
$earliest = $months[0] . '-01';

// Submission counts grouped by site + month
$rows = $pdo->prepare(
    "SELECT s.id AS site_id, s.name AS site_name,
            DATE_FORMAT(sub.submitted_at, '%Y-%m') AS ym,
            COUNT(*) AS cnt
     FROM submissions sub
     JOIN sites s ON s.id = sub.site_id
     WHERE sub.submitted_at >= ?
     GROUP BY s.id, s.name, ym
     ORDER BY s.name, ym"
);
$rows->execute([$earliest]);

// Pivot: $data[$site_id] = ['name' => ..., 'months' => ['2025-01' => 42, ...]]
$data      = [];
$col_totals = array_fill_keys($months, 0);

foreach ($rows->fetchAll() as $r) {
    $sid = $r['site_id'];
    if (!isset($data[$sid])) {
        $data[$sid] = ['name' => $r['site_name'], 'months' => array_fill_keys($months, 0)];
    }
    if (isset($data[$sid]['months'][$r['ym']])) {
        $data[$sid]['months'][$r['ym']] = (int)$r['cnt'];
        $col_totals[$r['ym']] += (int)$r['cnt'];
    }
}

// Sort sites by total submissions descending
uasort($data, function ($a, $b) {
    return array_sum($b['months']) - array_sum($a['months']);
});

$grand_total = array_sum($col_totals);

// Helper: last day of a YYYY-MM string
function month_last_day(string $ym): string {
    return date('Y-m-t', strtotime($ym . '-01'));
}

$page   = 'Monthly breakdown';
$active = 'monthly';
require __DIR__ . '/../src/layout.php';
?>

<div class="page-head">
    <div>
        <h1>Monthly breakdown</h1>
        <div class="sub">Submissions per site · last 12 months</div>
    </div>
    <a href="/submissions.php" class="btn">All submissions</a>
</div>

<?php if (!$data): ?>
    <div class="empty">No submissions yet. <a href="/sites.php?new=1">Add your first site</a>.</div>
<?php else: ?>

<div style="overflow-x: auto;">
<table class="t" style="min-width: 900px;">
    <thead>
        <tr>
            <th style="min-width:160px;">Site</th>
            <?php foreach ($months as $ym): ?>
                <?php $label = date('M Y', strtotime($ym . '-01')); ?>
                <th style="text-align:right; white-space:nowrap;">
                    <a href="/submissions.php?from=<?= $ym ?>-01&to=<?= month_last_day($ym) ?>"
                       style="color:inherit;">
                        <?= $label ?>
                    </a>
                </th>
            <?php endforeach; ?>
            <th style="text-align:right; color:var(--text-dim);">Total</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($data as $site_id => $site): ?>
        <?php $row_total = array_sum($site['months']); ?>
        <tr>
            <td>
                <a href="/submissions.php?site_id=<?= (int)$site_id ?>"><?= e($site['name']) ?></a>
            </td>
            <?php foreach ($months as $ym): ?>
                <?php $cnt = $site['months'][$ym]; ?>
                <td style="text-align:right; font-variant-numeric: tabular-nums;">
                    <?php if ($cnt > 0): ?>
                        <a href="/submissions.php?site_id=<?= (int)$site_id ?>&from=<?= $ym ?>-01&to=<?= month_last_day($ym) ?>"
                           style="color:var(--text);">
                            <?= number_format($cnt) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-faint);">—</span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td style="text-align:right; font-weight:600; font-variant-numeric: tabular-nums;">
                <?= number_format($row_total) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="border-top: 2px solid var(--line-2);">
            <td style="color:var(--text-dim); font-weight:600;">All sites</td>
            <?php foreach ($months as $ym): ?>
                <?php $t = $col_totals[$ym]; ?>
                <td style="text-align:right; font-weight:600; color:var(--text-dim); font-variant-numeric: tabular-nums;">
                    <?php if ($t > 0): ?>
                        <a href="/submissions.php?from=<?= $ym ?>-01&to=<?= month_last_day($ym) ?>"
                           style="color:var(--text-dim);">
                            <?= number_format($t) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-faint);">—</span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td style="text-align:right; font-weight:700; font-variant-numeric: tabular-nums;">
                <?= number_format($grand_total) ?>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
