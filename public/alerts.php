<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name   = trim($_POST['name'] ?? '');
        $emails = trim($_POST['emails'] ?? '');
        $type   = in_array($_POST['type'] ?? '', ['submission', 'email_health']) ? $_POST['type'] : 'submission';
        $sid    = $_POST['site_id'] ? (int)$_POST['site_id'] : null;
        $fid    = ($type === 'submission' && $_POST['form_id']) ? (int)$_POST['form_id'] : null;

        $emailList = array_filter(array_map('trim', explode(',', $emails)));
        $invalidEmail = null;
        foreach ($emailList as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $invalidEmail = $em;
                break;
            }
        }

        if ($name === '' || empty($emailList)) {
            flash('Name and at least one email are required.', 'error');
        } elseif ($invalidEmail !== null) {
            flash('Invalid email address: ' . e($invalidEmail), 'error');
        } else {
            $pdo->prepare('INSERT INTO alert_rules (name, type, site_id, form_id, notify_emails) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $type, $sid, $fid, implode(', ', $emailList)]);
            flash('Alert rule created.', 'success');
        }
        redirect('/alerts.php');
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE alert_rules SET enabled = 1 - enabled WHERE id = ?')
            ->execute([(int)$_POST['id']]);
        redirect('/alerts.php');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM alert_rules WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('Alert rule deleted.', 'success');
        redirect('/alerts.php');
    }
}

$rules = $pdo->query(
    "SELECT a.*, s.name AS site_name, f.title AS form_title
     FROM alert_rules a
     LEFT JOIN sites s ON s.id = a.site_id
     LEFT JOIN forms f ON f.id = a.form_id
     ORDER BY a.created_at DESC"
)->fetchAll();

$sites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();
$forms = $pdo->query(
    'SELECT f.id, f.title, s.name AS site_name FROM forms f JOIN sites s ON s.id = f.site_id ORDER BY s.name, f.title'
)->fetchAll();

$page = 'Email alerts';
$active = 'alerts';
require __DIR__ . '/../src/layout.php';
?>
<div class="page-head">
    <div>
        <h1>Email alerts</h1>
        <div class="sub">Get notified when a new submission lands</div>
    </div>
</div>

<?php if ($user['role']==='admin'): ?>
<div class="card" style="margin-bottom: 18px;">
    <h3 style="margin-top:0; font-size:14px;">New alert rule</h3>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
            <div class="field">
                <label>Name</label>
                <input type="text" name="name" required placeholder="e.g. Notify sales on contact forms">
            </div>
            <div class="field">
                <label>Notify emails (comma-separated)</label>
                <input type="text" name="emails" required placeholder="alice@example.com, bob@example.com">
            </div>
            <div class="field">
                <label>Type</label>
                <select name="type" id="alert-type" onchange="document.getElementById('form-scope').style.display=this.value==='submission'?'block':'none';">
                    <option value="submission">New form submission</option>
                    <option value="email_health">Email health failure</option>
                </select>
            </div>
            <div class="field">
                <label>Site (leave blank for any)</label>
                <select name="site_id">
                    <option value="">Any site</option>
                    <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" id="form-scope">
                <label>Form (leave blank for any)</label>
                <select name="form_id">
                    <option value="">Any form</option>
                    <?php foreach ($forms as $f): ?>
                    <option value="<?= $f['id'] ?>"><?= e($f['site_name'] . ' — ' . $f['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn primary">Create rule</button>
    </form>
</div>
<?php endif; ?>

<?php if (!$rules): ?>
    <div class="empty">No alert rules yet.</div>
<?php else: ?>
<table class="t">
    <thead><tr><th>Name</th><th>Type</th><th>Scope</th><th>Recipients</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): ?>
    <tr>
        <td><strong><?= e($r['name']) ?></strong></td>
        <td>
            <?php if (($r['type'] ?? 'submission') === 'email_health'): ?>
                <span class="pill" style="color:#fb923c; border-color:rgba(251,146,60,0.3);">email health</span>
            <?php else: ?>
                <span class="pill" style="color:#60a5fa; border-color:rgba(96,165,250,0.3);">submission</span>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;">
            <?= $r['site_name'] ? e($r['site_name']) : '<span style="color:var(--text-faint)">Any site</span>' ?>
            <?php if (($r['type'] ?? 'submission') === 'submission'): ?>
            ·
            <?= $r['form_title'] ? e($r['form_title']) : '<span style="color:var(--text-faint)">Any form</span>' ?>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;"><?= e($r['notify_emails']) ?></td>
        <td><span class="pill <?= $r['enabled']?'ok':'warn' ?>"><?= $r['enabled']?'enabled':'paused' ?></span></td>
        <?php if ($user['role']==='admin'): ?>
        <td style="text-align:right;">
            <form method="post" style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button name="action" value="toggle" class="btn"><?= $r['enabled']?'Pause':'Resume' ?></button>
                <button name="action" value="delete" class="btn danger" onclick="return confirm('Delete this rule?')">Delete</button>
            </form>
        </td>
        <?php else: ?><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/../src/layout_foot.php'; ?>
