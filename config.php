<?php
function loadEnv($path) {
    if (!file_exists($path)) {
        die(".env file not found. Please create .env file.");
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

loadEnv(__DIR__ . '/.env');

$host     = $_ENV['DB_HOST']    ?? 'localhost';
$dbname   = $_ENV['DB_NAME']    ?? '';
$username = $_ENV['DB_USER']    ?? '';
$password = $_ENV['DB_PASS']    ?? '';
$charset  = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

if (empty($dbname) || empty($username)) {
    die("Please configure database details in .env file");
}

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Auto-migrate missing columns / tables
try {
    $cols = $pdo->query("SHOW COLUMNS FROM urls LIKE 'preview_enabled'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE urls ADD COLUMN preview_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER description");
    }
} catch (Exception $e) {}

try {
    $pdo->query("SELECT 1 FROM clicks LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `clicks` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `url_id` int(11) NOT NULL,
          `ip` varchar(45) DEFAULT NULL,
          `user_agent` text DEFAULT NULL,
          `referer` text DEFAULT NULL,
          `country` varchar(100) DEFAULT NULL,
          `city` varchar(100) DEFAULT NULL,
          `device` varchar(50) DEFAULT NULL,
          `browser` varchar(50) DEFAULT NULL,
          `os` varchar(50) DEFAULT NULL,
          `is_bot` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `url_id` (`url_id`),
          KEY `created_at` (`created_at`),
          KEY `is_bot` (`is_bot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e2) {}
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: dashboard.php");
        exit;
    }
}

function generateShortCode($length = 6) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}
