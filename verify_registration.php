<?php
/**
 * Verify Registration Email
 * Confirms user's email address after registration
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/EmailService.php';

$pdo = getDBConnection();
$success = false;
$message = '';
$error_message = '';

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error_message = "Ungültiger oder fehlender Verifizierungslink.";
} else {
    try {
        // Legacy mode: token points to an already stored registration row.
        if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $stmt = $pdo->prepare("\n                SELECT * FROM registration_requests\n                WHERE token = ?\n                  AND status = 'pending'\n            ");
            $stmt->execute([$token]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception("Ungültiger Verifizierungslink oder bereits verifiziert.");
            }

            if (!empty($request['email_verified_at'])) {
                $success = true;
                $message = "Ihre E-Mail-Adresse wurde bereits verifiziert.";
            } else {
                $stmt = $pdo->prepare("\n                    UPDATE registration_requests\n                    SET email_verified_at = NOW()\n                    WHERE id = ?\n                ");
                $stmt->execute([$request['id']]);

                $success = true;
                $message = "Ihre E-Mail-Adresse wurde erfolgreich verifiziert!";
            }
        } else {
            // New mode: token carries signed registration data; create request only now.
            $payload = parse_registration_verification_token($token);
            if (!$payload) {
                throw new Exception("Ungültiger oder abgelaufener Verifizierungslink.");
            }

            $email = trim((string)$payload['email']);
            $first_name = validate_person_name($payload['first_name'], 'Vorname');
            $last_name = validate_person_name($payload['last_name'], 'Nachname');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Ungültige E-Mail-Adresse.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                throw new Exception("Diese E-Mail-Adresse ist bereits registriert.");
            }

            $stmt = $pdo->prepare("SELECT id, status, email_verified_at FROM registration_requests WHERE email = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $needsAdminNotification = false;
            if ($existing && $existing['status'] === 'pending' && !empty($existing['email_verified_at'])) {
                $success = true;
                $message = "Ihre E-Mail-Adresse wurde bereits verifiziert.";
            } else {
                $storedToken = bin2hex(random_bytes(32));

                if ($existing && $existing['status'] === 'pending') {
                    $stmt = $pdo->prepare("\n                        UPDATE registration_requests\n                        SET first_name = ?,\n                            last_name = ?,\n                            token = ?,\n                            email_verified_at = NOW(),\n                            created_at = NOW()\n                        WHERE id = ?\n                    ");
                    $stmt->execute([$first_name, $last_name, $storedToken, $existing['id']]);
                } else {
                    $stmt = $pdo->prepare("\n                        INSERT INTO registration_requests (email, first_name, last_name, token, status, created_at, email_verified_at)\n                        VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())\n                    ");
                    $stmt->execute([$email, $first_name, $last_name, $storedToken]);
                }

                $needsAdminNotification = true;
                $success = true;
                $message = "Ihre E-Mail-Adresse wurde erfolgreich verifiziert!";
            }

            $pdo->commit();

            if ($needsAdminNotification) {
                $emailService = new EmailService();
                $adminResult = $emailService->sendAdminRegistrationNotification($email, $first_name, $last_name);
                if (!$adminResult['success']) {
                    error_log('Admin registration notification failed: ' . ($adminResult['error'] ?? 'Unknown error'));
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
