<?php
/**
 * Email Settings Management
 * Configure SMTP settings for Magic Link authentication
 */

require_once '../includes/functions.php';
require_once '../includes/EmailService.php';

// Check permissions - only admins can configure email settings
if (!is_logged_in() || !is_admin()) {
    redirect('dashboard.php');
}

$pdo = getDBConnection();
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_settings':
                try {
                    $smtp_host = trim($_POST['smtp_host']);
                    $smtp_port = (int)$_POST['smtp_port'];
                    $smtp_username = trim($_POST['smtp_username']);
                    $smtp_password = trim($_POST['smtp_password']);
                    $from_email = trim($_POST['from_email']);
                    $from_name = trim($_POST['from_name']);
                    $use_tls = isset($_POST['use_tls']) ? 1 : 0;
                    
                    // Only update password if provided
                    if (!empty($smtp_password)) {
                        $stmt = $pdo->prepare("
                            UPDATE email_config 
                            SET smtp_host = ?, 
                                smtp_port = ?, 
                                smtp_username = ?, 
                                smtp_password = ?,
                                from_email = ?,
                                from_name = ?,
                                use_tls = ?
                            WHERE id = 1
                        ");
                        $stmt->execute([
                            $smtp_host, 
                            $smtp_port, 
                            $smtp_username, 
                            $smtp_password,
                            $from_email,
                            $from_name,
                            $use_tls
                        ]);
                    } else {
                        // Update without changing password
                        $stmt = $pdo->prepare("
                            UPDATE email_config 
                            SET smtp_host = ?, 
                                smtp_port = ?, 
                                smtp_username = ?,
                                from_email = ?,
                                from_name = ?,
                                use_tls = ?
                            WHERE id = 1
                        ");
                        $stmt->execute([
                            $smtp_host, 
                            $smtp_port, 
                            $smtp_username,
                            $from_email,
                            $from_name,
                            $use_tls
                        ]);
                    }
                    
                    $success_message = "E-Mail-Einstellungen erfolgreich gespeichert.";
                } catch (Exception $e) {
                    $error_message = "Fehler beim Speichern: " . $e->getMessage();
                }
                break;
                
            case 'send_test':
                try {
                    $test_email = trim($_POST['test_email']);
                    
                    if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Ungültige E-Mail-Adresse");
                    }
                    
                    $emailService = new EmailService();
                    $result = $emailService->sendTestEmail($test_email);
                    
                    if ($result['success']) {
                        $success_message = "Test-E-Mail erfolgreich an $test_email gesendet!";
                    } else {
                        $error_message = "Fehler beim Senden der Test-E-Mail: " . $result['error'];
                    }
                } catch (Exception $e) {
                    $error_message = "Fehler: " . $e->getMessage();
                }
                break;
        }
    }
}

