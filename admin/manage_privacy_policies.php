<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    redirect('login.php');
}

$page_title = 'Datenschutzrichtlinien verwalten';
include 'includes/header.php';

$db = getDBConnection();
$admin_id = $_SESSION['user_id'];

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    if ($action === 'create_policy') {
        try {
            $version = trim($_POST['version'] ?? '');
            $content = $_POST['content'] ?? '';
            $summary = trim($_POST['summary'] ?? '');
            $publish = isset($_POST['publish']) ? 1 : 0;
            
            if (empty($version) || empty($content)) {
                throw new Exception('Version und Inhalt sind erforderlich');
            }
            
            // Check if version already exists
            $stmt = $db->prepare("SELECT id FROM privacy_policy_versions WHERE version = ?");
            $stmt->execute([$version]);
            if ($stmt->fetch()) {
                throw new Exception('Diese Version existiert bereits');
            }
            
            // Insert new policy version
            $stmt = $db->prepare("
                INSERT INTO privacy_policy_versions 
                (version, content, summary, published_at, created_by, requires_acceptance)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $version,
                $content,
                $summary,
                $publish ? date('Y-m-d H:i:s') : null,
                $admin_id
            ]);
            
            $message = 'Datenschutzrichtlinie erfolgreich erstellt';
            if ($publish) {
                $message .= ' und veröffentlicht';
            }
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Fehler: ' . $e->getMessage();
            $message_type = 'error';
        }
    } elseif ($action === 'publish_policy') {
        try {
            $policy_id = intval($_POST['policy_id'] ?? 0);
            if (!$policy_id) {
                throw new Exception('Ungültige Policy-ID');
            }
            
            // Update policy to published
            $stmt = $db->prepare("
                UPDATE privacy_policy_versions 
                SET published_at = NOW() 
                WHERE id = ? AND published_at IS NULL
            ");
            $stmt->execute([$policy_id]);
            
            if ($stmt->rowCount() > 0) {
                $message = 'Datenschutzrichtlinie veröffentlicht. Alle Benutzer müssen dieser Version zustimmen';
                $message_type = 'success';
            } else {
                throw new Exception('Policy konnte nicht veröffentlicht werden oder ist bereits veröffentlicht');
            }
        } catch (Exception $e) {
            $message = 'Fehler: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Get all policy versions
$stmt = $db->query("
    SELECT ppv.*, u.first_name, u.last_name, 
           COUNT(DISTINCT ppc.id) as acceptance_count
    FROM privacy_policy_versions ppv
    LEFT JOIN users u ON ppv.created_by = u.id
    LEFT JOIN privacy_policy_consent ppc ON ppv.id = ppc.policy_version_id AND ppc.accepted = 1
    GROUP BY ppv.id
    ORDER BY ppv.published_at DESC, ppv.created_at DESC
");
$policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get consent statistics
$stmt = $db->query("
    SELECT 
        ppv.version,
        COUNT(DISTINCT CASE WHEN ppc.accepted = 1 THEN ppc.user_id END) as accepted_count,
        COUNT(DISTINCT CASE WHEN ppc.accepted = 0 THEN ppc.user_id END) as rejected_count,
        COUNT(DISTINCT ppc.user_id) as total_responses
    FROM privacy_policy_versions ppv
    LEFT JOIN privacy_policy_consent ppc ON ppv.id = ppc.policy_version_id
    WHERE ppv.published_at IS NOT NULL
    GROUP BY ppv.id, ppv.version
    ORDER BY ppv.published_at DESC
");
$consent_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if there are users who haven't accepted the latest policy
$stmt = $db->query("
    SELECT 
        u.id, u.email, u.first_name, u.last_name
    FROM users u
    WHERE u.require_privacy_policy_acceptance = 1
    LIMIT 10
");
$users_pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .section-title {
        font-size: 1.3rem;
        font-weight: bold;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--primary-color);
        color: var(--primary-color);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin: 30px 0;
    }
    
    .stat-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-left: 4px solid var(--primary-color);
    }
    
    .stat-label {
        font-size: 0.85rem;
        color: #666;
        text-transform: uppercase;
        margin-bottom: 10px;
    }
    
    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        color: var(--primary-color);
    }
    
    .policy-table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .policy-table th,
    .policy-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    
    .policy-table th {
        background: #f5f5f5;
        font-weight: bold;
        color: #333;
    }
    
    .policy-table tr:hover {
        background: #fafafa;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 0.85rem;
        font-weight: bold;
    }
    
    .badge-published {
        background: #c8e6c9;
        color: #2e7d32;
    }
    
    .badge-draft {
        background: #fff3cd;
        color: #856404;
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
    
    .form-section {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin: 30px 0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
    
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 1rem;
        font-family: inherit;
    }
    
    .form-group textarea {
        min-height: 300px;
        resize: vertical;
        font-family: monospace;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        margin: 15px 0;
    }
    
    .checkbox-group input {
        margin-right: 10px;
        width: 18px;
        height: 18px;
    }
    
    .pending-users {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 4px;
        padding: 15px;
        margin: 20px 0;
    }
    
    .audit-log {
        background: white;
        border-radius: 8px;
        padding: 15px;
        margin: 20px 0;
        border-left: 4px solid #2196F3;
    }
</style>

<div class="admin-container">
    <h1>🔐 Datenschutzrichtlinien verwalten</h1>
    
    <?php if ($message): ?>
        <div class="message-box <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Section -->
    <div>
        <h2 class="section-title">📊 Übersicht</h2>
        
        <?php if (count($consent_stats) > 0): ?>
            <div class="stats-grid">
                <?php 
                $latest_stat = $consent_stats[0];
                $total_users = $db->query("SELECT COUNT(*) as count FROM users WHERE active = 1")->fetch()['count'];
                ?>
                <div class="stat-card">
                    <div class="stat-label">Aktuelle Version</div>
                    <div class="stat-number"><?php echo htmlspecialchars($latest_stat['version']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Akzeptiert</div>
                    <div class="stat-number"><?php echo $latest_stat['accepted_count'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Abgelehnt</div>
                    <div class="stat-number" style="color: #f44336;"><?php echo $latest_stat['rejected_count'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Ausstehend</div>
                    <div class="stat-number" style="color: #ff9800;"><?php echo $total_users - ($latest_stat['total_responses'] ?? 0); ?></div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (count($users_pending) > 0): ?>
            <div class="pending-users">
                <strong>⚠️ <?php echo count($users_pending); ?> Benutzer müssen noch akzeptieren:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <?php foreach ($users_pending as $user): ?>
                        <li><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> (<?php echo htmlspecialchars($user['email']); ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Create New Policy Section -->
    <div class="form-section">
        <h2 class="section-title">📝 Neue Datenschutzrichtlinie erstellen</h2>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="version">Version (z.B. 1.0, 1.1, 2.0)</label>
                <input type="text" id="version" name="version" placeholder="1.0" required>
            </div>
            
            <div class="form-group">
                <label for="summary">Zusammenfassung von Änderungen</label>
                <textarea id="summary" name="summary" placeholder="Kurze Beschreibung der Änderungen..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="content">Inhalt der Datenschutzerklärung (HTML oder Plaintext)</label>
                <textarea id="content" name="content" required placeholder="Hier den vollständigen Text der Datenschutzerklärung einfügen..."></textarea>
                <small style="color: #666; display: block; margin-top: 5px;">
                    💡 Tipp: Sie können HTML verwenden für Formatierung (z.B. &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;strong&gt;)
                </small>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="publish" name="publish" value="1">
                <label for="publish" style="margin: 0;">Sofort veröffentlichen und alle Benutzer müssen akzeptieren</label>
            </div>
            
            <p style="color: #666; font-style: italic; font-size: 0.9rem; margin: 15px 0 0 0;">
                ℹ️ Wenn Sie nicht sofort veröffentlichen, können Sie dies später in der Übersicht tun.
            </p>
            
            <div style="margin-top: 25px;">
                <button type="submit" name="action" value="create_policy" class="btn btn-success">
                    💾 Datenschutzrichtlinie erstellen
                </button>
            </div>
        </form>
    </div>
    
    <!-- Existing Policies Section -->
    <div>
        <h2 class="section-title">📋 Vorhandene Datenschutzrichtlinien</h2>
        
        <?php if (count($policies) > 0): ?>
            <table class="policy-table">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>Erstellt von</th>
                        <th>Erstellt am</th>
                        <th>Status</th>
                        <th>Akzeptiert / Abgelehnt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($policies as $policy): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($policy['version']); ?></strong></td>
                            <td><?php echo htmlspecialchars($policy['first_name'] . ' ' . $policy['last_name']); ?></td>
                            <td><?php echo date('d.m.Y H:i', strtotime($policy['created_at'])); ?></td>
                            <td>
                                <?php if ($policy['published_at']): ?>
                                    <span class="badge badge-published">✓ Veröffentlicht</span>
                                    <br><small><?php echo date('d.m.Y', strtotime($policy['published_at'])); ?></small>
                                <?php else: ?>
                                    <span class="badge badge-draft">Entwurf</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $stat = array_filter($consent_stats, fn($s) => $s['version'] === $policy['version']);
                                $stat = reset($stat);
                                if ($stat): ?>
                                    ✓ <?php echo $stat['accepted_count'] ?? 0; ?> / ✗ <?php echo $stat['rejected_count'] ?? 0; ?>
                                <?php else: ?>
                                    <em style="color: #999;">Keine Antworten</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="privacy_policy_detail.php?id=<?php echo $policy['id']; ?>" class="btn btn-primary" style="margin-right: 5px;">
                                    Anzeigen
                                </a>
                                <?php if (!$policy['published_at']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="publish_policy">
                                        <input type="hidden" name="policy_id" value="<?php echo $policy['id']; ?>">
                                        <button type="submit" class="btn btn-success" onclick="return confirm('Diese Version veröffentlichen? Alle Benutzer müssen zustimmen.')">
                                            Veröffentlichen
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: #666; padding: 30px; text-align: center;">
                Noch keine Datenschutzrichtlinien erstellt
            </p>
        <?php endif; ?>
    </div>
    
    <!-- Audit Log Note -->
    <div class="audit-log">
        <strong>🔍 Audit Log:</strong>
        <p style="margin: 10px 0 0 0; color: #666;">
            Alle Akzeptanzen und Ablehnungen von Datenschutzrichtlinien werden mit Zeitstempel und IP-Adresse 
            protokolliert. Diese Informationen können Sie in den Benutzer-Details einsehen.
        </p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
