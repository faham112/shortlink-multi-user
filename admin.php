<?php
require 'config.php';
requireAdmin();

$message = '';
$is_error = false;
$edit_data = null;
$admin_id = $_SESSION['user_id'];
$host = $_SERVER['HTTP_HOST'];
$tab = $_GET['tab'] ?? 'home';
$stats_link_id = isset($_GET['stats']) ? (int)$_GET['stats'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_link') {
    $long_url = trim($_POST['long_url'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $preview_enabled = isset($_POST['preview_enabled']) ? 1 : 0;
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (!empty($_FILES['image_file']['name'])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array($ext, $allowed) && $_FILES['image_file']['size'] < 5 * 1024 * 1024) {
            $newName = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $newName)) {
                $image_url = 'https://' . $_SERVER['HTTP_HOST'] . '/uploads/' . $newName;
            }
        }
    }
    if (empty($long_url)) { $message = 'Destination URL required!'; $is_error = true; $tab = 'create'; }
    else {
        if (!preg_match('~^(?:f|ht)tps?://~i', $long_url)) $long_url = 'https://' . $long_url;
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE urls SET long_url=?, title=?, image_url=?, description=?, preview_enabled=? WHERE id=?')
                    ->execute([$long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled, $id]);
                header('Location: admin.php?tab=links&msg=updated'); exit;
            } else {
                $short_code = generateShortCode(); $ok = false;
                for ($i = 0; $i < 5; $i++) {
                    try {
                        $pdo->prepare('INSERT INTO urls (user_id, short_code, long_url, title, image_url, description, preview_enabled) VALUES (?,?,?,?,?,?,?)')
                            ->execute([$admin_id, $short_code, $long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled]);
                        $ok = true; break;
                    } catch (PDOException $e) { $short_code = generateShortCode(); }
                }
                if ($ok) { header('Location: admin.php?tab=links&msg=created'); exit; }
                $message = 'Error creating link'; $is_error = true; $tab = 'create';
            }
        } catch (PDOException $e) { $message = 'DB error: ' . $e->getMessage(); $is_error = true; $tab = 'create'; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pass = $_POST['password'] ?? '';
    if (empty($name) || empty($email) || empty($pass)) { $message = 'Sab fields zaroori hain'; $is_error = true; $tab = 'users'; }
    elseif (strlen($pass) < 6) { $message = 'Password min 6 characters'; $is_error = true; $tab = 'users'; }
    else {
        try {
            $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')")
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            header('Location: admin.php?tab=home&msg=user_created'); exit;
        } catch (PDOException $e) { $message = 'Email already exists!'; $is_error = true; $tab = 'users'; }
    }
}

if (isset($_GET['delete_user'])) {
    $id = (int)$_GET['delete_user'];
    if ($id != $admin_id) {
        try { $pdo->prepare('DELETE FROM clicks WHERE url_id IN (SELECT id FROM urls WHERE user_id = ?)')->execute([$id]); } catch (Exception $e) {}
        $pdo->prepare('DELETE FROM urls WHERE user_id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'")->execute([$id]);
    }
    header('Location: admin.php?tab=users&msg=user_deleted'); exit;
}
if (isset($_GET['delete_link'])) {
    $id = (int)$_GET['delete_link'];
    try { $pdo->prepare('DELETE FROM clicks WHERE url_id = ?')->execute([$id]); } catch (Exception $e) {}
    $pdo->prepare('DELETE FROM urls WHERE id = ?')->execute([$id]);
    header('Location: admin.php?tab=links&msg=deleted'); exit;
}
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit_data = $stmt->fetch();
    $tab = 'create';
}

$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalLinks = (int)$pdo->query('SELECT COUNT(*) FROM urls')->fetchColumn();
$totalClicks = (int)$pdo->query('SELECT COALESCE(SUM(clicks),0) FROM urls')->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM urls WHERE user_id = ?'); $st->execute([$admin_id]); $myLinksCount = (int)$st->fetchColumn();
$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM urls WHERE user_id = u.id) as link_count FROM users u WHERE role = 'user' ORDER BY id DESC")->fetchAll();
$recentUsers = array_slice($users, 0, 8);
$search = trim($_GET['search'] ?? '');
if ($search && (($_GET['tab'] ?? '') === 'links')) {
    $stmt = $pdo->prepare('SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id WHERE short_code LIKE ? OR long_url LIKE ? OR title LIKE ? ORDER BY urls.id DESC');
    $like = "%$search%"; $stmt->execute([$like, $like, $like]); $allLinks = $stmt->fetchAll();
} else {
    $allLinks = $pdo->query('SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id ORDER BY urls.id DESC LIMIT 100')->fetchAll();
}
$recent5 = $pdo->query('SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id ORDER BY urls.id DESC LIMIT 5')->fetchAll();

