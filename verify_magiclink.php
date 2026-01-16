<?php
/**
 * Verify Magic Link Token
 * Validates the token and automatically logs in the user
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$pdo = getDBConnection();
$error_message = '';

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error_message = "Ungültiger oder fehlender Magic Link.";
} else {
    try {
        // Find magic link
        $stmt = $pdo->prepare("
            SELECT ml.*, u.id as user_id, u.first_name, u.last_name, u.email, u.is_admin
            FROM magic_links ml
            JOIN users u ON ml.user_id = u.id
            WHERE ml.token = ?
        ");
        $stmt->execute([$token]);
        $magic_link = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$magic_link) {
            throw new Exception("Ungültiger Magic Link.");
        }
        
        // Check if already used
        if ($magic_link['used_at']) {
            throw new Exception("Dieser Magic Link wurde bereits verwendet.");
        }
        
        // Check if expired
        if (strtotime($magic_link['expires_at']) < time()) {
            throw new Exception("Dieser Magic Link ist abgelaufen. Bitte fordern Sie einen neuen an.");
        }
        
        // Mark as used
        $stmt = $pdo->prepare("
            UPDATE magic_links 
            SET used_at = NOW()
            WHERE token = ?
        ");
        $stmt->execute([$token]);
        
        // Log successful login
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (email, ip_address, user_agent, success, method, created_at)
            VALUES (?, ?, ?, 1, 'magic_link', NOW())
        ");
        $stmt->execute([
            $magic_link['email'],
            $ip_address,
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Create session
        session_start();
        $_SESSION['user_id'] = $magic_link['user_id'];
        $_SESSION['username'] = $magic_link['email'];
        $_SESSION['user_name'] = $magic_link['first_name'] . ' ' . $magic_link['last_name'];
        $_SESSION['first_name'] = $magic_link['first_name'];
        $_SESSION['last_name'] = $magic_link['last_name'];
        $_SESSION['is_admin'] = $magic_link['is_admin'];
        $_SESSION['auth_method'] = 'magic_link';
        $_SESSION['login_time'] = time();
        
        // Redirect to dashboard
        header('Location: admin/dashboard.php');
        exit;
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

$page_title = 'Magic Link Verifikation';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            width: 100%;
            height: 100%;
        }

        body {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .verify-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .error-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .verify-container h1 {
            color: #dc2626;
            margin: 0 0 20px 0;
            font-size: 28px;
            font-weight: 700;
        }

        .verify-container p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .btn-back {
            display: inline-block;
            padding: 12px 30px;
            background: #dc2626;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            transition: background 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }

        .btn-back:hover {
            background: #b91c1c;
        }

        .help-text {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #e5e5e5;
            font-size: 13px;
            color: #999;
        }
    </style>
</head>
<body>
<div class="verify-container">
    <div class="error-icon">⚠️</div>
    <h1>Magic Link ungültig</h1>
    <p><?php echo htmlspecialchars($error_message); ?></p>
    
    <a href="request_magiclink.php" class="btn-back">Neuen Magic Link anfordern</a>
    
    <div class="help-text">
        Magic Links sind aus Sicherheitsgründen nur 15 Minuten gültig und können nur einmal verwendet werden.
    </div>
</div>
</body>
</html>
