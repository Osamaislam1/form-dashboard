<?php
// public/health.php — lightweight health check for uptime monitors (no auth required)
// Returns HTTP 200 + JSON when healthy, HTTP 500 when the database is unreachable.
//
// Example: curl https://yourdomain.com/health.php

declare(strict_types=1);

define('FDASH_ROOT', dirname(__DIR__));
require FDASH_ROOT . '/src/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$payload = [
    'ok'                   => false,
    'db'                   => false,
    'submissions_24h'      => 0,
    'sites_active'         => 0,
    'sites_email_failing'  => 0,
    'dead_letters_pending' => 0,
    'checked_at'           => gmdate('c'),
];

try {
    $pdo = db();

    $payload['db'] = true;

    $payload['submissions_24h'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM submissions WHERE received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )->fetchColumn();

    $payload['sites_active'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM sites WHERE status = 'active'"
    )->fetchColumn();

    $payload['sites_email_failing'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM sites WHERE status = 'active' AND email_status = 'fail'"
    )->fetchColumn();

    $payload['dead_letters_pending'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM dead_letter_log WHERE received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();

    $payload['ok'] = true;
    http_response_code(200);
} catch (\Throwable $e) {
    $payload['error'] = 'Database unreachable';
    http_response_code(500);
}

echo json_encode($payload, JSON_PRETTY_PRINT);
