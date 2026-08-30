<?php
require 'config.php';
requireLogin();

if (isAdmin()) {
    header("Location: admin.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$is_error = false;
$edit_data = null;
$host = $_SERVER['HTTP_HOST'];
$tab = $_GET['tab'] ?? 'create';
$stats_link_id = isset($_GET['stats']) ? (int)$_GET['stats'] : 0;

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try { $pdo->prepare("DELETE FROM clicks WHERE url_id = ?")->execute([$id]); } catch (Exception $e) {}
    $pdo->prepare("DELETE FROM urls WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
    header("Location: dashboard.php?tab=links&msg=deleted");
    exit;
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['edit'], $user_id]);
    $edit_data = $stmt->fetch();
    $tab = 'create';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $long_url    = trim($_POST['long_url'] ?? '');
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $image_url   = trim($_POST['image_url'] ?? '');
    $preview_enabled = isset($_POST['preview_enabled']) ? 1 : 0;
    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if (!empty($_FILES['image_file']['name'])) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array($ext, $allowed) && $_FILES['image_file']['size'] < 5 * 1024 * 1024) {
            $newName = 'img_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $uploadDir . $newName;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target)) {
                $image_url = 'https://' . $_SERVER['HTTP_HOST'] . '/uploads/' . $newName;
            }
        }
    }

    if (empty($long_url)) {
        $message = "Destination URL required!";
        $is_error = true;
        $tab = 'create';
    } else {
        if (!preg_match("~^(?:f|ht)tps?://~i", $long_url)) {
            $long_url = "https://" . $long_url;
        }
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE urls SET long_url=?, title=?, image_url=?, description=?, preview_enabled=? WHERE id=? AND user_id=?");
                $stmt->execute([$long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled, $id, $user_id]);
                header("Location: dashboard.php?tab=links&msg=updated");
                exit;
            } else {
                $short_code = generateShortCode();
                $attempts = 0;
                $created = false;
                while ($attempts < 5) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO urls (user_id, short_code, long_url, title, image_url, description, preview_enabled) VALUES (?,?,?,?,?,?,?)");
                        $stmt->execute([$user_id, $short_code, $long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled]);
                        $created = true;
                        break;
                    } catch (PDOException $e) {
                        $short_code = generateShortCode();
                        $attempts++;
                    }
                }
                if ($created) {
                    header("Location: dashboard.php?tab=links&msg=created");
                    exit;
                }
                $message = "Error creating link. Try again.";
                $is_error = true;
                $tab = 'create';
            }
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $is_error = true;
            $tab = 'create';
        }
    }
}

