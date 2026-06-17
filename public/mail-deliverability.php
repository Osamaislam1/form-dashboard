<?php
// public/mail-deliverability.php — Zepto Mail deliverability dashboard
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/zepto_api.php';
$user = require_admin();
$pdo  = db();

// ── Resolve scope (global vs per-site) ───────────────────────────────────────
$scopeParam = $_GET['scope'] ?? $_POST['scope'] ?? 'global';
$scopeSiteId = null;
if ($scopeParam !== 'global' && ctype_digit((string)$scopeParam)) {
    $scopeSiteId = (int)$scopeParam;
}

// Sites that have a per-site token configured
$tokenSites = $pdo->query(
    "SELECT id, name FROM sites WHERE zepto_api_token IS NOT NULL AND zepto_api_token != '' ORDER BY name"
)->fetchAll();

$globalToken  = cfg('zepto.api_token', '');
$hasGlobal    = $globalToken !== '';
$hasAnyToken  = $hasGlobal || count($tokenSites) > 0;

// Default scope: global if token set, else first site with token
if (!$hasGlobal && $scopeParam === 'global' && count($tokenSites) > 0) {
    $scopeSiteId  = (int)$tokenSites[0]['id'];
    $scopeParam   = (string)$scopeSiteId;
}

// ── Date range (default: last 7 days) ────────────────────────────────────────
$tzOffset = date('P'); // server timezone offset
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days'));

$fromIso = $dateFrom . 'T00:00:00' . $tzOffset;
$toIso   = $dateTo   . 'T23:59:59' . $tzOffset;

// ── Handle POST: Refresh from API ────────────────────────────────────────────
$refreshResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refresh') {
    csrf_check();
    $refreshResult = zepto_fetch_and_cache($scopeSiteId, $fromIso, $toIso);
    // Redirect to GET to prevent double-submit
    $qs = http_build_query(array_filter([
        'scope'      => $scopeParam,
        'date_from'  => $dateFrom,
        'date_to'    => $dateTo,
        'refreshed'  => $refreshResult['error'] ? null : $refreshResult['inserted'],
        'err'        => $refreshResult['error'] ? urlencode($refreshResult['error']) : null,
    ]));
    header('Location: /mail-deliverability.php?' . $qs);
    exit;
}

// Flash messages from redirect
$refreshedCount = isset($_GET['refreshed']) ? (int)$_GET['refreshed'] : null;
$apiError       = isset($_GET['err']) ? urldecode($_GET['err']) : null;

// ── Status filter ─────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';

// ── Summary stats ─────────────────────────────────────────────────────────────
$summary = zepto_summary($scopeSiteId);
$total     = (int)($summary['total']     ?? 0);
$delivered = (int)($summary['delivered'] ?? 0);
$bounced   = (int)($summary['bounced']   ?? 0);
$failed    = (int)($summary['failed']    ?? 0);
$opened    = (int)($summary['opened']    ?? 0);
$lastFetch = $summary['last_fetched'] ?? null;

$deliveredPct = $total > 0 ? round($delivered / $total * 100, 1) : 0;
$bouncePct    = $total > 0 ? round(($bounced + $failed) / $total * 100, 1) : 0;
$openPct      = $delivered > 0 ? round($opened / $delivered * 100, 1) : 0;

// ── Log table query ───────────────────────────────────────────────────────────
$perPage = 50;
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($pageNum - 1) * $perPage;

$where  = [];
$params = [];

if ($scopeSiteId !== null) {
    $where[]  = 'site_id = ?';
    $params[] = $scopeSiteId;
} else {
    $where[] = 'site_id IS NULL';
}

