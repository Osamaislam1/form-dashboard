<?php
// src/zepto_api.php — Zepto Mail API helpers (OAuth 2.0, no Composer required)
//
// Auth flow:
//   Global scope  → OAuth 2.0 via api-console.zoho.com (access + refresh token stored in oauth_tokens table)
//   Per-site scope → simple Mail Agent token from sites.zepto_api_token (fallback for site owners)
//
// One-time setup:
//   1. Register "Server-based Application" at https://api-console.zoho.com/
//   2. Set redirect URI to: https://your-dashboard/zepto-oauth-callback.php
//   3. Paste client_id / client_secret / redirect_uri into config.local.php under 'zepto'
//   4. Visit /zepto-oauth.php → Connect → authorize on Zoho

declare(strict_types=1);

// ── Token resolution ──────────────────────────────────────────────────────────

/**
 * Returns the effective token and auth-type for a given scope.
 *
 * Per-site:  uses sites.zepto_api_token  → header "Zoho-enczapikey TOKEN"
 * Global:    uses OAuth access token      → header "Zoho-oauthtoken TOKEN"
 *            auto-refreshes if < 5 minutes from expiry.
 *
 * @return array{token:string|null, type:'oauth'|'enczapikey'|null}
 */
function zepto_token_info(?int $site_id): array
{
    $none = ['token' => null, 'type' => null];

    // Per-site: simple Mail Agent token takes precedence
    if ($site_id !== null) {
        $stmt = db()->prepare('SELECT zepto_api_token FROM sites WHERE id = ?');
        $stmt->execute([$site_id]);
        $tok = $stmt->fetchColumn();
        if ($tok && $tok !== '') {
            return ['token' => $tok, 'type' => 'enczapikey'];
        }
    }

    // Global: OAuth access token from oauth_tokens table
    $row = db()->prepare("SELECT * FROM oauth_tokens WHERE service = 'zepto_global'");
    $row->execute();
    $row = $row->fetch();
    if (!$row) return $none;

    // Auto-refresh if expiring within 5 minutes
    if (strtotime($row['expires_at']) - time() < 300) {
        $refreshed = zepto_refresh_access_token('zepto_global');
        if (!$refreshed) return $none;
        return ['token' => $refreshed, 'type' => 'oauth'];
    }

    return ['token' => $row['access_token'], 'type' => 'oauth'];
}

// ── OAuth token lifecycle ─────────────────────────────────────────────────────

/**
 * POST to Zoho's token endpoint and return the decoded response.
 * Used by both code exchange and refresh flows.
 *
 * @return array{data:array|null, error:string|null}
 */
function zepto_token_request(array $params): array
{
    $url = rtrim(cfg('zepto.accounts_url', 'https://accounts.zoho.com'), '/') . '/oauth/v2/token';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($err) return ['data' => null, 'error' => 'cURL error: ' . $err];

    $json = json_decode((string)$body, true);
    if (!is_array($json)) return ['data' => null, 'error' => 'Non-JSON response (HTTP ' . $code . ')'];
    if (!empty($json['error'])) return ['data' => null, 'error' => $json['error'] . ': ' . ($json['error_description'] ?? '')];

    return ['data' => $json, 'error' => null];
}

/**
 * Exchange a one-time authorization code for access + refresh tokens.
 * Stores the result in the oauth_tokens table as service='zepto_global'.
 *
 * @return array{ok:bool, error:string|null}
 */
function zepto_exchange_code(string $code): array
{
    $result = zepto_token_request([
        'code'          => $code,
        'client_id'     => cfg('zepto.client_id', ''),
        'client_secret' => cfg('zepto.client_secret', ''),
        'redirect_uri'  => cfg('zepto.redirect_uri', ''),
        'grant_type'    => 'authorization_code',
    ]);

    if ($result['error']) return ['ok' => false, 'error' => $result['error']];

    $data = $result['data'];
    if (empty($data['access_token']) || empty($data['refresh_token'])) {
        return ['ok' => false, 'error' => 'OAuth token exchange failed — check client credentials'];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));

    db()->prepare(
        "INSERT INTO oauth_tokens (service, access_token, refresh_token, expires_at)
         VALUES ('zepto_global', ?, ?, ?)
         ON DUPLICATE KEY UPDATE access_token=VALUES(access_token),
             refresh_token=VALUES(refresh_token), expires_at=VALUES(expires_at)"
    )->execute([$data['access_token'], $data['refresh_token'], $expiresAt]);

    return ['ok' => true, 'error' => null];
}

