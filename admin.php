<?php
require 'config.php';
requireAdmin();

$message = '';
$is_error = false;
$edit_data = null;
$admin_id = $_SESSION['user_id'];
$host = $_SERVER['HTTP_HOST'];
$tab = $_GET['tab'] ?? 'home';

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
                $pdo->prepare('UPDATE urls SET long_url=?, title=?, image_url=?, description=?, preview_enabled=? WHERE id=? AND user_id=?')
                    ->execute([$long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled, $id, $admin_id]);
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
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$_GET['edit'], $admin_id]);
    $edit_data = $stmt->fetch(); $tab = 'create';
}

$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalLinks = (int)$pdo->query('SELECT COUNT(*) FROM urls')->fetchColumn();
$totalClicks = (int)$pdo->query('SELECT COALESCE(SUM(clicks),0) FROM urls')->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM urls WHERE user_id = ?'); $st->execute([$admin_id]); $myLinksCount = (int)$st->fetchColumn();
$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM urls WHERE user_id = u.id) as link_count FROM users u WHERE role = 'user' ORDER BY id DESC")->fetchAll();
$recentUsers = array_slice($users, 0, 8);
$search = trim($_GET['search'] ?? '');
if ($tab === 'links' || $tab === 'create' || $tab === 'home') {
    if ($search && $tab === 'links') {
        $stmt = $pdo->prepare('SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id WHERE short_code LIKE ? OR long_url LIKE ? OR title LIKE ? ORDER BY urls.id DESC');
        $like = "%$search%"; $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $pdo->query('SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id ORDER BY urls.id DESC LIMIT 50');
    }
    $allLinks = $stmt->fetchAll();
} else { $allLinks = []; }
$myLinksData = $pdo->prepare('SELECT * FROM urls WHERE user_id = ? ORDER BY id DESC LIMIT 20');
$myLinksData->execute([$admin_id]); $myLinksData = $myLinksData->fetchAll();
if (isset($_GET['tab'])) $tab = $_GET['tab'];
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
.stat-card .num{font-size:26px;font-weight:700;color:#c4b5fd}
.stat-card .label{font-size:12px;color:#94a3b8;margin-top:4px}
.card{background:rgba(255,255,255,0.05);backdrop-filter:blur(12px);border:1px solid rgba(167,139,250,0.15);border-radius:18px;padding:20px;margin-bottom:16px;display:none}
.card.active{display:block}
.card h2{font-size:17px;margin-bottom:14px;color:#e9d5ff}
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
.actions button,.actions a{padding:8px 14px;border-radius:10px;font-size:13px;font-weight:500;border:none;cursor:pointer;text-decoration:none}
.btn-copy{background:linear-gradient(135deg,#7c3aed,#a78bfa);color:white}
.btn-edit{background:rgba(167,139,250,0.15);color:#c4b5fd;border:1px solid rgba(167,139,250,0.3)!important}
.btn-del,.del-btn{background:rgba(248,113,113,0.15);color:#f87171;border:1px solid rgba(248,113,113,0.3);padding:6px 12px;border-radius:8px;font-size:12px;text-decoration:none}
.msg{background:rgba(34,197,94,0.15);color:#4ade80;padding:12px;border-radius:12px;margin-bottom:14px;text-align:center;font-size:14px}
.error{background:rgba(239,68,68,0.15);color:#fca5a5}
.msg-warn{background:rgba(251,191,36,0.15);color:#fbbf24;border:1px solid rgba(251,191,36,0.3)}
.empty{text-align:center;color:#64748b;padding:25px 0}
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
<div class="stat-card"><div class="num"><?= $totalUsers ?></div><div class="label">Total Users</div></div>
<div class="stat-card"><div class="num"><?= $totalLinks ?></div><div class="label">Total Links</div></div>
<div class="stat-card"><div class="num"><?= $totalClicks ?></div><div class="label">Total Clicks</div></div>
<div class="stat-card"><div class="num"><?= $myLinksCount ?></div><div class="label">My Links</div></div>
</div>
<p style="color:#94a3b8;font-size:13px;text-align:center;margin:10px 0 18px;">Welcome, <?= htmlspecialchars($_SESSION['name']) ?></p>
<h2 style="font-size:15px;">Recent Users</h2>
<?php if (empty($recentUsers)): ?><div class="empty" style="padding:16px 0;">No users yet — create from Users tab</div>
<?php else: foreach ($recentUsers as $u): ?>
<div class="user-item"><div class="user-info"><div class="name"><?= htmlspecialchars($u['name']) ?></div><div class="email"><?= htmlspecialchars($u['email']) ?></div><div class="meta"><?= (int)$u['link_count'] ?> links · joined <?= htmlspecialchars($u['created_at']??'') ?></div></div>
<a href="?delete_user=<?= (int)$u['id'] ?>" class="del-btn" onclick="return confirm('Delete user?')">Delete</a></div>
<?php endforeach; ?><a href="admin.php?tab=users" class="cancel" style="margin-top:12px;">View all users →</a><?php endif; ?>
</div>

<div class="card <?= $tab==='create'?'active':'' ?>">
<h2><?= $edit_data?'Edit Link':'Create Short Link' ?></h2>
<?php $editPreviewOn = true; if ($edit_data && array_key_exists('preview_enabled',$edit_data)) $editPreviewOn=((int)$edit_data['preview_enabled']===1); ?>
<?php if ($edit_data && !$editPreviewOn): ?><div class="msg msg-warn">Preview is OFF — WhatsApp/Facebook pe title/image nahi dikhega.</div><?php endif; ?>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="create_link">
<?php if ($edit_data): ?><input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>"><?php endif; ?>
<input type="text" name="long_url" placeholder="Destination URL" required value="<?= htmlspecialchars($edit_data['long_url']??'') ?>">
<input type="text" name="title" placeholder="Title (News style)" value="<?= htmlspecialchars($edit_data['title']??'') ?>">
<textarea name="description" placeholder="Description (News style)"><?= htmlspecialchars($edit_data['description']??'') ?></textarea>
<label class="file-label">Preview Image</label>
<input type="file" name="image_file" accept="image/*" class="file-input">
<input type="text" name="image_url" placeholder="Ya Image URL (Optional)" value="<?= htmlspecialchars($edit_data['image_url']??'') ?>">
<div class="toggle-row"><div><div class="toggle-text">Link Preview (WhatsApp / Facebook)</div><div class="toggle-sub">ON = dikhe · OFF = strict hide</div></div>
<label class="switch"><input type="checkbox" name="preview_enabled" value="1" <?= $editPreviewOn?'checked':'' ?> onchange="var a=document.getElementById('previewOffAlert');if(a)a.style.display=this.checked?'none':'block';"><span class="slider"></span></label></div>
<div id="previewOffAlert" class="msg msg-warn" style="display:<?= $editPreviewOn?'none':'block' ?>;">Preview OFF — share pe koi title/image nahi.</div>
<button type="submit" class="btn-primary"><?= $edit_data?'Update Link':'Shorten Now' ?></button>
<?php if ($edit_data): ?><a href="admin.php?tab=create" class="cancel">Cancel</a><?php endif; ?>
</form>
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
$pOn = !array_key_exists('preview_enabled',$link) || (int)$link['preview_enabled']===1; ?>
<div class="link-item">
<div class="short-url"><a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a>
<span class="clicks"><?= (int)$link['clicks'] ?> clicks</span>
<?php if ($pOn): ?><span class="badge-on">ON</span><?php else: ?><span class="badge-off">OFF</span><?php endif; ?></div>
<div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
<div class="meta-line">by <?= htmlspecialchars($link['user_name']??'') ?></div>
<div class="actions"><button class="btn-copy" onclick="copyLink(this,'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
<a href="?delete_link=<?= (int)$link['id'] ?>" class="btn-del" onclick="return confirm('Delete?')">Del</a></div></div>
<?php endforeach; endif; ?>
</div>
</div>
<nav class="bottom-nav">
<a href="admin.php?tab=home" class="nav-item <?= ($tab==='home'||$tab==='')?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1h-2z"/></svg>Home</a>
<a href="admin.php?tab=create" class="nav-item <?= $tab==='create'?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>Create</a>
<a href="admin.php?tab=users" class="nav-item <?= $tab==='users'?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>Users</a>
<a href="admin.php?tab=links" class="nav-item <?= $tab==='links'?'active':'' ?>"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>Links</a>
</nav>
<script>function copyLink(btn,url){navigator.clipboard.writeText(url).then(()=>{const o=btn.innerText;btn.innerText='Copied!';btn.style.background='#22c55e';setTimeout(()=>{btn.innerText=o;btn.style.background='';},1500);}).catch(()=>alert('Copy failed'));}</script>
</body></html>
