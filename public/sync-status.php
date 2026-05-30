<?php
// public/sync-status.php — polled by the WP plugin to check for pending resync requests
// Authentication: API key via query param (read-only, no sensitive data returned)

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$apiKey = trim($_GET['key'] ?? '');
if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Missing key']);
    exit;
}

$stmt = db()->prepare('SELECT id, resync_requested_at FROM sites WHERE api_key = ? AND status = "active"');
$stmt->execute([$apiKey]);
$site = $stmt->fetch();

if (!$site) {
    http_response_code(401);
    echo json_encode(['error' => 'Unknown or inactive site']);
    exit;
}

$requested = !empty($site['resync_requested_at']);

http_response_code(200);
echo json_encode([
    'ok'               => true,
    'resync_requested' => $requested,
    'requested_at'     => $requested ? $site['resync_requested_at'] : null,
]);
