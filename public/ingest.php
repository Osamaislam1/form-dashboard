<?php
// public/ingest.php - receives form submissions from the WordPress bridge plugin

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');

function reject(int $code, string $msg, ?int $siteId = null): void {
    try {
        db()->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, ?, ?, ?)')
            ->execute([$siteId, $code === 200 ? 'ok' : 'rejected', $msg, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (\Throwable) { /* don't block on logging */ }
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reject(405, 'Method not allowed');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    reject(400, 'Empty body');
}
if (strlen($raw) > 2 * 1024 * 1024) {
    reject(413, 'Payload too large');
}

$apiKey    = $_SERVER['HTTP_X_FDASH_KEY']       ?? '';
$signature = $_SERVER['HTTP_X_FDASH_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_FDASH_TIMESTAMP'] ?? '';

if ($apiKey === '' || $signature === '' || $timestamp === '') {
    reject(401, 'Missing auth headers');
}

// Reject stale requests (5 minute window)
if (abs(time() - (int)$timestamp) > 300) {
    reject(401, 'Stale timestamp');
}

$site = db()->prepare('SELECT * FROM sites WHERE api_key = ? AND status = "active"');
$site->execute([$apiKey]);
$site = $site->fetch();
if (!$site) {
    reject(401, 'Unknown or inactive site');
}

// Verify HMAC: sha256(timestamp . "." . body) using the site's secret
$expected = hash_hmac('sha256', $timestamp . '.' . $raw, $site['secret_hash']);
if (!hash_equals($expected, $signature)) {
    reject(401, 'Invalid signature', (int)$site['id']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    reject(400, 'Invalid JSON', (int)$site['id']);
}

$pdo = db();

// ── Handle email health check payloads ──────────────────────────────────────
if (($data['type'] ?? '') === 'email_health') {
    $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
    $status = ($fields['status'] ?? '') === 'ok' ? 'ok' : 'fail';
    $error  = substr((string)($fields['error'] ?? ''), 0, 500) ?: null;
    $mailer = substr((string)($fields['mailer'] ?? ''), 0, 100) ?: null;

    $pdo->prepare('INSERT INTO email_health_log (site_id, status, error_msg, mailer) VALUES (?, ?, ?, ?)')
        ->execute([$site['id'], $status, $error, $mailer]);

    $pdo->prepare('UPDATE sites SET email_status = ?, email_checked_at = NOW(), email_error = ?, last_seen_at = NOW() WHERE id = ?')
        ->execute([$status, $error, $site['id']]);

    if ($status === 'fail') {
        try {
            $rules = $pdo->prepare(
                "SELECT ar.*, ar.cooldown_seconds, ar.last_alerted_at
                 FROM alert_rules ar
                 WHERE ar.type = 'email_health' AND ar.enabled = 1
                   AND (ar.site_id IS NULL OR ar.site_id = ?)"
            );
            $rules->execute([$site['id']]);
            foreach ($rules->fetchAll() as $rule) {
                $subject = '[' . cfg('app.name') . '] Email FAILING on ' . $site['name'];
                $body    = "Email delivery is failing on {$site['name']} ({$site['url']}).\n\n"
                         . "Error: {$error}\n"
                         . "Mailer: {$mailer}\n"
                         . "Checked: " . date('Y-m-d H:i:s') . "\n\n"
                         . "Check your dashboard: " . cfg('app.base_url') . "/email-health.php";
                send_alert($rule, $subject, $body, null, (int)$site['id'], $pdo);
            }
        } catch (\Throwable $e) { /* don't block ingest */ }
    }

    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "ok", ?, ?)')
        ->execute([$site['id'], 'email_health: ' . $status, $_SERVER['REMOTE_ADDR'] ?? null]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'type' => 'email_health']);
    exit;
}

// ── Handle heartbeat payloads ────────────────────────────────────────────────
if (($data['type'] ?? '') === 'heartbeat') {
    $pdo->prepare('UPDATE sites SET last_seen_at = NOW(), last_heartbeat_at = NOW() WHERE id = ?')
        ->execute([$site['id']]);

    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "ok", ?, ?)')
        ->execute([$site['id'], 'heartbeat', $_SERVER['REMOTE_ADDR'] ?? null]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'type' => 'heartbeat']);
    exit;
}

