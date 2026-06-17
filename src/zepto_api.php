<?php
// src/zepto_api.php — Zepto Mail API helpers (no Composer required)
//
// Zepto Mail is Zoho's transactional email product (zeptomail.com).
// Auth: Authorization: Zoho-enczapikey <TOKEN>
// Base: https://api.zeptomail.com/v1.1/
//
// Run the schema migrations before using these functions:
//   ALTER TABLE sites ADD COLUMN zepto_api_token VARCHAR(255) DEFAULT NULL;
//   CREATE TABLE zepto_mail_log (...) — see sql/schema.sql

declare(strict_types=1);

/**
 * Returns the effective API token for a given scope.
 * Per-site token takes precedence; falls back to the global config token.
 */
function zepto_token(?int $site_id): ?string
{
    if ($site_id !== null) {
        $stmt = db()->prepare('SELECT zepto_api_token FROM sites WHERE id = ?');
        $stmt->execute([$site_id]);
        $token = $stmt->fetchColumn();
        if ($token && $token !== '') return $token;
    }
    $global = cfg('zepto.api_token', '');
    return ($global !== '') ? $global : null;
}

/**
 * Perform a GET request against the Zepto Mail API.
 * Returns ['data' => array|null, 'error' => string|null].
 */
function zepto_get(string $endpoint, array $params, string $token): array
{
    $base = rtrim(cfg('zepto.api_url', 'https://api.zeptomail.com/v1.1/'), '/');
    $url  = $base . '/' . ltrim($endpoint, '/');
    if ($params) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Zoho-enczapikey ' . $token,
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)       return ['data' => null, 'error' => 'cURL error: ' . $err];
    if ($code === 401 || $code === 403) return ['data' => null, 'error' => 'Invalid or unauthorized API token (HTTP ' . $code . ')'];
    if ($code !== 200) return ['data' => null, 'error' => 'HTTP ' . $code . ': ' . substr((string)$body, 0, 300)];

    $json = json_decode((string)$body, true);
    if (!is_array($json)) return ['data' => null, 'error' => 'Unexpected response (not JSON)'];

    return ['data' => $json, 'error' => null];
}

/**
 * Parse a date value that may be an ISO string or a Unix timestamp integer.
 */
function zepto_parse_date(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return date('Y-m-d H:i:s', (int)$v);
    $t = strtotime((string)$v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/**
 * Fetch email activity from Zepto API and replace the local cache for the given scope.
 * $from and $to are ISO datetime strings (e.g. "2024-01-01T00:00:00Z").
 * Returns ['inserted' => N, 'error' => string|null].
 */
function zepto_fetch_and_cache(?int $site_id, string $from, string $to): array
{
    $token = zepto_token($site_id);
    if (!$token) {
        return ['inserted' => 0, 'error' => 'No Zepto API token configured for this scope'];
    }

    $allRows = [];
    $page    = 1;
    $pageSize = 100;

    // Paginate through all activity records
    do {
        $result = zepto_get('email/activity', [
            'from_time' => $from,
            'to_time'   => $to,
            'page_no'   => $page,
            'page_size' => $pageSize,
        ], $token);

        if ($result['error']) {
            return ['inserted' => count($allRows), 'error' => $result['error']];
        }

        // Zepto returns: { "data": { "activity": [...], "count": N } }
        // Fallback paths handle minor API version differences
        $data     = $result['data']['data'] ?? $result['data'] ?? [];
        $batch    = $data['activity'] ?? $data['email_activity'] ?? [];
        $total    = (int)($data['count'] ?? $data['total_count'] ?? 0);

        if (!is_array($batch)) break;

        $allRows = array_merge($allRows, $batch);
        $page++;
    } while (count($batch) === $pageSize && count($allRows) < $total && $page <= 20);

    // Replace cached rows for this scope (zepto_mail_log is a cache, not an audit log)
    $pdo = db();
    if ($site_id !== null) {
        $pdo->prepare('DELETE FROM zepto_mail_log WHERE site_id = ?')->execute([$site_id]);
    } else {
        $pdo->exec('DELETE FROM zepto_mail_log WHERE site_id IS NULL');
    }

    $validStatuses = ['queued', 'sent', 'delivered', 'bounced', 'opened', 'clicked', 'failed'];
    $stmt = $pdo->prepare(
        'INSERT INTO zepto_mail_log (site_id, message_id, recipient, subject, status, bounce_reason, sent_at, delivered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($allRows as $item) {
        $status = strtolower((string)($item['status'] ?? 'sent'));
        if (!in_array($status, $validStatuses, true)) $status = 'sent';

        $recipient = $item['to_address'] ?? $item['recipient'] ?? $item['to_email'] ?? null;

        $stmt->execute([
            $site_id,
            $item['message_id'] ?? null,
            is_array($recipient) ? ($recipient['address'] ?? null) : $recipient,
            mb_substr((string)($item['subject'] ?? ''), 0, 500),
            $status,
            $item['bounce_reason'] ?? $item['bounce_description'] ?? null,
            zepto_parse_date($item['sent_at'] ?? null),
            zepto_parse_date($item['delivered_at'] ?? null),
        ]);
    }

    return ['inserted' => count($allRows), 'error' => null];
}

/**
 * Aggregate summary stats from the local cache for a given scope.
 * Returns [total, delivered, bounced, opened, last_fetched].
 */
function zepto_summary(?int $site_id): array
{
    $pdo   = db();
    $where = $site_id !== null ? 'site_id = ?' : 'site_id IS NULL';
    $args  = $site_id !== null ? [$site_id] : [];

    $row = $pdo->prepare(
        "SELECT
            COUNT(*)                                             AS total,
            SUM(status IN ('delivered','opened','clicked'))      AS delivered,
            SUM(status = 'bounced')                              AS bounced,
            SUM(status = 'failed')                               AS failed,
            SUM(status IN ('opened','clicked'))                  AS opened,
            MAX(fetched_at)                                      AS last_fetched
         FROM zepto_mail_log WHERE $where"
    );
    $row->execute($args);
    return $row->fetch() ?: [
        'total' => 0, 'delivered' => 0, 'bounced' => 0, 'failed' => 0, 'opened' => 0, 'last_fetched' => null,
    ];
}
