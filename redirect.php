<?php
require 'config.php';

$code = $_GET['code'] ?? '';

if (empty($code) || !preg_match('/^[a-zA-Z0-9]+$/', $code)) {
    http_response_code(404);
    exit('Not found');
}

$stmt = $pdo->prepare("SELECT * FROM urls WHERE short_code = ? LIMIT 1");
$stmt->execute([$code]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    exit('Not found');
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$userAgentLower = strtolower($userAgent);
$isBot = false;

$bots = [
    'whatsapp', 'facebookexternalhit', 'facebot', 'twitterbot', 'telegrambot',
    'linkedinbot', 'slackbot', 'discordbot', 'pinterest', 'googlebot', 'bingbot',
    'yandex', 'baiduspider', 'embedly', 'quora link preview', 'showyoubot',
    'outbrain', 'vkshare', 'redditbot', 'applebot', 'tumblr', 'skypeuripreview',
    'viber', 'line', 'bot', 'crawler', 'spider', 'preview', 'facebookcatalog',
    'meta-externalagent', 'meta-externalfetcher'
];

foreach ($bots as $bot) {
    if (strpos($userAgentLower, $bot) !== false) {
        $isBot = true;
        break;
    }
}
if (strlen($userAgent) < 25) $isBot = true;

function parseUA($ua) {
    $ua = strtolower($ua);
    $device = 'Desktop';
    $browser = 'Unknown';
    $os = 'Unknown';
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'ipod') !== false) {
        $device = 'Mobile';
    } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
        $device = 'Tablet';
    }
    if (strpos($ua, 'edg/') !== false || strpos($ua, 'edge') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'chrome') !== false && strpos($ua, 'chromium') === false) $browser = 'Chrome';
    elseif (strpos($ua, 'safari') !== false && strpos($ua, 'chrome') === false) $browser = 'Safari';
    elseif (strpos($ua, 'firefox') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'opera') !== false || strpos($ua, 'opr/') !== false) $browser = 'Opera';
    elseif (strpos($ua, 'msie') !== false || strpos($ua, 'trident') !== false) $browser = 'IE';
    if (strpos($ua, 'windows') !== false) $os = 'Windows';
    elseif (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) $os = 'MacOS';
    elseif (strpos($ua, 'android') !== false) $os = 'Android';
    elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false || strpos($ua, 'ios') !== false) $os = 'iOS';
    elseif (strpos($ua, 'linux') !== false) $os = 'Linux';
    return [$device, $browser, $os];
}

list($device, $browser, $os) = parseUA($userAgent);

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
if ($country === 'XX' || $country === '') $country = null;

try {
    $stmt = $pdo->prepare("INSERT INTO clicks (url_id, ip, user_agent, referer, country, city, device, browser, os, is_bot) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $link['id'],
        $ip ?: null,
        $userAgent ?: null,
        $referer ?: null,
        $country,
        null,
        $device,
        $browser,
        $os,
        $isBot ? 1 : 0
    ]);
} catch (Exception $e) {}

if (!$isBot) {
    try {
        $pdo->prepare("UPDATE urls SET clicks = clicks + 1 WHERE short_code = ?")->execute([$code]);
    } catch (Exception $e) {}
}

$previewOn = true;
if (array_key_exists('preview_enabled', $link)) {
    $previewOn = ((int)$link['preview_enabled'] === 1);
}

if ($isBot) {
    header_remove('X-Powered-By');
    header_remove('Server');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Referrer-Policy: no-referrer');

    if (!$previewOn) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><title></title></head><body></body></html>';
        exit;
    }

    $title       = !empty($link['title'])       ? $link['title']       : 'Breaking News';
    $description = !empty($link['description']) ? $link['description'] : 'Latest updates and full story';
    $image       = !empty($link['image_url'])   ? $link['image_url']   : '';
    $shortUrl    = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $link['short_code'];
    $siteName    = 'News Daily';

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
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
    <?php endif; ?>
    <meta property="og:locale" content="en_US">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($image) ?>">
    <?php endif; ?>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <meta name="robots" content="noindex, nofollow">
</head>
<body>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($description) ?></p>
</body>
</html>
    <?php
    exit;
}

header_remove('X-Powered-By');
header_remove('Server');
header('Referrer-Policy: no-referrer');
header('Location: ' . $link['long_url'], true, 302);
exit;