// ── Handle dead letter payloads ──────────────────────────────────────────────
if (($data['type'] ?? '') === 'dead_letter') {
    $fields       = is_array($data['fields'] ?? null) ? $data['fields'] : [];
    $attempts     = (int)($fields['attempts'] ?? 5);
    $firstQueued  = date('Y-m-d H:i:s', (int)($fields['first_queued_at'] ?? time()));
    $preview      = substr((string)($fields['payload_preview'] ?? ''), 0, 300);

    try {
        $pdo->prepare(
            'INSERT INTO dead_letter_log (site_id, payload_json, attempts, first_queued_at) VALUES (?, ?, ?, ?)'
        )->execute([$site['id'], json_encode($fields), $attempts, $firstQueued]);
    } catch (\Throwable $e) { /* don't block */ }

    // Fire an immediate alert for every dead letter — these are truly lost submissions
    try {
        $rules = $pdo->prepare(
            "SELECT ar.*, ar.cooldown_seconds, ar.last_alerted_at
             FROM alert_rules ar
             WHERE ar.type = 'dead_letter' AND ar.enabled = 1
               AND (ar.site_id IS NULL OR ar.site_id = ?)"
        );
        $rules->execute([$site['id']]);
        foreach ($rules->fetchAll() as $rule) {
            $subject = '[' . cfg('app.name') . '] LOST submission on ' . $site['name'];
            $body    = "A form submission on {$site['name']} ({$site['url']}) permanently failed after {$attempts} retry attempts.\n\n"
                     . "First queued: {$firstQueued}\n"
                     . "Preview: {$preview}\n\n"
                     . "Check your dashboard dead letter log: " . cfg('app.base_url') . "/webhook-log.php";
            send_alert($rule, $subject, $body, null, (int)$site['id'], $pdo);
        }
    } catch (\Throwable $e) {}

    $pdo->prepare('UPDATE sites SET last_seen_at = NOW() WHERE id = ?')->execute([$site['id']]);
    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "error", ?, ?)')
        ->execute([$site['id'], 'dead_letter: ' . $attempts . ' attempts', $_SERVER['REMOTE_ADDR'] ?? null]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'type' => 'dead_letter']);
    exit;
}

// ── Handle queue overflow payloads ───────────────────────────────────────────
if (($data['type'] ?? '') === 'queue_overflow') {
    $fields     = is_array($data['fields'] ?? null) ? $data['fields'] : [];
    $queueDepth = (int)($fields['queue_depth'] ?? 0);

    try {
        $rules = $pdo->prepare(
            "SELECT ar.*, ar.cooldown_seconds, ar.last_alerted_at
             FROM alert_rules ar
             WHERE ar.type = 'queue_overflow' AND ar.enabled = 1
               AND (ar.site_id IS NULL OR ar.site_id = ?)"
        );
        $rules->execute([$site['id']]);
        foreach ($rules->fetchAll() as $rule) {
            $subject = '[' . cfg('app.name') . '] Retry queue full on ' . $site['name'];
            $body    = "The retry queue on {$site['name']} ({$site['url']}) is full ({$queueDepth} items).\n\n"
                     . "Oldest submissions are being dropped. Check network connectivity and dashboard availability.\n\n"
                     . "Dashboard: " . cfg('app.base_url');
            send_alert($rule, $subject, $body, null, (int)$site['id'], $pdo);
        }
    } catch (\Throwable $e) {}

    $pdo->prepare('UPDATE sites SET last_seen_at = NOW() WHERE id = ?')->execute([$site['id']]);
    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "error", ?, ?)')
        ->execute([$site['id'], 'queue_overflow: depth=' . $queueDepth, $_SERVER['REMOTE_ADDR'] ?? null]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'type' => 'queue_overflow']);
    exit;
}

