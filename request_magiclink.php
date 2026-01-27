<?php
/**
 * Request Magic Link - Passwordless Login
 * Users enter their email to receive a magic link
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/EmailService.php';

// Format retry wait in user-friendly time
if (!function_exists('format_retry_wait')) {
    function format_retry_wait($seconds) {
        if ($seconds < 60) {
            return $seconds . ' Sekunde' . ($seconds !== 1 ? 'n' : '');
        } elseif ($seconds < 3600) {
            $minutes = ceil($seconds / 60);
            return $minutes . ' Minute' . ($minutes !== 1 ? 'n' : '');
        } else {
            $hours = ceil($seconds / 3600);
            return $hours . ' Stunde' . ($hours !== 1 ? 'n' : '');
        }
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: admin/dashboard.php');
    exit;
}

$pdo = getDBConnection();
$success_message = '';
$error_message = '';

// Initialize form timing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['ml_form_time'] = time();
}

// Handle magic link request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Anti-bot: honeypot must remain empty
        if (!empty($_POST['homepage'] ?? '')) {
            throw new Exception("Bitte bestätigen Sie, dass Sie kein Bot sind.");
        }

        // Anti-bot: minimal time on page
        $minSeconds = 3;
        if (empty($_SESSION['ml_form_time']) || (time() - (int)$_SESSION['ml_form_time']) < $minSeconds) {
            throw new Exception("Bitte bestätigen Sie, dass Sie kein Bot sind.");
        }

        // Anti-bot: optional Turnstile
        if (defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '') {
            $cfResponse = $_POST['cf-turnstile-response'] ?? '';
            if (!verify_turnstile_token($cfResponse)) {
                throw new Exception("Bitte bestätigen Sie, dass Sie kein Bot sind.");
            }
        }

        // Global IP rate limit: 5 requests per 10 minutes
        $rl = rate_limit_allow('magiclink', 5, 10 * 60);
        if (!$rl['allowed']) {
            $wait_time = format_retry_wait($rl['retry_after']);
            throw new Exception("Zu viele Anfragen. Bitte warten Sie " . $wait_time . ".");
        }

        $email = trim($_POST['email']);
        
        if (empty($email)) {
            throw new Exception("Bitte geben Sie Ihre E-Mail-Adresse ein.");
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Ungültige E-Mail-Adresse.");
        }
        
        // Check rate limiting (max 3 requests per 15 minutes)
        $ip_address = get_client_ip();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempt_count
            FROM login_attempts 
            WHERE email = ? 
            AND ip_address = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$email, $ip_address]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['attempt_count'] >= 3) {
            throw new Exception("Zu viele Anfragen. Bitte warten Sie 15 Minuten.");
        }
        
        // Check if user exists and is approved
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, auth_method, email_verified
            FROM users 
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Don't reveal if user exists or not for security
            $success_message = "Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde ein Magic Link gesendet.";
            
            // Log failed attempt
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (email, ip_address, user_agent, success, method, created_at)
                VALUES (?, ?, ?, 0, 'magic_link', NOW())
            ");
                $stmt->execute([(string)$email, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '']);
        } else {
            // Check if email is verified
            if (!$user['email_verified']) {
                throw new Exception("Bitte verifizieren Sie zuerst Ihre E-Mail-Adresse.");
            }
            
            // Check if user supports magic link auth
            if ($user['auth_method'] !== 'magic_link' && $user['auth_method'] !== 'both') {
                throw new Exception("Magic Link ist für dieses Konto nicht aktiviert.");
            }
            
            // Generate magic link token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Store magic link
            $stmt = $pdo->prepare("
                INSERT INTO magic_links (token, user_id, expires_at, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $token,
                $user['id'],
                $expires_at,
                $ip_address,
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            // Send magic link email
            $emailService = new EmailService();
            $result = $emailService->sendMagicLink($user['email'], $user['first_name'], $token);
            
            if (!$result['success']) {
                throw new Exception("Fehler beim Senden der E-Mail: " . $result['error']);
            }
            
            // Log successful attempt
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (email, ip_address, user_agent, success, method, created_at)
                VALUES (?, ?, ?, 1, 'magic_link', NOW())
            ");
                $stmt->execute([(string)$email, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '']);
            
            $success_message = "Ein Magic Link wurde an Ihre E-Mail-Adresse gesendet. Der Link ist 15 Minuten gültig.";
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

include 'includes/header.php';
?>

<style>
.page-section {
    padding: 60px 20px;
    background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%);
    min-height: calc(100vh - 200px);
}

.login-wrapper {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 20px 0;
}

.login-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    max-width: 450px;
    width: 100%;
    padding: 40px;
}

.login-header {
    text-align: center;
    margin-bottom: 30px;
}

.login-header h1 {
    color: #d32f2f;
    margin: 0 0 10px 0;
    font-size: 28px;
    font-weight: 600;
}

.login-header p {
    color: #666;
    margin: 0;
    font-size: 14px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
    font-size: 14px;
}

.form-group input {
    width: 100%;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 4px;
    font-size: 15px;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.form-group input:focus {
    outline: none;
    border-color: #d32f2f;
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.btn-magic {
    width: 100%;
    padding: 12px;
    background: #d32f2f;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-magic:hover {
    background: #b71c1c;
}

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
}

.alert-success {
    background: #e8f5e9;
    color: #2e7d32;
    border-left: 4px solid #4caf50;
}

.alert-error {
    background: #ffebee;
    color: #c62828;
    border-left: 4px solid #d32f2f;
}

.info-box {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
    font-size: 14px;
    color: #666;
    line-height: 1.6;
    border-left: 4px solid #1976d2;
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
    background: #e0e0e0;
}

.divider span {
    background: white;
    padding: 0 15px;
    position: relative;
    color: #999;
    font-size: 13px;
}

.register-link {
    text-align: center;
    margin-top: 25px;
}

.register-link a {
    color: #d32f2f;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s ease;
}

.register-link a:hover {
    color: #b71c1c;
    text-decoration: underline;
}

.admin-login-link {
    text-align: center;
    margin-top: 15px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}

.admin-login-link a {
    color: #666;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.3s ease;
}

.admin-login-link a:hover {
    color: #d32f2f;
}

/* Hide honeypot field */
.hp-field {
    position: absolute !important;
    left: -5000px !important;
    width: 1px !important;
    height: 1px !important;
    overflow: hidden !important;
}
</style>