$validStatuses = ['queued','sent','delivered','bounced','opened','clicked','failed'];
if ($statusFilter && in_array($statusFilter, $validStatuses, true)) {
    $where[]  = 'status = ?';
    $params[] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM zepto_mail_log $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$logsStmt = $pdo->prepare(
    "SELECT * FROM zepto_mail_log $whereSql ORDER BY sent_at DESC, id DESC LIMIT $perPage OFFSET $offset"
);
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

$page   = 'Mail Deliverability';
$active = 'mail-deliverability';
require __DIR__ . '/../src/layout.php';

// Status → badge class map
function zepto_badge(string $status): string {
    return match($status) {
        'delivered', 'opened', 'clicked' => 'ok',
        'bounced', 'failed'               => 'err',
        default                           => 'warn',
    };
}
function zepto_icon(string $status): string {
    return match($status) {
        'delivered' => '✓',
        'opened'    => '👁',
        'clicked'   => '↗',
        'bounced'   => '↩',
        'failed'    => '✕',
        'sent'      => '→',
        default     => '·',
    };
}
?>

<div class="page-head">
    <div>
        <h1>Mail Deliverability</h1>
        <div class="sub">Fetched directly from the Zepto Mail API — no plugin required</div>
    </div>
    <?php if ($hasAnyToken): ?>
    <form method="post" style="display:inline;">
        <input type="hidden" name="_csrf"   value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action"  value="refresh">
        <input type="hidden" name="scope"   value="<?= e($scopeParam) ?>">
        <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="hidden" name="date_to"   value="<?= e($dateTo) ?>">
        <button class="btn primary" type="submit">↻ Refresh from API</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($refreshedCount !== null): ?>
<div class="flash success">Pulled <?= $refreshedCount ?> records from Zepto Mail API.</div>
<?php endif; ?>
<?php if ($apiError): ?>
<div class="flash error">API error: <?= e($apiError) ?></div>
<?php endif; ?>

<?php if (!$hasAnyToken): ?>
<div class="card" style="background:rgba(251,191,36,0.06); border-color:rgba(251,191,36,0.3); margin-bottom:20px;">
    <div style="font-weight:500; margin-bottom:8px;">⚙ No Zepto API token configured</div>
    <p style="margin:0; color:var(--text-dim); font-size:13px; line-height:1.6;">
        To get started:
        <ol style="margin:8px 0 0; padding-left:20px;">
            <li>Log in to <strong>zeptomail.com</strong> → Settings → API Tokens → generate a token.</li>
            <li>Add it to <code>config.local.php</code> under <code>zepto.api_token</code> for the global account, <em>or</em></li>
            <li>Go to <a href="/sites.php">Sites</a> and set a per-site Zepto token for any site that has its own Zepto account.</li>
        </ol>
    </p>
</div>
<?php else: ?>

<!-- Scope + date toolbar -->
<form method="get" class="toolbar" style="margin-bottom:20px;">
    <?php if ($hasGlobal || count($tokenSites) > 1): ?>
    <select name="scope" onchange="this.form.submit()" style="width:200px;">
        <?php if ($hasGlobal): ?>
        <option value="global" <?= $scopeParam==='global'?'selected':'' ?>>Global (Dashboard)</option>
        <?php endif; ?>
        <?php foreach ($tokenSites as $ts): ?>
        <option value="<?= (int)$ts['id'] ?>" <?= $scopeParam===(string)$ts['id']?'selected':'' ?>><?= e($ts['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php else: ?>
    <span style="font-size:13px; color:var(--text-dim); padding:8px 0;">
        Scope: <?= $scopeParam === 'global' ? 'Global (Dashboard)' : e($tokenSites[0]['name'] ?? '') ?>
    </span>
    <input type="hidden" name="scope" value="<?= e($scopeParam) ?>">
    <?php endif; ?>

    <label style="display:flex;align-items:center;gap:6px;margin:0;color:var(--text-dim);font-size:13px;">
        From <input type="date" name="date_from" value="<?= e($dateFrom) ?>" style="width:140px;" onchange="this.form.submit()">
    </label>
    <label style="display:flex;align-items:center;gap:6px;margin:0;color:var(--text-dim);font-size:13px;">
        To <input type="date" name="date_to" value="<?= e($dateTo) ?>" style="width:140px;" onchange="this.form.submit()">
    </label>
    <div class="grow"></div>
    <?php if ($lastFetch): ?>
    <span style="font-size:11px; color:var(--text-faint);">Last synced <?= date('M j H:i', strtotime($lastFetch)) ?></span>
    <?php endif; ?>
</form>

<!-- Summary cards -->
<div class="cards">
    <div class="card">
        <div class="label">Total sent</div>
        <div class="value"><?= number_format($total) ?></div>
        <div class="delta">in cache for this scope</div>
    </div>
    <div class="card" <?= $deliveredPct < 90 && $total > 0 ? 'style="border-color:rgba(251,191,36,0.4);"' : '' ?>>
        <div class="label">Delivered</div>
        <div class="value" style="color:var(--ok);"><?= $deliveredPct ?>%</div>
        <div class="delta"><?= number_format($delivered) ?> of <?= number_format($total) ?></div>
    </div>
    <div class="card" <?= $bouncePct > 5 && $total > 0 ? 'style="border-color:rgba(248,113,113,0.4);"' : '' ?>>
        <div class="label">Bounce / Fail</div>
        <div class="value" style="color:<?= $bouncePct > 5 ? 'var(--err)' : 'var(--text)' ?>;"><?= $bouncePct ?>%</div>
        <div class="delta"><?= number_format($bounced + $failed) ?> messages</div>
    </div>
    <div class="card">
        <div class="label">Open rate</div>
        <div class="value"><?= $openPct ?>%</div>
        <div class="delta"><?= number_format($opened) ?> of <?= number_format($delivered) ?> delivered</div>
    </div>
</div>

<!-- Log filter -->
<form method="get" class="toolbar">
    <input type="hidden" name="scope"      value="<?= e($scopeParam) ?>">
    <input type="hidden" name="date_from"  value="<?= e($dateFrom) ?>">
    <input type="hidden" name="date_to"    value="<?= e($dateTo) ?>">
    <select name="status" onchange="this.form.submit()" style="width:180px;">
        <option value="">All statuses</option>
        <?php foreach (['delivered','opened','clicked','sent','queued','bounced','failed'] as $st): ?>
        <option value="<?= $st ?>" <?= $statusFilter===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="grow"></div>
    <span style="font-size:12px; color:var(--text-dim);"><?= number_format($totalRows) ?> records</span>
</form>

<?php if (!$logs): ?>
<div class="empty">
    No records in cache yet.<br>
    <?php if ($total === 0): ?>
    Click <strong>↻ Refresh from API</strong> to pull the latest delivery logs from Zepto Mail.
    <?php else: ?>
    No records match the current filter.
    <?php endif; ?>
</div>
<?php else: ?>
<div class="table-wrap">
<table class="t">
    <thead>
        <tr>
            <th>Recipient</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Sent at</th>
            <th>Delivered at</th>
            <th>Bounce reason</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
        <td style="font-size:12px;"><?= e($l['recipient'] ?: '—') ?></td>
        <td style="font-size:12px; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($l['subject']) ?>">
            <?= e($l['subject'] ?: '—') ?>
        </td>
        <td>
            <span class="pill <?= zepto_badge($l['status']) ?>"><?= zepto_icon($l['status']) ?> <?= e($l['status']) ?></span>
        </td>
        <td style="font-size:12px; color:var(--text-dim); white-space:nowrap;">
            <?= $l['sent_at'] ? date('M j, Y H:i', strtotime($l['sent_at'])) : '—' ?>
        </td>
        <td style="font-size:12px; color:var(--text-dim); white-space:nowrap;">
            <?= $l['delivered_at'] ? date('M j, Y H:i', strtotime($l['delivered_at'])) : '—' ?>
        </td>
        <td style="font-size:12px; color:var(--err); max-width:240px;">
            <?= e($l['bounce_reason'] ?: '') ?: '<span style="color:var(--text-faint)">—</span>' ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
    <?php for ($p = 1; $p <= min($totalPages, 20); $p++): ?>
        <?php
        $qs = http_build_query(array_filter([
            'scope'     => $scopeParam,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'status'    => $statusFilter ?: null,
            'page'      => $p > 1 ? $p : null,
        ]));
        ?>
        <?php if ($p === $pageNum): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="?<?= $qs ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($totalPages > 20): ?>
        <span style="color:var(--text-faint);">… <?= number_format($totalPages) ?> pages</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // end $logs ?>
<?php endif; // end $hasAnyToken ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