// ── Handle connection test payloads (no DB write) ───────────────────────────
if (($data['type'] ?? '') === 'test') {
    $pdo->prepare('UPDATE sites SET last_seen_at = NOW() WHERE id = ?')->execute([$site['id']]);
    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "ok", "connection-test", ?)')
        ->execute([$site['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'type' => 'test']);
    exit;
}

// ── Handle resync complete signal from WP plugin ─────────────────────────────
if (($data['type'] ?? '') === 'resync_complete') {
    $pdo->prepare('UPDATE sites SET resync_requested_at = NULL, last_seen_at = NOW() WHERE id = ?')
        ->execute([$site['id']]);
    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "ok", "resync_complete", ?)')
        ->execute([$site['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'type' => 'resync_complete']);
    exit;
}

// ── Handle regular form submissions ─────────────────────────────────────────
foreach (['plugin', 'form_id', 'form_title', 'fields'] as $req) {
    if (!isset($data[$req])) reject(400, "Missing field: $req", (int)$site['id']);
}

$plugin     = substr((string)$data['plugin'], 0, 40);
$remoteForm = substr((string)$data['form_id'], 0, 120);
$formTitle  = substr((string)$data['form_title'], 0, 255);
$fields     = is_array($data['fields']) ? $data['fields'] : [];
$remoteEntry = isset($data['entry_id']) ? substr((string)$data['entry_id'], 0, 120) : null;
$submittedAt = isset($data['submitted_at'])
    ? date('Y-m-d H:i:s', strtotime((string)$data['submitted_at']) ?: time())
    : date('Y-m-d H:i:s');
$ip  = isset($data['ip']) ? substr((string)$data['ip'], 0, 45) : null;
$ua  = isset($data['user_agent']) ? substr((string)$data['user_agent'], 0, 500) : null;

// Extract email & name for the list view
$email = null;
$name  = null;
foreach ($fields as $k => $v) {
    if (!is_string($v) && !is_numeric($v)) continue;
    $val = trim((string)$v);
    $key = strtolower((string)$k);
    if (!$email && filter_var($val, FILTER_VALIDATE_EMAIL)) {
        $email = substr($val, 0, 255);
    }
    if (!$name && preg_match('/(^|_)name($|_)/', $key) && $val !== '') {
        $name = substr($val, 0, 255);
    }
}

// Build a short summary
$summaryParts = [];
foreach ($fields as $k => $v) {
    if (count($summaryParts) >= 3) break;
    if (is_string($v) && $v !== '') {
        $summaryParts[] = $k . ': ' . mb_substr($v, 0, 60);
    }
}
$summary = mb_substr(implode(' · ', $summaryParts), 0, 500);

$formId       = 0;
$submissionId = 0;

$pdo->beginTransaction();
try {
    // Upsert form
    $pdo->prepare(
        'INSERT INTO forms (site_id, plugin, remote_form_id, title, first_seen_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            first_seen_at = LEAST(first_seen_at, VALUES(first_seen_at))'
    )->execute([$site['id'], $plugin, $remoteForm, $formTitle, $submittedAt]);

    $stmt = $pdo->prepare('SELECT id FROM forms WHERE site_id = ? AND plugin = ? AND remote_form_id = ?');
    $stmt->execute([$site['id'], $plugin, $remoteForm]);
    $formId = (int)$stmt->fetchColumn();

    // Insert submission — INSERT IGNORE silently skips if the same (site, form, entry_id)
    // has already been stored (prevents duplicates from retried pushes).
    $pdo->prepare(
        'INSERT IGNORE INTO submissions
         (site_id, form_id, remote_entry_id, payload_json, summary, email, name, ip_address, user_agent, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $site['id'], $formId, $remoteEntry,
        json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $summary, $email, $name, $ip, $ua, $submittedAt,
    ]);
    $submissionId = (int)$pdo->lastInsertId();

    // lastInsertId() returns 0 when INSERT IGNORE skipped a duplicate row
    if ($submissionId === 0) {
        $pdo->rollBack();
        http_response_code(200);
        echo json_encode(['ok' => true, 'submission_id' => null, 'duplicate' => true]);
        exit;
    }

    // Update form rollup
    $pdo->prepare(
        'UPDATE forms
         SET submission_count = submission_count + 1,
             last_submission_at = GREATEST(COALESCE(last_submission_at, "1970-01-01"), ?)
         WHERE id = ?'
    )->execute([$submittedAt, $formId]);

    // Update site last_seen
    $pdo->prepare('UPDATE sites SET last_seen_at = NOW() WHERE id = ?')
        ->execute([$site['id']]);

    $pdo->prepare('INSERT INTO webhook_log (site_id, status, message, ip) VALUES (?, "ok", ?, ?)')
        ->execute([$site['id'], 'submission #' . $submissionId, $_SERVER['REMOTE_ADDR'] ?? null]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    reject(500, 'DB error: ' . $e->getMessage(), (int)$site['id']);
}

// Fire submission alert emails — rate-limited per rule, every attempt logged
try {
    $rules = $pdo->prepare(
        'SELECT ar.*, ar.cooldown_seconds, ar.last_alerted_at
         FROM alert_rules ar
         WHERE ar.enabled = 1
           AND ar.type = "submission"
           AND (ar.site_id IS NULL OR ar.site_id = ?)
           AND (ar.form_id IS NULL OR ar.form_id = ?)'
    );
    $rules->execute([$site['id'], $formId]);
    foreach ($rules->fetchAll() as $rule) {
        $subject = '[' . cfg('app.name') . '] New submission: ' . $formTitle . ' on ' . $site['name'];
        $body    = "Site: {$site['name']}\nForm: {$formTitle}\nSubmitted: {$submittedAt}\n\n";
        foreach ($fields as $k => $v) {
            $body .= $k . ': ' . (is_scalar($v) ? $v : json_encode($v)) . "\n";
        }
        $body .= "\nView in dashboard: " . cfg('app.base_url') . '/submissions.php?id=' . $submissionId;
        send_alert($rule, $subject, $body, $submissionId, (int)$site['id'], $pdo);
    }
} catch (\Throwable $e) { /* don't block response */ }

http_response_code(200);
echo json_encode(['ok' => true, 'submission_id' => $submissionId]);
