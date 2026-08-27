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

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
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
    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // Image Upload from Gallery
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
    } else {
        if (!preg_match("~^(?:f|ht)tps?://~i", $long_url)) {
            $long_url = "https://" . $long_url;
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE urls SET long_url=?, title=?, image_url=?, description=? WHERE id=? AND user_id=?");
            $stmt->execute([$long_url, $title ?: null, $image_url ?: null, $description ?: null, $id, $user_id]);
            header("Location: dashboard.php?tab=links&msg=updated");
            exit;
        } else {
            $short_code = generateShortCode();
            $attempts = 0;
            while ($attempts < 5) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO urls (user_id, short_code, long_url, title, image_url, description) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$user_id, $short_code, $long_url, $title ?: null, $image_url ?: null, $description ?: null]);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - ShortLink</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f0a1f; color: #e2e8f0; min-height: 100vh; padding-bottom: 90px; }
        .header { background: rgba(76, 29, 149, 0.45); backdrop-filter: blur(16px); border-bottom: 1px solid rgba(167, 139, 250, 0.15); padding: 14px 18px; position: sticky; top: 0; z-index: 50; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 19px; font-weight: 700; color: #e9d5ff; }
        .header .user { font-size: 13px; color: #c4b5fd; }
        .container { max-width: 600px; margin: 0 auto; padding: 16px; }
        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .stat-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(167, 139, 250, 0.15); border-radius: 14px; padding: 14px; text-align: center; }
        .stat-card .num { font-size: 22px; font-weight: 700; color: #c4b5fd; }
        .stat-card .label { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .card { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(12px); border: 1px solid rgba(167, 139, 250, 0.15); border-radius: 18px; padding: 20px; margin-bottom: 16px; display: none; }
        .card.active { display: block; }
        .card h2 { font-size: 17px; margin-bottom: 14px; color: #e9d5ff; }
        input, textarea { width: 100%; padding: 13px 14px; border-radius: 12px; border: 1px solid rgba(167, 139, 250, 0.2); background: rgba(15, 10, 31, 0.6); color: white; font-size: 15px; margin-bottom: 12px; outline: none; }
        input:focus, textarea:focus { border-color: #a78bfa; }
        textarea { min-height: 70px; resize: vertical; }
        .btn-primary { width: 100%; padding: 14px; border: none; border-radius: 12px; background: linear-gradient(135deg, #7c3aed, #a78bfa); color: white; font-size: 16px; font-weight: 600; cursor: pointer; }
        .search-bar { display: flex; gap: 8px; margin-bottom: 14px; }
        .search-bar input { margin-bottom: 0; flex: 1; }
        .search-bar button { background: #7c3aed; color: white; border: none; padding: 0 16px; border-radius: 12px; font-weight: 600; cursor: pointer; }
        .link-item { border-bottom: 1px solid rgba(167, 139, 250, 0.1); padding: 14px 0; }
        .link-item:last-child { border-bottom: none; }
        .short-url a { color: #c4b5fd; font-weight: 600; text-decoration: none; word-break: break-all; font-size: 14px; }
        .clicks { display: inline-block; background: rgba(34, 197, 94, 0.15); color: #4ade80; font-size: 11px; padding: 2px 8px; border-radius: 10px; margin-left: 6px; }
        .long-url { color: #94a3b8; font-size: 12px; margin-top: 4px; word-break: break-all; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
        .actions button, .actions a { padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500; border: none; cursor: pointer; text-decoration: none; }
        .btn-copy { background: linear-gradient(135deg, #7c3aed, #a78bfa); color: white; }
        .btn-edit { background: rgba(167, 139, 250, 0.15); color: #c4b5fd; border: 1px solid rgba(167, 139, 250, 0.3) !important; }
        .btn-del { background: rgba(248, 113, 113, 0.12); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.25) !important; }
        .msg { background: rgba(34, 197, 94, 0.15); color: #4ade80; padding: 12px; border-radius: 12px; margin-bottom: 14px; text-align: center; font-size: 14px; }
        .empty { text-align: center; color: #64748b; padding: 30px 0; }
        .cancel { display: block; text-align: center; margin-top: 10px; color: #94a3b8; font-size: 13px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(30, 27, 75, 0.95); backdrop-filter: blur(20px); border-top: 1px solid rgba(167, 139, 250, 0.25); display: flex; justify-content: space-around; padding: 8px 0 max(12px, env(safe-area-inset-bottom)); z-index: 100; }
        .nav-item { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 11px; gap: 3px; padding: 8px 16px; border-radius: 14px; transition: all 0.2s; min-width: 70px; }
        .nav-item.active { color: #e9d5ff; background: rgba(124, 58, 237, 0.4); box-shadow: 0 0 12px rgba(124, 58, 237, 0.3); }
        .nav-item svg { width: 22px; height: 22px; }
        .file-label { font-size: 13px; color: #c4b5fd; display: block; margin-bottom: 6px; }
        .file-input { padding: 10px; background: rgba(15,10,31,0.6); border: 1px solid rgba(167,139,250,0.2); border-radius: 12px; color: #e2e8f0; width: 100%; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ShortLink</h1>
        <div class="user"><?= htmlspecialchars($_SESSION['name']) ?></div>
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
            <div class="msg" style="background:rgba(239,68,68,0.15);color:#fca5a5;"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card <?= $tab === 'create' ? 'active' : '' ?>" id="tab-create">
            <h2><?= $edit_data ? 'Edit Link' : 'Create Short Link' ?></h2>
            <form method="POST" enctype="multipart/form-data">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>
                <input type="text" name="long_url" placeholder="Destination URL" required value="<?= htmlspecialchars($edit_data['long_url'] ?? '') ?>">
                <input type="text" name="title" placeholder="Title (News style)" value="<?= htmlspecialchars($edit_data['title'] ?? '') ?>">
                <textarea name="description" placeholder="Description (News style)"><?= htmlspecialchars($edit_data['description'] ?? '') ?></textarea>

                <label class="file-label">Preview Image (Gallery se upload)</label>
                <input type="file" name="image_file" accept="image/*" class="file-input">
                <?php if (!empty($edit_data['image_url'])): ?>
                    <div style="margin-bottom:12px;font-size:12px;color:#94a3b8;">
                        Current: <a href="<?= htmlspecialchars($edit_data['image_url']) ?>" target="_blank" style="color:#a78bfa;">View Image</a>
                    </div>
                <?php endif; ?>

                <input type="text" name="image_url" placeholder="Ya Image URL (Optional)" value="<?= htmlspecialchars($edit_data['image_url'] ?? '') ?>">
                <button type="submit" class="btn-primary"><?= $edit_data ? 'Update Link' : 'Shorten Now' ?></button>
                <?php if ($edit_data): ?>
                    <a href="dashboard.php?tab=create" class="cancel">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card <?= $tab === 'links' ? 'active' : '' ?>" id="tab-links">
            <div class="stats">
                <div class="stat-card"><div class="num"><?= $totalLinks ?></div><div class="label">My Links</div></div>
                <div class="stat-card"><div class="num"><?= $totalClicks ?></div><div class="label">Total Clicks</div></div>
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
                <?php foreach ($links as $link): ?>
                    <div class="link-item">
                        <div class="short-url">
                            <a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a>
                            <span class="clicks"><?= (int)$link['clicks'] ?> clicks</span>
                        </div>
                        <div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
                        <div class="actions">
                            <button class="btn-copy" onclick="copyLink(this, 'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
                            <a href="?tab=create&edit=<?= $link['id'] ?>" class="btn-edit">Edit</a>
                            <a href="?delete=<?= $link['id'] ?>" class="btn-del" onclick="return confirm('Delete this link?')">Del</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php?tab=create" class="nav-item <?= $tab === 'create' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create
        </a>
        <a href="dashboard.php?tab=links" class="nav-item <?= $tab === 'links' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            My Links
        </a>
        <a href="logout.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Logout
        </a>
    </nav>

    <script>
        function copyLink(btn, url) {
            navigator.clipboard.writeText(url).then(() => {
                const original = btn.innerText;
                btn.innerText = "Copied!";
                btn.style.background = "#22c55e";
                setTimeout(() => { btn.innerText = original; btn.style.background = ""; }, 1500);
            }).catch(() => alert("Copy failed"));
        }
    </script>
</body>
</html>
