<?php
require __DIR__ . '/../src/bootstrap.php';
session_start_safe();

if (current_user()) redirect('/index.php');

// Ensure rate-limit table exists (self-bootstrapping, no manual migration needed)
db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Purge attempts older than 15 minutes, then count recent ones for this IP
    db()->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->execute();
    $attStmt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ?");
    $attStmt->execute([$ip]);
    $recentAttempts = (int)$attStmt->fetchColumn();

    if ($recentAttempts >= 10) {
        $err = 'Too many failed login attempts from your IP. Please wait 15 minutes and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $stmt  = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, $u['password_hash'])) {
            // Clear failed attempts on successful login
            db()->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            redirect('/index.php');
        }
        // Record the failed attempt
        db()->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
        $err = 'Invalid email or password';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login · <?= e(cfg('app.name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    --accent:     #a3e635;
    --err:        #f87171;
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
    --accent:     #5a9e00;
    --err:        #dc2626;
}

* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: 'Geist', -apple-system, system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    display: grid; place-items: center;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    transition: background-color var(--t), color var(--t);
}

.box {
    background: var(--bg-2);
    border: 1px solid var(--line);
    padding: 36px;
    border-radius: 10px;
    width: min(400px, calc(100vw - 32px));
    box-shadow: 0 4px 32px rgba(0,0,0,0.18);
    transition: background-color var(--t), border-color var(--t), box-shadow var(--t);
}
[data-theme="light"] .box { box-shadow: 0 4px 32px rgba(0,0,0,0.07); }

.login-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 6px;
}
.box h1 {
    font-size: 18px; margin: 0;
    letter-spacing: -0.02em;
    display: flex; gap: 8px; align-items: center;
}
.box h1 .dot { width: 8px; height: 8px; background: var(--accent); border-radius: 2px; transition: background-color var(--t); }
.box .sub { color: var(--text-dim); font-size: 13px; margin-bottom: 24px; margin-top: 6px; }

.theme-btn {
    background: none; border: 1px solid var(--line-2); border-radius: var(--r);
    color: var(--text-dim); cursor: pointer; font-size: 14px; padding: 3px 8px; line-height: 1;
    transition: background var(--t), color var(--t), border-color var(--t);
}
.theme-btn:hover { background: var(--bg-3); color: var(--text); }

.field { margin-bottom: 14px; }
label { font-size: 12px; color: var(--text-dim); margin-bottom: 4px; display: block; }

input[type=email], input[type=password] {
    width: 100%;
    background: var(--bg-3);
    border: 1px solid var(--line-2);
    color: var(--text);
    padding: 10px 13px;
    border-radius: var(--r);
    font: inherit; font-size: 14px;
    transition: background-color var(--t), border-color var(--t), box-shadow var(--t), color var(--t);
}
input:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(163,230,53,0.15);
}
[data-theme="light"] input:focus { box-shadow: 0 0 0 3px rgba(90,158,0,0.12); }

button[type=submit] {
    width: 100%; padding: 11px;
    background: var(--accent); color: #0a1206;
    border: none; border-radius: var(--r);
    font: inherit; font-size: 14px; font-weight: 600;
    cursor: pointer; margin-top: 4px;
    transition: filter var(--t), box-shadow var(--t);
}
button[type=submit]:hover {
    filter: brightness(1.08);
    box-shadow: 0 2px 12px rgba(163,230,53,0.3);
}
[data-theme="light"] button[type=submit]:hover { box-shadow: 0 2px 12px rgba(90,158,0,0.25); }

.err {
    background: rgba(248,113,113,0.08);
    border: 1px solid rgba(248,113,113,0.3);
    color: var(--err);
    padding: 10px 13px; border-radius: var(--r);
    margin-bottom: 16px; font-size: 13px;
    transition: background-color var(--t), color var(--t);
}
[data-theme="light"] .err { background: rgba(220,38,38,0.06); }
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
<form class="box" method="post">
    <div class="login-header">
        <h1><span class="dot"></span><?= e(cfg('app.name')) ?></h1>
        <button type="button" class="theme-btn" data-theme-toggle aria-label="Toggle theme" title="Toggle light/dark">
            <span class="theme-icon">☀</span>
        </button>
    </div>
    <p class="sub">Sign in to continue</p>
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="field">
        <label>Email</label>
        <input type="email" name="email" required autofocus autocomplete="email">
    </div>
    <div class="field">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit">Sign in</button>
</form>
<script>
(function(){
    var KEY='fdash_theme', html=document.documentElement;
    function applyTheme(t){
        t==='light'
            ? html.setAttribute('data-theme','light')
            : html.removeAttribute('data-theme');
    }
    /* Sync icon to active theme */
    var icon=document.querySelector('.theme-icon');
    if(icon) icon.textContent=html.getAttribute('data-theme')==='light'?'☽':'☀';

    document.addEventListener('click',function(e){
        if(!e.target.closest('[data-theme-toggle]')) return;
        var cur=html.getAttribute('data-theme')==='light'?'light':'dark';
        var next=cur==='light'?'dark':'light';
        applyTheme(next);
        localStorage.setItem(KEY,next);
        var ic=document.querySelector('.theme-icon');
        if(ic) ic.textContent=next==='light'?'☽':'☀';
    });
})();
</script>
</body>
</html>
