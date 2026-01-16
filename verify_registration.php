<?php
/**
 * Verify Registration Email
 * Confirms user's email address after registration
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$pdo = getDBConnection();
$success = false;
$error_message = '';

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error_message = "Ungültiger oder fehlender Verifizierungslink.";
} else {
    try {
        // Find registration request
            $stmt = $pdo->prepare("
                SELECT * FROM registration_requests 
                WHERE token = ?
            AND status = 'pending'
        ");
        $stmt->execute([$token]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception("Ungültiger Verifizierungslink oder bereits verifiziert.");
        }
        
        // Check if already verified
        if ($request['email_verified_at']) {
            $success = true;
            $message = "Ihre E-Mail-Adresse wurde bereits verifiziert.";
        } else {
            // Mark email as verified
            $stmt = $pdo->prepare("
                UPDATE registration_requests 
                SET email_verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$request['id']]);
            
            $success = true;
            $message = "Ihre E-Mail-Adresse wurde erfolgreich verifiziert!";
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

.verify-wrapper {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 20px 0;
}

.verify-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    max-width: 500px;
    width: 100%;
    padding: 40px;
    text-align: center;
}

.verify-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.verify-icon.success {
    color: #4caf50;
}

.verify-icon.error {
    color: #d32f2f;
}

.verify-container h1 {
    font-size: 28px;
    margin-bottom: 15px;
    font-weight: 600;
}

.verify-container h1.success {
    color: #4caf50;
}

.verify-container h1.error {
    color: #d32f2f;
}

.verify-container p {
    color: #666;
    line-height: 1.6;
    margin-bottom: 30px;
}

.info-box {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    font-size: 14px;
    color: #666;
    line-height: 1.8;
    text-align: left;
    border-left: 4px solid #1976d2;
}

.btn {
    display: inline-block;
    padding: 12px 32px;
    border-radius: 4px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-success {
    background-color: #4caf50;
    color: white;
}

.btn-success:hover {
    background-color: #388e3c;
}

.btn-primary {
    background-color: #d32f2f;
    color: white;
}

.btn-primary:hover {
    background-color: #b71c1c;
}
</style>

<div class="page-section">
    <div class="verify-wrapper">
        <div class="verify-container">
            <?php if ($success): ?>
                <div class="verify-icon success">✓</div>
                <h1 class="success">E-Mail verifiziert!</h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                
                <div class="info-box">
                    <strong>Nächste Schritte:</strong><br><br>
                    1. Ein Administrator wird Ihre Registrierung prüfen<br>
                    2. Sie erhalten eine E-Mail, sobald Ihr Zugang genehmigt wurde<br>
                    3. Danach können Sie sich mit einem Magic Link anmelden
                </div>
                
                <a href="request_magiclink.php" class="btn btn-success">Zur Anmeldung</a>
                
            <?php else: ?>
                <div class="verify-icon error">⚠️</div>
                <h1 class="error">Verifizierung fehlgeschlagen</h1>
                <p><?php echo htmlspecialchars($error_message); ?></p>
                
                <a href="register.php" class="btn btn-primary">Erneut registrieren</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
