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

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try { $pdo->prepare("DELETE FROM clicks WHERE url_id = ?")->execute([$id]); } catch (Exception $e) {}
    $pdo->prepare("DELETE FROM urls WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
    header("Location: dashboard.php?tab=links&msg=deleted");
    exit;
}

// Load edit
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['edit'], $user_id]);
    $edit_data = $stmt->fetch();
    $tab = 'create';
}

// Create / Update
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

// Always load links
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

// Global analytics for this user
$by_device = []; $by_browser = []; $by_country = []; $by_os = [];
$top_links = []; $recent_clicks_global = [];
$clicks_today = 0; $clicks_7d = 0; $analytics_error = '';
try {
    $by_device = $pdo->prepare("SELECT COALESCE(device,'Unknown') as device, COUNT(*) as c FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 GROUP BY device ORDER BY c DESC");
    $by_device->execute([$user_id]); $by_device = $by_device->fetchAll();

    $by_browser = $pdo->prepare("SELECT COALESCE(browser,'Unknown') as browser, COUNT(*) as c FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 GROUP BY browser ORDER BY c DESC");
    $by_browser->execute([$user_id]); $by_browser = $by_browser->fetchAll();

    $by_country = $pdo->prepare("SELECT COALESCE(country,'Unknown') as country, COUNT(*) as c FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 GROUP BY country ORDER BY c DESC LIMIT 15");
    $by_country->execute([$user_id]); $by_country = $by_country->fetchAll();

    $by_os = $pdo->prepare("SELECT COALESCE(os,'Unknown') as os, COUNT(*) as c FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 GROUP BY os ORDER BY c DESC");
    $by_os->execute([$user_id]); $by_os = $by_os->fetchAll();

    $top_links = $pdo->prepare("SELECT short_code, title, clicks FROM urls WHERE user_id = ? ORDER BY clicks DESC LIMIT 10");
    $top_links->execute([$user_id]); $top_links = $top_links->fetchAll();

    $recent_clicks_global = $pdo->prepare("SELECT c.*, u.short_code FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 ORDER BY c.id DESC LIMIT 20");
    $recent_clicks_global->execute([$user_id]); $recent_clicks_global = $recent_clicks_global->fetchAll();

    $st = $pdo->prepare("SELECT COUNT(*) FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 AND DATE(c.created_at) = CURDATE()");
    $st->execute([$user_id]); $clicks_today = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM clicks c JOIN urls u ON u.id = c.url_id WHERE u.user_id = ? AND c.is_bot = 0 AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $st->execute([$user_id]); $clicks_7d = (int)$st->fetchColumn();
} catch (Exception $e) {
    $analytics_error = $e->getMessage();
}

// Per-link stats
$stats = null;
$recent_clicks = [];
$s_device = []; $s_browser = []; $s_country = []; $s_referer = [];
$stats_error = '';
if ($stats_link_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->execute([$stats_link_id, $user_id]);
    $stats = $stmt->fetch();
    if ($stats) {
        $tab = 'stats';
        try {
            $q = $pdo->prepare("SELECT * FROM clicks WHERE url_id = ? AND is_bot = 0 ORDER BY id DESC LIMIT 80");
            $q->execute([$stats_link_id]); $recent_clicks = $q->fetchAll();

            $q = $pdo->prepare("SELECT COALESCE(device,'Unknown') as device, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY device ORDER BY c DESC");
            $q->execute([$stats_link_id]); $s_device = $q->fetchAll();

            $q = $pdo->prepare("SELECT COALESCE(browser,'Unknown') as browser, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY browser ORDER BY c DESC");
            $q->execute([$stats_link_id]); $s_browser = $q->fetchAll();

            $q = $pdo->prepare("SELECT COALESCE(country,'Unknown') as country, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY country ORDER BY c DESC LIMIT 15");
            $q->execute([$stats_link_id]); $s_country = $q->fetchAll();

            $q = $pdo->prepare("SELECT CASE WHEN referer IS NULL OR referer = '' THEN 'Direct' ELSE referer END as ref, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY ref ORDER BY c DESC LIMIT 20");
            $q->execute([$stats_link_id]); $s_referer = $q->fetchAll();
        } catch (Exception $e) {
            $stats_error = $e->getMessage();
        }
    }
}

