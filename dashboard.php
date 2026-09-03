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
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $newName)) {
                $image_url = 'https://' . $host . '/uploads/' . $newName;
            }
        }
    }

    if (empty($long_url) || !filter_var($long_url, FILTER_VALIDATE_URL)) {
        $message = "Valid long URL required";
        $is_error = true;
    } else {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE urls SET long_url=?, title=?, image_url=?, description=?, preview_enabled=? WHERE id=? AND user_id=?");
            $stmt->execute([$long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled, $id, $user_id]);
            header("Location: dashboard.php?tab=links&msg=updated");
            exit;
        } else {
            $short_code = generateShortCode();
            $attempts = 0;
            while ($attempts < 5) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO urls (user_id, short_code, long_url, title, image_url, description, preview_enabled) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$user_id, $short_code, $long_url, $title ?: null, $image_url ?: null, $description ?: null, $preview_enabled]);
                    header("Location: dashboard.php?tab=links&msg=created");
                    exit;
                } catch (PDOException $e) {
                    $short_code = generateShortCode();
                    $attempts++;
                }
            }
            $message = "Error creating link";
            $is_error = true;
        }
    }
}

// Search + List
$search = trim($_GET['search'] ?? '');
if ($search) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE user_id = ? AND (short_code LIKE ? OR long_url LIKE ? OR title LIKE ?) ORDER BY id DESC");
    $like = "%$search%";
    $stmt->execute([$user_id, $like, $like, $like]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$user_id]);
}
$links = $stmt->fetchAll();

$totalLinks = count($links);
$totalClicks = 0;
foreach ($links as $l) $totalClicks += (int)$l['clicks'];