$search = trim($_GET['search'] ?? '');
try {
    if ($search) {
        $stmt = $pdo->prepare("SELECT * FROM urls WHERE user_id = ? AND (short_code LIKE ? OR long_url LIKE ? OR title LIKE ?) ORDER BY id DESC");
        $like = "%$search%";
        $stmt->execute([$user_id, $like, $like, $like]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM urls WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
    }
    $links = $stmt->fetchAll();
} catch (Exception $e) {
    $links = [];
    $message = "Links load error: " . $e->getMessage();
    $is_error = true;
}

$totalLinks = count($links);
$totalClicks = 0;
foreach ($links as $l) $totalClicks += (int)($l['clicks'] ?? 0);

$stats = null;
$recent_clicks = [];
$by_device = [];
$by_browser = [];
$by_country = [];
$by_referer = [];
$stats_error = '';
if ($stats_link_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->execute([$stats_link_id, $user_id]);
    $stats = $stmt->fetch();
    if ($stats) {
        $tab = 'stats';
        try {
            $q = $pdo->prepare("SELECT * FROM clicks WHERE url_id = ? AND is_bot = 0 ORDER BY id DESC LIMIT 80");
            $q->execute([$stats_link_id]);
            $recent_clicks = $q->fetchAll();
            $q = $pdo->prepare("SELECT COALESCE(device,'Unknown') as device, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY device ORDER BY c DESC");
            $q->execute([$stats_link_id]);
            $by_device = $q->fetchAll();
            $q = $pdo->prepare("SELECT COALESCE(browser,'Unknown') as browser, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY browser ORDER BY c DESC");
            $q->execute([$stats_link_id]);
            $by_browser = $q->fetchAll();
            $q = $pdo->prepare("SELECT COALESCE(country,'Unknown') as country, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY country ORDER BY c DESC LIMIT 20");
            $q->execute([$stats_link_id]);
            $by_country = $q->fetchAll();
            $q = $pdo->prepare("SELECT CASE WHEN referer IS NULL OR referer = '' THEN 'Direct' ELSE referer END as ref, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY ref ORDER BY c DESC LIMIT 25");
            $q->execute([$stats_link_id]);
            $by_referer = $q->fetchAll();
        } catch (Exception $e) {
            $stats_error = "Analytics error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['msg']) && in_array($_GET['msg'], ['created','updated','deleted'], true) && !isset($_GET['tab'])) {
    $tab = 'links';
}
if (isset($_GET['tab']) && $_GET['tab'] === 'links') {
    $tab = 'links';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Dashboard - ShortLink</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f0a1f;color:#e2e8f0;min-height:100vh;padding-bottom:90px}
.header{background:rgba(76,29,149,0.45);backdrop-filter:blur(16px);border-bottom:1px solid rgba(167,139,250,0.15);padding:14px 18px;position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:19px;font-weight:700;color:#e9d5ff}
.header-right{display:flex;align-items:center;gap:12px}
.header .user{font-size:13px;color:#c4b5fd}
.btn-logout{background:rgba(248,113,113,0.15);color:#f87171;border:1px solid rgba(248,113,113,0.3);padding:6px 12px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none}
.container{max-width:600px;margin:0 auto;padding:16px}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.stat-card{background:rgba(255,255,255,0.05);border:1px solid rgba(167,139,250,0.15);border-radius:14px;padding:14px;text-align:center}
.stat-card .num{font-size:22px;font-weight:700;color:#c4b5fd}
.stat-card .label{font-size:12px;color:#94a3b8;margin-top:2px}
.card{background:rgba(255,255,255,0.05);backdrop-filter:blur(12px);border:1px solid rgba(167,139,250,0.15);border-radius:18px;padding:20px;margin-bottom:16px;display:none}
.card.active{display:block}
.card h2{font-size:17px;margin-bottom:14px;color:#e9d5ff}
input,textarea{width:100%;padding:13px 14px;border-radius:12px;border:1px solid rgba(167,139,250,0.2);background:rgba(15,10,31,0.6);color:white;font-size:15px;margin-bottom:12px;outline:none}
input:focus,textarea:focus{border-color:#a78bfa}
textarea{min-height:70px;resize:vertical}
.btn-primary{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:white;font-size:16px;font-weight:600;cursor:pointer}
.search-bar{display:flex;gap:8px;margin-bottom:14px}
.search-bar input{margin-bottom:0;flex:1}
.search-bar button{background:#7c3aed;color:white;border:none;padding:0 16px;border-radius:12px;font-weight:600;cursor:pointer}
.link-item{border-bottom:1px solid rgba(167,139,250,0.1);padding:14px 0}
.link-item:last-child{border-bottom:none}
.short-url a{color:#c4b5fd;font-weight:600;text-decoration:none;word-break:break-all;font-size:14px}
.badge-on{display:inline-block;background:rgba(34,197,94,0.15);color:#4ade80;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:4px}
.badge-off{display:inline-block;background:rgba(248,113,113,0.15);color:#f87171;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:4px}
.clicks{display:inline-block;background:rgba(34,197,94,0.15);color:#4ade80;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px}
.long-url{color:#94a3b8;font-size:12px;margin-top:4px;word-break:break-all;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
.actions button,.actions a{padding:8px 14px;border-radius:10px;font-size:13px;font-weight:500;border:none;cursor:pointer;text-decoration:none}
.btn-copy{background:linear-gradient(135deg,#7c3aed,#a78bfa);color:white}
.btn-edit{background:rgba(167,139,250,0.15);color:#c4b5fd;border:1px solid rgba(167,139,250,0.3)!important}
.btn-del{background:rgba(248,113,113,0.12);color:#f87171;border:1px solid rgba(248,113,113,0.25)!important}
.btn-stats{background:rgba(56,189,248,0.15);color:#38bdf8;border:1px solid rgba(56,189,248,0.3)!important}
.msg{background:rgba(34,197,94,0.15);color:#4ade80;padding:12px;border-radius:12px;margin-bottom:14px;text-align:center;font-size:14px}
.msg-err{background:rgba(239,68,68,0.15);color:#fca5a5}
.msg-warn{background:rgba(251,191,36,0.15);color:#fbbf24;border:1px solid rgba(251,191,36,0.3)}
.empty{text-align:center;color:#64748b;padding:30px 0}
.cancel{display:block;text-align:center;margin-top:10px;color:#94a3b8;font-size:13px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:rgba(30,27,75,0.95);backdrop-filter:blur(20px);border-top:1px solid rgba(167,139,250,0.25);display:flex;justify-content:space-around;padding:8px 0 max(12px,env(safe-area-inset-bottom));z-index:100}
.nav-item{display:flex;flex-direction:column;align-items:center;text-decoration:none;color:#94a3b8;font-size:11px;gap:3px;padding:8px 16px;border-radius:14px;min-width:70px}
.nav-item.active{color:#e9d5ff;background:rgba(124,58,237,0.4);box-shadow:0 0 12px rgba(124,58,237,0.3)}
.nav-item svg{width:22px;height:22px}
.file-label{font-size:13px;color:#c4b5fd;display:block;margin-bottom:6px}
.file-input{padding:10px;background:rgba(15,10,31,0.6);border:1px solid rgba(167,139,250,0.2);border-radius:12px;color:#e2e8f0;width:100%;margin-bottom:12px}
.toggle-row{display:flex;align-items:center;justify-content:space-between;background:rgba(15,10,31,0.5);border:1px solid rgba(167,139,250,0.2);border-radius:12px;padding:12px 14px;margin-bottom:12px}
.toggle-text{font-size:14px;color:#e2e8f0}
.toggle-sub{font-size:11px;color:#94a3b8;margin-top:2px}
.switch{position:relative;width:52px;height:28px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#334155;border-radius:28px;transition:0.25s}
.slider:before{position:absolute;content:"";height:22px;width:22px;left:3px;bottom:3px;background:white;border-radius:50%;transition:0.25s}
.switch input:checked + .slider{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
.switch input:checked + .slider:before{transform:translateX(24px)}
.stat-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(167,139,250,0.1);font-size:13px}
.stat-bar-wrap{background:rgba(255,255,255,0.05);border-radius:8px;height:8px;margin-top:4px;overflow:hidden}
.stat-bar{background:linear-gradient(90deg,#7c3aed,#a78bfa);height:100%;border-radius:8px}
.click-row{font-size:12px;color:#94a3b8;padding:8px 0;border-bottom:1px solid rgba(167,139,250,0.08)}
.click-row strong{color:#e2e8f0}
</style>
</head>
<body>
<div class="header">
<h1>ShortLink</h1>
<div class="header-right">
<div class="user"><?= htmlspecialchars($_SESSION['name']) ?></div>
<a href="logout.php" class="btn-logout">Logout</a>
</div>
</div>
<div class="container">
<?php if (isset($_GET['msg'])): ?>
<div class="msg"><?php
if ($_GET['msg'] === 'created') echo "Link created successfully!";
elseif ($_GET['msg'] === 'updated') echo "Link updated!";
elseif ($_GET['msg'] === 'deleted') echo "Link deleted!";
?></div>
<?php endif; ?>
<?php if ($message): ?>
<div class="msg <?= $is_error ? 'msg-err' : '' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<div class="card <?= $tab === 'create' ? 'active' : '' ?>">
<h2><?= $edit_data ? 'Edit Link' : 'Create Short Link' ?></h2>
<?php
$editPreviewOn = true;
if ($edit_data && array_key_exists('preview_enabled', $edit_data)) {
    $editPreviewOn = ((int)$edit_data['preview_enabled'] === 1);
}
?>
<?php if ($edit_data && !$editPreviewOn): ?>
<div class="msg msg-warn">Preview is OFF — WhatsApp / Facebook pe title, image, description nahi dikhega.</div>
<?php endif; ?>
<form method="POST" enctype="multipart/form-data">
<?php if ($edit_data): ?><input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>"><?php endif; ?>
<input type="text" name="long_url" placeholder="Destination URL" required value="<?= htmlspecialchars($edit_data['long_url'] ?? '') ?>">
<input type="text" name="title" placeholder="Title (News style)" value="<?= htmlspecialchars($edit_data['title'] ?? '') ?>">
<textarea name="description" placeholder="Description (News style)"><?= htmlspecialchars($edit_data['description'] ?? '') ?></textarea>
<label class="file-label">Preview Image (Gallery se upload)</label>
<input type="file" name="image_file" accept="image/*" class="file-input">
<?php if (!empty($edit_data['image_url'])): ?>
<div style="margin-bottom:12px;font-size:12px;color:#94a3b8;">Current: <a href="<?= htmlspecialchars($edit_data['image_url']) ?>" target="_blank" style="color:#a78bfa;">View Image</a></div>
<?php endif; ?>
<input type="text" name="image_url" placeholder="Ya Image URL (Optional)" value="<?= htmlspecialchars($edit_data['image_url'] ?? '') ?>">
<div class="toggle-row">
<div>
<div class="toggle-text">Link Preview (WhatsApp / Facebook)</div>
<div class="toggle-sub">ON = preview dikhe · OFF = bilkul hide (strict)</div>
</div>
<label class="switch">
<input type="checkbox" name="preview_enabled" value="1" <?= $editPreviewOn ? 'checked' : '' ?> onchange="togglePreviewAlert(this)">
<span class="slider"></span>
</label>
</div>
<div id="previewOffAlert" class="msg msg-warn" style="display:<?= $editPreviewOn ? 'none' : 'block' ?>;">Preview OFF selected — share pe koi title/image nahi aayega.</div>
<button type="submit" class="btn-primary"><?= $edit_data ? 'Update Link' : 'Shorten Now' ?></button>
<?php if ($edit_data): ?><a href="dashboard.php?tab=create" class="cancel">Cancel Edit</a><?php endif; ?>
</form>
</div>
<div class="card <?= $tab === 'links' ? 'active' : '' ?>">
<div class="stats">
<div class="stat-card"><div class="num"><?= (int)$totalLinks ?></div><div class="label">My Links</div></div>
<div class="stat-card"><div class="num"><?= (int)$totalClicks ?></div><div class="label">Total Clicks</div></div>
</div>
<h2>Your Links</h2>
<form method="GET" class="search-bar">
<input type="hidden" name="tab" value="links">
<input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
<button type="submit">Search</button>
</form>
<?php if (empty($links)): ?>
<div class="empty">No links yet. Create your first short link!</div>
<?php else: ?>
<?php foreach ($links as $link):
    $pOn = true;
    if (array_key_exists('preview_enabled', $link)) $pOn = ((int)$link['preview_enabled'] === 1);
?>
<div class="link-item">
<div class="short-url">
<a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a>
<span class="clicks"><?= (int)($link['clicks'] ?? 0) ?> clicks</span>
<?php if ($pOn): ?><span class="badge-on">Preview ON</span><?php else: ?><span class="badge-off">Preview OFF</span><?php endif; ?>
</div>
<div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
<div class="actions">
<button class="btn-copy" onclick="copyLink(this, 'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
<a href="?stats=<?= (int)$link['id'] ?>" class="btn-stats">Stats</a>
<a href="?tab=create&edit=<?= (int)$link['id'] ?>" class="btn-edit">Edit</a>
<a href="?delete=<?= (int)$link['id'] ?>" class="btn-del" onclick="return confirm('Delete this link?')">Del</a>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<div class="card <?= $tab === 'stats' ? 'active' : '' ?>">
<?php if ($stats): ?>
<h2>Traffic Stats</h2>
<p style="font-size:13px;color:#c4b5fd;margin-bottom:8px;word-break:break-all;">https://<?= $host ?>/<?= htmlspecialchars($stats['short_code']) ?></p>
<p style="font-size:12px;color:#94a3b8;margin-bottom:8px;"><?= (int)($stats['clicks'] ?? 0) ?> real clicks
<?php $sp = !array_key_exists('preview_enabled', $stats) || (int)$stats['preview_enabled'] === 1; ?>
<?php if ($sp): ?><span class="badge-on">Preview ON</span><?php else: ?><span class="badge-off">Preview OFF</span><?php endif; ?>
</p>
<?php if ($stats_error): ?><div class="msg msg-err"><?= htmlspecialchars($stats_error) ?></div><?php endif; ?>
<h2 style="font-size:15px;">By Device</h2>
<?php $maxd = 1; if (!empty($by_device)) $maxd = max(array_column($by_device, 'c')) ?: 1; foreach ($by_device as $row): $pct = round(((int)$row['c'] / $maxd) * 100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['device']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; ?>
<?php if (empty($by_device)): ?><p class="empty" style="padding:10px 0;">No click data yet — open the short link once</p><?php endif; ?>
<h2 style="font-size:15px;margin-top:20px;">By Browser</h2>
<?php foreach ($by_browser as $row): ?>
<div class="stat-row"><span><?= htmlspecialchars($row['browser']) ?></span><span><?= (int)$row['c'] ?></span></div>
<?php endforeach; ?>
<?php if (empty($by_browser)): ?><p class="empty" style="padding:10px 0;">No data yet</p><?php endif; ?>
<h2 style="font-size:15px;margin-top:20px;">By Country</h2>
<?php foreach ($by_country as $row): ?>
<div class="stat-row"><span><?= htmlspecialchars($row['country']) ?></span><span><?= (int)$row['c'] ?></span></div>
<?php endforeach; ?>
<?php if (empty($by_country)): ?><p class="empty" style="padding:10px 0;">No data yet</p><?php endif; ?>
<h2 style="font-size:15px;margin-top:20px;">Traffic Sources</h2>
<?php foreach ($by_referer as $row): ?>
<div class="stat-row"><span style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_substr($row['ref'], 0, 60)) ?></span><span><?= (int)$row['c'] ?></span></div>
<?php endforeach; ?>
<?php if (empty($by_referer)): ?><p class="empty" style="padding:10px 0;">No data yet</p><?php endif; ?>
<h2 style="font-size:15px;margin-top:20px;">Recent Clicks</h2>
<?php foreach ($recent_clicks as $c): ?>
<div class="click-row">
<strong><?= htmlspecialchars($c['device'] ?? '-') ?></strong> · <?= htmlspecialchars($c['browser'] ?? '-') ?> · <?= htmlspecialchars($c['os'] ?? '-') ?>
<?php if (!empty($c['country'])): ?> · <?= htmlspecialchars($c['country']) ?><?php endif; ?><br>
IP: <?= htmlspecialchars($c['ip'] ?? '-') ?> · <?= htmlspecialchars($c['created_at'] ?? '') ?>
<?php if (!empty($c['referer'])): ?><br>From: <?= htmlspecialchars(mb_substr($c['referer'], 0, 80)) ?><?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (empty($recent_clicks)): ?><p class="empty" style="padding:10px 0;">No clicks yet</p><?php endif; ?>
<a href="dashboard.php?tab=links" class="cancel" style="margin-top:16px;">← Back to Links</a>
<?php else: ?>
<div class="empty">Select a link and tap Stats</div>
<a href="dashboard.php?tab=links" class="cancel">← Back to Links</a>
<?php endif; ?>
</div>
</div>
<nav class="bottom-nav">
<a href="dashboard.php?tab=create" class="nav-item <?= $tab === 'create' ? 'active' : '' ?>">
<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
Create
</a>
<a href="dashboard.php?tab=links" class="nav-item <?= ($tab === 'links' || $tab === 'stats') ? 'active' : '' ?>">
<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
My Links
</a>
</nav>
<script>
function copyLink(btn, url) {
  navigator.clipboard.writeText(url).then(() => {
    const o = btn.innerText;
    btn.innerText = "Copied!";
    btn.style.background = "#22c55e";
    setTimeout(() => { btn.innerText = o; btn.style.background = ""; }, 1500);
  }).catch(() => alert("Copy failed"));
}
function togglePreviewAlert(el) {
  var a = document.getElementById('previewOffAlert');
  if (a) a.style.display = el.checked ? 'none' : 'block';
}
</script>
</body>
</html>
