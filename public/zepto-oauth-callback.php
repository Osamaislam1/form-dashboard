<?php
// public/zepto-oauth-callback.php — Zoho OAuth callback for Zepto Mail
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/zepto_api.php';
require_admin();
session_start_safe();

// Zoho sends ?error= if the user denied access
if (!empty($_GET['error'])) {
    flash('Zoho authorization denied: ' . htmlspecialchars($_GET['error']), 'error');
    redirect('/mail-deliverability.php');
}

// Validate state to prevent CSRF
$expectedState = $_SESSION['zepto_oauth_state'] ?? '';
$receivedState = $_GET['state'] ?? '';
unset($_SESSION['zepto_oauth_state']);

if ($expectedState === '' || !hash_equals($expectedState, $receivedState)) {
    flash('OAuth state mismatch — possible CSRF. Please try connecting again.', 'error');
    redirect('/mail-deliverability.php');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    flash('No authorization code received from Zoho.', 'error');
    redirect('/mail-deliverability.php');
}

// Exchange authorization code for access + refresh tokens
$result = zepto_exchange_code($code);

if (!$result['ok']) {
    flash('Failed to connect Zepto Mail: ' . $result['error'], 'error');
    redirect('/mail-deliverability.php');
}

flash('Zepto Mail connected successfully. Click Refresh to pull delivery logs.', 'success');
redirect('/mail-deliverability.php');