<div class="page-section">
    <div class="login-wrapper">
        <div class="login-container">
            <div class="login-header">
                <h1>Magic Link Login</h1>
                <p>OwMM Feuerwehr Verwaltungssystem</p>
            </div>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                🔐 Kein Passwort erforderlich! Geben Sie Ihre E-Mail-Adresse ein und Sie erhalten einen sicheren Anmelde-Link.
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" 
                           placeholder="ihre-email@example.com" 
                           required autofocus>
                </div>
                
                <!-- Honeypot field: should remain empty -->
                <div class="hp-field" aria-hidden="true">
                    <label for="homepage">Homepage</label>
                    <input type="text" id="homepage" name="homepage" tabindex="-1" autocomplete="off" value="">
                </div>

                <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== ''): ?>
                    <div class="form-group">
                        <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(TURNSTILE_SITE_KEY); ?>"></div>
                    </div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                <?php endif; ?>
                
                <button type="submit" class="btn-magic">Magic Link anfordern</button>
            </form>
            
            <div class="divider">
                <span>oder</span>
            </div>
            
            <div class="register-link">
                Noch kein Konto? <a href="register.php">Jetzt registrieren</a>
            </div>
            
            <div class="admin-login-link">
                <a href="admin/login.php">Admin-Login (Passwort)</a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
