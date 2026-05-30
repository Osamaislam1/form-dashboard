<?php
// src/bootstrap.php

declare(strict_types=1);

if (!defined('FDASH_ROOT')) {
    define('FDASH_ROOT', dirname(__DIR__));
}

$configFile = file_exists(FDASH_ROOT . '/config.local.php')
    ? FDASH_ROOT . '/config.local.php'
    : FDASH_ROOT . '/config.php';

$CONFIG = require $configFile;

date_default_timezone_set($CONFIG['app']['timezone'] ?? 'UTC');

// PDO
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $CONFIG;
        $c = $CONFIG['db'];
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function cfg(string $path, $default = null) {
    global $CONFIG;
    $parts = explode('.', $path);
    $v = $CONFIG;
    foreach ($parts as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}

// Session (only for the web UI, not the webhook endpoint)
function session_start_safe(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(cfg('app.session_name', 'fdash'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array {
    session_start_safe();
    if (empty($_SESSION['uid'])) return null;
    static $u = null;
    if ($u === null) {
        $u = db()->prepare('SELECT id, email, name, role FROM users WHERE id = ?');
        $u->execute([$_SESSION['uid']]);
        $u = $u->fetch() ?: null;
    }
    return $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    return $u;
}

function csrf_token(): string {
    session_start_safe();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    session_start_safe();
    $given = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $given)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'info'): void {
    session_start_safe();
    $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_pull(): array {
    session_start_safe();
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/**
 * Send an alert email for a given alert rule, enforcing the per-rule cooldown
 * and logging every attempt (ok or fail) to the alert_log table.
 *
 * @param array       $rule         Row from alert_rules (must include id, notify_emails, cooldown_seconds, last_alerted_at)
 * @param string      $subject      Email subject
 * @param string      $body         Plain-text body
 * @param int|null    $submissionId Linked submission id (or null for system alerts)
 * @param int         $siteId
 * @param PDO         $pdo
 */
function send_alert(array $rule, string $subject, string $body, ?int $submissionId, int $siteId, PDO $pdo): void {
    // Enforce cooldown
    $cooldown    = (int)($rule['cooldown_seconds'] ?? 300);
    $lastAlerted = !empty($rule['last_alerted_at']) ? strtotime($rule['last_alerted_at']) : 0;
    if ($lastAlerted && (time() - $lastAlerted) < $cooldown) {
        return;
    }

    $emails = array_filter(array_map('trim', explode(',', $rule['notify_emails'])));
    if (!$emails) return;

    $headers = 'From: ' . cfg('mail.from_name') . ' <' . cfg('mail.from_email') . '>';
    $sent    = @mail(implode(',', $emails), $subject, $body, $headers);

    // Log every attempt so failures are visible in the dashboard
    try {
        $pdo->prepare(
            'INSERT INTO alert_log (rule_id, site_id, submission_id, sent_to, subject, status, error_msg)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $rule['id'],
            $siteId,
            $submissionId,
            implode(',', $emails),
            mb_substr($subject, 0, 500),
            $sent ? 'ok' : 'fail',
            $sent ? null : 'mail() returned false',
        ]);
    } catch (\Throwable $e) { /* don't block on logging */ }

    if ($sent) {
        try {
            $pdo->prepare('UPDATE alert_rules SET last_alerted_at = NOW() WHERE id = ?')
                ->execute([$rule['id']]);
        } catch (\Throwable $e) {}
    }
}