/**
 * Refresh an expired access token using the stored refresh token.
 * Updates the oauth_tokens row and returns the new access token, or null on failure.
 */
function zepto_refresh_access_token(string $service): ?string
{
    $row = db()->prepare('SELECT refresh_token FROM oauth_tokens WHERE service = ?');
    $row->execute([$service]);
    $refreshToken = $row->fetchColumn();
    if (!$refreshToken) return null;

    $result = zepto_token_request([
        'refresh_token' => $refreshToken,
        'client_id'     => cfg('zepto.client_id', ''),
        'client_secret' => cfg('zepto.client_secret', ''),
        'grant_type'    => 'refresh_token',
    ]);

    if ($result['error'] || empty($result['data']['access_token'])) return null;

    $data      = $result['data'];
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));

    db()->prepare(
        "UPDATE oauth_tokens SET access_token = ?, expires_at = ? WHERE service = ?"
    )->execute([$data['access_token'], $expiresAt, $service]);

    return $data['access_token'];
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

/**
 * Perform a GET request against the Zepto Mail API.
 *
 * @param string $auth_type  'oauth' uses "Zoho-oauthtoken"; 'enczapikey' uses "Zoho-enczapikey"
 * @return array{data:array|null, error:string|null}
 */
function zepto_get(string $endpoint, array $params, string $token, string $auth_type = 'oauth'): array
{
    $base = rtrim(cfg('zepto.api_url', 'https://api.zeptomail.com/v1.1/'), '/');
    $url  = rtrim($base . '/' . ltrim($endpoint, '/'), '/');
    if ($params) $url .= '?' . http_build_query($params);

    $authHeader = $auth_type === 'enczapikey'
        ? 'Authorization: Zoho-enczapikey ' . $token
        : 'Authorization: Zoho-oauthtoken ' . $token;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [$authHeader, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($err) return ['data' => null, 'error' => 'cURL error: ' . $err];
    $json = json_decode((string)$body, true);

    if ($code !== 200) {
        // Dump the full Zepto response so errors are always diagnosable
        $detail = is_array($json)
            ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : substr((string)$body, 0, 500);
        return ['data' => null, 'error' => 'HTTP ' . $code . ': ' . $detail];
    }

    if (!is_array($json)) return ['data' => null, 'error' => 'Unexpected non-JSON response'];

    return ['data' => $json, 'error' => null];
}

// ── Date helpers ──────────────────────────────────────────────────────────────

/**
 * Convert a Y-m-d date string to Zepto Mail's required format: "DD/MM/YYYY, hh:mm AM/PM"
 * $time should be "00:00 AM" for start-of-day or "11:59 PM" for end-of-day.
 */
function zepto_format_date(string $date, string $time = '12:00 AM'): string
{
    $d = date_create($date);
    return $d ? date_format($d, 'd/m/Y') . ', ' . $time : $date;
}

/**
 * Parse a date that may be an ISO string or a Unix timestamp into MySQL DATETIME format.
 */
function zepto_parse_date(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return date('Y-m-d H:i:s', (int)$v);
    $t = strtotime((string)$v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

// ── Fetch + cache ─────────────────────────────────────────────────────────────

/**
 * Paginate through any Zepto GET /email endpoint call and return all rows.
 * $baseParams must include date_from/date_to; optional filter keys like is_hb, is_sb, etc.
 *
 * @return array{rows:array, error:string|null}
 */
function zepto_fetch_pages(array $baseParams, string $token, string $auth_type): array
{
    $all = []; $limit = 50; $offset = 0; $total = null;
    do {
        $r = zepto_get('email', array_merge($baseParams, ['offset' => $offset, 'limit' => $limit]), $token, $auth_type);
        if ($r['error']) return ['rows' => $all, 'error' => $r['error']];
        $batch = $r['data']['data']['logs'] ?? [];
        if ($total === null) $total = (int)($r['data']['metadata']['count'] ?? count($batch));
        if (!is_array($batch) || !$batch) break;
        $all    = array_merge($all, $batch);
        $offset += $limit;
    } while (count($all) < $total && $offset <= 2000);
    return ['rows' => $all, 'error' => null];
}

/**
 * Fetch email logs from Zepto Mail API and replace the local cache for this scope.
 *
 * Zepto always returns status="processed" on the unfiltered endpoint. Real delivery
 * outcomes are obtained by repeating the call with is_hb/is_sb/is_mailfailure/is_delivered
 * filters, then overlaying those statuses onto the base result set.
 *
 * @return array{inserted:int, error:string|null}
 */
function zepto_fetch_and_cache(?int $site_id, string $from, string $to): array
{
    $info = zepto_token_info($site_id);
    if (!$info['token']) {
        return ['inserted' => 0, 'error' => 'No Zepto API token configured for this scope'];
    }

    $dateParams = [
        'date_from' => zepto_format_date($from, '12:00 AM'),
        'date_to'   => zepto_format_date($to,   '11:59 PM'),
    ];

    // Base pass — fetches all emails; Zepto always returns status="processed" here
    $base = zepto_fetch_pages($dateParams, $info['token'], $info['type']);
    if ($base['error']) return ['inserted' => 0, 'error' => $base['error']];
    $allRows = $base['rows'];

    // Filter passes — identify real delivery outcomes by request_id.
    // Later entries win (hb overrides sb, etc.).
    $overrides    = [];
    $filterPasses = [
        'is_delivered'   => ['status' => 'delivered', 'bounce_reason' => null],
        'is_mailfailure' => ['status' => 'failed',    'bounce_reason' => null],
        'is_sb'          => ['status' => 'bounced',   'bounce_reason' => 'soft bounce'],
        'is_hb'          => ['status' => 'bounced',   'bounce_reason' => 'hard bounce'],
    ];
    foreach ($filterPasses as $filterKey => $override) {
        $r = zepto_fetch_pages(array_merge($dateParams, [$filterKey => 'true']), $info['token'], $info['type']);
        foreach ($r['rows'] as $item) {   // errors silently skipped — worst case status stays 'sent'
            $rid = $item['request_id'] ?? null;
            if ($rid) $overrides[$rid] = $override;
        }
    }

    // Delete only records within the requested date window so historical data is preserved
    $pdo       = db();
    $fromMysql = date('Y-m-d H:i:s', strtotime($from));
    $toMysql   = date('Y-m-d H:i:s', strtotime($to));
    $pdo->prepare('DELETE FROM zepto_mail_log WHERE site_id IS NULL AND sent_at BETWEEN ? AND ?')
        ->execute([$fromMysql, $toMysql]);

    $validStatuses = ['queued', 'sent', 'delivered', 'bounced', 'opened', 'clicked', 'failed'];

    // Map Zepto status names to our ENUM values
    $statusMap = [
        'processed'   => 'sent',
        'sent'        => 'sent',
        'delivered'   => 'delivered',
        'opened'      => 'opened',
        'clicked'     => 'clicked',
        'bounced'     => 'bounced',
        'hard_bounced'=> 'bounced',
        'soft_bounced'=> 'bounced',
        'failed'      => 'failed',
        'mail_failure'=> 'failed',
        'queued'      => 'queued',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO zepto_mail_log
             (site_id, message_id, recipient, from_address, mailagent_key, subject, status, bounce_reason, sent_at, delivered_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($allRows as $item) {
        $info_block = $item['email_info'] ?? $item;
        $rawStatus  = strtolower((string)($info_block['status'] ?? 'sent'));
        $apiStatus  = $statusMap[$rawStatus] ?? (in_array($rawStatus, $validStatuses, true) ? $rawStatus : 'sent');

        // Apply filter-pass override (hard bounce, soft bounce, failed, delivered)
        $rid      = $item['request_id'] ?? null;
        $override = $rid ? ($overrides[$rid] ?? null) : null;
        $status   = $override['status'] ?? $apiStatus;

        // All recipient addresses joined (email may go to multiple to[] entries)
        $toField    = $info_block['to'] ?? [];
        $addrs      = [];
        if (is_array($toField)) {
            foreach ($toField as $t) {
                $addr = is_array($t) ? ($t['address'] ?? null) : (string)$t;
                if ($addr) $addrs[] = $addr;
            }
        }
        $recipient = $addrs ? mb_substr(implode(', ', $addrs), 0, 255) : null;

        // request_id (root) is the clean unique key; message_id has angle brackets
        $messageId    = $item['request_id'] ?? trim((string)($info_block['message_id'] ?? ''), '<>') ?: null;
        $fromAddress  = $info_block['from']['address'] ?? null;
        $mailagentKey = $info_block['mailagent_key']   ?? null;

        // email_delivery_details may be [] (empty, not yet delivered) or an array
        // of per-recipient delivery event objects when the email IS delivered/bounced
        $deliveryList = $item['email_delivery_details'] ?? [];
        // Normalise: if it's an indexed array of event objects, flatten into first entry
        $deliveryDetails = (isset($deliveryList[0]) && is_array($deliveryList[0]))
            ? $deliveryList[0]
            : (is_array($deliveryList) && !isset($deliveryList[0]) ? $deliveryList : []);

        $deliveredAt  = null;
        foreach (['delivered_time','delivery_time','delivered_at','time_of_delivery'] as $f) {
            if (!empty($deliveryDetails[$f])) {
                $deliveredAt = zepto_parse_date($deliveryDetails[$f]);
                break;
            }
        }

        $bounceReason = $override['bounce_reason']             // 'hard bounce' / 'soft bounce' from filter pass
            ?? $deliveryDetails['bounce_reason']
            ?? $deliveryDetails['bounce_description']
            ?? $deliveryDetails['bounce_detail']
            ?? $deliveryDetails['reason']
            ?? null;

        // Zepto never returns a delivery timestamp in the list endpoint (email_delivery_details is
        // always []). For confirmed-delivered emails, processed_time is a close approximation.
        if ($status === 'delivered' && $deliveredAt === null) {
            $deliveredAt = zepto_parse_date($info_block['processed_time'] ?? null);
        }

        $stmt->execute([
            null,   // site_id always NULL — global account; filter by from_address instead
            $messageId,
            $recipient,
            $fromAddress,
            $mailagentKey,
            mb_substr((string)($info_block['subject'] ?? ''), 0, 500),
            $status,
            $bounceReason,
            zepto_parse_date($info_block['processed_time'] ?? $info_block['sent_at'] ?? null),
            $deliveredAt,
        ]);
    }

    return ['inserted' => count($allRows), 'error' => null];
}

// ── Summary stats ─────────────────────────────────────────────────────────────

/**
 * Aggregate deliverability stats from the local cache.
 * Pass $fromAddress to scope results to a single site's sender address.
 */
function zepto_summary(?string $fromAddress = null): array
{
    $where = 'site_id IS NULL';
    $args  = [];
    if ($fromAddress !== null) {
        $where .= ' AND from_address = ?';
        $args[] = $fromAddress;
    }

    $stmt = db()->prepare(
        "SELECT
            COUNT(*)                                              AS total,
            SUM(status IN ('delivered','opened','clicked'))       AS delivered,
            SUM(status = 'bounced')                               AS bounced,
            SUM(status = 'failed')                                AS failed,
            SUM(status IN ('opened','clicked'))                   AS opened,
            MAX(fetched_at)                                       AS last_fetched
         FROM zepto_mail_log WHERE $where"
    );
    $stmt->execute($args);
    return $stmt->fetch() ?: [
        'total' => 0, 'delivered' => 0, 'bounced' => 0, 'failed' => 0, 'opened' => 0, 'last_fetched' => null,
    ];
}

// ── Connection status helpers ─────────────────────────────────────────────────

/**
 * Returns true if the global OAuth connection is active (token row exists).
 */
function zepto_is_connected(): bool
{
    $stmt = db()->prepare("SELECT id FROM oauth_tokens WHERE service = 'zepto_global' LIMIT 1");
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

/**
 * Returns true if the OAuth credentials (client_id) are configured in config.
 */
function zepto_is_configured(): bool
{
    return cfg('zepto.client_id', '') !== '';
}
