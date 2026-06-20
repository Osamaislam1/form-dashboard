<?php
// public/mail-deliverability.php — Zepto Mail deliverability dashboard (OAuth)
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/zepto_api.php';
$user = require_admin();
$pdo  = db();

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Refresh: pull latest logs from API
    if (($_POST['action'] ?? '') === 'refresh') {
        $dateFrom = $_POST['date_from'] ?? date('Y-m-d', strtotime('-6 days'));
        $dateTo   = $_POST['date_to']   ?? date('Y-m-d');
        $tzOffset = date('P');
        $fromIso  = $dateFrom . 'T00:00:00' . $tzOffset;
        $toIso    = $dateTo   . 'T23:59:59' . $tzOffset;

        $r = zepto_fetch_and_cache(null, $fromIso, $toIso);

        $qs = http_build_query(array_filter([
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'refreshed' => $r['error'] ? null : $r['inserted'],
            'err'       => $r['error'] ? urlencode($r['error']) : null,
        ]));
        header('Location: /mail-deliverability.php?' . $qs);
        exit;
    }

    // Disconnect: remove OAuth token
    if (($_POST['action'] ?? '') === 'disconnect') {
        $pdo->prepare("DELETE FROM oauth_tokens WHERE service = 'zepto_global'")->execute();
        flash('Zepto Mail disconnected.', 'success');
        redirect('/mail-deliverability.php');
    }
}

// ── OAuth / config status ─────────────────────────────────────────────────────
$isConfigured = zepto_is_configured();
$isConnected  = $isConfigured && zepto_is_connected();

$hasAnyScope = $isConnected;

// ── From-address filter (site filtering on global dataset) ────────────────────
// Build map: from_address => site name (from sites.zepto_from_email)
$siteByFrom = [];
foreach ($pdo->query("SELECT name, zepto_from_email FROM sites WHERE zepto_from_email IS NOT NULL AND zepto_from_email != '' ORDER BY name")->fetchAll() as $r) {
    $siteByFrom[$r['zepto_from_email']] = $r['name'];
}

// Distinct from_address values present in the cached log
$logSenders = $pdo->query(
    "SELECT DISTINCT from_address FROM zepto_mail_log WHERE site_id IS NULL AND from_address IS NOT NULL ORDER BY from_address"
)->fetchAll(\PDO::FETCH_COLUMN);

$fromFilter = $_GET['from'] ?? '';   // active from_address filter (empty = all sites)

// ── Date range (default: last 7 days) ─────────────────────────────────────────
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days'));

// ── Flash messages from redirect ──────────────────────────────────────────────
$refreshedCount = isset($_GET['refreshed']) ? (int)$_GET['refreshed'] : null;
$apiError       = isset($_GET['err'])       ? urldecode($_GET['err'])  : null;

// ── Summary stats ────────────────────────────────────────────────────────────
$summary   = $hasAnyScope ? zepto_summary($fromFilter ?: null) : [];
$total     = (int)($summary['total']     ?? 0);
$delivered = (int)($summary['delivered'] ?? 0);
$bounced   = (int)($summary['bounced']   ?? 0);
$failed    = (int)($summary['failed']    ?? 0);
$opened    = (int)($summary['opened']    ?? 0);
$lastFetch = $summary['last_fetched']    ?? null;

$deliveredPct  = $total > 0 ? round($delivered / $total * 100, 1) : 0;
$bouncePct     = $total > 0 ? round(($bounced + $failed) / $total * 100, 1) : 0;
$processFailed = (int)($summary['failed'] ?? 0);

// ── Log table query ───────────────────────────────────────────────────────────
$perPage = 50;
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($pageNum - 1) * $perPage;

$where  = ['site_id IS NULL'];
$params = [];
if ($fromFilter !== '') {
    $where[]  = 'from_address = ?';
    $params[] = $fromFilter;
}

$statusFilter  = $_GET['status'] ?? '';
$validStatuses = ['queued','sent','delivered','bounced','opened','clicked','failed'];
if ($statusFilter && in_array($statusFilter, $validStatuses, true)) {
    $where[]  = 'status = ?';
    $params[] = $statusFilter;
}

$whereSql   = 'WHERE ' . implode(' AND ', $where);
$totalRows  = 0;
$totalPages = 1;
$logs       = [];

if ($hasAnyScope) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM zepto_mail_log $whereSql");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $logsStmt = $pdo->prepare(
        "SELECT * FROM zepto_mail_log $whereSql ORDER BY sent_at DESC, id DESC LIMIT $perPage OFFSET $offset"
    );
    $logsStmt->execute($params);
    $logs = $logsStmt->fetchAll();
}

$page   = 'Mail Deliverability';
$active = 'mail-deliverability';
require __DIR__ . '/../src/layout.php';

function zepto_badge(string $status): string {
    return match($status) {
        'delivered','opened','clicked' => 'ok',
        'bounced','failed'             => 'err',
        default                        => 'warn',
    };
}
function zepto_icon(string $status): string {
    return match($status) {
        'delivered' => '✓', 'opened' => '👁', 'clicked' => '↗',
        'bounced'   => '↩', 'failed' => '✕',  'sent'    => '→',
        default     => '·',
    };
}
?>

