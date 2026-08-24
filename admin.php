<?php
require 'config.php';
requireAdmin();

$message = '';
$is_error = false;
$edit_data = null;
$admin_id = $_SESSION['user_id'];
$host = $_SERVER['HTTP_HOST'];
$tab = $_GET['tab'] ?? 'home';

// CREATE / UPDATE LINK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_link') {
    $long_url = trim($_POST['long_url'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if (empty($long_url)) {
        $message = "Destination URL required!";
        $is_error = true;
    } else {
        if (!preg_match("~^(?:f|ht)tps?://~i", $long_url)) $long_url = "https://" . $long_url;

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE urls SET long_url=?, title=?, image_url=?, description=? WHERE id=? AND user_id=?");
            $stmt->execute([$long_url, $title ?: null, $image_url ?: null, $description ?: null, $id, $admin_id]);
            header("Location: admin.php?tab=links&msg=updated");
            exit;
        } else {
            $short_code = generateShortCode();
            $attempts = 0;
            while ($attempts < 5) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO urls (user_id, short_code, long_url, title, image_url, description) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$admin_id, $short_code, $long_url, $title ?: null, $image_url ?: null, $description ?: null]);
                    header("Location: admin.php?tab=links&msg=created");
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

// CREATE USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (empty($name) || empty($email) || empty($pass)) {
        $message = "Sab fields zaroori hain";
        $is_error = true;
    } elseif (strlen($pass) < 6) {
        $message = "Password kam se kam 6 characters ka ho";
        $is_error = true;
    } else {
        try {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
            $stmt->execute([$name, $email, $hash]);
            header("Location: admin.php?tab=users&msg=user_created");
            exit;
        } catch (PDOException $e) {
            $message = "Email already exists!";
            $is_error = true;
        }
    }
}

// Delete User
if (isset($_GET['delete_user'])) {
    $id = (int)$_GET['delete_user'];
    if ($id != $admin_id) {
        $pdo->prepare("DELETE FROM urls WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'")->execute([$id]);
    }
    header("Location: admin.php?tab=users&msg=user_deleted");
    exit;
}

// Delete Link
if (isset($_GET['delete_link'])) {
    $id = (int)$_GET['delete_link'];
    $pdo->prepare("DELETE FROM urls WHERE id = ?")->execute([$id]);
    header("Location: admin.php?tab=links&msg=deleted");
    exit;
}

// Load edit
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['edit'], $admin_id]);
    $edit_data = $stmt->fetch();
    $tab = 'create';
}

// Stats
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalLinks = $pdo->query("SELECT COUNT(*) FROM urls")->fetchColumn();
$totalClicks = $pdo->query("SELECT COALESCE(SUM(clicks),0) FROM urls")->fetchColumn();
$myLinksCount = $pdo->prepare("SELECT COUNT(*) FROM urls WHERE user_id = ?");
$myLinksCount->execute([$admin_id]);
$myLinksCount = $myLinksCount->fetchColumn();

// Users
$users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM urls WHERE user_id = u.id) as link_count FROM users u WHERE role = 'user' ORDER BY id DESC")->fetchAll();

// Links
$search = trim($_GET['search'] ?? '');
if ($tab === 'links' || $tab === 'create') {
    if ($search && $tab === 'links') {
        $stmt = $pdo->prepare("SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id WHERE short_code LIKE ? OR long_url LIKE ? OR title LIKE ? ORDER BY urls.id DESC");
        $like = "%$search%";
        $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $pdo->query("SELECT urls.*, users.name as user_name FROM urls JOIN users ON urls.user_id = users.id ORDER BY urls.id DESC LIMIT 50");
    }
    $allLinks = $stmt->fetchAll();
} else {
    $allLinks = [];
}

