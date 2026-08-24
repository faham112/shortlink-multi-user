<?php
require 'config.php';

$code = $_GET['code'] ?? '';

if (empty($code) || !preg_match('/^[a-zA-Z0-9]+$/', $code)) {
    header("Location: /");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM urls WHERE short_code = ?");
$stmt->execute([$code]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><title>Not Found</title>
    <style>body{font-family:sans-serif;text-align:center;padding:60px;background:#0f172a;color:white;}
    a{color:#a78bfa;}</style></head><body>
    <h2>Link not found</h2>
    <p><a href='/'>Go Home</a></p>
    </body></html>";
    exit;
}

$pdo->prepare("UPDATE urls SET clicks = clicks + 1 WHERE short_code = ?")->execute([$code]);

$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = false;
$bots = ['whatsapp','facebookexternalhit','facebot','twitterbot','telegrambot','linkedinbot','slackbot','discordbot','pinterest','googlebot','bingbot','yandex','baiduspider','embedly','quora link preview','showyoubot','outbrain','vkshare','redditbot','applebot','tumblr','skypeuripreview','viber','line'];

foreach ($bots as $bot) {
    if (strpos($userAgent, $bot) !== false) {
        $isBot = true;
        break;
    }
}

if ($isBot) {
    $title = !empty($link['title']) ? $link['title'] : 'Short Link';
    $description = !empty($link['description']) ? $link['description'] : 'Click to open the link';
    $image = !empty($link['image_url']) ? $link['image_url'] : '';
    $shortUrl = "https://" . $_SERVER['HTTP_HOST'] . "/" . $link['short_code'];

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($shortUrl) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?>
    <meta property="og:image" content="<?= htmlspecialchars($image) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($image) ?>">
    <?php endif; ?>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1e1b4b; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; text-align: center; padding: 20px; }
        .card { background: rgba(255,255,255,0.08); backdrop-filter: blur(12px); padding: 40px 30px; border-radius: 20px; max-width: 420px; border: 1px solid rgba(255,255,255,0.1); }
        h1 { font-size: 22px; margin-bottom: 12px; }
        p { color: #c4b5fd; font-size: 15px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= htmlspecialchars($description) ?></p>
    </div>
</body>
</html>
    <?php
    exit;
}

header("Location: " . $link['long_url'], true, 302);
exit;