$by_device=[]; $by_browser=[]; $by_country=[]; $by_os=[]; $top_links=[]; $recent_clicks_global=[]; $clicks_today=0; $clicks_7d=0; $analytics_error='';
try {
    $by_device = $pdo->query("SELECT COALESCE(device,'Unknown') as device, COUNT(*) as c FROM clicks WHERE is_bot = 0 GROUP BY device ORDER BY c DESC")->fetchAll();
    $by_browser = $pdo->query("SELECT COALESCE(browser,'Unknown') as browser, COUNT(*) as c FROM clicks WHERE is_bot = 0 GROUP BY browser ORDER BY c DESC")->fetchAll();
    $by_country = $pdo->query("SELECT COALESCE(country,'Unknown') as country, COUNT(*) as c FROM clicks WHERE is_bot = 0 GROUP BY country ORDER BY c DESC LIMIT 12")->fetchAll();
    $by_os = $pdo->query("SELECT COALESCE(os,'Unknown') as os, COUNT(*) as c FROM clicks WHERE is_bot = 0 GROUP BY os ORDER BY c DESC")->fetchAll();
    $top_links = $pdo->query('SELECT short_code, title, clicks FROM urls ORDER BY clicks DESC LIMIT 8')->fetchAll();
    $recent_clicks_global = $pdo->query('SELECT c.*, u.short_code FROM clicks c LEFT JOIN urls u ON u.id = c.url_id WHERE c.is_bot = 0 ORDER BY c.id DESC LIMIT 15')->fetchAll();
    $clicks_today = (int)$pdo->query('SELECT COUNT(*) FROM clicks WHERE is_bot = 0 AND DATE(created_at) = CURDATE()')->fetchColumn();
    $clicks_7d = (int)$pdo->query('SELECT COUNT(*) FROM clicks WHERE is_bot = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
} catch (Exception $e) { $analytics_error = $e->getMessage(); }

$stats=null; $recent_clicks=[]; $s_device=[]; $s_browser=[]; $s_country=[]; $s_referer=[]; $stats_error='';
if ($stats_link_id > 0) {
    $stmt = $pdo->prepare('SELECT urls.*, users.name as user_name FROM urls LEFT JOIN users ON urls.user_id = users.id WHERE urls.id = ?');
    $stmt->execute([$stats_link_id]); $stats = $stmt->fetch();
    if ($stats) {
        $tab = 'stats';
        try {
            $q = $pdo->prepare('SELECT * FROM clicks WHERE url_id = ? AND is_bot = 0 ORDER BY id DESC LIMIT 80'); $q->execute([$stats_link_id]); $recent_clicks = $q->fetchAll();
            $q = $pdo->prepare("SELECT COALESCE(device,'Unknown') as device, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY device ORDER BY c DESC"); $q->execute([$stats_link_id]); $s_device = $q->fetchAll();
            $q = $pdo->prepare("SELECT COALESCE(browser,'Unknown') as browser, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY browser ORDER BY c DESC"); $q->execute([$stats_link_id]); $s_browser = $q->fetchAll();
            $q = $pdo->prepare("SELECT COALESCE(country,'Unknown') as country, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY country ORDER BY c DESC LIMIT 15"); $q->execute([$stats_link_id]); $s_country = $q->fetchAll();
            $q = $pdo->prepare("SELECT CASE WHEN referer IS NULL OR referer = '' THEN 'Direct' ELSE referer END as ref, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY ref ORDER BY c DESC LIMIT 20"); $q->execute([$stats_link_id]); $s_referer = $q->fetchAll();
        } catch (Exception $e) { $stats_error = $e->getMessage(); }
    }
}
if (isset($_GET['tab'])) $tab = $_GET['tab'];
if ($stats_link_id > 0 && $stats) $tab = 'stats';
function barMax($rows) { if (empty($rows)) return 1; $m = max(array_map(function($r){return (int)$r['c'];}, $rows)); return $m > 0 ? $m : 1; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Admin - ShortLink</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f0a1f;color:#e2e8f0;min-height:100vh;padding-bottom:90px}
.header{background:rgba(76,29,149,0.5);backdrop-filter:blur(16px);border-bottom:1px solid rgba(167,139,250,0.2);padding:14px 18px;position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:19px;color:#e9d5ff}
.header-right{display:flex;align-items:center;gap:10px}
.badge{background:#7c3aed;color:white;font-size:11px;padding:3px 10px;border-radius:20px}
.btn-logout{background:rgba(248,113,113,0.15);color:#f87171;border:1px solid rgba(248,113,113,0.3);padding:6px 12px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none}
.container{max-width:700px;margin:0 auto;padding:16px}
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:18px}
.stat-card{background:rgba(255,255,255,0.05);border:1px solid rgba(167,139,250,0.15);border-radius:16px;padding:16px;text-align:center}
.stat-card .num{font-size:24px;font-weight:700;color:#c4b5fd}
.stat-card .label{font-size:12px;color:#94a3b8;margin-top:4px}
.card{background:rgba(255,255,255,0.05);backdrop-filter:blur(12px);border:1px solid rgba(167,139,250,0.15);border-radius:18px;padding:20px;margin-bottom:16px;display:none}
.card.active{display:block}
.card h2{font-size:17px;margin-bottom:14px;color:#e9d5ff}
.card h3{font-size:14px;margin:18px 0 10px;color:#c4b5fd}
input,textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(167,139,250,0.2);background:rgba(15,10,31,0.6);color:white;font-size:15px;margin-bottom:10px;outline:none}
.btn-primary{width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:white;font-size:15px;font-weight:600;cursor:pointer}
.search-bar{display:flex;gap:8px;margin-bottom:14px}
.search-bar input{margin-bottom:0;flex:1}
.search-bar button{background:#7c3aed;color:white;border:none;padding:0 16px;border-radius:12px;font-weight:600;cursor:pointer}
.user-item,.link-item{border-bottom:1px solid rgba(167,139,250,0.1);padding:12px 0}
.user-item{display:flex;justify-content:space-between;align-items:center}
.user-info .name{font-weight:600;color:#e2e8f0}
.user-info .email{font-size:12px;color:#94a3b8}
.user-info .meta{font-size:11px;color:#64748b;margin-top:2px}
.short-url a{color:#c4b5fd;text-decoration:none;font-weight:600;font-size:14px;word-break:break-all}
.long-url{font-size:12px;color:#94a3b8;margin-top:3px;word-break:break-all;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.meta-line{font-size:11px;color:#64748b;margin-top:3px}
.clicks{display:inline-block;background:rgba(34,197,94,0.15);color:#4ade80;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px}
.badge-on{display:inline-block;background:rgba(34,197,94,0.15);color:#4ade80;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:4px}
.badge-off{display:inline-block;background:rgba(248,113,113,0.15);color:#f87171;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:4px}
.actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
.actions button,.actions a{padding:8px 12px;border-radius:10px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none}
.btn-copy{background:linear-gradient(135deg,#7c3aed,#a78bfa);color:white}
.btn-edit{background:rgba(167,139,250,0.15);color:#c4b5fd;border:1px solid rgba(167,139,250,0.3)!important}
.btn-stats{background:rgba(56,189,248,0.15);color:#38bdf8;border:1px solid rgba(56,189,248,0.3)!important}
.btn-del,.del-btn{background:rgba(248,113,113,0.15);color:#f87171;border:1px solid rgba(248,113,113,0.3);padding:6px 12px;border-radius:8px;font-size:12px;text-decoration:none}
.msg{background:rgba(34,197,94,0.15);color:#4ade80;padding:12px;border-radius:12px;margin-bottom:14px;text-align:center;font-size:14px}
.error{background:rgba(239,68,68,0.15);color:#fca5a5}
.msg-warn{background:rgba(251,191,36,0.15);color:#fbbf24;border:1px solid rgba(251,191,36,0.3)}
.empty{text-align:center;color:#64748b;padding:20px 0}
.cancel{display:block;text-align:center;margin-top:10px;color:#94a3b8;font-size:13px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:rgba(30,27,75,0.95);backdrop-filter:blur(20px);border-top:1px solid rgba(167,139,250,0.25);display:flex;justify-content:space-around;padding:8px 0 max(12px,env(safe-area-inset-bottom));z-index:100}
.nav-item{display:flex;flex-direction:column;align-items:center;text-decoration:none;color:#94a3b8;font-size:11px;gap:3px;padding:8px 12px;border-radius:14px;min-width:60px}
.nav-item.active{color:#e9d5ff;background:rgba(124,58,237,0.4)}
.nav-item svg{width:22px;height:22px}
.file-label{font-size:13px;color:#c4b5fd;display:block;margin-bottom:6px}
.file-input{padding:10px;background:rgba(15,10,31,0.6);border:1px solid rgba(167,139,250,0.2);border-radius:12px;color:#e2e8f0;width:100%;margin-bottom:10px}
.toggle-row{display:flex;align-items:center;justify-content:space-between;background:rgba(15,10,31,0.5);border:1px solid rgba(167,139,250,0.2);border-radius:12px;padding:12px 14px;margin-bottom:12px}
.toggle-text{font-size:14px;color:#e2e8f0}
.toggle-sub{font-size:11px;color:#94a3b8;margin-top:2px}
.switch{position:relative;width:52px;height:28px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#334155;border-radius:28px;transition:0.25s}
.slider:before{position:absolute;content:"";height:22px;width:22px;left:3px;bottom:3px;background:white;border-radius:50%;transition:0.25s}
.switch input:checked + .slider{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
.switch input:checked + .slider:before{transform:translateX(24px)}
.stat-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}
.stat-bar-wrap{background:rgba(255,255,255,0.06);border-radius:8px;height:10px;margin:4px 0 10px;overflow:hidden}
.stat-bar{background:linear-gradient(90deg,#7c3aed,#a78bfa);height:100%;border-radius:8px;min-width:2px}
.stat-bar.green{background:linear-gradient(90deg,#059669,#34d399)}
.stat-bar.blue{background:linear-gradient(90deg,#0284c7,#38bdf8)}
.stat-bar.orange{background:linear-gradient(90deg,#c2410c,#fb923c)}
.click-row{font-size:12px;color:#94a3b8;padding:8px 0;border-bottom:1px solid rgba(167,139,250,0.08)}
.click-row strong{color:#e2e8f0}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.chart-box{background:rgba(15,10,31,0.45);border:1px solid rgba(167,139,250,0.12);border-radius:14px;padding:12px}
.chart-box h4{font-size:12px;color:#94a3b8;margin-bottom:8px}
</style>
</head>
<body>
<div class="header"><h1>Admin Panel</h1><div class="header-right"><span class="badge">Admin</span><a href="logout.php" class="btn-logout">Logout</a></div></div>
<div class="container">
<?php if (isset($_GET['msg'])): ?><div class="msg"><?php $msgs=['created'=>'Link created!','updated'=>'Link updated!','deleted'=>'Link deleted!','user_created'=>'User created!','user_deleted'=>'User deleted!']; echo $msgs[$_GET['msg']]??''; ?></div><?php endif; ?>
<?php if ($message): ?><div class="msg <?= $is_error?'error':'' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="card <?= ($tab==='home'||$tab==='')?'active':'' ?>">
<h2>Dashboard Overview</h2>
<div class="stats">
<div class="stat-card"><div class="num"><?= $totalUsers ?></div><div class="label">Users</div></div>
<div class="stat-card"><div class="num"><?= $totalLinks ?></div><div class="label">Links</div></div>
<div class="stat-card"><div class="num"><?= $totalClicks ?></div><div class="label">Total Clicks</div></div>
<div class="stat-card"><div class="num"><?= $clicks_today ?></div><div class="label">Today</div></div>
</div>
<div class="stats">
<div class="stat-card"><div class="num"><?= $clicks_7d ?></div><div class="label">Last 7 Days</div></div>
<div class="stat-card"><div class="num"><?= $myLinksCount ?></div><div class="label">My Links</div></div>
</div>
<?php if ($analytics_error): ?><div class="msg error">Analytics: <?= htmlspecialchars($analytics_error) ?></div><?php endif; ?>

<h3>Traffic by Device</h3>
<?php $maxd=barMax($by_device); foreach ($by_device as $row): $pct=round(((int)$row['c']/$maxd)*100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['device']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_device)): ?><p class="empty" style="padding:8px 0;">No clicks yet — open a short link once</p><?php endif; ?>

<div class="chart-grid">
<div class="chart-box"><h4>Browser</h4>
<?php $maxb=barMax($by_browser); foreach (array_slice($by_browser,0,5) as $row): $pct=round(((int)$row['c']/$maxb)*100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['browser']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar blue" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_browser)): ?><p class="empty" style="padding:6px 0;font-size:12px;">No data</p><?php endif; ?></div>
<div class="chart-box"><h4>OS</h4>
<?php $maxo=barMax($by_os); foreach (array_slice($by_os,0,5) as $row): $pct=round(((int)$row['c']/$maxo)*100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['os']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar orange" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_os)): ?><p class="empty" style="padding:6px 0;font-size:12px;">No data</p><?php endif; ?></div>
</div>

<h3>By Country</h3>
<?php $maxc=barMax($by_country); foreach ($by_country as $row): $pct=round(((int)$row['c']/$maxc)*100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['country']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar green" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_country)): ?><p class="empty" style="padding:8px 0;">No data yet</p><?php endif; ?>

<h3>Top Links</h3>
<?php if (empty($top_links)): ?><p class="empty" style="padding:8px 0;">No links</p>
<?php else: $maxt=max(1,max(array_column($top_links,'clicks'))); foreach ($top_links as $tl): $pct=round(((int)$tl['clicks']/$maxt)*100); ?>
<div class="stat-row"><span style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">/<?= htmlspecialchars($tl['short_code']) ?></span><span><?= (int)$tl['clicks'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; endif; ?>

<h3>Recent Clicks</h3>
<?php foreach ($recent_clicks_global as $c): ?>
<div class="click-row"><strong>/<?= htmlspecialchars($c['short_code']??'?') ?></strong> · <?= htmlspecialchars($c['device']??'-') ?> · <?= htmlspecialchars($c['browser']??'-') ?><?php if (!empty($c['country'])): ?> · <?= htmlspecialchars($c['country']) ?><?php endif; ?><br><?= htmlspecialchars($c['ip']??'-') ?> · <?= htmlspecialchars($c['created_at']??'') ?></div>
<?php endforeach; if (empty($recent_clicks_global)): ?><p class="empty" style="padding:8px 0;">No clicks logged yet</p><?php endif; ?>

<h3 style="margin-top:22px;">Recent Users</h3>
<?php if (empty($recentUsers)): ?><div class="empty" style="padding:12px 0;">No users yet</div>
<?php else: foreach ($recentUsers as $u): ?>
<div class="user-item"><div class="user-info"><div class="name"><?= htmlspecialchars($u['name']) ?></div><div class="email"><?= htmlspecialchars($u['email']) ?></div><div class="meta"><?= (int)$u['link_count'] ?> links</div></div>
<a href="?delete_user=<?= (int)$u['id'] ?>" class="del-btn" onclick="return confirm('Delete?')">Delete</a></div>
<?php endforeach; ?><a href="admin.php?tab=users" class="cancel">View all users →</a><?php endif; ?>
</div>

<div class="card <?= $tab==='create'?'active':'' ?>">
<h2><?= $edit_data?'Edit Link':'Create Short Link' ?></h2>
<?php $editPreviewOn=true; if ($edit_data && array_key_exists('preview_enabled',$edit_data)) $editPreviewOn=((int)$edit_data['preview_enabled']===1); ?>
<?php if ($edit_data && !$editPreviewOn): ?><div class="msg msg-warn">Preview is OFF</div><?php endif; ?>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="create_link">
<?php if ($edit_data): ?><input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>"><?php endif; ?>
<input type="text" name="long_url" placeholder="Destination URL" required value="<?= htmlspecialchars($edit_data['long_url']??'') ?>">
<input type="text" name="title" placeholder="Title (News style)" value="<?= htmlspecialchars($edit_data['title']??'') ?>">
<textarea name="description" placeholder="Description"><?= htmlspecialchars($edit_data['description']??'') ?></textarea>
<label class="file-label">Preview Image</label>
<input type="file" name="image_file" accept="image/*" class="file-input">
<input type="text" name="image_url" placeholder="Image URL (Optional)" value="<?= htmlspecialchars($edit_data['image_url']??'') ?>">
<div class="toggle-row"><div><div class="toggle-text">Link Preview</div><div class="toggle-sub">ON = dikhe · OFF = hide</div></div>
<label class="switch"><input type="checkbox" name="preview_enabled" value="1" <?= $editPreviewOn?'checked':'' ?> onchange="var a=document.getElementById('previewOffAlert');if(a)a.style.display=this.checked?'none':'block';"><span class="slider"></span></label></div>
<div id="previewOffAlert" class="msg msg-warn" style="display:<?= $editPreviewOn?'none':'block' ?>;">Preview OFF</div>
<button type="submit" class="btn-primary"><?= $edit_data?'Update Link':'Shorten Now' ?></button>
<?php if ($edit_data): ?><a href="admin.php?tab=create" class="cancel">Cancel</a><?php endif; ?>
</form>
<?php if (!$edit_data): ?>
<h3>Recently Made (Last 5)</h3>
<?php if (empty($recent5)): ?><div class="empty" style="padding:12px 0;">No links yet</div>
<?php else: foreach ($recent5 as $link):
$pOn=!array_key_exists('preview_enabled',$link)||(int)$link['preview_enabled']===1; ?>
<div class="link-item">
<div class="short-url"><a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a>
<span class="clicks"><?= (int)$link['clicks'] ?> clicks</span>
<?php if ($pOn): ?><span class="badge-on">ON</span><?php else: ?><span class="badge-off">OFF</span><?php endif; ?></div>
<div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
<div class="meta-line">by <?= htmlspecialchars($link['user_name']??'') ?></div>
<div class="actions">
<button class="btn-copy" onclick="copyLink(this,'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
<a href="?stats=<?= (int)$link['id'] ?>" class="btn-stats">Stats</a>
<a href="?tab=create&edit=<?= (int)$link['id'] ?>" class="btn-edit">Edit</a>
<a href="?delete_link=<?= (int)$link['id'] ?>" class="btn-del" onclick="return confirm('Delete?')">Del</a>
</div></div>
<?php endforeach; endif; endif; ?>
</div>

<div class="card <?= $tab==='users'?'active':'' ?>">
<h2>Create New User</h2>
<form method="POST"><input type="hidden" name="action" value="create_user">
<input type="text" name="name" placeholder="Full Name" required>
<input type="email" name="email" placeholder="Email" required>
<input type="text" name="password" placeholder="Password (min 6)" required>
<button type="submit" class="btn-primary">Create User</button></form>
<h2 style="margin-top:24px;">All Users (<?= count($users) ?>)</h2>
<?php if (empty($users)): ?><div class="empty">No users yet</div>
<?php else: foreach ($users as $u): ?>
<div class="user-item"><div class="user-info"><div class="name"><?= htmlspecialchars($u['name']) ?></div><div class="email"><?= htmlspecialchars($u['email']) ?></div><div class="meta"><?= (int)$u['link_count'] ?> links</div></div>
<a href="?delete_user=<?= (int)$u['id'] ?>" class="del-btn" onclick="return confirm('Delete?')">Delete</a></div>
<?php endforeach; endif; ?>
</div>

<div class="card <?= $tab==='links'?'active':'' ?>">
<h2>All Links</h2>
<form method="GET" class="search-bar"><input type="hidden" name="tab" value="links">
<input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
<button type="submit">Search</button></form>
<?php if (empty($allLinks)): ?><div class="empty">No links found</div>
<?php else: foreach ($allLinks as $link):
$pOn=!array_key_exists('preview_enabled',$link)||(int)$link['preview_enabled']===1; ?>
<div class="link-item">
<div class="short-url"><a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a>
<span class="clicks"><?= (int)$link['clicks'] ?> clicks</span>
<?php if ($pOn): ?><span class="badge-on">ON</span><?php else: ?><span class="badge-off">OFF</span><?php endif; ?></div>
<div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
<div class="meta-line">by <?= htmlspecialchars($link['user_name']??'') ?></div>
<div class="actions">
<button class="btn-copy" onclick="copyLink(this,'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
<a href="?stats=<?= (int)$link['id'] ?>" class="btn-stats">Stats</a>
<a href="?tab=create&edit=<?= (int)$link['id'] ?>" class="btn-edit">Edit</a>
<a href="?delete_link=<?= (int)$link['id'] ?>" class="btn-del" onclick="return confirm('Delete?')">Del</a>
</div></div>
<?php endforeach; endif; ?>
</div>

<div class="card <?= $tab==='stats'?'active':'' ?>">
<?php if ($stats): ?>
<h2>Link Analytics</h2>
<p style="font-size:13px;color:#c4b5fd;margin-bottom:6px;word-break:break-all;">https://<?= $host ?>/<?= htmlspecialchars($stats['short_code']) ?></p>
<p style="font-size:12px;color:#94a3b8;margin-bottom:14px;"><?= (int)$stats['clicks'] ?> clicks · by <?= htmlspecialchars($stats['user_name']??'-') ?></p>
<?php if ($stats_error): ?><div class="msg error"><?= htmlspecialchars($stats_error) ?></div><?php endif; ?>
<h3>Device</h3>
<?php $maxd=barMax($s_device); foreach ($s_device as $row): $pct=round(((int)$row['c']/$maxd)*100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['device']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($s_device)): ?><p class="empty" style="padding:8px 0;">No data — open short link once</p><?php endif; ?>
<h3>Browser</h3>
<?php foreach ($s_browser as $row): ?><div class="stat-row"><span><?= htmlspecialchars($row['browser']) ?></span><span><?= (int)$row['c'] ?></span></div><?php endforeach; ?>
<?php if (empty($s_browser)): ?><p class="empty" style="padding:8px 0;">No data</p><?php endif; ?>
<h3>Country</h3>
<?php foreach ($s_country as $row): ?><div class="stat-row"><span><?= htmlspecialchars($row['country']) ?></span><span><?= (int)$row['c'] ?></span></div><?php endforeach; ?>
<?php if (empty($s_country)): ?><p class="empty" style="padding:8px 0;">No data</p><?php endif; ?>
<h3>Traffic Sources</h3>
<?php foreach ($s_referer as $row): ?><div class="stat-row"><span style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_substr($row['ref'],0,55)) ?></span><span><?= (int)$row['c'] ?></span></div><?php endforeach; ?>
<?php if (empty($s_referer)): ?><p class="empty" style="padding:8px 0;">No data</p><?php endif; ?>
<h3>Recent Clicks</h3>
<?php foreach ($recent_clicks as $c): ?>
<div class="click-row"><strong><?= htmlspecialchars($c['device']??'-') ?></strong> · <?= htmlspecialchars($c['browser']??'-') ?> · <?= htmlspecialchars($c['os']??'-') ?><?php if (!empty($c['country'])): ?> · <?= htmlspecialchars($c['country']) ?><?php endif; ?><br>IP: <?= htmlspecialchars($c['ip']??'-') ?> · <?= htmlspecialchars($c['created_at']??'') ?></div>
<?php endforeach; if (empty($recent_clicks)): ?><p class="empty" style="padding:8px 0;">No clicks yet</p><?php endif; ?>
<a href="admin.php?tab=links" class="cancel" style="margin-top:16px;">← Back to Links</a>
<?php else: ?><div class="empty">Select a link and tap Stats</div><a href="admin.php?tab=links" class="cancel">← Back</a><?php endif; ?>
</div>
</div>

<nav class="bottom-nav">
<a href="admin.php?tab=home" class="nav-item <?= ($tab==='home'||$tab==='')?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1h-2z"/></svg>Home</a>
<a href="admin.php?tab=create" class="nav-item <?= $tab==='create'?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>Create</a>
<a href="admin.php?tab=users" class="nav-item <?= $tab==='users'?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>Users</a>
<a href="admin.php?tab=links" class="nav-item <?= ($tab==='links'||$tab==='stats')?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>Links</a>
</nav>
<script>function copyLink(btn,url){navigator.clipboard.writeText(url).then(()=>{const o=btn.innerText;btn.innerText='Copied!';btn.style.background='#22c55e';setTimeout(()=>{btn.innerText=o;btn.style.background='';},1500);}).catch(()=>alert('Copy failed'));}</script>
</body></html>
