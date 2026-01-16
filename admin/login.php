<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Bitte alle Felder ausfüllen';
    } else {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username AND active = 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            
            // Update last login
            $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
            $stmt->execute(['id' => $user['id']]);
            
            redirect('dashboard.php');
        } else {
            $error = 'Ungültige Anmeldedaten';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .login-page {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-box {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 450px;
            width: 100%;
            padding: 40px;
        }
        
        .login-box h1 {
            color: #dc2626;
            margin: 0 0 10px 0;
            text-align: center;
            font-size: 28px;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin: 0 0 30px 0;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e5e5;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #dc2626;
        }
        
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-primary:hover {
            background: #b91c1c;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        
        .divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e5e5e5;
        }
        
        .divider span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: #999;
            font-size: 13px;
        }
        
        .magic-link-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: white;
            color: #dc2626;
            border: 2px solid #dc2626;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
            margin-bottom: 15px;
        }
        
        .magic-link-btn:hover {
            background: #dc2626;
            color: white;
        }
        
        .back-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e5e5e5;
        }
        
        .back-link a {
            color: #666;
            text-decoration: none;
            font-size: 13px;
        }
        
        .back-link a:hover {
            color: #dc2626;
        }
        
        .info-text {
            font-size: 13px;
            color: #666;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <h1>Admin-Bereich</h1>
            <p class="subtitle"><?php echo SITE_NAME; ?></p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <!-- Magic Link Option -->
            <div class="info-text">
                🔐 Passwordless Login verfügbar
            </div>
            <a href="../request_magiclink.php" class="magic-link-btn">
                Mit Magic Link anmelden
            </a>
            
            <div class="divider">
                <span>oder mit Passwort</span>
            </div>
            
            <!-- Traditional Password Login -->
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Benutzername</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Mit Passwort anmelden</button>
            </form>
            
            <p class="back-link"><a href="../index.php">← Zurück zur Website</a></p>
        </div>
    </div>
</body>
</html>
