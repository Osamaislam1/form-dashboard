<?php
// public/zepto-oauth.php — Initiate Zoho OAuth flow for Zepto Mail
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/zepto_api.php';
require_admin();

$clientId    = cfg('zepto.client_id', '');
$redirectUri = cfg('zepto.redirect_uri', '');
$accountsUrl = rtrim(cfg('zepto.accounts_url', 'https://accounts.zoho.com'), '/');

// Guard: credentials must be in config before we can start the flow
if ($clientId === '' || $redirectUri === '') {
    $page   = 'Connect Zepto Mail';
    $active = 'mail-deliverability';
    require __DIR__ . '/../src/layout.php';
    ?>
    <div class="page-head"><div><h1>Connect Zepto Mail</h1></div></div>
    <div class="card" style="background:rgba(248,113,113,0.06); border-color:rgba(248,113,113,0.3); max-width:600px;">
        <div style="font-weight:500; margin-bottom:8px; color:var(--err);">OAuth credentials not configured</div>
        <p style="margin:0; color:var(--text-dim); font-size:13px; line-height:1.7;">
            Before connecting, add your Zoho OAuth app credentials to <code>config.local.php</code>:
        </p>
        <pre style="background:var(--bg-3); border:1px solid var(--line-2); border-radius:var(--r); padding:12px 14px; font-size:12px; margin:12px 0 0; overflow-x:auto;">'zepto' => [
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'redirect_uri'  => '<?= e(rtrim(cfg('app.base_url','https://your-dashboard.example.com'),'/')) ?>/zepto-oauth-callback.php',
    'api_url'       => 'https://api.zeptomail.com/v1.1/',
    'accounts_url'  => 'https://accounts.zoho.com',
],</pre>
        <p style="margin:12px 0 0; color:var(--text-dim); font-size:13px; line-height:1.7;">
            Get credentials at <strong>https://api-console.zoho.com/</strong> →
            Add Client → Server-based Applications.<br>
            Set <strong>Authorized Redirect URI</strong> to:<br>
            <code><?= e(rtrim(cfg('app.base_url','https://your-dashboard.example.com'),'/')) ?>/zepto-oauth-callback.php</code>
        </p>
    </div>
    <?php
    require __DIR__ . '/../src/layout_foot.php';
    exit;
}

// Store a CSRF state token in the session to validate the callback
session_start_safe();
$state = bin2hex(random_bytes(16));
$_SESSION['zepto_oauth_state'] = $state;

// Build the Zoho authorization URL
$authUrl = $accountsUrl . '/oauth/v2/auth?' . http_build_query([
    'scope'         => 'Zeptomail.email.READ',
    'client_id'     => $clientId,
    'response_type' => 'code',
    'redirect_uri'  => $redirectUri,
    'access_type'   => 'offline',
    'state'         => $state,
    'prompt'        => 'consent',
]);

header('Location: ' . $authUrl);
exit;
