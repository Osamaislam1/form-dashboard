<?php
// src/layout.php
require_once __DIR__ . '/bootstrap.php';
$user = current_user();
$page = $page ?? 'Dashboard';
$active = $active ?? 'overview';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($page) ?> · <?= e(cfg('app.name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Dark theme (default) ── */
:root {
    --bg:         #0f1115;
    --bg-2:       #161922;
    --bg-3:       #1d2230;
    --line:       #262b3a;
    --line-2:     #323849;
    --text:       #e6e8ee;
    --text-dim:   #9aa3b2;
    --text-faint: #5e6677;
    --accent:     #a3e635;
    --accent-dim: #65a30d;
    --warn:       #fbbf24;
    --err:        #f87171;
    --ok:         #34d399;
    --r:          6px;
    --t:          0.15s;
}

/* ── Light theme override ── */
[data-theme="light"] {
    --bg:         #f5f6f8;
    --bg-2:       #ffffff;
    --bg-3:       #eef0f4;
    --line:       #dde0e8;
    --line-2:     #c8cdd8;
    --text:       #111318;
    --text-dim:   #4b5263;
    --text-faint: #9aa3b2;
    --accent:     #5a9e00;
    --accent-dim: #3d6d00;
    --warn:       #b45309;
    --err:        #dc2626;
    --ok:         #059669;
}

* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: 'Geist', -apple-system, system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    transition: background-color var(--t), color var(--t);
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
code, pre, .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }

/* ── App layout ── */
.app { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }

/* ── Mobile top bar (hidden on desktop) ── */
.mobile-bar { display: none; }
.hamburger {
    background: none; border: none; color: var(--text);
    cursor: pointer; padding: 8px; border-radius: var(--r); line-height: 0;
    min-width: 40px; min-height: 40px;
    display: flex; align-items: center; justify-content: center;
}
.hamburger:hover { background: var(--bg-3); }

/* Sidebar close button (× inside sidebar, mobile only) */
.sidebar-close {
    display: none;
    position: absolute; top: 14px; right: 14px;
    background: none; border: none; color: var(--text-dim);
    cursor: pointer; padding: 6px; border-radius: var(--r); line-height: 0;
    font-size: 20px; font-weight: 300; line-height: 1;
    transition: color var(--t), background var(--t);
}
.sidebar-close:hover { color: var(--text); background: var(--bg-3); }

/* Backdrop overlay (mobile only, hidden by default) */
.sidebar-overlay {
    display: none;
    position: fixed; inset: 0; z-index: 150;
    background: rgba(0,0,0,0.45);
    opacity: 0;
    transition: opacity 0.22s ease;
    pointer-events: none;
}
.sidebar-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

/* ── Sidebar ── */
.sidebar {
    background: var(--bg-2);
    border-right: 1px solid var(--line);
    padding: 18px 0;
    position: sticky; top: 0; height: 100vh;
    overflow-y: auto;
    transition: background-color var(--t), border-color var(--t);
}
.brand {
    padding: 0 20px 20px;
    font-weight: 700;
    font-size: 15px;
    letter-spacing: -0.01em;
    display: flex; align-items: center; gap: 8px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 14px;
    transition: border-color var(--t);
}
.brand .dot { width: 8px; height: 8px; background: var(--accent); border-radius: 2px; transition: background-color var(--t); }

/* ── Navigation ── */
.nav { list-style: none; padding: 0; margin: 0; }
.nav li a {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 20px;
    color: var(--text-dim);
    font-size: 13px;
    border-left: 2px solid transparent;
    min-height: 40px;
    -webkit-tap-highlight-color: transparent;
    transition: background-color var(--t), color var(--t), border-left-color var(--t);
}
.nav li a:hover { color: var(--text); background: var(--bg-3); text-decoration: none; }
.nav li a.active {
    color: var(--text); background: var(--bg-3);
    border-left-color: var(--accent);
}
.nav .section {
    padding: 14px 20px 6px;
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--text-faint);
}
.nav-icon { width: 16px; height: 16px; flex-shrink: 0; opacity: 0.65; }
.nav li a:hover .nav-icon, .nav li a.active .nav-icon { opacity: 1; }

