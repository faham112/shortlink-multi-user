<?php
require 'config.php';

if (isLoggedIn()) {
    header("Location: " . (isAdmin() ? "admin.php" : "dashboard.php"));
    exit;
}

// Change WhatsApp number here (country code, no +)
$WHATSAPP_NUMBER = '923013250144';
$CREDIT_NAME = 'Faham Baloch';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (empty($email) || empty($pass)) {
        $error = "Email aur Password dono zaroori hain";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];

            if ($user['role'] === 'admin') {
                header("Location: admin.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            $error = "Galat Email ya Password";
        }
    }
}

$waMsg = urlencode('Hi, I just visited your ShortLink tool.');
$waLink = 'https://wa.me/' . preg_replace('/\D/', '', $WHATSAPP_NUMBER) . '?text=' . $waMsg;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - ShortLink</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 50%, #a78bfa 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .glass {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            padding: 40px 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { color: white; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
        .logo p { color: rgba(255,255,255,0.7); font-size: 14px; margin-top: 6px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; color: rgba(255,255,255,0.85); font-size: 13px; margin-bottom: 6px; font-weight: 500; }
        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 16px;
            outline: none;
            transition: all 0.2s;
        }
        input::placeholder { color: rgba(255,255,255,0.4); }
        input:focus { border-color: rgba(255,255,255,0.5); background: rgba(255,255,255,0.15); }
        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 14px;
            background: white;
            color: #6d28d9;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fecaca;
            padding: 12px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 18px;
            text-align: center;
        }
        .login-footer {
            margin-top: 24px;
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            text-align: center;
        }
        .footer-brand {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: rgba(255,255,255,0.75);
            font-weight: 500;
        }
        .footer-brand .heart {
            color: #f0abfc;
            font-size: 11px;
            animation: pulse-heart 1.4s ease-in-out infinite;
        }
        @keyframes pulse-heart {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.15); }
        }
        .footer-brand .name {
            color: #fff;
            font-weight: 600;
        }
        .footer-wa {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: 0.2s;
        }
        .footer-wa:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }
        .footer-wa i { font-size: 14px; }
    </style>
</head>
<body>
    <div class="glass">
        <div class="logo">
            <h1>ShortLink</h1>
            <p>Sign in to continue</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="you@example.com" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>
    </div>

    <div class="login-footer">
        <div class="footer-brand">
            <span>Coded with</span>
            <span class="heart">♥</span>
            <span>by</span>
            <span class="name"><?= htmlspecialchars($CREDIT_NAME) ?></span>
        </div>
        <a class="footer-wa" href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener noreferrer">
            <i class="fa-brands fa-whatsapp"></i>
            <span>Contact us</span>
        </a>
    </div>
</body>
</html>
