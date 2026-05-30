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
:root {
    --bg: #0f1115;
    --bg-2: #161922;
    --bg-3: #1d2230;
    --line: #262b3a;
    --line-2: #323849;
    --text: #e6e8ee;
    --text-dim: #9aa3b2;
    --text-faint: #5e6677;
    --accent: #a3e635;
    --accent-dim: #65a30d;
    --warn: #fbbf24;
    --err: #f87171;
    --ok: #34d399;
    --r: 6px;
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
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
code, pre, .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }

.app { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }
.sidebar {
    background: var(--bg-2);
    border-right: 1px solid var(--line);
    padding: 18px 0;
    position: sticky; top: 0; height: 100vh;
    overflow-y: auto;
}
.brand {
    padding: 0 20px 20px;
    font-weight: 700;
    font-size: 15px;
    letter-spacing: -0.01em;
    display: flex; align-items: center; gap: 8px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 14px;
}
.brand .dot { width: 8px; height: 8px; background: var(--accent); border-radius: 2px; }
.nav { list-style: none; padding: 0; margin: 0; }
.nav li a {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 20px;
    color: var(--text-dim);
    font-size: 13px;
    border-left: 2px solid transparent;
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

.user-bar {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 14px 20px; border-top: 1px solid var(--line);
    background: var(--bg-2);
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px;
}
.user-bar .who { color: var(--text-dim); }
.user-bar a { color: var(--text-dim); }

.main { padding: 28px 36px 60px; max-width: 1400px; }
.page-head {
    display: flex; justify-content: space-between; align-items: flex-end;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 16px;
}
.page-head h1 {
    font-size: 22px; font-weight: 600; margin: 0;
    letter-spacing: -0.02em;
}
.page-head .sub { color: var(--text-dim); margin-top: 4px; font-size: 13px; }

.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    background: var(--bg-3); color: var(--text);
    border: 1px solid var(--line-2);
    border-radius: var(--r);
    font: inherit; font-size: 13px;
    cursor: pointer;
    transition: background .12s;
}
.btn:hover { background: #252a3a; text-decoration: none; }
.btn.primary {
    background: var(--accent); color: #0a1206; border-color: var(--accent);
    font-weight: 500;
}
.btn.primary:hover { background: #b8f04d; }
.btn.danger { color: var(--err); border-color: rgba(248,113,113,0.3); }

.cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
.card {
    background: var(--bg-2);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 16px 18px;
}
.card .label {
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--text-faint); margin-bottom: 8px;
}
.card .value { font-size: 26px; font-weight: 600; letter-spacing: -0.02em; }
.card .delta { font-size: 12px; color: var(--text-dim); margin-top: 4px; }

table.t {
    width: 100%; border-collapse: collapse;
    background: var(--bg-2);
    border: 1px solid var(--line);
    border-radius: var(--r);
    overflow: hidden;
}
table.t th, table.t td {
    text-align: left; padding: 10px 14px;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
}
table.t th {
    background: var(--bg-3);
    font-weight: 500; font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--text-dim);
}
table.t tr:last-child td { border-bottom: none; }
table.t tr:hover td { background: rgba(255,255,255,0.02); }

.pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    background: var(--bg-3);
    border: 1px solid var(--line-2);
    color: var(--text-dim);
}
.pill.ok { color: var(--ok); border-color: rgba(52,211,153,0.3); }
.pill.warn { color: var(--warn); border-color: rgba(251,191,36,0.3); }
.pill.err { color: var(--err); border-color: rgba(248,113,113,0.3); }
.pill.plugin-forminator { color: #c084fc; border-color: rgba(192,132,252,0.3); }
.pill.plugin-cf7 { color: #60a5fa; border-color: rgba(96,165,250,0.3); }
.pill.plugin-gravity { color: #fb923c; border-color: rgba(251,146,60,0.3); }
.pill.plugin-wpforms { color: #f472b6; border-color: rgba(244,114,182,0.3); }
.pill.plugin-fluent { color: #34d399; border-color: rgba(52,211,153,0.3); }
.pill.plugin-elementor { color: #f87171; border-color: rgba(248,113,113,0.3); }

input[type=text], input[type=email], input[type=password], input[type=url], select, textarea {
    background: var(--bg-3);
    border: 1px solid var(--line-2);
    color: var(--text);
    padding: 8px 12px; border-radius: var(--r);
    font: inherit; font-size: 13px;
    width: 100%;
}
input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--accent);
}
label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 5px; }
.field { margin-bottom: 14px; }

.toolbar {
    display: flex; gap: 10px; margin-bottom: 16px;
    align-items: center;
}
.toolbar .grow { flex: 1; }

.flash {
    padding: 10px 14px; margin-bottom: 16px; border-radius: var(--r);
    border: 1px solid var(--line-2);
    background: var(--bg-2);
}
.flash.success { border-color: rgba(52,211,153,0.4); color: var(--ok); }
.flash.error { border-color: rgba(248,113,113,0.4); color: var(--err); }

.empty {
    padding: 50px 20px; text-align: center;
    color: var(--text-faint); font-size: 13px;
    border: 1px dashed var(--line-2); border-radius: var(--r);
}

.chart {
    background: var(--bg-2);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 18px; margin-bottom: 24px;
}
.chart h3 {
    margin: 0 0 14px; font-size: 13px; font-weight: 500;
    color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.06em;
}

.kvgrid { display: grid; grid-template-columns: 160px 1fr; gap: 6px 16px; }
.kvgrid .k { color: var(--text-dim); font-size: 12px; padding-top: 2px; }
.kvgrid .v { font-size: 13px; word-break: break-word; }

.pager { display: flex; gap: 8px; margin-top: 14px; justify-content: flex-end; }
.pager a, .pager span {
    padding: 4px 10px; font-size: 12px;
    border: 1px solid var(--line-2); border-radius: var(--r);
    color: var(--text-dim);
}
.pager span.current { background: var(--bg-3); color: var(--text); }

@media (max-width: 880px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; }
    .cards { grid-template-columns: repeat(2, 1fr); }
    .main { padding: 20px; }
}
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand"><span class="dot"></span> <?= e(cfg('app.name')) ?></div>
        <ul class="nav">
            <li><a href="/index.php" class="<?= $active==='overview'?'active':'' ?>">Overview</a></li>
            <li><a href="/sites.php" class="<?= $active==='sites'?'active':'' ?>">Sites</a></li>
            <li><a href="/forms.php" class="<?= $active==='forms'?'active':'' ?>">Forms</a></li>
            <li><a href="/submissions.php" class="<?= $active==='submissions'?'active':'' ?>">Submissions</a></li>
            <li><a href="/monthly.php" class="<?= $active==='monthly'?'active':'' ?>">Monthly</a></li>
            <li class="section">Settings</li>
            <li><a href="/alerts.php" class="<?= $active==='alerts'?'active':'' ?>">Email alerts</a></li>
            <li><a href="/email-health.php" class="<?= $active==='email-health'?'active':'' ?>">Email health</a></li>
            <li><a href="/webhook-log.php" class="<?= $active==='webhook-log'?'active':'' ?>">Webhook log</a></li>
            <?php if ($user && $user['role']==='admin'): ?>
            <li><a href="/users.php" class="<?= $active==='users'?'active':'' ?>">Users</a></li>
            <?php endif; ?>
        </ul>
        <?php if ($user): ?>
        <div class="user-bar">
            <span class="who"><?= e($user['name']) ?></span>
            <a href="/logout.php">Logout</a>
        </div>
        <?php endif; ?>
    </aside>
    <main class="main">
        <?php foreach (flash_pull() as $f): ?>
            <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
