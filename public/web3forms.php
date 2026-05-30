<?php
// public/web3forms.php - receives form submissions from Web3Forms webhooks

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

$apiKey = $_GET['key'] ?? '';
if ($apiKey === '') {
    reject(401, 'Missing API key in URL');
}

$site = db()->prepare('SELECT * FROM sites WHERE api_key = ? AND status = "active"');
$site->execute([$apiKey]);
$site = $site->fetch();
if (!$site) {
    reject(401, 'Unknown or inactive site');
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    reject(400, 'Invalid JSON', (int)$site['id']);
}

$pdo = db();

$plugin = 'web3forms';

// Web3Forms payload might not have a form ID. We will use `form_name` or `subject` if present, else a default.
$remoteForm = 'web3forms-default';
$formTitle = 'Web3Forms Submission';

if (!empty($data['form_name']) && is_string($data['form_name'])) {
    $remoteForm = 'web3forms-' . preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '-', $data['form_name'])));
    $formTitle = substr($data['form_name'], 0, 255);
} elseif (!empty($data['subject']) && is_string($data['subject'])) {
    $remoteForm = 'web3forms-' . preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '-', $data['subject'])));
    $formTitle = substr($data['subject'], 0, 255);
}

// Ensure the form title is not empty
if ($formTitle === '') {
    $formTitle = 'Web3Forms Submission';
}

$remoteForm = substr($remoteForm, 0, 120);

// Use the entire Web3Forms payload as fields
$fields = $data;

// Generate a pseudo-unique entry ID since Web3Forms doesn't send one
$remoteEntry = md5($raw . time());

$submittedAt = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

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
    if (in_array(strtolower((string)$k), ['botcheck', 'access_key'])) continue; // skip internal web3forms fields
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
        ->execute([$site['id'], 'web3forms submission #' . $submissionId, $_SERVER['REMOTE_ADDR'] ?? null]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    reject(500, 'DB error: ' . $e->getMessage(), (int)$site['id']);
}

// Fire submission alert emails
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