$myLinksData = $pdo->prepare("SELECT * FROM urls WHERE user_id = ? ORDER BY id DESC LIMIT 20");
$myLinksData->execute([$admin_id]);
$myLinksData = $myLinksData->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin - ShortLink</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f0a1f; color: #e2e8f0; min-height: 100vh; padding-bottom: 90px; }
        .header { background: rgba(76, 29, 149, 0.5); backdrop-filter: blur(16px); border-bottom: 1px solid rgba(167, 139, 250, 0.2); padding: 14px 18px; position: sticky; top: 0; z-index: 50; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 19px; color: #e9d5ff; }
        .badge { background: #7c3aed; color: white; font-size: 11px; padding: 3px 10px; border-radius: 20px; }
        .container { max-width: 700px; margin: 0 auto; padding: 16px; }
        .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
        .stat-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(167, 139, 250, 0.15); border-radius: 16px; padding: 16px; text-align: center; }
        .stat-card .num { font-size: 26px; font-weight: 700; color: #c4b5fd; }
        .stat-card .label { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .card { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(12px); border: 1px solid rgba(167, 139, 250, 0.15); border-radius: 18px; padding: 20px; margin-bottom: 16px; display: none; }
        .card.active { display: block; }
        .card h2 { font-size: 17px; margin-bottom: 14px; color: #e9d5ff; }
        input, textarea { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(167, 139, 250, 0.2); background: rgba(15, 10, 31, 0.6); color: white; font-size: 15px; margin-bottom: 10px; outline: none; }
        input:focus, textarea:focus { border-color: #a78bfa; }
        textarea { min-height: 70px; resize: vertical; }
        .btn-primary { width: 100%; padding: 13px; border: none; border-radius: 12px; background: linear-gradient(135deg, #7c3aed, #a78bfa); color: white; font-size: 15px; font-weight: 600; cursor: pointer; }
        .search-bar { display: flex; gap: 8px; margin-bottom: 14px; }
        .search-bar input { margin-bottom: 0; flex: 1; }
        .search-bar button { background: #7c3aed; color: white; border: none; padding: 0 16px; border-radius: 12px; font-weight: 600; cursor: pointer; }
        .user-item, .link-item { border-bottom: 1px solid rgba(167, 139, 250, 0.1); padding: 12px 0; }
        .user-item:last-child, .link-item:last-child { border-bottom: none; }
        .user-item { display: flex; justify-content: space-between; align-items: center; }
        .user-info .name { font-weight: 600; color: #e2e8f0; }
        .user-info .email { font-size: 12px; color: #94a3b8; }
        .user-info .meta { font-size: 11px; color: #64748b; margin-top: 2px; }
        .short-url { font-size: 14px; color: #c4b5fd; word-break: break-all; font-weight: 600; }
        .short-url a { color: #c4b5fd; text-decoration: none; }
        .long-url { font-size: 12px; color: #94a3b8; margin-top: 3px; word-break: break-all; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .meta-line { font-size: 11px; color: #64748b; margin-top: 3px; }
        .clicks { display: inline-block; background: rgba(34, 197, 94, 0.15); color: #4ade80; font-size: 11px; padding: 2px 8px; border-radius: 10px; margin-left: 6px; }
        .actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
        .actions button, .actions a { padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 500; border: none; cursor: pointer; text-decoration: none; }
        .btn-copy { background: linear-gradient(135deg, #7c3aed, #a78bfa); color: white; }
        .btn-edit { background: rgba(167, 139, 250, 0.15); color: #c4b5fd; border: 1px solid rgba(167, 139, 250, 0.3) !important; }
        .btn-del { background: rgba(248, 113, 113, 0.12); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.25) !important; }
        .del-btn { background: rgba(248, 113, 113, 0.15); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3); padding: 6px 12px; border-radius: 8px; font-size: 12px; text-decoration: none; }
        .msg { background: rgba(34, 197, 94, 0.15); color: #4ade80; padding: 12px; border-radius: 12px; margin-bottom: 14px; text-align: center; font-size: 14px; }
        .error { background: rgba(239, 68, 68, 0.15); color: #fca5a5; }
        .empty { text-align: center; color: #64748b; padding: 25px 0; }
        .cancel { display: block; text-align: center; margin-top: 10px; color: #94a3b8; font-size: 13px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(30, 27, 75, 0.95); backdrop-filter: blur(20px); border-top: 1px solid rgba(167, 139, 250, 0.25); display: flex; justify-content: space-around; padding: 8px 0 max(12px, env(safe-area-inset-bottom)); z-index: 100; }
        .nav-item { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #94a3b8; font-size: 11px; gap: 3px; padding: 8px 12px; border-radius: 14px; transition: all 0.2s; min-width: 60px; }
        .nav-item.active { color: #e9d5ff; background: rgba(124, 58, 237, 0.4); box-shadow: 0 0 12px rgba(124, 58, 237, 0.3); }
        .nav-item svg { width: 22px; height: 22px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Panel</h1>
        <span class="badge">Admin</span>
    </div>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="msg">
                <?php
                $msgs = ['created'=>'Link created!','updated'=>'Link updated!','deleted'=>'Link deleted!','user_created'=>'User created!','user_deleted'=>'User deleted!'];
                echo $msgs[$_GET['msg']] ?? '';
                ?>
            </div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="msg <?= $is_error ? 'error' : '' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- HOME -->
        <div class="card <?= ($tab === 'home' || !isset($_GET['tab'])) ? 'active' : '' ?>">
            <h2>Dashboard Overview</h2>
            <div class="stats">
                <div class="stat-card"><div class="num"><?= $totalUsers ?></div><div class="label">Total Users</div></div>
                <div class="stat-card"><div class="num"><?= $totalLinks ?></div><div class="label">Total Links</div></div>
                <div class="stat-card"><div class="num"><?= $totalClicks ?></div><div class="label">Total Clicks</div></div>
                <div class="stat-card"><div class="num"><?= $myLinksCount ?></div><div class="label">My Links</div></div>
            </div>
            <p style="color:#94a3b8;font-size:13px;text-align:center;margin-top:10px;">Welcome, <?= htmlspecialchars($_SESSION['name']) ?></p>
        </div>

        <!-- CREATE -->
        <div class="card <?= $tab === 'create' ? 'active' : '' ?>">
            <h2><?= $edit_data ? 'Edit Link' : 'Create Short Link' ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_link">
                <?php if ($edit_data): ?><input type="hidden" name="id" value="<?= $edit_data['id'] ?>"><?php endif; ?>
                <input type="text" name="long_url" placeholder="Destination URL" required value="<?= htmlspecialchars($edit_data['long_url'] ?? '') ?>">
                <input type="text" name="title" placeholder="Title (Optional)" value="<?= htmlspecialchars($edit_data['title'] ?? '') ?>">
                <input type="text" name="image_url" placeholder="Image URL (Optional)" value="<?= htmlspecialchars($edit_data['image_url'] ?? '') ?>">
                <textarea name="description" placeholder="Description (Optional)"><?= htmlspecialchars($edit_data['description'] ?? '') ?></textarea>
                <button type="submit" class="btn-primary"><?= $edit_data ? 'Update Link' : 'Shorten Now' ?></button>
                <?php if ($edit_data): ?><a href="admin.php?tab=create" class="cancel">Cancel Edit</a><?php endif; ?>
            </form>
            <?php if (!empty($myLinksData)): ?>
                <h2 style="margin-top:24px;">My Recent Links</h2>
                <?php foreach ($myLinksData as $link): ?>
                    <div class="link-item">
                        <div class="short-url"><a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a><span class="clicks"><?= (int)$link['clicks'] ?> clicks</span></div>
                        <div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
                        <div class="actions">
                            <button class="btn-copy" onclick="copyLink(this, 'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
                            <a href="?tab=create&edit=<?= $link['id'] ?>" class="btn-edit">Edit</a>
                            <a href="?delete_link=<?= $link['id'] ?>" class="btn-del" onclick="return confirm('Delete?')">Del</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- USERS -->
        <div class="card <?= $tab === 'users' ? 'active' : '' ?>">
            <h2>Create New User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                <input type="text" name="name" placeholder="Full Name" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="text" name="password" placeholder="Password (min 6)" required>
                <button type="submit" class="btn-primary">Create User</button>
            </form>
            <h2 style="margin-top:24px;">All Users (<?= count($users) ?>)</h2>
            <?php if (empty($users)): ?>
                <div class="empty">No users yet</div>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                    <div class="user-item">
                        <div class="user-info">
                            <div class="name"><?= htmlspecialchars($u['name']) ?></div>
                            <div class="email"><?= htmlspecialchars($u['email']) ?></div>
                            <div class="meta"><?= $u['link_count'] ?> links</div>
                        </div>
                        <a href="?delete_user=<?= $u['id'] ?>" class="del-btn" onclick="return confirm('Delete this user and all their links?')">Delete</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ALL LINKS -->
        <div class="card <?= $tab === 'links' ? 'active' : '' ?>">
            <h2>All Links</h2>
            <form method="GET" class="search-bar">
                <input type="hidden" name="tab" value="links">
                <input type="text" name="search" placeholder="Search links..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit">Search</button>
            </form>
            <?php if (empty($allLinks)): ?>
                <div class="empty">No links found</div>
            <?php else: ?>
                <?php foreach ($allLinks as $link): ?>
                    <div class="link-item">
                        <div class="short-url"><a href="https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>" target="_blank">https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?></a><span class="clicks"><?= (int)$link['clicks'] ?> clicks</span></div>
                        <div class="long-url"><?= htmlspecialchars($link['long_url']) ?></div>
                        <div class="meta-line">by <?= htmlspecialchars($link['user_name']) ?></div>
                        <div class="actions">
                            <button class="btn-copy" onclick="copyLink(this, 'https://<?= $host ?>/<?= htmlspecialchars($link['short_code']) ?>')">Copy</button>
                            <a href="?delete_link=<?= $link['id'] ?>" class="btn-del" onclick="return confirm('Delete?')">Del</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="admin.php?tab=home" class="nav-item <?= ($tab === 'home' || !isset($_GET['tab'])) ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1h-2z"/></svg>
            Home
        </a>
        <a href="admin.php?tab=create" class="nav-item <?= $tab === 'create' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create
        </a>
        <a href="admin.php?tab=users" class="nav-item <?= $tab === 'users' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            Users
        </a>
        <a href="admin.php?tab=links" class="nav-item <?= $tab === 'links' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            Links
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
