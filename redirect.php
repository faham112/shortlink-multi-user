<?php
require 'config.php';

$code = $_GET['code'] ?? '';

if (empty($code) || !preg_match('/^[a-zA-Z0-9]+$/', $code)) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM urls WHERE short_code = ? LIMIT 1");
$stmt->execute([$code]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    exit;
}

// Click count
$pdo->prepare("UPDATE urls SET clicks = clicks + 1 WHERE short_code = ?")->execute([$code]);

$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = false;

$bots = [
    'whatsapp', 'facebookexternalhit', 'facebot', 'twitterbot', 'telegrambot',
    'linkedinbot', 'slackbot', 'discordbot', 'pinterest', 'googlebot', 'bingbot',
    'yandex', 'baiduspider', 'embedly', 'quora link preview', 'showyoubot',
    'outbrain', 'vkshare', 'redditbot', 'applebot', 'tumblr', 'skypeuripreview',
    'viber', 'line', 'bot', 'crawler', 'spider', 'preview'
];

foreach ($bots as $bot) {
    if (strpos($userAgent, $bot) !== false) {
        $isBot = true;
        break;
    }
}

if (strlen($userAgent) < 25) $isBot = true;

if ($isBot) {
    $title       = !empty($link['title'])       ? $link['title']       : 'Breaking News';
    $description = !empty($link['description']) ? $link['description'] : 'Latest updates and full story';
    $image       = !empty($link['image_url'])   ? $link['image_url']   : '';
    $shortUrl    = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $link['short_code'];
    $siteName    = 'News Daily';

    header_remove('X-Powered-By');
    header_remove('Server');
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>

    <!-- Open Graph (Facebook / WhatsApp) - News style -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($shortUrl) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?>
    <meta property="og:image" content="<?= htmlspecialchars($image) ?>">
    <meta property="og:image:secure_url" content="<?= htmlspecialchars($image) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/jpeg">
    <?php endif; ?>
    <meta property="og:locale" content="en_US">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($image) ?>">
    <?php endif; ?>

    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <meta name="robots" content="noindex, nofollow">

    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f0f1a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px}
        .card{background:rgba(255,255,255,0.05);backdrop-filter:blur(16px);padding:40px 28px;border-radius:24px;max-width:420px;border:1px solid rgba(255,255,255,0.08)}
        h1{font-size:22px;margin:0 0 12px;font-weight:600}
        p{color:#a5b4fc;font-size:15px;line-height:1.5;margin:0}
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

// Real user → clean redirect
header_remove('X-Powered-By');
header_remove('Server');
header('Referrer-Policy: no-referrer');
header('Location: ' . $link['long_url'], true, 302);
exit;
