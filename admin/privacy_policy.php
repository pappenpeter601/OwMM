<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('login.php');
}

$db = getDBConnection();
$user_id = $_SESSION['user_id'];

// Handle print request
$action = $_GET['action'] ?? null;
if ($action === 'print') {
    // Set headers for PDF-friendly printing
    header('Content-Type: text/html; charset=utf-8');
}

// Get the latest privacy policy version
$stmt = $db->prepare("
    SELECT * FROM privacy_policy_versions 
    WHERE published_at IS NOT NULL 
    ORDER BY published_at DESC 
    LIMIT 1
");
$stmt->execute();
$current_policy = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_policy) {
    die('Datenschutzerklärung nicht verfügbar.');
}

// Check if this is a modal dialog or full page view
$is_modal = $_GET['modal'] ?? false;

// Get user's current acceptance status for this policy
$stmt = $db->prepare("
    SELECT * FROM privacy_policy_consent 
    WHERE user_id = ? AND policy_version_id = ? 
    ORDER BY consent_date DESC 
    LIMIT 1
");
$stmt->execute([$user_id, $current_policy['id']]);
$user_consent = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle consent submission (accept/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    if ($action === 'accept' || $action === 'reject') {
        try {
            $accepted = ($action === 'accept') ? 1 : 0;
            
            // Remove existing consent record if any
            $stmt = $db->prepare("
                DELETE FROM privacy_policy_consent 
                WHERE user_id = ? AND policy_version_id = ?
            ");
            $stmt->execute([$user_id, $current_policy['id']]);
            
            // Handle email consent checkbox
            $email_activities = isset($_POST['email_activities']) ? 1 : 0;
            
            // Insert new consent record
            $stmt = $db->prepare("
                INSERT INTO privacy_policy_consent 
                (user_id, policy_version_id, accepted, consent_date, ip_address, user_agent) 
                VALUES (?, ?, ?, NOW(), ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $current_policy['id'],
                $accepted,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            // Update user's privacy policy acceptance status
            $stmt = $db->prepare("
                UPDATE users 
                SET privacy_policy_accepted_version = ?,
                    privacy_policy_accepted_at = NOW(),
                    require_privacy_policy_acceptance = ?,
                    active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $current_policy['version'],
                0, // Clear the flag if accepted
                $accepted ? 1 : 0, // Disable user if rejected
                $user_id
            ]);
            
            // Update email consent in email_consent table
            if ($accepted) {
                $stmt = $db->prepare("
                    INSERT INTO email_consent (user_id, email_activities, email_updates, email_notifications)
                    VALUES (?, ?, 0, 1)
                    ON DUPLICATE KEY UPDATE 
                        email_activities = VALUES(email_activities),
                        updated_at = NOW(),
                        updated_by_user = 1
                ");
                $stmt->execute([$user_id, $email_activities]);
            }
            
            if ($action === 'reject') {
                // If user rejects, log them out and show message
                session_destroy();
                $_SESSION = array();
                header('Location: login.php?rejected=1');
                exit;
            } else {
                // If user accepts, clear the privacy policy flag and redirect to profile
                if (isset($_SESSION['show_privacy_policy_only'])) {
                    unset($_SESSION['show_privacy_policy_only']);
                }
                // Default landing page is always profile
                $redirect = $_GET['redirect'] ?? 'profile.php';
                redirect($redirect);
            }
        } catch (Exception $e) {
            error_log('Privacy policy consent error: ' . $e->getMessage());
            die('Error processing consent: ' . htmlspecialchars($e->getMessage()));
        }
    }
}

// If not modal, include header
if (!$is_modal) {
    // For privacy policy page: set a flag to hide sidebar navigation
    // Only set this flag if we haven't just redirected from acceptance
    $_SESSION['show_privacy_policy_only'] = true;
    
    $page_title = 'Datenschutzerklärung';
    include 'includes/header.php';
}
?>

<style>
    .privacy-policy-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 20px;
        background: white;
        border-radius: 8px;
    }
    
    .policy-header {
        border-bottom: 2px solid var(--primary-color);
        padding-bottom: 15px;
        margin-bottom: 30px;
    }
    
    .policy-version {
        font-size: 0.9rem;
        color: #666;
        margin-top: 10px;
    }
    
    .policy-content {
        line-height: 1.8;
        color: #333;
        max-height: 600px;
        overflow-y: auto;
        border: 1px solid #ddd;
        padding: 20px;
        border-radius: 4px;
        background: #fafafa;
        margin: 30px 0;
    }
    
    .policy-actions {
        margin-top: 30px;
        display: flex;
        gap: 10px;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
    
    .consent-form {
        margin-top: 20px;
        padding: 20px;
        background: #f9f9f9;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
    }
    
    .consent-checkbox {
        margin-bottom: 15px;
    }
    
    .consent-checkbox input[type="checkbox"] {
        margin-right: 10px;
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .consent-checkbox label {
        cursor: pointer;
        margin: 0;
        display: flex;
        align-items: center;
    }
    
    .print-button {
        background: #757575;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1rem;
    }
    
    .print-button:hover {
        background: #616161;
    }
    
    @media print {
        .policy-actions,
        .consent-form,
        .print-button,
        .admin-controls {
            display: none !important;
        }
        
        .policy-content {
            max-height: none;
            overflow: visible;
            border: none;
            padding: 0;
            background: white;
        }
        
        body {
            background: white;
        }
        
        .privacy-policy-container {
            max-width: 100%;
            padding: 0;
        }
    }
    
    .policy-status {
        padding: 10px 15px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 0.95rem;
    }
    
    .policy-status.accepted {
        background: #c8e6c9;
        color: #2e7d32;
        border: 1px solid #66bb6a;
    }
    
    .policy-status.rejected {
        background: #ffcdd2;
        color: #c62828;
        border: 1px solid #ef5350;
    }
    
    .policy-status.pending {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffc107;
    }
</style>

<div class="privacy-policy-container">
    <div class="policy-header">
        <h1>Datenschutzerklärung</h1>
        <p style="margin: 10px 0 0 0; font-size: 0.95rem; color: #666;">
            Gültige Version: <strong><?php echo htmlspecialchars($current_policy['version']); ?></strong>
        </p>
        <?php if ($current_policy['published_at']): ?>
            <p style="margin: 5px 0 0 0; font-size: 0.9rem; color: #999;">
                Gültig ab: <strong><?php echo date('d. F Y', strtotime($current_policy['published_at'])); ?></strong>
            </p>
        <?php endif; ?>
    </div>
    
    <?php if ($user_consent): ?>
        <div class="policy-status <?php echo $user_consent['accepted'] ? 'accepted' : 'rejected'; ?>">
            <?php if ($user_consent['accepted']): ?>
                ✓ Sie haben dieser Datenschutzerklärung am <strong><?php echo date('d.m.Y H:i', strtotime($user_consent['consent_date'])); ?></strong> zugestimmt.
            <?php else: ?>
                ✗ Sie haben dieser Datenschutzerklärung am <strong><?php echo date('d.m.Y H:i', strtotime($user_consent['consent_date'])); ?></strong> nicht zugestimmt.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="policy-content">
        <?php echo $current_policy['content']; ?>
    </div>
    
    <?php if (!$user_consent || !$user_consent['accepted']): ?>
        <form method="POST" class="consent-form">
            <h3 style="margin-top: 0;">Ihre Zustimmung ist erforderlich</h3>
            
            <p style="margin-bottom: 20px; font-style: italic; color: #666;">
                Bitte lesen Sie die Datenschutzerklärung sorgfältig durch und treffen Sie eine Entscheidung.
            </p>
            
            <div class="consent-checkbox">
                <label>
                    <input type="checkbox" name="confirm_read" id="confirm_read" required>
                    Ich habe die Datenschutzerklärung gelesen und verstanden
                </label>
            </div>
            
            <div class="consent-checkbox">
                <label>
                    <input type="checkbox" name="email_activities" id="email_activities" checked>
                    Ich bin damit einverstanden, gelegentlich E-Mails zu Aktivitäten der OwMM zu erhalten
                </label>
            </div>
            
            <div style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">
                <button type="submit" name="action" value="accept" class="btn btn-success" style="margin-right: 10px;">
                    ✓ Akzeptieren und fortfahren
                </button>
                <button type="submit" name="action" value="reject" class="btn btn-danger" onclick="return confirm('Sind Sie sicher? Wenn Sie nicht akzeptieren, wird Ihr Konto deaktiviert.');">
                    ✗ Ablehnen
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="policy-actions">
            <button class="btn btn-primary" onclick="window.print();">
                🖨️ Drucken
            </button>
            <a href="profile.php" class="btn btn-secondary">
                ← Zurück zu Profil
            </a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$is_modal): ?>
    <?php include 'includes/footer.php'; ?>
<?php endif; ?>