// Load current settings
$stmt = $pdo->query("SELECT * FROM email_config WHERE id = 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
.settings-card {
    background: white;
    border-radius: 8px;
    padding: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    max-width: 800px;
    margin: 30px auto;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    color: #333;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="number"],
.form-group input[type="password"] {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
}

.form-group input[type="text"]:focus,
.form-group input[type="email"]:focus,
.form-group input[type="number"]:focus,
.form-group input[type="password"]:focus {
    outline: none;
    border-color: #dc2626;
}

.form-group .help-text {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.checkbox-group {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.checkbox-group input[type="checkbox"] {
    margin-right: 10px;
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.checkbox-group label {
    font-weight: normal;
    cursor: pointer;
}

.btn-group {
    display: flex;
    gap: 15px;
    margin-top: 30px;
    padding-top: 30px;
    border-top: 1px solid #e5e5e5;
}

.btn {
    padding: 12px 30px;
    border: none;
    border-radius: 5px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-primary {
    background: #dc2626;
    color: white;
}

.btn-primary:hover {
    background: #b91c1c;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.alert {
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
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

.test-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 5px;
    margin-top: 30px;
}

.test-section h3 {
    margin-top: 0;
    color: #333;
}

.test-form {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.test-form .form-group {
    flex: 1;
    margin-bottom: 0;
}

.section-title {
    font-size: 20px;
    color: #333;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #dc2626;
}
</style>

<div class="settings-card">
    <h1 style="margin-top: 0; color: #dc2626;">E-Mail-Einstellungen</h1>
    <p style="color: #666;">Konfigurieren Sie die SMTP-Einstellungen für Magic Link E-Mails.</p>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="section-title">SMTP-Server Einstellungen</div>
        
        <div class="form-group">
            <label for="smtp_host">SMTP Host *</label>
            <input type="text" id="smtp_host" name="smtp_host" 
                   value="<?php echo htmlspecialchars($config['smtp_host'] ?? 'smtp.ionos.de'); ?>" 
                   required>
            <div class="help-text">z.B. smtp.ionos.de, smtp.gmail.com, smtp.office365.com</div>
        </div>
        
        <div class="form-group">
            <label for="smtp_port">SMTP Port *</label>
            <input type="number" id="smtp_port" name="smtp_port" 
                   value="<?php echo htmlspecialchars($config['smtp_port'] ?? 587); ?>" 
                   required>
            <div class="help-text">Standard: 587 (TLS) oder 465 (SSL)</div>
        </div>
        
        <div class="checkbox-group">
            <input type="checkbox" id="use_tls" name="use_tls" 
                   <?php echo ($config['use_tls'] ?? 1) ? 'checked' : ''; ?>>
            <label for="use_tls">TLS/STARTTLS verwenden (Port 587)</label>
        </div>
        
        <div class="form-group">
            <label for="smtp_username">SMTP Benutzername *</label>
            <input type="text" id="smtp_username" name="smtp_username" 
                   value="<?php echo htmlspecialchars($config['smtp_username'] ?? ''); ?>" 
                   required>
            <div class="help-text">Meist die vollständige E-Mail-Adresse</div>
        </div>
        
        <div class="form-group">
            <label for="smtp_password">SMTP Passwort</label>
            <input type="password" id="smtp_password" name="smtp_password" 
                   placeholder="<?php echo $config['smtp_password'] ? '••••••••' : ''; ?>">
            <div class="help-text">Leer lassen, um aktuelles Passwort beizubehalten</div>
        </div>
        
        <div class="section-title" style="margin-top: 40px;">Absender-Informationen</div>
        
        <div class="form-group">
            <label for="from_email">Absender E-Mail *</label>
            <input type="email" id="from_email" name="from_email" 
                   value="<?php echo htmlspecialchars($config['from_email'] ?? ''); ?>" 
                   required>
            <div class="help-text">E-Mail-Adresse, die als Absender angezeigt wird</div>
        </div>
        
        <div class="form-group">
            <label for="from_name">Absender Name *</label>
            <input type="text" id="from_name" name="from_name" 
                   value="<?php echo htmlspecialchars($config['from_name'] ?? 'OwMM Feuerwehr'); ?>" 
                   required>
            <div class="help-text">Name, der als Absender angezeigt wird</div>
        </div>
        
        <div class="btn-group">
            <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            <a href="dashboard.php" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
    
    <div class="test-section">
        <h3>Test-E-Mail senden</h3>
        <p style="color: #666; margin-bottom: 15px;">Testen Sie die E-Mail-Konfiguration durch Versenden einer Test-E-Mail.</p>
        
        <form method="POST" class="test-form">
            <input type="hidden" name="action" value="send_test">
            <div class="form-group">
                <label for="test_email">Test-E-Mail an:</label>
                <input type="email" id="test_email" name="test_email" 
                       placeholder="ihre-email@example.com" required>
            </div>
            <button type="submit" class="btn btn-secondary">Test-E-Mail senden</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
