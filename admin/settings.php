<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/EmailService.php';

// Check if user is admin
if (!is_logged_in() || !has_role('admin')) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    
    if ($section === 'social_media') {
        $action = $_POST['action'];
        
        if ($action === 'update') {
            $id = $_POST['id'];
            $platform = sanitize_input($_POST['platform']);
            $url = sanitize_input($_POST['url']);
            $icon_class = sanitize_input($_POST['icon_class']);
            $active = isset($_POST['active']) ? 1 : 0;
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("UPDATE social_media SET platform = :platform, url = :url, 
                                   icon_class = :icon, active = :active, sort_order = :sort 
                                   WHERE id = :id");
            $stmt->execute([
                'platform' => $platform,
                'url' => $url,
                'icon' => $icon_class,
                'active' => $active,
                'sort' => $sort_order,
                'id' => $id
            ]);
            $success = "Social Media Link aktualisiert";
        } elseif ($action === 'add') {
            $platform = sanitize_input($_POST['platform']);
            $url = sanitize_input($_POST['url']);
            $icon_class = sanitize_input($_POST['icon_class']);
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("INSERT INTO social_media (platform, url, icon_class, sort_order) 
                                   VALUES (:platform, :url, :icon, :sort)");
            $stmt->execute([
                'platform' => $platform,
                'url' => $url,
                'icon' => $icon_class,
                'sort' => $sort_order
            ]);
            $success = "Social Media Link hinzugefügt";
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM social_media WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = "Social Media Link gelöscht";
        }
    } elseif ($section === 'users') {
        $action = $_POST['action'];
        
        if ($action === 'add') {
            $username = sanitize_input($_POST['username']);
            $email = sanitize_input($_POST['email']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            $first_name = sanitize_input($_POST['first_name']);
            $last_name = sanitize_input($_POST['last_name']);
            
            $stmt = $db->prepare("INSERT INTO users (username, email, password, is_admin, first_name, last_name) 
                                   VALUES (:username, :email, :password, :is_admin, :first_name, :last_name)");
            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'is_admin' => $is_admin,
                'first_name' => $first_name,
                'last_name' => $last_name
            ]);
            $success = "Benutzer erfolgreich hinzugefügt";
        } elseif ($action === 'update_member_link') {
            $user_id = (int)$_POST['user_id'];
            $member_id = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
            
            // If linking to a member, ensure no other user is already linked to this member
            if ($member_id) {
                // Check if this member is already linked to another user
                $stmt = $db->prepare("SELECT id FROM users WHERE member_id = :member_id AND id != :user_id");
                $stmt->execute(['member_id' => $member_id, 'user_id' => $user_id]);
                $existing_user = $stmt->fetch();
                
                if ($existing_user) {
                    // Unlink the old user first
                    $stmt = $db->prepare("UPDATE users SET member_id = NULL WHERE id = :id");
                    $stmt->execute(['id' => $existing_user['id']]);
                }
            }
            
            // Get user's email
            $stmt = $db->prepare("SELECT email FROM users WHERE id = :user_id");
            $stmt->execute(['user_id' => $user_id]);
            $user = $stmt->fetch();
            $user_email = $user ? $user['email'] : null;
            
            // Update user's member_id
            $stmt = $db->prepare("UPDATE users SET member_id = :member_id WHERE id = :user_id");
            $stmt->execute([
                'member_id' => $member_id,
                'user_id' => $user_id
            ]);
            
            // If linking a member, update the member's email with the user's verified email
            if ($member_id && $user_email) {
                $stmt = $db->prepare("UPDATE members SET email = :email WHERE id = :member_id");
                $stmt->execute([
                    'email' => $user_email,
                    'member_id' => $member_id
                ]);
            }
            
            $_SESSION['success_message'] = "Mitgliederverknüpfung aktualisiert";
            header('Location: settings.php?tab=users&user_id=' . $user_id);
            exit;
        } elseif ($action === 'delete' && $_POST['id'] != $_SESSION['user_id']) {
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = "Benutzer gelöscht";
        }
    } elseif ($section === 'transaction_categories') {
        $action = $_POST['action'];
        
        if ($action === 'update') {
            $id = $_POST['id'];
            $name = sanitize_input($_POST['name']);
            $description = $_POST['description'];
            $color = $_POST['color'];
            $icon = sanitize_input($_POST['icon']);
            $active = isset($_POST['active']) ? 1 : 0;
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("UPDATE transaction_categories SET name = :name, description = :description, 
                                   color = :color, icon = :icon, active = :active, sort_order = :sort 
                                   WHERE id = :id");
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'icon' => $icon,
                'active' => $active,
                'sort' => $sort_order,
                'id' => $id
            ]);
            $success = "Kategorie aktualisiert";
        } elseif ($action === 'add') {
            $name = sanitize_input($_POST['name']);
            $description = $_POST['description'];
            $color = $_POST['color'];
            $icon = sanitize_input($_POST['icon']);
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("INSERT INTO transaction_categories (name, description, color, icon, sort_order) 
                                   VALUES (:name, :description, :color, :icon, :sort)");
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'icon' => $icon,
                'sort' => $sort_order
            ]);
            $success = "Kategorie hinzugefügt";
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM transaction_categories WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = "Kategorie gelöscht";
        }
    } elseif ($section === 'email') {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'save') {
            try {
                $smtp_host = trim($_POST['smtp_host']);
                $smtp_port = (int)$_POST['smtp_port'];
                $smtp_username = trim($_POST['smtp_username']);
                $smtp_password = trim($_POST['smtp_password']);
                $from_email = trim($_POST['from_email']);
                $from_name = trim($_POST['from_name']);
                $use_tls = isset($_POST['use_tls']) ? 1 : 0;
                
                if (!empty($smtp_password)) {
                    $stmt = $db->prepare("UPDATE email_config SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, from_email = ?, from_name = ?, use_tls = ? WHERE id = 1");
                    $stmt->execute([$smtp_host, $smtp_port, $smtp_username, $smtp_password, $from_email, $from_name, $use_tls]);
                } else {
                    $stmt = $db->prepare("UPDATE email_config SET smtp_host = ?, smtp_port = ?, smtp_username = ?, from_email = ?, from_name = ?, use_tls = ? WHERE id = 1");
                    $stmt->execute([$smtp_host, $smtp_port, $smtp_username, $from_email, $from_name, $use_tls]);
                }
                $success = "E-Mail-Einstellungen gespeichert.";
            } catch (Exception $e) {
                $error = "Fehler beim Speichern: " . $e->getMessage();
            }
        } elseif ($action === 'test') {
            try {
                $test_email = trim($_POST['test_email']);
                if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Ungültige E-Mail-Adresse");
                }
                $emailService = new EmailService();
                $result = $emailService->sendTestEmail($test_email);
                if ($result['success']) {
                    $success = "Test-E-Mail erfolgreich an $test_email gesendet!";
                } else {
                    $error = "Fehler beim Senden: " . $result['error'];
                }
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    } elseif ($section === 'organization') {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'save') {
            try {
                $data = [
                    'name' => sanitize_input($_POST['name'] ?? ''),
                    'legal_name' => sanitize_input($_POST['legal_name'] ?? ''),
                    'iban' => sanitize_input($_POST['iban'] ?? ''),
                    'bic' => sanitize_input($_POST['bic'] ?? ''),
                    'paypal_link' => sanitize_input($_POST['paypal_link'] ?? ''),
                    'phone' => sanitize_input($_POST['phone'] ?? ''),
                    'phone_emergency' => sanitize_input($_POST['phone_emergency'] ?? ''),
                    'email' => sanitize_input($_POST['email'] ?? ''),
                    'email_support' => sanitize_input($_POST['email_support'] ?? ''),
                    'street' => sanitize_input($_POST['street'] ?? ''),
                    'postal_code' => sanitize_input($_POST['postal_code'] ?? ''),
                    'city' => sanitize_input($_POST['city'] ?? ''),
                    'country' => sanitize_input($_POST['country'] ?? 'Deutschland'),
                    'website' => sanitize_input($_POST['website'] ?? ''),
                    'bank_name' => sanitize_input($_POST['bank_name'] ?? ''),
                    'bank_owner' => sanitize_input($_POST['bank_owner'] ?? ''),
                    'notes' => $_POST['notes'] ?? '',
                    'updated_by' => $_SESSION['user_id']
                ];
                
                // Check if organization exists
                $stmt = $db->query("SELECT id FROM organization WHERE id = 1");
                $exists = $stmt->fetch();
                
                if ($exists) {
                    $sql = "UPDATE organization SET " . 
                           implode(", ", array_map(fn($k) => "$k = :$k", array_keys($data))) . 
                           " WHERE id = 1";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($data);
                } else {
                    $data['id'] = 1;
                    $columns = implode(", ", array_keys($data));
                    $placeholders = implode(", ", array_map(fn($k) => ":$k", array_keys($data)));
                    $sql = "INSERT INTO organization ($columns) VALUES ($placeholders)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($data);
                }
                
                $success = "Organisationseinstellungen erfolgreich gespeichert.";
            } catch (Exception $e) {
                $error = "Fehler beim Speichern: " . $e->getMessage();
            }
        }
    } elseif ($section === 'fees') {
        $action = $_POST['action'] ?? 'save';
        
        if ($action === 'add') {
            try {
                $member_type = sanitize_input($_POST['member_type']);
                $minimum_amount = (float)$_POST['minimum_amount'];
                $valid_from = sanitize_input($_POST['valid_from']);
                $valid_until = !empty($_POST['valid_until']) ? sanitize_input($_POST['valid_until']) : null;
                $description = sanitize_input($_POST['description'] ?? '');
                
                $stmt = $db->prepare("INSERT INTO membership_fees (member_type, minimum_amount, valid_from, valid_until, description, created_by) 
                                     VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$member_type, $minimum_amount, $valid_from, $valid_until, $description, $_SESSION['user_id']]);
                $success = "Beitragssatz erfolgreich hinzugefügt.";
            } catch (Exception $e) {
                $error = "Fehler beim Hinzufügen: " . $e->getMessage();
            }
        } elseif ($action === 'update') {
            try {
                $id = (int)$_POST['id'];
                $minimum_amount = (float)$_POST['minimum_amount'];
                $valid_until = !empty($_POST['valid_until']) ? sanitize_input($_POST['valid_until']) : null;
                $description = sanitize_input($_POST['description'] ?? '');
                
                $stmt = $db->prepare("UPDATE membership_fees SET minimum_amount = ?, valid_until = ?, description = ? WHERE id = ?");
                $stmt->execute([$minimum_amount, $valid_until, $description, $id]);
                $success = "Beitragssatz erfolgreich aktualisiert.";
            } catch (Exception $e) {
                $error = "Fehler beim Aktualisieren: " . $e->getMessage();
            }
        } elseif ($action === 'delete') {
            try {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("DELETE FROM membership_fees WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Beitragssatz gelöscht.";
            } catch (Exception $e) {
                $error = "Fehler beim Löschen: " . $e->getMessage();
            }
        }
    } elseif ($section === 'permissions') {
        $action = $_POST['action'];
        
        if ($action === 'grant') {
            $user_id = (int)$_POST['user_id'];
            $permission_id = (int)$_POST['permission_id'];
            
            $stmt = $db->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id, granted_by) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $permission_id, $_SESSION['user_id']]);
            $success = "Berechtigung zugewiesen";
        } elseif ($action === 'revoke') {
            $user_id = (int)$_POST['user_id'];
            $permission_id = (int)$_POST['permission_id'];
            
            $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?");
            $stmt->execute([$user_id, $permission_id]);
            $success = "Berechtigung entzogen";
        }
    }
}