<div class="page-head">
    <div>
        <h1>Mail Deliverability</h1>
        <div class="sub">Delivery logs pulled directly from the Zepto Mail API</div>
    </div>
    <?php if ($hasAnyScope): ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <form method="post" style="margin:0;">
            <input type="hidden" name="_csrf"     value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action"    value="refresh">
            <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
            <input type="hidden" name="date_to"   value="<?= e($dateTo) ?>">
            <button class="btn primary" type="submit">↻ Refresh from API</button>
        </form>
        <?php if ($isConnected): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Disconnect Zepto Mail? You can reconnect at any time.')">
            <input type="hidden" name="_csrf"   value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action"  value="disconnect">
            <button class="btn danger" type="submit" style="font-size:12px;">Disconnect</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($refreshedCount !== null): ?>
<div class="flash success">Pulled <?= $refreshedCount ?> records from Zepto Mail.</div>
<?php endif; ?>
<?php if ($apiError): ?>
<div class="flash error">API error: <?= e($apiError) ?></div>
<?php endif; ?>


<?php /* ── State: credentials not configured ── */ ?>
<?php if (!$isConfigured): ?>
<div class="card" style="background:rgba(251,191,36,0.06); border-color:rgba(251,191,36,0.3); max-width:640px;">
    <div style="font-weight:500; margin-bottom:8px;">⚙ Step 1 — Add OAuth credentials to config</div>
    <p style="margin:0 0 10px; color:var(--text-dim); font-size:13px; line-height:1.7;">
        Register a <strong>Server-based Application</strong> at
        <strong>https://api-console.zoho.com/</strong>, then add to <code>config.local.php</code>:
    </p>
    <pre style="background:var(--bg-3); border:1px solid var(--line-2); border-radius:var(--r); padding:12px 14px; font-size:12px; overflow-x:auto; margin:0;">'zepto' => [
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'redirect_uri'  => '<?= e(rtrim(cfg('app.base_url','https://your-dashboard.example.com'),'/')) ?>/zepto-oauth-callback.php',
    'api_url'       => 'https://api.zeptomail.com/v1.1/',
    'accounts_url'  => 'https://accounts.zoho.com',
],</pre>
    <p style="margin:10px 0 0; font-size:12px; color:var(--text-faint);">
        Set Authorized Redirect URI in Zoho console to:
        <code><?= e(rtrim(cfg('app.base_url','https://your-dashboard.example.com'),'/')) ?>/zepto-oauth-callback.php</code>
    </p>
</div>

<?php /* ── State: configured but not yet connected ── */ ?>
<?php elseif (!$isConnected && !count($tokenSites)): ?>
<div class="card" style="background:rgba(163,230,53,0.04); border-color:rgba(163,230,53,0.25); max-width:480px;">
    <div style="font-weight:500; margin-bottom:8px;">⚡ Step 2 — Authorize with Zoho</div>
    <p style="margin:0 0 14px; color:var(--text-dim); font-size:13px; line-height:1.6;">
        Credentials are configured. Click below to open Zoho's authorization page,
        then grant access to your Zepto Mail account.
    </p>
    <a href="/zepto-oauth.php" class="btn primary">Connect Zepto Mail →</a>
</div>

<?php /* ── State: connected or has per-site tokens — show full UI ── */ ?>
<?php else: ?>

<!-- Connection status banner -->
<div style="display:flex; align-items:center; gap:8px; margin-bottom:20px; font-size:13px; color:var(--text-dim);">
    <span class="pill ok">✓ Connected</span>
    Global Zepto Mail account linked via OAuth — showing logs from all Mail Agents.
</div>

<!-- Site filter + date range toolbar -->
<form method="get" class="toolbar" style="margin-bottom:20px;">
    <?php if ($logSenders): ?>
    <select name="from" onchange="this.form.submit()" style="width:220px;">
        <option value="">All Sites</option>
        <?php foreach ($logSenders as $addr): ?>
        <option value="<?= e($addr) ?>" <?= $fromFilter === $addr ? 'selected' : '' ?>>
            <?= e($siteByFrom[$addr] ?? $addr) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php else: ?>
    <span style="font-size:13px; color:var(--text-dim); padding:8px 0;">All Sites</span>
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
        <div class="delta"><?= $fromFilter ? e($siteByFrom[$fromFilter] ?? $fromFilter) : 'all sites' ?></div>
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
    <div class="card" <?= $processFailed > 0 ? 'style="border-color:rgba(248,113,113,0.4);"' : '' ?>>
        <div class="label">Process failed</div>
        <div class="value" style="color:<?= $processFailed > 0 ? 'var(--err)' : 'var(--text)' ?>;"><?= number_format($processFailed) ?></div>
        <div class="delta">emails failed to process</div>
    </div>
</div>

<!-- Status filter + record count -->
<form method="get" class="toolbar">
    <?php if ($fromFilter): ?>
    <input type="hidden" name="from" value="<?= e($fromFilter) ?>">
    <?php endif; ?>
    <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
    <input type="hidden" name="date_to"   value="<?= e($dateTo) ?>">
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
    No records cached yet.<br>
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
        <td style="font-size:12px; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
            title="<?= e($l['subject']) ?>"><?= e($l['subject'] ?: '—') ?></td>
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
            <?php if ($l['bounce_reason']): ?>
                <?= e($l['bounce_reason']) ?>
            <?php else: ?>
                <span style="color:var(--text-faint)">—</span>
            <?php endif; ?>
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
            'from'      => $fromFilter ?: null,
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
<?php endif; // end connected/scope state ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
