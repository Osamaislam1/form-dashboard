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
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login · <?= e(cfg('app.name')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body { background: #0f1115; color: #e6e8ee; font-family: 'Geist', sans-serif;
  display: grid; place-items: center; min-height: 100vh; margin: 0; }
.box { background: #161922; border: 1px solid #262b3a; padding: 32px;
  border-radius: 8px; width: 360px; }
.box h1 { font-size: 18px; margin: 0 0 6px; letter-spacing: -0.02em; display:flex; gap:8px; align-items:center; }
.box h1 .dot { width:8px; height:8px; background:#a3e635; border-radius:2px; }
.box .sub { color: #9aa3b2; font-size: 13px; margin-bottom: 24px; }
input { width: 100%; background:#1d2230; border:1px solid #323849; color:#e6e8ee;
  padding: 9px 12px; border-radius: 6px; font: inherit; box-sizing: border-box; margin-bottom: 12px; }
button { width: 100%; padding: 10px; background:#a3e635; color:#0a1206;
  border: none; border-radius: 6px; font: inherit; font-weight: 500; cursor: pointer; }
.err { background: rgba(248,113,113,0.1); border:1px solid rgba(248,113,113,0.3);
  color:#f87171; padding: 9px 12px; border-radius: 6px; margin-bottom: 14px; font-size: 13px;}
label { font-size: 12px; color: #9aa3b2; margin-bottom: 4px; display: block; }
</style>
</head>
<body>
<form class="box" method="post">
<h1><span class="dot"></span><?= e(cfg('app.name')) ?></h1>
<p class="sub">Sign in to continue</p>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
<label>Email</label>
<input type="email" name="email" required autofocus>
<label>Password</label>
<input type="password" name="password" required>
<button type="submit">Sign in</button>
</form>
</body></html>