/* ── User bar ── */
.user-bar {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 12px 20px; border-top: 1px solid var(--line);
    background: var(--bg-2);
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px;
    transition: background-color var(--t), border-color var(--t);
}
.user-bar .who { color: var(--text-dim); }
.user-bar a { color: var(--text-dim); }
.theme-btn {
    background: none; border: 1px solid var(--line-2); border-radius: var(--r);
    color: var(--text-dim); cursor: pointer; font-size: 14px; padding: 3px 8px; line-height: 1;
    transition: background var(--t), color var(--t), border-color var(--t);
}
.theme-btn:hover { background: var(--bg-3); color: var(--text); }

/* ── Main content ── */
.main { padding: 28px 36px 60px; max-width: 1400px; }
.page-head {
    display: flex; justify-content: space-between; align-items: flex-end;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 16px;
    transition: border-color var(--t);
}
.page-head h1 {
    font-size: 22px; font-weight: 600; margin: 0;
    letter-spacing: -0.02em;
}
.page-head .sub { color: var(--text-dim); margin-top: 4px; font-size: 13px; }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    background: var(--bg-3); color: var(--text);
    border: 1px solid var(--line-2);
    border-radius: var(--r);
    font: inherit; font-size: 13px;
    cursor: pointer; min-height: 34px; white-space: nowrap;
    transition: background var(--t), border-color var(--t), box-shadow var(--t), color var(--t);
}
.btn:hover { border-color: var(--text-faint); text-decoration: none; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.btn.primary {
    background: var(--accent); color: #0a1206; border-color: var(--accent);
    font-weight: 500;
}
.btn.primary:hover { filter: brightness(1.1); box-shadow: 0 1px 8px rgba(163,230,53,0.25); }
[data-theme="light"] .btn.primary:hover { box-shadow: 0 1px 8px rgba(90,158,0,0.25); }
.btn.danger { color: var(--err); border-color: rgba(248,113,113,0.3); }
.btn.danger:hover { background: rgba(248,113,113,0.08); }

/* ── Cards ── */
.cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
.card {
    background: var(--bg-2);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 16px 18px;
    transition: background-color var(--t), border-color var(--t), box-shadow var(--t), transform var(--t);
}
.card:hover {
    border-color: var(--line-2);
    box-shadow: 0 2px 12px rgba(0,0,0,0.12);
    transform: translateY(-1px);
}
[data-theme="light"] .card:hover { box-shadow: 0 2px 16px rgba(0,0,0,0.07); }
.card .label {
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--text-faint); margin-bottom: 8px;
}
.card .value { font-size: 26px; font-weight: 600; letter-spacing: -0.02em; }
.card .delta { font-size: 12px; color: var(--text-dim); margin-top: 4px; }

/* ── Tables ── */
table.t {
    width: 100%; border-collapse: collapse;
    background: var(--bg-2);
    border: 1px solid var(--line);
    border-radius: var(--r);
    overflow: hidden;
    transition: background-color var(--t), border-color var(--t);
}
table.t th, table.t td {
    text-align: left; padding: 10px 14px;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    transition: background-color var(--t), border-color var(--t);
}
table.t th {
    background: var(--bg-3);
    font-weight: 500; font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--text-dim);
}
table.t tr:last-child td { border-bottom: none; }
table.t tr:hover td { background: rgba(127,127,127,0.04); }

