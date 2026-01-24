<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('login.php');
}


// Clear the privacy policy flag when on profile
if (isset($_SESSION['show_privacy_policy_only'])) {
    unset($_SESSION['show_privacy_policy_only']);
}

$page_title = 'Mein Profil';
include 'includes/header.php';

$db = getDBConnection();
$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get member data if linked
$member = null;
if ($user['member_id']) {
    $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([$user['member_id']]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get user's obligations
$obligations = [];
if ($user['member_id']) {
    $stmt = $db->prepare("
        SELECT * FROM member_fee_obligations 
        WHERE member_id = ? 
           ORDER BY fee_year DESC, due_date DESC
    ");
    $stmt->execute([$user['member_id']]);
    $obligations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get email consent preferences
$stmt = $db->prepare("SELECT * FROM email_consent WHERE user_id = ?");
$stmt->execute([$user_id]);
$email_consent = $stmt->fetch(PDO::FETCH_ASSOC);

// Get privacy policy acceptance status
$stmt = $db->prepare("
    SELECT pc.*, ppv.version 
    FROM privacy_policy_consent pc
    JOIN privacy_policy_versions ppv ON pc.policy_version_id = ppv.id
    WHERE pc.user_id = ? 
    ORDER BY pc.consent_date DESC 
    LIMIT 1
");
$stmt->execute([$user_id]);
$privacy_acceptance = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle profile update
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;

    if ($action === 'update_profile') {
        try {
            // Update user profile
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Ungültige E-Mail-Adresse');
            }
            
            // Check if email is unique (excluding current user)
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Diese E-Mail-Adresse wird bereits verwendet');
            }
            
            // Update user
            $stmt = $db->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, email = ? 
                WHERE id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $user_id]);
            
            // Update member data if exists
            if ($member) {
                $phone = trim($_POST['phone'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $street = trim($_POST['street'] ?? '');
                $postal_code = trim($_POST['postal_code'] ?? '');
                $city = trim($_POST['city'] ?? '');
                $iban = trim($_POST['iban'] ?? '');
                
                $stmt = $db->prepare("
                    UPDATE members 
                        SET telephone = ?, mobile = ?, street = ?, postal_code = ?, city = ?, iban = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$phone, $mobile, $street, $postal_code, $city, $iban, $member['id']]);
                
                // Update session
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
            }
            
            $message = 'Profil erfolgreich aktualisiert';
            $message_type = 'success';
            
            // Reload user data
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user['member_id']) {
                $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
                $stmt->execute([$user['member_id']]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $message = 'Fehler: ' . $e->getMessage();
            $message_type = 'error';
        }
    } elseif ($action === 'update_email_consent') {
        try {
            $email_activities = isset($_POST['email_activities']) ? 1 : 0;
            
            // Check if record exists
            $stmt = $db->prepare("SELECT id FROM email_consent WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                $stmt = $db->prepare("
                    UPDATE email_consent 
                    SET email_activities = ?, updated_at = NOW(), updated_by_user = 1 
                    WHERE user_id = ?
                ");
            } else {
                $stmt = $db->prepare("
                    INSERT INTO email_consent (user_id, email_activities, email_notifications, updated_by_user) 
                    VALUES (?, ?, 1, 1)
                ");
            }
            $stmt->execute([$email_activities, $user_id]);
            
            $message = 'E-Mail-Einstellungen gespeichert';
            $message_type = 'success';
            
            // Reload consent data
            $stmt = $db->prepare("SELECT * FROM email_consent WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $email_consent = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $message = 'Fehler: ' . $e->getMessage();
            $message_type = 'error';
        }
    } elseif ($action === 'delete_account') {
        $confirm = $_POST['confirm_delete'] ?? false;
        if (!$confirm) {
            $message = 'Bitte bestätigen Sie das Löschen des Kontos';
            $message_type = 'error';
        } else {
            try {
                // Soft delete - deactivate account instead of hard delete
                $stmt = $db->prepare("UPDATE users SET active = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                
                // Destroy session
                session_destroy();
                $_SESSION = array();
                
                header('Location: login.php?account_deleted=1');
                exit;
            } catch (Exception $e) {
                $message = 'Fehler beim Löschen des Kontos: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Check if user has access to dashboard - only dashboard or admin
$has_dashboard_access = is_admin() || has_permission('dashboard.php');
?>

<style>
    .profile-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .profile-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--primary-color);
    }
    
    .profile-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .profile-section {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .section-title {
        font-size: 1.3rem;
        font-weight: bold;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
        color: var(--primary-color);
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .info-item {
        padding: 15px;
        background: #f9f9f9;
        border-radius: 4px;
        border-left: 4px solid var(--primary-color);
    }
    
    .info-label {
        font-size: 0.85rem;
        color: #666;
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 5px;
    }
    
    .info-value {
        font-size: 1rem;
        color: #333;
        word-break: break-word;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #333;
    }
    
    .form-group input[type="text"],
    .form-group input[type="email"] {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 1rem;
    }
    
    .obligations-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    
    .obligations-table th,
    .obligations-table td {
        padding: 10px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    
    .obligations-table th {
        background: #f5f5f5;
        font-weight: bold;
        color: #333;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 0.85rem;
        font-weight: bold;
    }
    
    .status-badge.open {
        background: #ffcdd2;
        color: #c62828;
    }
    
    .status-badge.partial {
        background: #fff3cd;
        color: #856404;
    }
    
    .status-badge.paid {
        background: #c8e6c9;
        color: #2e7d32;
    }
    
    .message-box {
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 20px;
    }
    
    .message-box.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .message-box.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .edit-form {
        background: #fafafa;
        padding: 20px;
        border-radius: 4px;
        margin-top: 15px;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        margin: 15px 0;
    }
    
    .checkbox-group input[type="checkbox"] {
        margin-right: 10px;
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .checkbox-group label {
        cursor: pointer;
        margin: 0;
    }
    
    .consent-status {
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 15px;
        font-size: 0.95rem;
    }
    
    .consent-status.accepted {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .consent-status.pending {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }
    
    .consent-status.rejected {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .btn-delete {
        background: #f44336;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1rem;
    }
    
    .btn-delete:hover {
        background: #da190b;
    }
</style>

<div class="profile-container">
    <div class="profile-header">
        <h1>Mein Profil</h1>
        <div class="profile-actions">
            <?php if ($has_dashboard_access): ?>
                <a href="dashboard.php" class="btn btn-primary">
                    📊 Dashboard
                </a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-secondary">
                🚪 Abmelden
            </a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="message-box <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Personal Data Section -->
    <div class="profile-section">
        <div class="section-title">👤 Persönliche Daten</div>
        
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Vorname</div>
                <div class="info-value"><?php echo htmlspecialchars($user['first_name'] ?? 'Nicht angegeben'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Nachname</div>
                <div class="info-value"><?php echo htmlspecialchars($user['last_name'] ?? 'Nicht angegeben'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">E-Mail</div>
                <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
            </div>
            <?php if ($member): ?>
                <div class="info-item">
                    <div class="info-label">Mitgliedstyp</div>
                    <div class="info-value">
                        <?php echo $member['member_type'] === 'active' ? 'Einsatzeinheit' : 'Förderer'; ?>
                    </div>
                </div>
                <?php if (!empty($member['telephone'])): ?>
                    <div class="info-item">
                        <div class="info-label">Telefon</div>
                        <div class="info-value"><?php echo htmlspecialchars($member['telephone']); ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($member['mobile'])): ?>
                    <div class="info-item">
                        <div class="info-label">Mobile</div>
                        <div class="info-value"><?php echo htmlspecialchars($member['mobile']); ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($member['city']): ?>
                    <div class="info-item">
                        <div class="info-label">Adresse</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars(trim($member['street'] . ' ' . $member['postal_code'] . ' ' . $member['city'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <button class="btn btn-primary" onclick="toggleEditForm('profile')">
            ✏️ Bearbeiten
        </button>
        
        <div id="profile-edit-form" style="display: none;">
            <form method="POST" class="edit-form">
                <h3 style="margin-top: 0; margin-bottom: 20px;">Daten aktualisieren</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="first_name">Vorname</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Nachname</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">E-Mail</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                </div>
                
                <?php if ($member): ?>
                    <hr style="margin: 20px 0;">
                    <h4 style="margin-bottom: 15px;">Mitgliedsdaten</h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="phone">Telefon</label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($member['telephone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="mobile">Mobile</label>
                            <input type="text" id="mobile" name="mobile" value="<?php echo htmlspecialchars($member['mobile'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="postal_code">Postleitzahl</label>
                            <input type="text" id="postal_code" name="postal_code" value="<?php echo htmlspecialchars($member['postal_code'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="city">Stadt</label>
                            <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($member['city'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="street">Straße + Nr.</label>
                        <input type="text" id="street" name="street" value="<?php echo htmlspecialchars($member['street'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="iban">IBAN</label>
                        <input type="text" id="iban" name="iban" placeholder="DE89..." value="<?php echo htmlspecialchars($member['iban'] ?? ''); ?>">
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 20px;">
                    <button type="submit" name="action" value="update_profile" class="btn btn-success">
                        💾 Speichern
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="toggleEditForm('profile')">
                        ✕ Abbrechen
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Obligations Section -->
    <?php if ($member && count($obligations) > 0): ?>
        <div class="profile-section">
            <div class="section-title">📋 Meine Forderungen</div>
            
            <p style="color: #666; margin-bottom: 15px;">
                Übersicht Deiner offenen Mitgliedsbeiträge, Rechnungen und Guthaben.
            </p>
            
            <table class="obligations-table">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Betrag</th>
                        <th>Bezahlt</th>
                        <th>Offen</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($obligations as $obligation): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($obligation['fee_year']); ?></strong></td>
                            <td><?php echo number_format($obligation['fee_amount'], 2, ',', '.'); ?> €</td>
                            <td><?php echo number_format($obligation['paid_amount'], 2, ',', '.'); ?> €</td>
                            <td><?php echo number_format($obligation['fee_amount'] - $obligation['paid_amount'], 2, ',', '.'); ?> €</td>
                            <td>
                                <span class="status-badge <?php echo htmlspecialchars($obligation['status']); ?>">
                                    <?php 
                                    $status_text = [
                                        'open' => 'Offen',
                                        'partial' => 'Teilweise bezahlt',
                                        'paid' => 'Bezahlt',
                                        'cancelled' => 'Storniert'
                                    ];
                                    echo $status_text[$obligation['status']] ?? $obligation['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
    <!-- Privacy Policy Section -->
    <div class="profile-section">
        <div class="section-title">🔐 Datenschutz & Einstellungen</div>
        
        <h3 style="margin-top: 0;">Datenschutzerklärung</h3>
        <?php if ($privacy_acceptance): ?>
            <div class="consent-status <?php echo $privacy_acceptance['accepted'] ? 'accepted' : 'rejected'; ?>">
                <?php if ($privacy_acceptance['accepted']): ?>
                    ✓ Version <?php echo htmlspecialchars($privacy_acceptance['version']); ?> akzeptiert am <?php echo date('d.m.Y', strtotime($privacy_acceptance['consent_date'])); ?>
                <?php else: ?>
                    ✗ Version <?php echo htmlspecialchars($privacy_acceptance['version']); ?> abgelehnt am <?php echo date('d.m.Y', strtotime($privacy_acceptance['consent_date'])); ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="consent-status pending">
                ⚠️ Einverständnis noch erforderlich
            </div>
        <?php endif; ?>
        
        <p style="margin: 15px 0 0 0;">
            <a href="privacy_policy.php?redirect=profile.php" class="btn btn-primary">
                📄 Datenschutzerklärung anzeigen
            </a>
        </p>
        
        <h3 style="margin-top: 30px;">E-Mail Kommunikation</h3>
        <p style="color: #666; margin-bottom: 15px;">
            Teilen Sie uns mit, welche Arten von E-Mail-Benachrichtigungen Sie erhalten möchten.
        </p>
        
        <form method="POST" class="edit-form">
            <div class="checkbox-group">
                <input type="checkbox" id="email_activities" name="email_activities" value="1" 
                    <?php echo (isset($email_consent) && $email_consent['email_activities']) ? 'checked' : ''; ?>>
                <label for="email_activities">
                    Ich bin damit einverstanden, gelegentlich E-Mails zu Aktivitäten der OwMM zu erhalten
                </label>
            </div>
            
            <p style="color: #666; font-size: 0.9rem; margin-top: 15px; margin-bottom: 0;">
                💡 Hinweis: Wichtige Benachrichtigungen (z.B. zu Ihren Forderungen) erhalten Sie in jedem Fall.
            </p>
            
            <div style="margin-top: 20px;">
                <button type="submit" name="action" value="update_email_consent" class="btn btn-success">
                    💾 Speichern
                </button>
            </div>
        </form>
    </div>
    
    <!-- Danger Zone Section -->
    <div class="profile-section" style="background: #fff5f5; border: 2px solid #ffebee;">
        <div class="section-title" style="color: #c62828;">⚠️ Konto-Verwaltung</div>
        
        <p style="color: #666; margin-bottom: 20px;">
            Diese Aktion kann nicht rückgängig gemacht werden. Alle Ihre persönlichen Daten werden gelöscht.
        </p>
        
        <button class="btn-delete" onclick="toggleEditForm('delete')">
            🗑️ Konto löschen
        </button>
        
        <div id="delete-edit-form" style="display: none;">
            <form method="POST" class="edit-form" style="background: #ffebee; border: 1px solid #ffcdd2; margin-top: 15px;">
                <h3 style="margin-top: 0; color: #c62828;">Bestätigung erforderlich</h3>
                
                <p style="color: #666;">
                    Sind Sie sicher, dass Sie Ihr Konto löschen möchten? Dies kann nicht rückgängig gemacht werden.
                </p>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="confirm_delete" name="confirm_delete" value="1" required>
                    <label for="confirm_delete">
                        Ja, ich möchte mein Konto permanent löschen
                    </label>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="submit" name="action" value="delete_account" class="btn-delete">
                        Konto löschen
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="toggleEditForm('delete')">
                        Abbrechen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleEditForm(form) {
    const element = document.getElementById(form + '-edit-form');
    if (element.style.display === 'none') {
        element.style.display = 'block';
    } else {
        element.style.display = 'none';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
