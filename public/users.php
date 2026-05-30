<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin','viewer'], true) ? $_POST['role'] : 'viewer';
        $pass  = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Valid email and password (8+ chars) required.', 'error');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)')
                    ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name ?: $email, $role]);
                flash('User created.', 'success');
            } catch (PDOException $e) {
                flash('Could not create user (email already in use?).', 'error');
            }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('User deleted.', 'success');
    }
    redirect('/users.php');
}

$users = $pdo->query('SELECT * FROM users ORDER BY created_at')->fetchAll();
$page = 'Users';
$active = 'users';
require __DIR__ . '/../src/layout.php';
?>
<div class="page-head">
    <div><h1>Users</h1><div class="sub">Who can access this dashboard</div></div>
</div>

<div class="card" style="margin-bottom: 18px;">
    <h3 style="margin-top:0; font-size:14px;">Add user</h3>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px;">
            <div class="field"><label>Email</label><input type="email" name="email" required></div>
            <div class="field"><label>Name</label><input type="text" name="name"></div>
            <div class="field"><label>Password (8+ chars)</label><input type="password" name="password" required></div>
            <div class="field"><label>Role</label>
                <select name="role"><option value="viewer">Viewer</option><option value="admin">Admin</option></select>
            </div>
        </div>
        <button class="btn primary">Add user</button>
    </form>
</div>

<table class="t">
    <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
        <td><?= e($u['email']) ?></td>
        <td><?= e($u['name']) ?></td>
        <td><span class="pill"><?= e($u['role']) ?></span></td>
        <td style="font-size:12px; color:var(--text-dim);"><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
        <td style="text-align:right;">
            <?php if ((int)$u['id'] !== (int)current_user()['id']): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button name="action" value="delete" class="btn danger" onclick="return confirm('Delete this user?')">Delete</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
