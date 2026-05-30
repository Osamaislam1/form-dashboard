<?php
// Serves plugin update metadata to WordPress sites running the Form Dashboard Bridge.
// Called by the plugin on WordPress's pre_set_site_transient_update_plugins hook.
// No authentication required — returns only public version metadata.

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$jsonFile = dirname(__DIR__) . '/plugin-version.json';

if (!file_exists($jsonFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Version file not found. Run a tagged release first.']);
    exit;
}

$raw = file_get_contents($jsonFile);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not read version file.']);
    exit;
}

$data = json_decode(trim($raw), true);
if (!is_array($data) || empty($data['version'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Malformed version file.']);
    exit;
}

// Refuse to serve a non-HTTPS download URL — guards against hand-edit mistakes.
$downloadUrl = $data['download_url'] ?? '';
if (!str_starts_with($downloadUrl, 'https://')) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid download_url in version file.']);
    exit;
}

http_response_code(200);
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