if (isset($_GET['msg']) && in_array($_GET['msg'], ['created','updated','deleted'], true) && !isset($_GET['tab'])) {
    $tab = 'links';
}
if (isset($_GET['tab'])) $tab = $_GET['tab'];
if ($stats_link_id > 0 && $stats) $tab = 'stats';

function barMax($rows) {
    if (empty($rows)) return 1;
    $m = max(array_map(function($r){ return (int)$r['c']; }, $rows));
    return $m > 0 ? $m : 1;
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Dashboard - ShortLink</title>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root,[data-theme="dark"]{--bg:#0f0a1f;--bg2:rgba(255,255,255,0.05);--bg3:rgba(15,10,31,0.6);--border:rgba(167,139,250,0.18);--text:#e2e8f0;--muted:#94a3b8;--accent:#c4b5fd;--heading:#e9d5ff;--header-bg:rgba(76,29,149,0.5);--nav-bg:rgba(30,27,75,0.96);--primary:linear-gradient(135deg,#7c3aed,#a78bfa);--primary-solid:#7c3aed;--ok:#4ade80;--ok-bg:rgba(34,197,94,0.15);--err:#f87171;--err-bg:rgba(248,113,113,0.15);--warn:#fbbf24;--warn-bg:rgba(251,191,36,0.15);--bar:linear-gradient(90deg,#7c3aed,#a78bfa);--bar-blue:linear-gradient(90deg,#0284c7,#38bdf8);--bar-green:linear-gradient(90deg,#059669,#34d399);--bar-orange:linear-gradient(90deg,#c2410c,#fb923c);--input-border:rgba(167,139,250,0.25);--shadow:0 0 12px rgba(124,58,237,0.25)}
[data-theme="light"]{--bg:#f1f5f9;--bg2:#fff;--bg3:#f8fafc;--border:rgba(100,116,139,0.2);--text:#0f172a;--muted:#64748b;--accent:#6d28d9;--heading:#4c1d95;--header-bg:rgba(255,255,255,0.92);--nav-bg:rgba(255,255,255,0.96);--primary:linear-gradient(135deg,#7c3aed,#6d28d9);--primary-solid:#7c3aed;--ok:#16a34a;--ok-bg:rgba(22,163,74,0.12);--err:#dc2626;--err-bg:rgba(220,38,38,0.1);--warn:#d97706;--warn-bg:rgba(217,119,6,0.12);--bar:linear-gradient(90deg,#7c3aed,#6d28d9);--bar-blue:linear-gradient(90deg,#0284c7,#0ea5e9);--bar-green:linear-gradient(90deg,#059669,#10b981);--bar-orange:linear-gradient(90deg,#ea580c,#f97316);--input-border:rgba(100,116,139,0.3);--shadow:0 2px 12px rgba(124,58,237,0.15)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:100px;transition:background .25s,color .25s}
.header{background:var(--header-bg);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);padding:12px 16px;position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:18px;color:var(--heading)}
.header-right{display:flex;align-items:center;gap:8px}
.header .user{font-size:13px;color:var(--accent)}
.icon-btn{background:var(--bg2);border:1px solid var(--border);color:var(--text);width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.icon-btn svg{width:18px;height:18px}
.btn-logout{background:var(--err-bg);color:var(--err);border:1px solid var(--err);padding:6px 12px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none}
.container{max-width:600px;margin:0 auto;padding:16px}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:16px;text-align:center}
.stat-card .num{font-size:24px;font-weight:700;color:var(--accent)}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:18px;padding:20px;margin-bottom:16px;display:none}
.card.active{display:block}
.card h2{font-size:17px;margin-bottom:14px;color:var(--heading)}
.card h3{font-size:14px;margin:18px 0 10px;color:var(--accent)}
input,textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--input-border);background:var(--bg3);color:var(--text);font-size:15px;margin-bottom:10px;outline:none}
.btn-primary{width:100%;padding:13px;border:none;border-radius:12px;background:var(--primary);color:#fff;font-size:15px;font-weight:600;cursor:pointer}
.search-bar{display:flex;gap:8px;margin-bottom:14px}
.search-bar input{margin-bottom:0;flex:1}
.search-bar button{background:var(--primary-solid);color:#fff;border:none;padding:0 16px;border-radius:12px;font-weight:600;cursor:pointer}
.link-item{border-bottom:1px solid var(--border);padding:12px 0}
.short-url a{color:var(--accent);text-decoration:none;font-weight:600;font-size:14px;word-break:break-all}
.long-url{font-size:12px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.clicks{display:inline-block;background:var(--ok-bg);color:var(--ok);font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px}
.badge-on{display:inline-block;background:var(--ok-bg);color:var(--ok);font-size:10px;padding:2px 8px;border-radius:10px;margin-left:4px}
.badge-off{display:inline-block;background:var(--err-bg);color:var(--err);font-size:10px;padding:2px 8px;border-radius:10px;margin-left:4px}
.actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
.actions button,.actions a{padding:8px 12px;border-radius:10px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none}
.btn-copy{background:var(--primary);color:#fff}
.btn-edit{background:rgba(124,58,237,0.12);color:var(--accent);border:1px solid var(--border)!important}
.btn-stats{background:rgba(56,189,248,0.12);color:#38bdf8;border:1px solid rgba(56,189,248,0.3)!important}
.btn-del{background:var(--err-bg);color:var(--err);border:1px solid var(--err)}
.msg{background:var(--ok-bg);color:var(--ok);padding:12px;border-radius:12px;margin-bottom:14px;text-align:center;font-size:14px}
.msg-err,.error{background:var(--err-bg);color:var(--err)}
.msg-warn{background:var(--warn-bg);color:var(--warn);border:1px solid var(--warn)}
.empty{text-align:center;color:var(--muted);padding:20px 0}
.cancel{display:block;text-align:center;margin-top:10px;color:var(--muted);font-size:13px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--nav-bg);backdrop-filter:blur(20px);border-top:1px solid var(--border);display:flex;justify-content:space-around;padding:6px 0 max(10px,env(safe-area-inset-bottom));z-index:100}
.nav-item{display:flex;flex-direction:column;align-items:center;text-decoration:none;color:var(--muted);font-size:10px;gap:2px;padding:6px 10px;border-radius:12px;min-width:60px}
.nav-item.active{color:var(--heading);background:rgba(124,58,237,0.25)}
.nav-item svg{width:20px;height:20px}
.file-label{font-size:13px;color:var(--accent);display:block;margin-bottom:6px}
.file-input{padding:10px;background:var(--bg3);border:1px solid var(--input-border);border-radius:12px;color:var(--text);width:100%;margin-bottom:10px}
.toggle-row{display:flex;align-items:center;justify-content:space-between;background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:12px}
.toggle-text{font-size:14px}
.toggle-sub{font-size:11px;color:var(--muted);margin-top:2px}
.switch{position:relative;width:52px;height:28px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#334155;border-radius:28px;transition:.25s}
.slider:before{position:absolute;content:"";height:22px;width:22px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s}
.switch input:checked+.slider{background:var(--primary-solid)}
.switch input:checked+.slider:before{transform:translateX(24px)}
.stat-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}
.stat-bar-wrap{background:rgba(128,128,128,0.12);border-radius:8px;height:10px;margin:4px 0 10px;overflow:hidden}
.stat-bar{background:var(--bar);height:100%;border-radius:8px;min-width:2px}
.stat-bar.blue{background:var(--bar-blue)}
.stat-bar.green{background:var(--bar-green)}
.stat-bar.orange{background:var(--bar-orange)}
.click-row{font-size:12px;color:var(--muted);padding:8px 0;border-bottom:1px solid var(--border)}
.click-row strong{color:var(--text)}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.chart-box{background:var(--bg3);border:1px solid var(--border);border-radius:14px;padding:12px}
.chart-box h4{font-size:12px;color:var(--muted);margin-bottom:8px}
</style>
</head>
<body>
<div class="header">
<h1>ShortLink</h1>
<div class="header-right">
<button type="button" class="icon-btn" id="themeToggle" title="Toggle theme" aria-label="Toggle dark/light mode"><i data-lucide="moon" id="themeIcon"></i></button>
<span class="user"><?= htmlspecialchars($_SESSION['name']) ?></span>
<a href="logout.php" class="btn-logout">Logout</a>
</div>
</div>

<div class="container">
<?php if (isset($_GET['msg'])): ?>
<div class="msg">
<?php
if ($_GET['msg'] === 'created') echo "Link created successfully!";
elseif ($_GET['msg'] === 'updated') echo "Link updated!";
elseif ($_GET['msg'] === 'deleted') echo "Link deleted!";
?>
</div>
<?php endif; ?>
<?php if ($message): ?>
<div class="msg <?= $is_error ? 'msg-err' : '' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- CREATE -->
<div class="card <?= $tab === 'create' ? 'active' : '' ?>">
<h2><?= $edit_data ? 'Edit Link' : 'Create Short Link' ?></h2>
<?php
$editPreviewOn = true;
if ($edit_data && array_key_exists('preview_enabled', $edit_data)) {
    $editPreviewOn = ((int)$edit_data['preview_enabled'] === 1);
}
?>
<?php if ($edit_data && !$editPreviewOn): ?>
<div class="msg msg-warn">Preview is OFF — WhatsApp / Facebook will show no title, image or description.</div>
<?php endif; ?>
<form method="POST" enctype="multipart/form-data">
<?php if ($edit_data): ?><input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>"><?php endif; ?>
<input type="text" name="long_url" placeholder="Destination URL" required value="<?= htmlspecialchars($edit_data['long_url'] ?? '') ?>">
<input type="text" name="title" placeholder="Title (News style)" value="<?= htmlspecialchars($edit_data['title'] ?? '') ?>">
<textarea name="description" placeholder="Description (News style)"><?= htmlspecialchars($edit_data['description'] ?? '') ?></textarea>
<label class="file-label">Preview Image (from gallery)</label>
<input type="file" name="image_file" accept="image/*" class="file-input">
<?php if (!empty($edit_data['image_url'])): ?>
<div style="margin-bottom:12px;font-size:12px;color:var(--muted);">Current: <a href="<?= htmlspecialchars($edit_data['image_url']) ?>" target="_blank" style="color:var(--accent);">View Image</a></div>
<?php endif; ?>
<input type="text" name="image_url" placeholder="Or Image URL (Optional)" value="<?= htmlspecialchars($edit_data['image_url'] ?? '') ?>">
<div class="toggle-row">
<div>
<div class="toggle-text">Link Preview (WhatsApp / Facebook)</div>
<div class="toggle-sub">ON = show preview · OFF = fully hide (strict)</div>
</div>
<label class="switch">
<input type="checkbox" name="preview_enabled" value="1" id="previewToggle" <?= $editPreviewOn ? 'checked' : '' ?> onchange="togglePreviewAlert(this)">
<span class="slider"></span>
</label>
</div>
<div id="previewOffAlert" class="msg msg-warn" style="display:<?= $editPreviewOn ? 'none' : 'block' ?>;">Preview OFF — no title/image will appear anywhere.</div>
<button type="submit" class="btn-primary"><?= $edit_data ? 'Update Link' : 'Shorten Now' ?></button>
<?php if ($edit_data): ?><a href="dashboard.php?tab=create" class="cancel">Cancel Edit</a><?php endif; ?>
</form>
</div>

<!-- LINKS -->
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
<?php if ($pOn): ?><span class="badge-on">ON</span><?php else: ?><span class="badge-off">OFF</span><?php endif; ?>
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

<!-- ANALYTICS (fully separate tab) -->
<div class="card <?= $tab === 'analytics' ? 'active' : '' ?>">
<h2>Analytics</h2>
<div class="stats">
<div class="stat-card"><div class="num"><?= $clicks_today ?></div><div class="label">Today</div></div>
<div class="stat-card"><div class="num"><?= $clicks_7d ?></div><div class="label">Last 7 Days</div></div>
<div class="stat-card"><div class="num"><?= $totalClicks ?></div><div class="label">All Time</div></div>
<div class="stat-card"><div class="num"><?= $totalLinks ?></div><div class="label">Links</div></div>
</div>
<?php if ($analytics_error): ?><div class="msg msg-err"><?= htmlspecialchars($analytics_error) ?></div><?php endif; ?>

<h3>Traffic by Device</h3>
<?php $maxd = barMax($by_device); foreach ($by_device as $row): $pct = round(((int)$row['c'] / $maxd) * 100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['device']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_device)): ?><p class="empty" style="padding:8px 0;">No clicks yet — open a short link once</p><?php endif; ?>

<div class="chart-grid">
<div class="chart-box"><h4>Browser</h4>
<?php $maxb = barMax($by_browser); foreach (array_slice($by_browser, 0, 6) as $row): $pct = round(((int)$row['c'] / $maxb) * 100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['browser']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar blue" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_browser)): ?><p class="empty" style="padding:6px 0;font-size:12px;">No data</p><?php endif; ?>
</div>
<div class="chart-box"><h4>OS</h4>
<?php $maxo = barMax($by_os); foreach (array_slice($by_os, 0, 6) as $row): $pct = round(((int)$row['c'] / $maxo) * 100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['os']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar orange" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_os)): ?><p class="empty" style="padding:6px 0;font-size:12px;">No data</p><?php endif; ?>
</div>
</div>

<h3>By Country</h3>
<?php $maxc = barMax($by_country); foreach ($by_country as $row): $pct = round(((int)$row['c'] / $maxc) * 100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['country']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar green" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($by_country)): ?><p class="empty" style="padding:8px 0;">No data yet</p><?php endif; ?>

<h3>Top Links</h3>
<?php if (empty($top_links)): ?><p class="empty" style="padding:8px 0;">No links</p>
<?php else: $maxt = max(1, max(array_column($top_links, 'clicks'))); foreach ($top_links as $tl): $pct = round(((int)$tl['clicks'] / $maxt) * 100); ?>
<div class="stat-row"><span style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">/<?= htmlspecialchars($tl['short_code']) ?></span><span><?= (int)$tl['clicks'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; endif; ?>

<h3>Recent Clicks</h3>
<?php foreach ($recent_clicks_global as $c): ?>
<div class="click-row"><strong>/<?= htmlspecialchars($c['short_code'] ?? '?') ?></strong> · <?= htmlspecialchars($c['device'] ?? '-') ?> · <?= htmlspecialchars($c['browser'] ?? '-') ?><?php if (!empty($c['country'])): ?> · <?= htmlspecialchars($c['country']) ?><?php endif; ?><br><?= htmlspecialchars($c['ip'] ?? '-') ?> · <?= htmlspecialchars($c['created_at'] ?? '') ?></div>
<?php endforeach; if (empty($recent_clicks_global)): ?><p class="empty" style="padding:8px 0;">No clicks logged yet</p><?php endif; ?>
</div>

<!-- PER-LINK STATS -->
<div class="card <?= $tab === 'stats' ? 'active' : '' ?>">
<?php if ($stats): ?>
<h2>Link Traffic</h2>
<p style="font-size:13px;color:var(--accent);margin-bottom:8px;word-break:break-all;">https://<?= $host ?>/<?= htmlspecialchars($stats['short_code']) ?></p>
<p style="font-size:12px;color:var(--muted);margin-bottom:8px;"><?= (int)($stats['clicks'] ?? 0) ?> real clicks
<?php
$sp = true;
if (array_key_exists('preview_enabled', $stats)) $sp = ((int)$stats['preview_enabled'] === 1);
?>
<?php if ($sp): ?><span class="badge-on">Preview ON</span><?php else: ?><span class="badge-off">Preview OFF</span><?php endif; ?>
</p>
<?php if ($stats_error): ?><div class="msg msg-err"><?= htmlspecialchars($stats_error) ?></div><?php endif; ?>

<h3>By Device</h3>
<?php $maxd = barMax($s_device); foreach ($s_device as $row): $pct = round(((int)$row['c'] / $maxd) * 100); ?>
<div class="stat-row"><span><?= htmlspecialchars($row['device']) ?></span><span><?= (int)$row['c'] ?></span></div>
<div class="stat-bar-wrap"><div class="stat-bar" style="width:<?= $pct ?>%"></div></div>
<?php endforeach; if (empty($s_device)): ?><p class="empty" style="padding:8px 0;">No click data yet</p><?php endif; ?>

<h3>Browser</h3>
<?php foreach ($s_browser as $row): ?><div class="stat-row"><span><?= htmlspecialchars($row['browser']) ?></span><span><?= (int)$row['c'] ?></span></div><?php endforeach; ?>
<?php if (empty($s_browser)): ?><p class="empty" style="padding:8px 0;">No data</p><?php endif; ?>

<h3>Country</h3>
<?php foreach ($s_country as $row): ?><div class="stat-row"><span><?= htmlspecialchars($row['country']) ?></span><span><?= (int)$row['c'] ?></span></div><?php endforeach; ?>
<?php if (empty($s_country)): ?><p class="empty" style="padding:8px 0;">No data</p><?php endif; ?>

<h3>Traffic Sources</h3>
<?php foreach ($s_referer as $row): ?>
<div class="stat-row"><span style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_substr($row['ref'], 0, 55)) ?></span><span><?= (int)$row['c'] ?></span></div>
<?php endforeach; ?>
<?php if (empty($s_referer)): ?><p class="empty" style="padding:8px 0;">No data</p><?php endif; ?>

<h3>Recent Clicks</h3>
<?php foreach ($recent_clicks as $c): ?>
<div class="click-row"><strong><?= htmlspecialchars($c['device'] ?? '-') ?></strong> · <?= htmlspecialchars($c['browser'] ?? '-') ?> · <?= htmlspecialchars($c['os'] ?? '-') ?><?php if (!empty($c['country'])): ?> · <?= htmlspecialchars($c['country']) ?><?php endif; ?><br>IP: <?= htmlspecialchars($c['ip'] ?? '-') ?> · <?= htmlspecialchars($c['created_at'] ?? '') ?></div>
<?php endforeach; if (empty($recent_clicks)): ?><p class="empty" style="padding:8px 0;">No clicks yet</p><?php endif; ?>
<a href="dashboard.php?tab=links" class="cancel" style="margin-top:16px;">← Back to Links</a>
<?php else: ?>
<div class="empty">Select a link and tap Stats</div>
<a href="dashboard.php?tab=links" class="cancel">← Back to Links</a>
<?php endif; ?>
</div>

</div>

<nav class="bottom-nav">
<a href="dashboard.php?tab=create" class="nav-item <?= $tab === 'create' ? 'active' : '' ?>"><i data-lucide="plus"></i>Create</a>
<a href="dashboard.php?tab=links" class="nav-item <?= ($tab === 'links' || $tab === 'stats') ? 'active' : '' ?>"><i data-lucide="link"></i>Links</a>
<a href="dashboard.php?tab=analytics" class="nav-item <?= $tab === 'analytics' ? 'active' : '' ?>"><i data-lucide="bar-chart-2"></i>Analytics</a>
</nav>

<script>
(function(){
  const root = document.documentElement;
  const saved = localStorage.getItem('sl_theme') || 'dark';
  root.setAttribute('data-theme', saved);
  function setIcon(theme) {
    const icon = document.getElementById('themeIcon');
    if (!icon) return;
    icon.setAttribute('data-lucide', theme === 'dark' ? 'sun' : 'moon');
    if (window.lucide) lucide.createIcons();
  }
  setIcon(saved);
  document.getElementById('themeToggle').addEventListener('click', function() {
    const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    localStorage.setItem('sl_theme', next);
    setIcon(next);
  });
  if (window.lucide) lucide.createIcons();
})();
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
