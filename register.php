<?php
/**
 * Public Registration Page
 * New users can register here - requires admin approval
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/EmailService.php';

$pdo = getDBConnection();
$success_message = '';
$error_message = '';

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        
        // Validate input
        if (empty($email) || empty($first_name) || empty($last_name)) {
            throw new Exception("Bitte füllen Sie alle Felder aus.");
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Ungültige E-Mail-Adresse.");
        }
        
        // Check if email already exists in users
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Diese E-Mail-Adresse ist bereits registriert.");
        }
        
        // Check if email already has pending registration
        $stmt = $pdo->prepare("SELECT id, status FROM registration_requests WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            if ($existing['status'] === 'pending') {
                throw new Exception("Für diese E-Mail-Adresse existiert bereits eine ausstehende Registrierungsanfrage.");
            }
            
            // Delete old approved/rejected request to allow re-registration
            $stmt = $pdo->prepare("DELETE FROM registration_requests WHERE email = ? AND status IN ('approved', 'rejected')");
            $stmt->execute([$email]);
        }
        
        // Generate verification token
        $verification_token = bin2hex(random_bytes(32)); // Generate verification token
        
        // Insert registration request
        $stmt = $pdo->prepare("
            INSERT INTO registration_requests (email, first_name, last_name, token, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$email, $first_name, $last_name, $verification_token]);
        
        // Send verification email to user
        $emailService = new EmailService();
        $result = $emailService->sendRegistrationConfirmation($email, $first_name, $verification_token);
        
        if (!$result['success']) {
            error_log("Failed to send registration confirmation: " . $result['error']);
            // Don't fail registration if email fails
        }
        
        // Send notification to admin
        $adminResult = $emailService->sendAdminRegistrationNotification($email, $first_name, $last_name);
        
        $success_message = "Registrierung erfolgreich! Bitte überprüfen Sie Ihr E-Mail-Postfach und bestätigen Sie Ihre E-Mail-Adresse. Nach der Bestätigung wird ein Administrator Ihre Registrierung prüfen. <strong>⚠️ Hinweis: Kontrollieren Sie bitte auch den SPAM/Junk-Ordner Ihres E-Mail-Accounts, da Bestätigungsmails dort landen können.</strong>";
        
        // Clear form
        $_POST = [];
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

include 'includes/header.php';
?>

<div class="page-section">
    <div class="container">
        <div class="registration-wrapper">
            <div class="registration-container">
                <div class="registration-header">
                    <h1>Registrierung</h1>
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
                
                <?php if (!$success_message): ?>
                    <div class="info-box">
                        <strong>So funktioniert die Registrierung:</strong>
                        <ul>
                            <li>Füllen Sie das Formular aus</li>
                            <li>Bestätigen Sie Ihre E-Mail-Adresse</li>
                            <li>Warten Sie auf die Genehmigung durch einen Administrator</li>
                            <li>Melden Sie sich mit Magic Link an (kein Passwort erforderlich)</li>
                        </ul>
                        <div style="margin-top: 15px; padding: 10px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                            <strong style="color: #856404;">⚠️ Wichtig:</strong>
                            <p style="margin: 5px 0 0 0; color: #856404; font-size: 0.95em;">
                                Bestätigungs- und Genehmigungsmails können in Ihrem <strong>SPAM/Junk-Ordner</strong> landen. 
                                Bitte überprüfen Sie diesen Ordner, wenn Sie keine E-Mail in Ihrem Posteingang finden.
                            </p>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label for="first_name">Vorname <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Nachname <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-Mail-Adresse <span class="required">*</span></label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Registrieren</button>
                    </form>
                <?php endif; ?>
                
                <div class="login-link">
                    Bereits registriert? <a href="request_magiclink.php">Mit Magic Link anmelden</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.page-section {
    padding: 60px 20px;
    background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%);
    min-height: calc(100vh - 200px);
}

.registration-wrapper {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 20px 0;
}

.registration-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    max-width: 500px;
    width: 100%;
    padding: 40px;
}

.registration-header {
    text-align: center;
    margin-bottom: 30px;
}

.registration-header h1 {
    color: #dc2626;
    margin: 0 0 10px 0;
    font-size: 28px;
}

.registration-header p {
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

.required {
    color: #dc2626;
}

.registration-container .btn {
    width: 100%;
    padding: 14px;
    margin-top: 10px;
}

.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 25px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #dc2626;
}

.login-link {
    text-align: center;
    margin-top: 25px;
    padding-top: 25px;
    border-top: 1px solid #e5e5e5;
}

.login-link a {
    color: #dc2626;
    text-decoration: none;
    font-weight: bold;
}

.login-link a:hover {
    text-decoration: underline;
}

.info-box {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 25px;
    font-size: 13px;
    color: #666;
}

.info-box ul {
    margin: 10px 0 0 0;
    padding-left: 20px;
}

.info-box li {
    margin: 5px 0;
}

@media (max-width: 600px) {
    .registration-container {
        padding: 30px 20px;
    }
    
    .registration-header h1 {
        font-size: 24px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