/* ── Pills ── */
.pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    background: var(--bg-3);
    border: 1px solid var(--line-2);
    color: var(--text-dim);
}
.pill.ok  { color: var(--ok);   border-color: rgba(52,211,153,0.3); }
.pill.warn { color: var(--warn); border-color: rgba(251,191,36,0.3); }
.pill.err  { color: var(--err);  border-color: rgba(248,113,113,0.3); }
.pill.plugin-forminator { color: #c084fc; border-color: rgba(192,132,252,0.3); }
.pill.plugin-cf7        { color: #60a5fa; border-color: rgba(96,165,250,0.3); }
.pill.plugin-gravity    { color: #fb923c; border-color: rgba(251,146,60,0.3); }
.pill.plugin-wpforms    { color: #f472b6; border-color: rgba(244,114,182,0.3); }
.pill.plugin-fluent     { color: #34d399; border-color: rgba(52,211,153,0.3); }
.pill.plugin-elementor  { color: #f87171; border-color: rgba(248,113,113,0.3); }

/* ── Form inputs ── */
input[type=text], input[type=email], input[type=password], input[type=url], input[type=date], select, textarea {
    background: var(--bg-3);
    border: 1px solid var(--line-2);
    color: var(--text);
    padding: 8px 12px; border-radius: var(--r);
    font: inherit; font-size: 13px;
    width: 100%;
    transition: background-color var(--t), border-color var(--t), box-shadow var(--t), color var(--t);
}
input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(163,230,53,0.15);
}
[data-theme="light"] input:focus,
[data-theme="light"] select:focus,
[data-theme="light"] textarea:focus {
    box-shadow: 0 0 0 3px rgba(90,158,0,0.12);
}
label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 5px; }
.field { margin-bottom: 14px; }

/* ── Toolbar ── */
.toolbar {
    display: flex; gap: 10px; margin-bottom: 16px;
    align-items: center; flex-wrap: wrap;
}
.toolbar .grow { flex: 1; min-width: 140px; }

/* ── Flash messages ── */
.flash {
    padding: 10px 14px; margin-bottom: 16px; border-radius: var(--r);
    border: 1px solid var(--line-2);
    background: var(--bg-2);
    display: flex; align-items: center; gap: 8px;
    font-size: 13px;
    transition: background-color var(--t), border-color var(--t);
}
.flash.success { border-color: rgba(52,211,153,0.4); color: var(--ok); background: rgba(52,211,153,0.06); }
.flash.error   { border-color: rgba(248,113,113,0.4); color: var(--err); background: rgba(248,113,113,0.06); }
[data-theme="light"] .flash.success { background: rgba(5,150,105,0.06); }
[data-theme="light"] .flash.error   { background: rgba(220,38,38,0.06); }

/* ── Empty state ── */
.empty {
    padding: 50px 20px; text-align: center;
    color: var(--text-faint); font-size: 13px;
    border: 1px dashed var(--line-2); border-radius: var(--r);
}

/* ── Chart ── */
.chart {
    background: var(--bg-2);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 18px; margin-bottom: 24px;
    transition: background-color var(--t), border-color var(--t);
}
.chart h3 {
    margin: 0 0 14px; font-size: 13px; font-weight: 500;
    color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.06em;
}

/* ── Key-value grid ── */
.kvgrid { display: grid; grid-template-columns: 160px 1fr; gap: 6px 16px; }
.kvgrid .k { color: var(--text-dim); font-size: 12px; padding-top: 2px; }
.kvgrid .v { font-size: 13px; word-break: break-word; }

/* ── Pagination ── */
.pager { display: flex; gap: 8px; margin-top: 14px; justify-content: flex-end; flex-wrap: wrap; }
.pager a, .pager span {
    padding: 4px 10px; font-size: 12px;
    border: 1px solid var(--line-2); border-radius: var(--r);
    color: var(--text-dim);
    transition: background-color var(--t), border-color var(--t);
}
.pager span.current { background: var(--bg-3); color: var(--text); }

/* ── Responsive: tablet (880px) ── */
@media (max-width: 880px) {
    .cards { grid-template-columns: repeat(2, 1fr); }
    .main  { padding: 20px 24px 60px; }
}

/* ── Responsive: mobile (768px) — off-canvas sidebar ── */
@media (max-width: 768px) {
    .app { grid-template-columns: 1fr; }

    .mobile-bar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 8px 0 16px;
        height: 52px;
        background: var(--bg-2); border-bottom: 1px solid var(--line);
        position: sticky; top: 0; z-index: 100;
        transition: background-color var(--t), border-color var(--t);
    }
    .mobile-bar .brand { padding: 0; margin: 0; border: none; font-size: 14px; }

    .sidebar {
        position: fixed; top: 0; left: 0; bottom: 0; width: 272px; height: 100vh;
        transform: translateX(-100%);
        transition: transform 0.24s cubic-bezier(0.4,0,0.2,1),
                    background-color var(--t), border-color var(--t);
        z-index: 200;
        padding-top: 0;
        overflow-y: auto;
    }
    .sidebar.open {
        transform: translateX(0);
        box-shadow: 6px 0 40px rgba(0,0,0,0.4);
    }

    .sidebar-close { display: flex; align-items: center; justify-content: center; }
    .sidebar-overlay { display: block; }

    .main { padding: 16px 16px 60px; }

    .toolbar input, .toolbar select { min-width: 120px; }

    /* Tables scroll horizontally on narrow screens */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table.t { min-width: 560px; }
}

/* ── Responsive: small mobile (480px) ── */
@media (max-width: 480px) {
    .cards { grid-template-columns: 1fr; }
    .page-head { flex-direction: column; align-items: flex-start; gap: 10px; }
    .page-head > .btn { align-self: flex-start; }
}
</style>
<script>
/* Anti-FOUT: apply stored/system theme before first paint */
(function(){
    var t=localStorage.getItem('fdash_theme');
    if(t==='light') document.documentElement.setAttribute('data-theme','light');
    else if(!t&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)
        document.documentElement.setAttribute('data-theme','light');
})();
</script>
</head>
<body>
<div class="app">
    <!-- Backdrop overlay — closes sidebar when tapped -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Mobile top bar -->
    <div class="mobile-bar">
        <div class="brand"><span class="dot"></span><?= e(cfg('app.name')) ?></div>
        <button class="hamburger" data-sidebar-toggle aria-label="Open menu">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="3" y1="6"  x2="19" y2="6"/>
                <line x1="3" y1="11" x2="19" y2="11"/>
                <line x1="3" y1="16" x2="19" y2="16"/>
            </svg>
        </button>
    </div>

    <aside class="sidebar" id="sidebar">
        <!-- Close button inside sidebar (mobile) -->
        <button class="sidebar-close" id="sidebar-close" aria-label="Close menu">&#x2715;</button>
        <div class="brand"><span class="dot"></span><?= e(cfg('app.name')) ?></div>
        <ul class="nav">
            <li><a href="/index.php" class="<?= $active==='overview'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/>
                    <rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/>
                </svg>
                Overview
            </a></li>
            <li><a href="/sites.php" class="<?= $active==='sites'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <circle cx="8" cy="8" r="6"/>
                    <path d="M2 8h12M8 2c-2 2-2.5 3.6-2.5 6S6 12 8 14M8 2c2 2 2.5 3.6 2.5 6S10 12 8 14"/>
                </svg>
                Sites
            </a></li>
            <li><a href="/forms.php" class="<?= $active==='forms'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <rect x="2" y="1" width="12" height="14" rx="1"/>
                    <line x1="5" y1="5"  x2="11" y2="5"/>
                    <line x1="5" y1="8"  x2="11" y2="8"/>
                    <line x1="5" y1="11" x2="8"  y2="11"/>
                </svg>
                Forms
            </a></li>
            <li><a href="/submissions.php" class="<?= $active==='submissions'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="3" width="14" height="10" rx="1"/>
                    <path d="M1 5l7 5 7-5"/>
                </svg>
                Submissions
            </a></li>
            <li><a href="/monthly.php" class="<?= $active==='monthly'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <rect x="2" y="3" width="12" height="11" rx="1"/>
                    <line x1="5" y1="1" x2="5" y2="5"/>
                    <line x1="11" y1="1" x2="11" y2="5"/>
                    <line x1="2" y1="7" x2="14" y2="7"/>
                </svg>
                Monthly
            </a></li>
            <li class="section">Settings</li>
            <li><a href="/alerts.php" class="<?= $active==='alerts'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 1a5 5 0 015 5c0 3.5 1.5 5 1.5 5h-13S3 9.5 3 6a5 5 0 015-5z"/>
                    <path d="M6.5 13a1.5 1.5 0 003 0"/>
                </svg>
                Email alerts
            </a></li>
            <li><a href="/email-health.php" class="<?= $active==='email-health'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="3" width="14" height="10" rx="1"/>
                    <path d="M1 5l7 5 7-5"/>
                    <path d="M6 10l1.5 1.5L10 8"/>
                </svg>
                Email health
            </a></li>
            <li><a href="/webhook-log.php" class="<?= $active==='webhook-log'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1,11 4,6 7,9 10,4 13,7 15,5"/>
                </svg>
                Webhook log
            </a></li>
            <?php if ($user && $user['role']==='admin'): ?>
            <li><a href="/users.php" class="<?= $active==='users'?'active':'' ?>">
                <svg class="nav-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="8" cy="5" r="3"/>
                    <path d="M1 14c0-3.3 3.1-6 7-6s7 2.7 7 6"/>
                </svg>
                Users
            </a></li>
            <?php endif; ?>
        </ul>
        <?php if ($user): ?>
        <div class="user-bar">
            <span class="who"><?= e($user['name']) ?></span>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="theme-btn" data-theme-toggle aria-label="Toggle theme" title="Toggle light/dark">
                    <span class="theme-icon">☀</span>
                </button>
                <a href="/logout.php">Logout</a>
            </div>
        </div>
        <?php endif; ?>
    </aside>
    <main class="main">
        <?php foreach (flash_pull() as $f): ?>
            <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