// Get social media links
$stmt = $db->query("SELECT * FROM social_media ORDER BY sort_order");
$social_links = $stmt->fetchAll();

// Get transaction categories
$stmt = $db->query("SELECT * FROM transaction_categories ORDER BY sort_order");
$categories = $stmt->fetchAll();

// Get users
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

// AJAX: return only selected user's detail panel without full page reload
if (isset($_GET['ajax']) && $_GET['ajax'] === 'user_detail') {
    $stmt = $db->query("SELECT * FROM permissions ORDER BY category, display_name");
    $all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $permissions_by_category = [];
    foreach ($all_permissions as $perm) {
        $cat = $perm['category'] ?? 'Sonstige';
        if (!isset($permissions_by_category[$cat])) { $permissions_by_category[$cat] = []; }
        $permissions_by_category[$cat][] = $perm;
    }

    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.is_admin, u.last_login, u.member_id,
                                 m.first_name as member_first_name, m.last_name as member_last_name, m.member_number
                          FROM users u 
                          LEFT JOIN members m ON u.member_id = m.id
                          WHERE u.id = ? AND u.active = 1");
    $stmt->execute([$user_id]);
    $selected_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$selected_user) {
        echo '<div class="empty-state"><p>Benutzer nicht gefunden.</p></div>';
        exit;
    }

    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.display_name, p.category
         FROM user_permissions up
         INNER JOIN permissions p ON up.permission_id = p.id
         WHERE up.user_id = ?
         ORDER BY p.category, p.display_name"
    );
    $stmt->execute([$selected_user['id']]);
    $user_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $user_perm_ids = array_column($user_permissions, 'id');
    ?>
    <div class="card user-detail-card">
        <div class="user-detail-header">
            <div class="user-ident">
                <h3 class="user-name"><?php echo htmlspecialchars($selected_user['first_name'] . ' ' . $selected_user['last_name']); ?></h3>
                <div class="user-meta">
                    <span class="user-handle">@<?php echo htmlspecialchars($selected_user['username']); ?></span>
                    • <span class="user-email"><?php echo htmlspecialchars($selected_user['email']); ?></span>
                    <?php if ($selected_user['is_admin']): ?>
                        • <span class="badge badge-danger">Admin</span>
                    <?php endif; ?>
                    <?php if ($selected_user['last_login']): ?>
                        • <span class="user-last-login">Letzter Login: <?php echo format_datetime($selected_user['last_login']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-actions">
                <?php if ($selected_user['id'] != $_SESSION['user_id']): ?>
                    <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo (int)$selected_user['id']; ?>)">Löschen</button>
                <?php else: ?>
                    <span class="badge badge-success">Aktueller Benutzer</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Member Link Section -->
        <div class="member-link-section" style="margin: 1.5rem 0; padding: 1rem; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #2196f3;">
            <strong class="section-title" style="display: block; margin-bottom: 1rem; color: #333; font-size: 1rem;">
                <i class="fas fa-link"></i> Mitgliederverknüpfung
            </strong>
            
            <?php
            // Get all members for dropdown
            $stmt = $db->query("SELECT id, first_name, last_name, member_number, active FROM members ORDER BY active DESC, last_name, first_name");
            $all_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <form method="POST" style="display: flex; flex-direction: column; gap: 1rem;">
                <input type="hidden" name="section" value="users">
                <input type="hidden" name="action" value="update_member_link">
                <input type="hidden" name="user_id" value="<?php echo (int)$selected_user['id']; ?>">
                
                <div style="display: flex; align-items: end; gap: 1rem; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <label for="member_id" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #555;">Verknüpftes Mitglied</label>
                        <select name="member_id" id="member_id" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.95rem;">
                            <option value="">Kein Mitglied verknüpft</option>
                            <?php foreach ($all_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" 
                                        <?php echo ($selected_user['member_id'] == $member['id']) ? 'selected' : ''; ?>
                                        <?php echo !$member['active'] ? 'style="color: #999;"' : ''; ?>>
                                    <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                    <?php if ($member['member_number']): ?>
                                        (<?php echo htmlspecialchars($member['member_number']); ?>)
                                    <?php endif; ?>
                                    <?php if (!$member['active']): ?>
                                        - [Inaktiv]
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1.5rem;">
                        <i class="fas fa-save"></i> Speichern
                    </button>
                </div>
                
                <?php if ($selected_user['member_id']): ?>
                    <div style="padding: 0.75rem; background: #e8f5e9; border-radius: 4px; border-left: 3px solid #4caf50;">
                        <i class="fas fa-check-circle" style="color: #4caf50;"></i>
                        <strong>Verknüpft mit:</strong> 
                        <?php echo htmlspecialchars($selected_user['member_first_name'] . ' ' . $selected_user['member_last_name']); ?>
                        <?php if ($selected_user['member_number']): ?>
                            (Mitgliedsnummer: <?php echo htmlspecialchars($selected_user['member_number']); ?>)
                        <?php endif; ?>
                        <a href="member_payments.php?id=<?php echo $selected_user['member_id']; ?>" 
                           class="btn btn-sm btn-secondary" 
                           style="margin-left: 1rem; padding: 0.25rem 0.75rem; font-size: 0.85rem;"
                           target="_blank">
                            <i class="fas fa-external-link-alt"></i> Zahlungen anzeigen
                        </a>
                    </div>
                <?php else: ?>
                    <div style="padding: 0.75rem; background: #fff3cd; border-radius: 4px; border-left: 3px solid #ffc107; font-size: 0.9rem; color: #856404;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Hinweis:</strong> Dieser Benutzer ist keinem Mitglied zugeordnet.
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($selected_user['is_admin']): ?>
            <p class="admin-info">✓ Admin hat automatisch alle Berechtigungen</p>
        <?php else: ?>
            <div class="permissions-section">
                <strong class="section-title">Berechtigungen</strong>
                <?php foreach ($permissions_by_category as $category => $perms): ?>
                    <div class="permission-category">
                        <span class="category-title"><?php echo htmlspecialchars($category); ?></span>
                        <div class="permission-list">
                            <?php foreach ($perms as $perm): $has_permission = in_array($perm['id'], $user_perm_ids); ?>
                                <form method="POST" class="permission-item">
                                    <input type="hidden" name="section" value="permissions">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$selected_user['id']; ?>">
                                    <input type="hidden" name="permission_id" value="<?php echo (int)$perm['id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $has_permission ? 'revoke' : 'grant'; ?>">
                                    <button type="submit" class="btn <?php echo $has_permission ? 'btn-success' : 'btn-outline'; ?>">
                                        <?php echo $has_permission ? '✓ ' : ''; ?><?php echo htmlspecialchars($perm['display_name']); ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

$page_title = 'Einstellungen';
include 'includes/header.php';

// Check for session success message
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Einstellungen</h1>
</div>

<div class="settings-tabs">
    <button class="tab-btn active" onclick="showTab('email')">E-Mail</button>
    <button class="tab-btn" onclick="showTab('organization')">Organisation</button>
    <button class="tab-btn" onclick="showTab('social')">Social Media</button>
    <button class="tab-btn" onclick="showTab('fees')">Beitragssätze</button>
    <button class="tab-btn" onclick="showTab('categories')">Kategorien</button>
    <button class="tab-btn" onclick="showTab('users')">Benutzer & Berechtigungen</button>
</div>

<!-- Email Tab -->
<div id="email-tab" class="tab-content active">
    <h2>E-Mail-Einstellungen</h2>
    <p style="color: #666; margin-bottom: 20px;">Konfigurieren Sie die SMTP-Einstellungen für Magic Link E-Mails.</p>
    
    <?php
    $stmt = $db->query("SELECT * FROM email_config WHERE id = 1");
    $email_config = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>
    
    <form method="POST" style="max-width: 600px;">
        <input type="hidden" name="section" value="email">
        <input type="hidden" name="action" value="save">
        
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">SMTP Host</label>
            <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($email_config['smtp_host'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
            <small style="color: #999;">z.B. smtp.ionos.de, smtp.gmail.com</small>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">SMTP Port</label>
            <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($email_config['smtp_port'] ?? 587); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
            <small style="color: #999;">Standard: 587 (TLS) oder 465 (SSL)</small>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display:flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="use_tls" <?php echo ($email_config['use_tls'] ?? 1) ? 'checked' : ''; ?>>
                <span style="font-weight: bold;">TLS/STARTTLS verwenden</span>
            </label>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">SMTP Benutzername</label>
            <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($email_config['smtp_username'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
            <small style="color: #999;">Meist die vollständige E-Mail-Adresse</small>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">SMTP Passwort</label>
            <input type="password" name="smtp_password" placeholder="<?php echo $email_config['smtp_password'] ? '••••••••' : ''; ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <small style="color: #999;">Leer lassen, um aktuelles Passwort beizubehalten</small>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">Absender E-Mail</label>
            <input type="email" name="from_email" value="<?php echo htmlspecialchars($email_config['from_email'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">Absender Name</label>
            <input type="text" name="from_name" value="<?php echo htmlspecialchars($email_config['from_name'] ?? 'OwMM Feuerwehr'); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
        </div>
        
        <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
    </form>
    
    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 30px;">
        <h3>Test-E-Mail senden</h3>
        <p style="color: #666; margin-bottom: 15px;">Testen Sie die E-Mail-Konfiguration durch Versenden einer Test-E-Mail.</p>
        
        <form method="POST" style="display: flex; gap: 10px;">
            <input type="hidden" name="section" value="email">
            <input type="hidden" name="action" value="test">
            <input type="email" name="test_email" placeholder="test@example.com" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
            <button type="submit" class="btn btn-secondary">Test senden</button>
        </form>
    </div>
</div>

<!-- Organization Tab -->
<div id="organization-tab" class="tab-content">
    <h2>Organisationsinformationen</h2>
    <p style="color: #666; margin-bottom: 20px;">Verwaltung der Organisationsdaten (Name, Kontaktdaten, Bankdaten, etc.). Diese Informationen werden in Zahlungserinnerungen und anderen Kommunikationen verwendet.</p>
    
    <?php
    $stmt = $db->query("SELECT * FROM organization WHERE id = 1");
    $org = $stmt->fetch(PDO::FETCH_ASSOC) ?? [];
    ?>
    
    <form method="POST" style="max-width: 800px;">
        <input type="hidden" name="section" value="organization">
        <input type="hidden" name="action" value="save">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Basis-Informationen -->
            <fieldset style="grid-column: 1 / -1; border: 1px solid #ddd; padding: 15px; border-radius: 8px;">
                <legend style="padding: 0 10px; font-weight: bold; color: #333;">Basis-Informationen</legend>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Name der Organisation *</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($org['name'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Rechtlicher Name</label>
                    <input type="text" name="legal_name" value="<?php echo htmlspecialchars($org['legal_name'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <small style="color: #999;">Für Rechnungen und offizielle Dokumente</small>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Website</label>
                    <input type="url" name="website" value="<?php echo htmlspecialchars($org['website'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="https://...">
                </div>
            </fieldset>
            
            <!-- Kontaktdaten -->
            <fieldset style="grid-column: 1 / -1; border: 1px solid #ddd; padding: 15px; border-radius: 8px;">
                <legend style="padding: 0 10px; font-weight: bold; color: #333;">Kontaktinformationen</legend>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Telefon (Zentrale)</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($org['phone'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Notruf / Notfall</label>
                    <input type="tel" name="phone_emergency" value="<?php echo htmlspecialchars($org['phone_emergency'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">E-Mail (Hauptadresse)</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($org['email'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">E-Mail (Support)</label>
                    <input type="email" name="email_support" value="<?php echo htmlspecialchars($org['email_support'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
            </fieldset>
            
            <!-- Adresse -->
            <fieldset style="grid-column: 1 / -1; border: 1px solid #ddd; padding: 15px; border-radius: 8px;">
                <legend style="padding: 0 10px; font-weight: bold; color: #333;">Adresse</legend>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Straße und Hausnummer</label>
                    <input type="text" name="street" value="<?php echo htmlspecialchars($org['street'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display:block; margin-bottom: 8px; font-weight: bold;">PLZ</label>
                        <input type="text" name="postal_code" value="<?php echo htmlspecialchars($org['postal_code'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom: 8px; font-weight: bold;">Stadt</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($org['city'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Land</label>
                    <input type="text" name="country" value="<?php echo htmlspecialchars($org['country'] ?? 'Deutschland'); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
            </fieldset>
            
            <!-- Bankdaten -->
            <fieldset style="grid-column: 1 / -1; border: 1px solid #ddd; padding: 15px; border-radius: 8px;">
                <legend style="padding: 0 10px; font-weight: bold; color: #333;">Bankdaten</legend>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Kontoinhaber</label>
                    <input type="text" name="bank_owner" value="<?php echo htmlspecialchars($org['bank_owner'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">Bank</label>
                    <input type="text" name="bank_name" value="<?php echo htmlspecialchars($org['bank_name'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">IBAN</label>
                    <input type="text" name="iban" value="<?php echo htmlspecialchars($org['iban'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="DE89...">
                    <small style="color: #999;">Wird in Zahlungserinnerungen verwendet</small>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">BIC / SWIFT Code</label>
                    <input type="text" name="bic" value="<?php echo htmlspecialchars($org['bic'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 8px; font-weight: bold;">PayPal Link</label>
                    <input type="url" name="paypal_link" value="<?php echo htmlspecialchars($org['paypal_link'] ?? ''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="https://paypal.me/...">
                    <small style="color: #999;">Wird in Zahlungserinnerungen verwendet</small>
                </div>
            </fieldset>
            
            <!-- Notizen -->
            <fieldset style="grid-column: 1 / -1; border: 1px solid #ddd; padding: 15px; border-radius: 8px;">
                <legend style="padding: 0 10px; font-weight: bold; color: #333;">Zusätzliche Notizen</legend>
                
                <textarea name="notes" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-height: 100px; font-family: sans-serif;"><?php echo htmlspecialchars($org['notes'] ?? ''); ?></textarea>
                <small style="color: #999;">Interne Notizen, die nicht öffentlich angezeigt werden</small>
            </fieldset>
        </div>
        
        <button type="submit" class="btn btn-primary btn-large">Einstellungen speichern</button>
    </form>
</div>

<!-- Social Media Tab -->
<div id="social-tab" class="tab-content">
    <h2>Social Media Links</h2>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sortierung</th>
                    <th>Plattform</th>
                    <th>URL</th>
                    <th>Icon-Klasse</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($social_links as $link): ?>
                    <tr>
                        <form method="POST">
                            <input type="hidden" name="section" value="social_media">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                            <td><input type="number" name="sort_order" value="<?php echo $link['sort_order']; ?>" style="width: 60px;"></td>
                            <td><input type="text" name="platform" value="<?php echo htmlspecialchars($link['platform']); ?>"></td>
                            <td><input type="url" name="url" value="<?php echo htmlspecialchars($link['url']); ?>"></td>
                            <td><input type="text" name="icon_class" value="<?php echo htmlspecialchars($link['icon_class']); ?>"></td>
                            <td><input type="checkbox" name="active" <?php echo $link['active'] ? 'checked' : ''; ?>></td>
                            <td class="actions">
                                <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteSocialMedia(<?php echo $link['id']; ?>)">Löschen</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <h3>Neuen Link hinzufügen</h3>
    <form method="POST" class="add-form">
        <input type="hidden" name="section" value="social_media">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <input type="text" name="platform" placeholder="Plattform (z.B. Instagram)" required>
            <input type="url" name="url" placeholder="https://..." required>
            <input type="text" name="icon_class" placeholder="fab fa-instagram" required>
            <input type="number" name="sort_order" placeholder="Sortierung" value="10" style="width: 100px;">
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
        </div>
    </form>
</div>

<!-- Fees Tab -->
<div id="fees-tab" class="tab-content">
    <h2>Beitragssätze verwalten</h2>
    <p style="color: #666; margin-bottom: 20px;">Verwalten Sie die Mitgliedsbeitragssätze. PayPal-Links und QR-Codes werden automatisch generiert.</p>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Mitgliedertyp</th>
                    <th>Beitrag</th>
                    <th>Gültig ab</th>
                    <th>Gültig bis</th>
                    <th>PayPal-Link</th>
                    <th>QR-Code</th>
                    <th>Beschreibung</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $db->query("SELECT * FROM membership_fees ORDER BY valid_from DESC, member_type");
                $all_fees = $stmt->fetchAll();
                foreach ($all_fees as $fee):
                    // Generate PayPal link
                    $org_stmt = $db->query("SELECT paypal_link FROM organization WHERE id = 1");
                    $org = $org_stmt->fetch();
                    $paypal_base = $org['paypal_link'] ?? '';
                    $paypal_link = $paypal_base ? $paypal_base . '/' . number_format($fee['minimum_amount'], 2) : '';
                    $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($paypal_link);
                ?>
                <tr>
                    <td>
                        <strong><?php 
                            $types = ['active' => 'Aktiv', 'supporter' => 'Gönner', 'pensioner' => 'Rentner'];
                            echo htmlspecialchars($types[$fee['member_type']] ?? $fee['member_type']);
                        ?></strong>
                    </td>
                    <td>€ <?php echo number_format($fee['minimum_amount'], 2, ',', '.'); ?></td>
                    <td><?php echo date('d.m.Y', strtotime($fee['valid_from'])); ?></td>
                    <td><?php echo $fee['valid_until'] ? date('d.m.Y', strtotime($fee['valid_until'])) : '<span style="color: #2ecc71;">aktuell</span>'; ?></td>
                    <td>
                        <?php if ($paypal_link): ?>
                            <a href="<?php echo htmlspecialchars($paypal_link); ?>" target="_blank" class="btn btn-sm btn-secondary" title="PayPal-Link öffnen">
                                <i class="fab fa-paypal"></i> PayPal
                            </a>
                        <?php else: ?>
                            <span style="color: #999;">Kein PayPal Link</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($paypal_link): ?>
                            <a href="<?php echo htmlspecialchars($qr_code_url); ?>" target="_blank" title="QR-Code vergrößern">
                                <img src="<?php echo htmlspecialchars($qr_code_url); ?>" alt="QR-Code" style="width: 60px; height: 60px;">
                            </a>
                        <?php else: ?>
                            <span style="color: #999;">Keine PayPal</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($fee['description'] ?? ''); ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-sm btn-primary" onclick="editFee(<?php echo $fee['id']; ?>, '<?php echo htmlspecialchars(json_encode($fee), ENT_QUOTES); ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="section" value="fees">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $fee['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Wirklich löschen?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <h3 style="margin-top: 30px;">Neuen Beitragssatz hinzufügen</h3>
    <form method="POST" style="max-width: 600px;">
        <input type="hidden" name="section" value="fees">
        <input type="hidden" name="action" value="add">
        
        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">Mitgliedertyp *</label>
            <select name="member_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
                <option value="">-- Bitte auswählen --</option>
                <option value="active">Aktive Mitglieder</option>
                <option value="supporter">Gönner</option>
                <option value="pensioner">Rentner</option>
            </select>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">Mindestbeitrag (€) *</label>
            <input type="number" name="minimum_amount" step="0.01" min="0" placeholder="z.B. 60.00" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div>
                <label style="display:block; margin-bottom: 8px; font-weight: bold;">Gültig ab *</label>
                <input type="date" name="valid_from" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
            </div>
            <div>
                <label style="display:block; margin-bottom: 8px; font-weight: bold;">Gültig bis (optional)</label>
                <input type="date" name="valid_until" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom: 8px; font-weight: bold;">Beschreibung (optional)</label>
            <input type="text" name="description" placeholder="z.B. Regelsatz 2024" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
        </div>
        
        <button type="submit" class="btn btn-primary">Beitragssatz hinzufügen</button>
    </form>
</div>

<!-- Categories Tab -->
<div id="categories-tab" class="tab-content">
    <h2>Transaktionskategorien verwalten</h2>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sortierung</th>
                    <th>Farbe</th>
                    <th>Name</th>
                    <th>Beschreibung</th>
                    <th>Icon</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <form method="POST">
                            <input type="hidden" name="section" value="transaction_categories">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                            <td><input type="number" name="sort_order" value="<?php echo $category['sort_order']; ?>" style="width: 60px;"></td>
                            <td>
                                <input type="color" name="color" value="<?php echo htmlspecialchars($category['color']); ?>" style="width: 60px; height: 40px; cursor: pointer;">
                            </td>
                            <td><input type="text" name="name" value="<?php echo htmlspecialchars($category['name']); ?>"></td>
                            <td><input type="text" name="description" value="<?php echo htmlspecialchars($category['description']); ?>"></td>
                            <td><input type="text" name="icon" value="<?php echo htmlspecialchars($category['icon']); ?>" placeholder="fas fa-..."></td>
                            <td><input type="checkbox" name="active" <?php echo $category['active'] ? 'checked' : ''; ?>></td>
                            <td class="actions">
                                <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteCategory(<?php echo $category['id']; ?>)">Löschen</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <h3>Neue Kategorie hinzufügen</h3>
    <form method="POST" class="add-form">
        <input type="hidden" name="section" value="transaction_categories">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <input type="text" name="name" placeholder="Kategoriename" required style="flex: 1;">
            <input type="text" name="description" placeholder="Beschreibung" style="flex: 2;">
            <input type="color" name="color" value="#1976d2" style="width: 70px;">
            <input type="text" name="icon" placeholder="fas fa-..." style="flex: 1;">
            <input type="number" name="sort_order" placeholder="Sortierung" value="10" style="width: 100px;">
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
        </div>
    </form>
</div>

<!-- Users Tab -->
<div id="users-tab" class="tab-content">
    <h2>Benutzer & Berechtigungen verwalten</h2>
    <p style="color: #666; margin-bottom: 30px;">Verwalten Sie Benutzer und weisen Sie ihnen flexible Berechtigungen zu. Ein Benutzer kann mehrere Berechtigungen haben.</p>
    
    <?php
    // Get all permissions grouped by category
    $stmt = $db->query("SELECT * FROM permissions ORDER BY category, display_name");
    $all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by category
    $permissions_by_category = [];
    foreach ($all_permissions as $perm) {
        $cat = $perm['category'] ?? 'Sonstige';
        if (!isset($permissions_by_category[$cat])) {
            $permissions_by_category[$cat] = [];
        }
        $permissions_by_category[$cat][] = $perm;
    }
    
    // Reload users with is_admin column (include email for display)
    $stmt = $db->query("SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.is_admin, u.last_login, u.member_id,
                               m.first_name as member_first_name, m.last_name as member_last_name, m.member_number
                        FROM users u 
                        LEFT JOIN members m ON u.member_id = m.id
                        WHERE u.active = 1 
                        ORDER BY u.username");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine selected user (from URL or default to first)
    $selected_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $selected_user = null;
    if (!empty($all_users)) {
        if ($selected_user_id === null) {
            $selected_user = $all_users[0];
            $selected_user_id = $selected_user['id'];
        } else {
            foreach ($all_users as $u) {
                if ((int)$u['id'] === $selected_user_id) { $selected_user = $u; break; }
            }
            if ($selected_user === null) { $selected_user = $all_users[0]; $selected_user_id = $selected_user['id']; }
        }
    }

    ?>

    <div class="user-management">
        <div class="user-list-panel">
            <h3 style="margin: 0 0 10px 0;">Benutzer</h3>
            <div class="user-search-wrap">
                <input type="text" id="userSearch" placeholder="Benutzer suchen…" aria-label="Benutzer suchen">
            </div>
            <ul class="user-list" id="userList">
                <?php foreach ($all_users as $u): ?>
                    <?php $is_active = ((int)$u['id'] === (int)$selected_user_id); ?>
                    <li class="user-list-item <?php echo $is_active ? 'active' : ''; ?>" data-name="<?php echo htmlspecialchars(($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['username'])); ?>">
                        <a href="settings.php?tab=users&user_id=<?php echo (int)$u['id']; ?>" class="user-list-link">
                            <span class="user-avatar" aria-hidden="true"><?php echo strtoupper(substr($u['first_name'] ?: $u['username'], 0, 1)); ?></span>
                            <span class="user-primary"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></span>
                            <span class="user-secondary">@<?php echo htmlspecialchars($u['username']); ?></span>
                            <?php if ($u['is_admin']): ?><span class="user-badge">Admin</span><?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="user-detail-panel">
            <div id="userDetailContent">
            <?php if ($selected_user): ?>
                <div class="card user-detail-card">
                    <div class="user-detail-header">
                        <div class="user-ident">
                            <h3 class="user-name"><?php echo htmlspecialchars($selected_user['first_name'] . ' ' . $selected_user['last_name']); ?></h3>
                            <div class="user-meta">
                                <span class="user-handle">@<?php echo htmlspecialchars($selected_user['username']); ?></span>
                                • <span class="user-email"><?php echo htmlspecialchars($selected_user['email']); ?></span>
                                <?php if ($selected_user['is_admin']): ?>
                                    • <span class="badge badge-danger">Admin</span>
                                <?php endif; ?>
                                <?php if ($selected_user['last_login']): ?>
                                    • <span class="user-last-login">Letzter Login: <?php echo format_datetime($selected_user['last_login']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="user-actions">
                            <?php if ($selected_user['id'] != $_SESSION['user_id']): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo (int)$selected_user['id']; ?>)">Löschen</button>
                            <?php else: ?>
                                <span class="badge badge-success">Aktueller Benutzer</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Member Link Section -->
                    <div class="member-link-section" style="margin: 1.5rem 0; padding: 1rem; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #2196f3;">
                        <strong class="section-title" style="display: block; margin-bottom: 1rem; color: #333; font-size: 1rem;">
                            <i class="fas fa-link"></i> Mitgliederverknüpfung
                        </strong>
                        
                        <?php
                        // Get all members for dropdown
                        $stmt = $db->query("SELECT id, first_name, last_name, member_number, active FROM members ORDER BY active DESC, last_name, first_name");
                        $all_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <form method="POST" style="display: flex; flex-direction: column; gap: 1rem;">
                            <input type="hidden" name="section" value="users">
                            <input type="hidden" name="action" value="update_member_link">
                            <input type="hidden" name="user_id" value="<?php echo (int)$selected_user['id']; ?>">
                            
                            <div style="display: flex; align-items: end; gap: 1rem; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 250px;">
                                    <label for="member_id" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #555;">Verknüpftes Mitglied</label>
                                    <select name="member_id" id="member_id" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.95rem;">
                                        <option value="">Kein Mitglied verknüpft</option>
                                        <?php foreach ($all_members as $member): ?>
                                            <option value="<?php echo $member['id']; ?>" 
                                                    <?php echo ($selected_user['member_id'] == $member['id']) ? 'selected' : ''; ?>
                                                    <?php echo !$member['active'] ? 'style="color: #999;"' : ''; ?>>
                                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                                <?php if ($member['member_number']): ?>
                                                    (<?php echo htmlspecialchars($member['member_number']); ?>)
                                                <?php endif; ?>
                                                <?php if (!$member['active']): ?>
                                                    - [Inaktiv]
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1.5rem;">
                                    <i class="fas fa-save"></i> Speichern
                                </button>
                            </div>
                            
                            <?php if ($selected_user['member_id']): ?>
                                <div style="padding: 0.75rem; background: #e8f5e9; border-radius: 4px; border-left: 3px solid #4caf50;">
                                    <i class="fas fa-check-circle" style="color: #4caf50;"></i>
                                    <strong>Verknüpft mit:</strong> 
                                    <?php echo htmlspecialchars($selected_user['member_first_name'] . ' ' . $selected_user['member_last_name']); ?>
                                    <?php if ($selected_user['member_number']): ?>
                                        (Mitgliedsnummer: <?php echo htmlspecialchars($selected_user['member_number']); ?>)
                                    <?php endif; ?>
                                    <a href="member_payments.php?id=<?php echo $selected_user['member_id']; ?>" 
                                       class="btn btn-sm btn-secondary" 
                                       style="margin-left: 1rem; padding: 0.25rem 0.75rem; font-size: 0.85rem;"
                                       target="_blank">
                                        <i class="fas fa-external-link-alt"></i> Zahlungen anzeigen
                                    </a>
                                </div>
                            <?php else: ?>
                                <div style="padding: 0.75rem; background: #fff3cd; border-radius: 4px; border-left: 3px solid #ffc107; font-size: 0.9rem; color: #856404;">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Hinweis:</strong> Dieser Benutzer ist keinem Mitglied zugeordnet.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if ($selected_user['is_admin']): ?>
                        <p class="admin-info">✓ Admin hat automatisch alle Berechtigungen</p>
                    <?php else: ?>
                        <?php
                        // Load permissions for selected user only
                        $stmt = $db->prepare(
                            "SELECT p.id, p.name, p.display_name, p.category
                             FROM user_permissions up
                             INNER JOIN permissions p ON up.permission_id = p.id
                             WHERE up.user_id = ?
                             ORDER BY p.category, p.display_name"
                        );
                        $stmt->execute([$selected_user['id']]);
                        $user_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $user_perm_ids = array_column($user_permissions, 'id');
                        ?>

                        <div class="permissions-section">
                            <strong class="section-title">Berechtigungen</strong>
                            <?php foreach ($permissions_by_category as $category => $perms): ?>
                                <div class="permission-category">
                                    <span class="category-title"><?php echo htmlspecialchars($category); ?></span>
                                    <div class="permission-list">
                                        <?php foreach ($perms as $perm): $has_permission = in_array($perm['id'], $user_perm_ids); ?>
                                            <form method="POST" class="permission-item">
                                                <input type="hidden" name="section" value="permissions">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$selected_user['id']; ?>">
                                                <input type="hidden" name="permission_id" value="<?php echo (int)$perm['id']; ?>">
                                                <input type="hidden" name="action" value="<?php echo $has_permission ? 'revoke' : 'grant'; ?>">
                                                <button type="submit" class="btn <?php echo $has_permission ? 'btn-success' : 'btn-outline'; ?>">
                                                    <?php echo $has_permission ? '✓ ' : ''; ?><?php echo htmlspecialchars($perm['display_name']); ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>Keine aktiven Benutzer gefunden. Bitte fügen Sie einen neuen Benutzer hinzu.</p>
                </div>
            <?php endif; ?>
            </div>

            <div class="user-add-section">
                <h3>Neuen Benutzer hinzufügen</h3>
                <form method="POST" class="user-form">
                    <input type="hidden" name="section" value="users">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Benutzername *</label>
                            <input type="text" name="username" required>
                        </div>
                        <div class="form-group">
                            <label>E-Mail *</label>
                            <input type="email" name="email" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Vorname</label>
                            <input type="text" name="first_name">
                        </div>
                        <div class="form-group">
                            <label>Nachname</label>
                            <input type="text" name="last_name">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Passwort *</label>
                            <input type="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_admin" value="1">
                                Admin-Berechtigungen
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Benutzer hinzufügen</button>
                </form>
            </div>
        </div>
    </div>
</div>



<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    if (tab === 'email') {
        document.getElementById('email-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[0].classList.add('active');
    } else if (tab === 'organization') {
        document.getElementById('organization-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[1].classList.add('active');
    } else if (tab === 'social') {
        document.getElementById('social-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[2].classList.add('active');
    } else if (tab === 'fees') {
        document.getElementById('fees-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[3].classList.add('active');
    } else if (tab === 'categories') {
        document.getElementById('categories-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[4].classList.add('active');
    } else if (tab === 'users') {
        document.getElementById('users-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[5].classList.add('active');
    }
}

// Activate tab from URL parameter on load + intercept user selection to avoid reload
document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab) { showTab(tab); }

    // Simple client-side filter for user list
    const userSearch = document.getElementById('userSearch');
    const userList = document.getElementById('userList');
    if (userSearch && userList) {
        userSearch.addEventListener('input', () => {
            const q = userSearch.value.toLowerCase();
            userList.querySelectorAll('.user-list-item').forEach(li => {
                const name = (li.getAttribute('data-name') || '').toLowerCase();
                li.style.display = name.includes(q) ? '' : 'none';
            });
        });

        // Intercept clicks on user list and load details via AJAX
        userList.addEventListener('click', (e) => {
            const link = e.target.closest('.user-list-link');
            if (!link) return;
            e.preventDefault();
            const targetUrl = new URL(link.href, window.location.origin);
            const userId = targetUrl.searchParams.get('user_id');
            if (!userId) return;
            const ajaxUrl = new URL(window.location.href);
            ajaxUrl.searchParams.set('ajax', 'user_detail');
            ajaxUrl.searchParams.set('user_id', userId);
            fetch(ajaxUrl.toString(), { credentials: 'same-origin' })
                .then(r => r.text())
                .then(html => {
                    const panel = document.getElementById('userDetailContent');
                    if (panel) panel.innerHTML = html;
                    // Update active highlight
                    userList.querySelectorAll('.user-list-item').forEach(li => li.classList.remove('active'));
                    const li = link.closest('.user-list-item');
                    if (li) li.classList.add('active');
                    // Persist URL and tab without reload
                    const newUrl = new URL(window.location.href);
                    newUrl.searchParams.set('tab', 'users');
                    newUrl.searchParams.set('user_id', userId);
                    newUrl.searchParams.delete('ajax');
                    history.pushState({ userId }, '', newUrl.toString());
                    showTab('users');
                })
                .catch(err => console.error('Load user detail failed:', err));
        });
    }

    // Handle back/forward navigation to reload details
    window.addEventListener('popstate', () => {
        const panel = document.getElementById('userDetailContent');
        const userId = new URLSearchParams(window.location.search).get('user_id');
        if (!panel || !userId) return;
        const ajaxUrl = new URL(window.location.href);
        ajaxUrl.searchParams.set('ajax', 'user_detail');
        ajaxUrl.searchParams.set('user_id', userId);
        fetch(ajaxUrl.toString(), { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => { panel.innerHTML = html; showTab('users'); })
            .catch(() => {});
    });
});

function deleteSocialMedia(id) {
    if (confirm('Diesen Social Media Link löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="section" value="social_media">' +
                        '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteCategory(id) {
    if (confirm('Diese Kategorie wirklich löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="section" value="transaction_categories">' +
                        '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteUser(id) {
    if (confirm('Diesen Benutzer wirklich löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="section" value="users">' +
                        '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<style>
.settings-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid var(--border-color);
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 1rem;
    color: var(--text-color);
    transition: all 0.3s;
}

.tab-btn:hover {
    background-color: var(--light-color);
}

.tab-btn.active {
    border-bottom-color: var(--primary-color);
    color: var(--primary-color);
    font-weight: 600;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.add-form {
    background: var(--light-color);
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 2rem;
}

.add-form .form-row {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.add-form input {
    flex: 1;
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
}

.user-form {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-top: 2rem;
}

.data-table input[type="text"],
.data-table input[type="url"],
.data-table input[type="number"] {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
}

/* Users master-detail layout */
.user-management {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
}

.user-list-panel {
    background: var(--light-color);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1rem;
}

.user-search-wrap {
    margin-bottom: 0.75rem;
}

#userSearch {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
}

.user-list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 60vh;
    overflow: auto;
}

.user-list-item {
    margin: 0;
}

.user-list-link {
    display: grid;
    grid-template-columns: 32px 1fr auto;
    gap: 10px;
    align-items: center;
    padding: 10px;
    border-radius: 6px;
    color: inherit;
    text-decoration: none;
}

.user-list-item.active .user-list-link {
    background: #eef6ff;
    border: 1px solid #d5e9ff;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #1976d2;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.user-primary { font-weight: 600; }
.user-secondary { color: #777; font-size: 13px; margin-left: 6px; }
.user-badge { background: #fce8e6; color: #c62828; font-size: 12px; padding: 2px 6px; border-radius: 4px; }

.user-detail-panel { min-height: 300px; }
.user-detail-card { padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: #fff; }
.user-detail-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
.user-name { margin: 0 0 5px 0; }
.user-meta { color: #666; font-size: 14px; }
.user-last-login { color: #999; }
.admin-info { color: #4caf50; margin: 10px 0; padding: 10px; background: #f1f8f4; border-radius: 4px; }

.permissions-section { margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
.section-title { display: block; margin-bottom: 12px; color: #333; }
.permission-category { margin-bottom: 15px; }
.category-title { display: inline-block; font-weight: 600; margin-bottom: 6px; color: #555; font-size: 13px; }
.permission-list { display: flex; flex-wrap: wrap; gap: 8px; }
.permission-item .btn.btn-outline { background: #f5f5f5; color: #333; border: 2px solid #ddd; }

.user-add-section { margin-top: 2rem; }
</style>

<?php include 'includes/footer.php'; ?>