// Stats for one link
$stats = null;
if ($stats_link_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->execute([$stats_link_id, $user_id]);
    $stats_link = $stmt->fetch();
    if ($stats_link) {
        try {
            $by_day = $pdo->prepare("SELECT DATE(created_at) as d, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 14");
            $by_day->execute([$stats_link_id]);
            $by_device = $pdo->prepare("SELECT COALESCE(device,'Unknown') as k, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY device ORDER BY c DESC LIMIT 8");
            $by_device->execute([$stats_link_id]);
            $by_browser = $pdo->prepare("SELECT COALESCE(browser,'Unknown') as k, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY browser ORDER BY c DESC LIMIT 8");
            $by_browser->execute([$stats_link_id]);
            $by_os = $pdo->prepare("SELECT COALESCE(os,'Unknown') as k, COUNT(*) as c FROM clicks WHERE url_id = ? AND is_bot = 0 GROUP BY os ORDER BY c DESC LIMIT 8");
            $by_os->execute([$stats_link_id]);
            $stats = [
                'link' => $stats_link,
                'by_day' => $by_day->fetchAll(),
                'by_device' => $by_device->fetchAll(),
                'by_browser' => $by_browser->fetchAll(),
                'by_os' => $by_os->fetchAll(),
            ];
            $tab = 'stats';
        } catch (Exception $e) {}
    }
}
if (isset($_GET['tab'])) $tab = $_GET['tab'];
if ($stats_link_id > 0 && $stats) $tab = 'stats';
function barMax($rows) { if (empty($rows)) return 1; $m = max(array_map(function($r){return (int)$r['c'];}, $rows)); return $m > 0 ? $m : 1; }
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Dashboard - ShortLink</title>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root,[data-theme="dark"]{--bg:#0a0a0a;--bg2:rgba(255,255,255,0.05);--bg3:rgba(20,20,20,0.7);--border:rgba(220,38,38,0.2);--text:#f5f5f5;--muted:#a3a3a3;--accent:#f87171;--heading:#fecaca;--header-bg:rgba(20,20,20,0.85);--nav-bg:rgba(15,15,15,0.97);--primary:linear-gradient(135deg,#dc2626,#ef4444);--primary-solid:#dc2626;--ok:#4ade80;--ok-bg:rgba(34,197,94,0.15);--err:#f87171;--err-bg:rgba(248,113,113,0.15);--warn:#fbbf24;--warn-bg:rgba(251,191,36,0.15);--bar:linear-gradient(90deg,#dc2626,#ef4444);--bar-blue:linear-gradient(90deg,#525252,#a3a3a3);--bar-green:linear-gradient(90deg,#059669,#34d399);--bar-orange:linear-gradient(90deg,#c2410c,#fb923c);--input-border:rgba(220,38,38,0.3);--shadow:0 0 12px rgba(220,38,38,0.25)}
[data-theme="light"]{--bg:#f5f5f5;--bg2:#ffffff;--bg3:#fafafa;--border:rgba(115,115,115,0.25);--text:#171717;--muted:#737373;--accent:#dc2626;--heading:#991b1b;--header-bg:rgba(255,255,255,0.95);--nav-bg:rgba(255,255,255,0.98);--primary:linear-gradient(135deg,#dc2626,#b91c1c);--primary-solid:#dc2626;--ok:#16a34a;--ok-bg:rgba(22,163,74,0.12);--err:#dc2626;--err-bg:rgba(220,38,38,0.1);--warn:#d97706;--warn-bg:rgba(217,119,6,0.12);--bar:linear-gradient(90deg,#dc2626,#b91c1c);--bar-blue:linear-gradient(90deg,#525252,#737373);--bar-green:linear-gradient(90deg,#059669,#10b981);--bar-orange:linear-gradient(90deg,#ea580c,#f97316);--input-border:rgba(115,115,115,0.35);--shadow:0 2px 12px rgba(220,38,38,0.12)}
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
.msg{padding:12px;border-radius:12px;margin-bottom:14px;font-size:14px;text-align:center}
.msg.ok{background:var(--ok-bg);color:var(--ok)}
.msg.err{background:var(--err-bg);color:var(--err)}
.search-bar{display:flex;gap:8px;margin-bottom:14px}
.search-bar input{margin:0;flex:1}
.search-bar button{background:var(--primary-solid);color:#fff;border:none;padding:0 16px;border-radius:12px;font-weight:600;cursor:pointer}
.link-item{background:var(--bg3);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:10px}
.link-item .code{font-weight:700;color:var(--accent);font-size:15px}
.link-item .url{font-size:12px;color:var(--muted);word-break:break-all;margin:6px 0}
.link-item .meta{font-size:12px;color:var(--muted)}
.link-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.link-actions a,.link-actions button{font-size:12px;padding:6px 10px;border-radius:8px;border:1px solid var(--border);background:var(--bg2);color:var(--text);text-decoration:none;cursor:pointer}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--nav-bg);backdrop-filter:blur(16px);border-top:1px solid var(--border);display:flex;justify-content:space-around;padding:10px 0 calc(10px + env(safe-area-inset-bottom));z-index:100}
.bottom-nav a{display:flex;flex-direction:column;align-items:center;gap:4px;color:var(--muted);text-decoration:none;font-size:11px;font-weight:500}
.bottom-nav a.active{color:var(--accent)}
.bottom-nav svg{width:22px;height:22px}
.bar-row{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px}
.bar-row .label{width:80px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-row .track{flex:1;height:8px;background:var(--bg3);border-radius:4px;overflow:hidden}
.bar-row .fill{height:100%;background:var(--bar);border-radius:4px}
.bar-row .val{width:36px;text-align:right;color:var(--muted)}
.toggle{display:flex;align-items:center;gap:10px;margin:12px 0}
.toggle input{width:auto;margin:0}
label.file-btn{display:inline-block;padding:10px 14px;background:var(--bg3);border:1px dashed var(--border);border-radius:12px;cursor:pointer;font-size:13px;color:var(--muted);margin-bottom:10px}
</style>
</head>
<body>
<div class="header">
  <h1>ShortLink</h1>
  <div class="header-right">
    <span class="user"><?= htmlspecialchars($_SESSION['name'] ?? '') ?></span>
    <button class="icon-btn" onclick="document.documentElement.setAttribute('data-theme', document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark')" title="Theme"><i data-lucide="sun-moon"></i></button>
    <a class="btn-logout" href="logout.php">Logout</a>
  </div>
</div>
<div class="container">
  <div class="stats">
    <div class="stat-card"><div class="num"><?= $totalLinks ?></div><div class="label">My Links</div></div>
    <div class="stat-card"><div class="num"><?= $totalClicks ?></div><div class="label">Total Clicks</div></div>
  </div>

  <?php if ($message): ?>
  <div class="msg <?= $is_error ? 'err' : 'ok' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['msg'])): ?>
  <div class="msg ok"><?= htmlspecialchars($_GET['msg']) ?></div>
  <?php endif; ?>

  <div class="card <?= $tab==='create'?'active':'' ?>" id="tab-create">
    <h2><?= $edit_data ? 'Edit Link' : 'Create Short Link' ?></h2>
    <form method="POST" enctype="multipart/form-data">
      <?php if ($edit_data): ?><input type="hidden" name="id" value="<?= (int)$edit_data['id'] ?>"><?php endif; ?>
      <input type="url" name="long_url" placeholder="https://example.com/long-url" required value="<?= htmlspecialchars($edit_data['long_url'] ?? '') ?>">
      <input type="text" name="title" placeholder="Preview title (e.g. Breaking News)" value="<?= htmlspecialchars($edit_data['title'] ?? '') ?>">
      <textarea name="description" rows="2" placeholder="Preview description"><?= htmlspecialchars($edit_data['description'] ?? '') ?></textarea>
      <input type="url" name="image_url" placeholder="Image URL (optional)" value="<?= htmlspecialchars($edit_data['image_url'] ?? '') ?>">
      <label class="file-btn">📷 Upload preview image<input type="file" name="image_file" accept="image/*" style="display:none"></label>
      <div class="toggle">
        <input type="checkbox" name="preview_enabled" id="preview_enabled" <?= (!isset($edit_data['preview_enabled']) || (int)($edit_data['preview_enabled']??1)===1)?'checked':'' ?>>
        <label for="preview_enabled">Enable link masking (news-style preview)</label>
      </div>
      <button type="submit" class="btn-primary"><?= $edit_data ? 'Update Link' : 'Create Link' ?></button>
      <?php if ($edit_data): ?><a href="dashboard.php?tab=create" style="display:block;text-align:center;margin-top:10px;color:var(--muted);font-size:13px">Cancel Edit</a><?php endif; ?>
    </form>
  </div>

  <div class="card <?= $tab==='links'?'active':'' ?>" id="tab-links">
    <h2>My Links</h2>
    <form class="search-bar" method="GET">
      <input type="hidden" name="tab" value="links">
      <input type="search" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
      <button type="submit">Search</button>
    </form>
    <?php foreach ($links as $l): ?>
    <div class="link-item">
      <div class="code"><?= htmlspecialchars($l['short_code']) ?></div>
      <div class="url"><?= htmlspecialchars($l['long_url']) ?></div>
      <div class="meta"><?= (int)$l['clicks'] ?> clicks · <?= htmlspecialchars($l['title'] ?? '') ?></div>
      <div class="link-actions">
        <button type="button" onclick="navigator.clipboard.writeText('https://<?= $host ?>/<?= htmlspecialchars($l['short_code']) ?>')">Copy</button>
        <a href="dashboard.php?edit=<?= (int)$l['id'] ?>">Edit</a>
        <a href="dashboard.php?stats=<?= (int)$l['id'] ?>">Stats</a>
        <a href="dashboard.php?delete=<?= (int)$l['id'] ?>" onclick="return confirm('Delete?')">Delete</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$links): ?><p style="color:var(--muted);text-align:center;padding:20px">No links yet</p><?php endif; ?>
  </div>

  <div class="card <?= $tab==='stats'?'active':'' ?>" id="tab-stats">
    <h2>Stats</h2>
    <?php if ($stats): ?>
    <h3><?= htmlspecialchars($stats['link']['short_code']) ?> · <?= (int)$stats['link']['clicks'] ?> clicks</h3>
    <h3>By day</h3>
    <?php $max = barMax($stats['by_day']); foreach ($stats['by_day'] as $r): ?>
    <div class="bar-row"><span class="label"><?= htmlspecialchars($r['d']) ?></span><div class="track"><div class="fill" style="width:<?= round(((int)$r['c']/$max)*100) ?>%"></div></div><span class="val"><?= (int)$r['c'] ?></span></div>
    <?php endforeach; ?>
    <h3>Device</h3>
    <?php $max = barMax($stats['by_device']); foreach ($stats['by_device'] as $r): ?>
    <div class="bar-row"><span class="label"><?= htmlspecialchars($r['k']) ?></span><div class="track"><div class="fill" style="width:<?= round(((int)$r['c']/$max)*100) ?>%"></div></div><span class="val"><?= (int)$r['c'] ?></span></div>
    <?php endforeach; ?>
    <h3>Browser</h3>
    <?php $max = barMax($stats['by_browser']); foreach ($stats['by_browser'] as $r): ?>
    <div class="bar-row"><span class="label"><?= htmlspecialchars($r['k']) ?></span><div class="track"><div class="fill" style="width:<?= round(((int)$r['c']/$max)*100) ?>%"></div></div><span class="val"><?= (int)$r['c'] ?></span></div>
    <?php endforeach; ?>
    <h3>OS</h3>
    <?php $max = barMax($stats['by_os']); foreach ($stats['by_os'] as $r): ?>
    <div class="bar-row"><span class="label"><?= htmlspecialchars($r['k']) ?></span><div class="track"><div class="fill" style="width:<?= round(((int)$r['c']/$max)*100) ?>%"></div></div><span class="val"><?= (int)$r['c'] ?></span></div>
    <?php endforeach; ?>
    <?php else: ?>
    <p style="color:var(--muted)">Select a link from My Links → Stats</p>
    <?php endif; ?>
  </div>
</div>

<nav class="bottom-nav">
  <a href="dashboard.php?tab=create" class="<?= $tab==='create'?'active':'' ?>"><i data-lucide="plus-circle"></i>Create</a>
  <a href="dashboard.php?tab=links" class="<?= $tab==='links'?'active':'' ?>"><i data-lucide="link"></i>Links</a>
  <a href="dashboard.php?tab=stats" class="<?= $tab==='stats'?'active':'' ?>"><i data-lucide="bar-chart-2"></i>Stats</a>
</nav>
<script>
lucide.createIcons();
function togglePreviewAlert(el) {
  var a = document.getElementById('previewOffAlert');
  if (a) a.style.display = el.checked ? 'none' : 'block';
}
</script>
</body>
</html>
