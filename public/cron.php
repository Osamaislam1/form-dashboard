<?php
// public/cron.php — server-side cron tasks (runs independently of WordPress)
//
// Add to server cron (crontab / cPanel) — runs every hour:
//   0 * * * * curl -s "https://yourdomain.com/cron.php?secret=YOUR_SECRET" > /dev/null
//
// Never expose this endpoint without the secret check below.

declare(strict_types=1);

define('FDASH_ROOT', dirname(__DIR__));
require FDASH_ROOT . '/src/bootstrap.php';

header('Content-Type: application/json');

// Secret guard — only the server cron may call this
$secret = cfg('cron.secret', '');
if ($secret === '' || $secret === 'CHANGE_ME_TO_A_RANDOM_STRING') {
    http_response_code(503);
    echo json_encode(['error' => 'cron.secret not configured']);
    exit;
}
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo    = db();
$report = ['tasks' => [], 'ran_at' => gmdate('c')];

// ── Task 1: Gap detection ─────────────────────────────────────────────────────
// Alert when an active site has been silent for longer than cron.site_offline_hours
$offlineHours = (int)cfg('cron.site_offline_hours', 48);

try {
    // Find silent sites independently of whether an alert rule exists.
    // Previously this used a JOIN on alert_rules, which returned 0 rows when
    // no site_offline rule was configured — silently skipping all gap checks.
    $silent = $pdo->prepare(
        "SELECT * FROM sites
         WHERE status = 'active'
           AND (
               last_seen_at IS NULL
               OR last_seen_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
           )"
    );
    $silent->execute([$offlineHours]);
    $silentSites = $silent->fetchAll();

    $alerted = 0;
    foreach ($silentSites as $site) {
        $lastSeen = $site['last_seen_at'] ?? 'never';
        $subject  = '[' . cfg('app.name') . '] Site offline: ' . $site['name'];
        $body     = "No submissions or heartbeats have been received from {$site['name']} ({$site['url']}) "
                  . "for more than {$offlineHours} hours.\n\n"
                  . "Last seen: {$lastSeen}\n\n"
                  . "Possible causes:\n"
                  . "  - The Form Dashboard Bridge plugin was deactivated\n"
                  . "  - WP Cron is disabled and no real cron is configured\n"
                  . "  - The site is down or unreachable\n\n"
                  . "Check your site and dashboard: " . cfg('app.base_url') . '/sites.php';

        $rules = $pdo->prepare(
            "SELECT * FROM alert_rules
             WHERE type = 'site_offline' AND enabled = 1
               AND (site_id IS NULL OR site_id = ?)"
        );
        $rules->execute([(int)$site['id']]);
        foreach ($rules->fetchAll() as $rule) {
            send_alert($rule, $subject, $body, null, (int)$site['id'], $pdo);
            $alerted++;
        }
    }
    $report['tasks']['gap_detection'] = ['sites_checked' => count($silentSites), 'alerts_fired' => $alerted];
} catch (\Throwable $e) {
    $report['tasks']['gap_detection'] = ['error' => $e->getMessage()];
}

// ── Task 2: Purge old webhook_log rows (keep 30 days) ────────────────────────
try {
    $del = $pdo->prepare('DELETE FROM webhook_log WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $del->execute();
    $report['tasks']['webhook_log_cleanup'] = ['deleted_rows' => $del->rowCount()];
} catch (\Throwable $e) {
    $report['tasks']['webhook_log_cleanup'] = ['error' => $e->getMessage()];
}

// ── Task 3: Purge old alert_log rows (keep 90 days) ──────────────────────────
try {
    $del = $pdo->prepare('DELETE FROM alert_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
    $del->execute();
    $report['tasks']['alert_log_cleanup'] = ['deleted_rows' => $del->rowCount()];
} catch (\Throwable $e) {
    $report['tasks']['alert_log_cleanup'] = ['error' => $e->getMessage()];
}

// ── Task 4: Purge old dead_letter_log rows (keep 90 days) ────────────────────
try {
    $del = $pdo->prepare('DELETE FROM dead_letter_log WHERE received_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
    $del->execute();
    $report['tasks']['dead_letter_cleanup'] = ['deleted_rows' => $del->rowCount()];
} catch (\Throwable $e) {
    $report['tasks']['dead_letter_cleanup'] = ['error' => $e->getMessage()];
}

http_response_code(200);
echo json_encode($report, JSON_PRETTY_PRINT);
