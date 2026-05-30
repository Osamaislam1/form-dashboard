<?php
// install.php - can be run via CLI (php install.php) or via browser.
// Creates the schema (idempotent) and sets up the first admin user.

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');
$error = null;
$success = null;

try {
    $pdo = db();
} catch (PDOException $e) {
    $error = "Database connection failed: " . $e->getMessage();
    if ($isCli) {
        exit("\nERROR: $error\nPlease check your config.php or config.local.php settings.\n\n");
    }
}

// 1. Run Schema (Idempotent)
if (!$error) {
    try {
        $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
        $success = "Database schema is ready.";

        // Migrations for existing installs (idempotent)
        $migrations = [
            "ALTER TABLE sites ADD COLUMN email_status ENUM('unknown','ok','fail') NOT NULL DEFAULT 'unknown' AFTER status",
            "ALTER TABLE sites ADD COLUMN email_checked_at DATETIME NULL AFTER email_status",
            "ALTER TABLE sites ADD COLUMN email_error VARCHAR(500) NULL AFTER email_checked_at",
            "ALTER TABLE alert_rules ADD COLUMN type ENUM('submission','email_health') NOT NULL DEFAULT 'submission' AFTER name",
        ];
        foreach ($migrations as $m) {
            try { $pdo->exec($m); } catch (Exception $e) { /* column already exists */ }
        }

    } catch (Exception $e) {
        $error = "Schema setup failed: " . $e->getMessage();
    }
}

// 2. Handle Admin Creation
if (!$error) {
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    
    if ($isCli) {
        // CLI Flow
        echo "Form Dashboard installer\n========================\n\n";
        echo "✓ Schema ready\n";

        if ($userCount > 0) {
            echo "✓ At least one user already exists ($userCount). Skipping admin creation.\n";
            exit(0);
        }

        echo "\nCreate the first admin user:\n";
        echo "Email:  "; $email = trim((string)fgets(STDIN));
        echo "Name:   "; $name  = trim((string)fgets(STDIN));
        echo "Password (8+ chars): "; 
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            system('stty -echo'); $pass = trim((string)fgets(STDIN)); system('stty echo');
        } else {
            $pass = trim((string)fgets(STDIN));
        }
        echo "\n";

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            exit("Invalid email or password too short (min 8 chars).\n");
        }

        $pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, "admin")')
            ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name ?: $email]);

        echo "✓ Admin user created. You can now log in at " . cfg('app.base_url') . "/login.php\n";
        exit(0);
    } else {
        // Web Flow
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userCount === 0) {
            $email = $_POST['email'] ?? '';
            $name = $_POST['name'] ?? '';
            $pass = $_POST['pass'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                $error = "Invalid email or password too short (min 8 characters).";
            } else {
                $pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, "admin")')
                    ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name ?: $email]);
                $success = "Admin user created successfully! You can now log in.";
                $userCount = 1;
            }
        }
    }
}

// --- Web UI ---
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Installer · Form Dashboard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f1115; --bg-2: #161922; --bg-3: #1d2230;
            --line: #262b3a; --text: #e6e8ee; --text-dim: #9aa3b2;
            --accent: #a3e635; --err: #f87171; --ok: #34d399;
        }
        body {
            font-family: 'Geist', sans-serif; background: var(--bg); color: var(--text);
            display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0;
        }
        .box {
            background: var(--bg-2); border: 1px solid var(--line); border-radius: 8px;
            padding: 40px; width: 100%; max-width: 450px; box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }
        h1 { font-size: 24px; margin: 0 0 10px; letter-spacing: -0.02em; }
        p { color: var(--text-dim); font-size: 14px; margin-bottom: 30px; }
        .alert { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; border: 1px solid transparent; }
        .alert-err { background: rgba(248,113,113,0.1); border-color: rgba(248,113,113,0.2); color: var(--err); }
        .alert-ok { background: rgba(52,211,153,0.1); border-color: rgba(52,211,153,0.2); color: var(--ok); }
        label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 6px; }
        input {
            width: 100%; padding: 10px 12px; background: var(--bg-3); border: 1px solid var(--line);
            border-radius: 6px; color: #fff; font-size: 14px; margin-bottom: 16px;
        }
        input:focus { outline: none; border-color: var(--accent); }
        .btn {
            width: 100%; padding: 12px; background: var(--accent); color: #000; border: none;
            border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px;
        }
        .btn:hover { background: #b8f04d; }
        .footer { margin-top: 20px; text-align: center; font-size: 13px; }
        .footer a { color: var(--accent); text-decoration: none; }
    </style>
</head>
<body>
<div class="box">
    <h1>Form Dashboard</h1>
    <p>System Installation & Setup</p>

    <?php if ($error): ?>
        <div class="alert alert-err"><?= e($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-ok"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!$error && $userCount === 0): ?>
        <form method="post">
            <label>Admin Name</label>
            <input type="text" name="name" placeholder="e.g. John Doe" required>
            
            <label>Admin Email</label>
            <input type="email" name="email" placeholder="admin@example.com" required>
            
            <label>Admin Password (min 8 chars)</label>
            <input type="password" name="pass" minlength="8" required>
            
            <button type="submit" class="btn">Complete Installation</button>
        </form>
    <?php elseif (!$error): ?>
        <div class="footer">
            <a href="login.php" class="btn" style="display:block; text-decoration:none;">Go to Login →</a>
        </div>
    <?php endif; ?>

    <?php if ($error && strpos($error, 'Database') !== false): ?>
        <div style="font-size: 12px; color: var(--text-dim); margin-top: 10px;">
            Tip: Edit <code>config.php</code> or create <code>config.local.php</code> with your database credentials.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
