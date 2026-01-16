<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('login.php');
}

$page_title = 'Dashboard';
include 'includes/header.php';

// Check if user has any permissions
$has_any_permission = is_admin() || has_permission('kontofuehrung.php') || has_permission('members.php') || 
                      has_permission('generate_obligations.php') || has_permission('items.php') || 
                      has_permission('outstanding_obligations.php') || has_permission('operations.php') || 
                      has_permission('events.php') || has_permission('trucks.php') || has_permission('content.php') || 
                      has_permission('board.php') || has_permission('messages.php') || 
                      has_permission('kassenpruefer_assignments.php') || has_permission('approve_registrations.php') || 
                      has_permission('settings.php') || has_permission('check_periods.php');
?>

<?php if (!$has_any_permission): ?>
<div class="quick-stats">
    <h2>Willkommen, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</h2>
    <div class="alert alert-info" style="margin: 20px 0;">
        <p style="margin: 0;">Sie sind erfolgreich angemeldet. Momentan sind Ihnen noch keine Berechtigungen zugewiesen.</p>
        <p style="margin: 10px 0 0 0;">Bitte wenden Sie sich an einen Administrator, um Zugriff auf bestimmte Bereiche zu erhalten.</p>
    </div>
</div>
<?php endif; ?>

<div class="quick-stats">
    <h2>Übersicht</h2>
    <div class="stats-grid">
        <?php
        $db = getDBConnection();
        
        // Cash transactions count and balance
        $stmt = $db->query("SELECT COUNT(*) as count FROM transactions");
        $trans_count = $stmt->fetch()['count'];
        $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM transactions");
        $balance = $stmt->fetch()['total'];
        
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Transaktionen</div>
                <div class="stat-number">' . $trans_count . '</div>
              </div>
              <div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Nettosaldo</div>
                <div class="stat-number" style="color: ' . ($balance >= 0 ? '#4caf50' : '#f44336') . '; font-size: 2rem; font-weight: bold;">' . number_format($balance, 2, ',', '.') . ' €</div>
              </div>';
        
        // Member statistics
        $stmt = $db->query("SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN member_type = 'active' AND active = 1 THEN 1 ELSE 0 END) as active_members,
                            SUM(CASE WHEN member_type = 'supporter' AND active = 1 THEN 1 ELSE 0 END) as supporters
                            FROM members");
        $member_stats = $stmt->fetch();
        
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Einsatzeinheit</div>
                <div class="stat-number">' . $member_stats['active_members'] . '</div>
              </div>
              <div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Förderer</div>
                <div class="stat-number">' . $member_stats['supporters'] . '</div>
              </div>';
        
        // Outstanding obligations for ACTIVE members
        $stmt = $db->query("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(fee_amount - paid_amount), 0) as total_outstanding
            FROM member_fee_obligations o
            INNER JOIN members m ON o.member_id = m.id
            WHERE m.active = 1 
                AND m.member_type = 'active'
                AND o.status IN ('open', 'partial')
        ");
        $active_outstanding = $stmt->fetch();
        
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Offene Forderungen (Aktive)</div>
                <div class="stat-number" style="color: ' . ($active_outstanding['count'] > 0 ? '#ff9800' : '#4caf50') . '">' . $active_outstanding['count'] . '</div>
              </div>';
        
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Ausstehend (Aktive)</div>
                <div class="stat-number" style="color: ' . ($active_outstanding['total_outstanding'] > 0 ? '#ff9800' : '#4caf50') . '; font-size: 1.8rem; font-weight: bold;">' . number_format($active_outstanding['total_outstanding'], 2, ',', '.') . ' €</div>
              </div>';
        
        // Outstanding obligations for SUPPORTER members
        $stmt = $db->query("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(fee_amount - paid_amount), 0) as total_outstanding
            FROM member_fee_obligations o
            INNER JOIN members m ON o.member_id = m.id
            WHERE m.active = 1 
                AND m.member_type = 'supporter'
                AND o.status IN ('open', 'partial')
        ");
        $supporter_outstanding = $stmt->fetch();
        
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Offene Forderungen (Förderer)</div>
                <div class="stat-number" style="color: ' . ($supporter_outstanding['count'] > 0 ? '#ff9800' : '#4caf50') . '">' . $supporter_outstanding['count'] . '</div>
              </div>';
        
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Ausstehend (Förderer)</div>
                <div class="stat-number" style="color: ' . ($supporter_outstanding['total_outstanding'] > 0 ? '#ff9800' : '#4caf50') . '; font-size: 1.8rem; font-weight: bold;">' . number_format($supporter_outstanding['total_outstanding'], 2, ',', '.') . ' €</div>
              </div>';
        
        // Messages count
        $stmt = $db->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'new'");
        $msg_count = $stmt->fetch()['count'];
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Neue Nachrichten</div>
                <div class="stat-number" style="color: ' . ($msg_count > 0 ? '#ff9800' : '#4caf50') . '">' . $msg_count . '</div>
              </div>';
        
        // Open registration requests count
        $stmt = $db->query("SELECT COUNT(*) as count FROM registration_requests WHERE status = 'pending' AND email_verified_at IS NOT NULL");
        $reg_count = $stmt->fetch()['count'];
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Offene Registrierungen</div>
                <div class="stat-number" style="color: ' . ($reg_count > 0 ? '#ff9800' : '#4caf50') . '">' . $reg_count . '</div>
              </div>';
        
        // Operations count
        $stmt = $db->query("SELECT COUNT(*) as count FROM operations WHERE published = 1");
        $ops_count = $stmt->fetch()['count'];
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Veröffentlichte Einsätze</div>
                <div class="stat-number">' . $ops_count . '</div>
              </div>';
        
        // Events count
        $stmt = $db->query("SELECT COUNT(*) as count FROM events WHERE status = 'upcoming' AND published = 1");
        $events_count = $stmt->fetch()['count'];
        echo '<div class="stat-box">
                <div class="stat-label" style="font-weight: bold;">Anstehende Events</div>
                <div class="stat-number">' . $events_count . '</div>
              </div>';
        ?>
    </div>
</div>

<div style="height: 50px;"></div>

<?php
// Load all permissions grouped by category
$db = getDBConnection();
$stmt = $db->query("SELECT * FROM permissions ORDER BY category, display_name");
$all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by category
$permissions_by_category = [];
foreach ($all_permissions as $perm) {
    $cat = $perm['category'] ?? 'Sonstige';
    if (!isset($permissions_by_category[$cat])) {
        $permissions_by_category[$cat] = [];
    }
    $permissions_by_category[$cat][] = $perm;
}

// Define permission to page/icon/description mapping
$perm_details = [
    'operations.php' => ['icon' => '📋', 'title' => 'Einsätze', 'desc' => 'Einsätze verwalten und neue hinzufügen', 'url' => 'operations.php'],
    'events.php' => ['icon' => '📅', 'title' => 'Veranstaltungen', 'desc' => 'Events und Termine verwalten', 'url' => 'events.php'],
    'trucks.php' => ['icon' => '🚒', 'title' => 'Fahrzeuge', 'desc' => 'Feuerwehrfahrzeuge und Ausstattung verwalten', 'url' => 'trucks.php'],
    'content.php' => ['icon' => '📝', 'title' => 'Seiteninhalte', 'desc' => 'Startseite und allgemeine Inhalte bearbeiten', 'url' => 'content.php'],
    'board.php' => ['icon' => '👥', 'title' => 'Kommando', 'desc' => 'Kommandomitglieder verwalten', 'url' => 'board.php'],
    'messages.php' => ['icon' => '💬', 'title' => 'Kontaktanfragen', 'desc' => 'Eingegangene Nachrichten ansehen', 'url' => 'messages.php'],
    'kassenpruefer_assignments.php' => ['icon' => '🧾', 'title' => 'Kassenprüfer', 'desc' => 'Prüferrollen zuweisen und verwalten', 'url' => 'kassenpruefer_assignments.php'],
    'approve_registrations.php' => ['icon' => '👤', 'title' => 'Registrierungen', 'desc' => 'Neue Benutzerregistrierungen genehmigen', 'url' => 'approve_registrations.php'],
    'settings.php' => ['icon' => '⚙️', 'title' => 'Einstellungen', 'desc' => 'System- und Benutzereinstellungen', 'url' => 'settings.php'],
    'kontofuehrung.php' => ['icon' => '💰', 'title' => 'Kontoführung', 'desc' => 'Kassenprüfung und Transaktionsverwaltung', 'url' => 'kontofuehrung.php'],
    'members.php' => ['icon' => '👤', 'title' => 'Mitglieder', 'desc' => 'Mitgliederverwaltung und Beiträge', 'url' => 'members.php'],
    'generate_obligations.php' => ['icon' => '📋', 'title' => 'Beitragsforderungen', 'desc' => 'Jahresbeiträge generieren und verwalten', 'url' => 'generate_obligations.php'],
    'items.php' => ['icon' => '📦', 'title' => 'Artikel', 'desc' => 'Artikel und Gegenstände verwalten', 'url' => 'items.php'],
    'outstanding_obligations.php' => ['icon' => '🔗', 'title' => 'Artikelverpflichtungen', 'desc' => 'Artikel-Verpflichtungen erstellen und verwalten', 'url' => 'outstanding_obligations.php'],
    'check_periods.php' => ['icon' => '✅', 'title' => 'Prüfperioden', 'desc' => 'Kassenprüfung nach Perioden durchführen', 'url' => 'check_periods.php'],
];
?>

<?php foreach ($permissions_by_category as $category => $perms): ?>
<div class="permissions-category-section" style="margin-bottom: 40px;">
    <h2 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 20px;"><?php echo htmlspecialchars($category); ?></h2>
    
    <!-- Active permissions -->
    <div class="dashboard-grid">
        <?php foreach ($perms as $perm): 
            if (!isset($perm_details[$perm['name']])) continue;
            if (has_permission($perm['name'])):
                $detail = $perm_details[$perm['name']];
        ?>
        <div class="dashboard-card">
            <div class="card-icon"><?php echo $detail['icon']; ?></div>
            <h3><?php echo $detail['title']; ?></h3>
            <p><?php echo $detail['desc']; ?></p>
            <a href="<?php echo $detail['url']; ?>" class="btn btn-primary">Öffnen</a>
            <span class="card-tech-info" title="Required Page: <?php echo $perm['name']; ?>"><i class="fas fa-info-circle"></i> <?php echo $perm['name']; ?></span>
        </div>
        <?php endif; endforeach; ?>
    </div>
    
    <!-- Disabled permissions (grayed out) -->
    <div style="margin-top: 30px; padding: 20px; background: #f5f5f5; border-radius: 8px; opacity: 0.6;">
        <p style="color: #999; font-size: 13px; margin-bottom: 15px; font-style: italic;">Verfügbare Berechtigungen (nicht aktiviert):</p>
        <div class="dashboard-grid">
            <?php foreach ($perms as $perm): 
                if (!isset($perm_details[$perm['name']])) continue;
                if (!has_permission($perm['name'])):
                    $detail = $perm_details[$perm['name']];
            ?>
            <div class="dashboard-card" style="opacity: 0.5; pointer-events: none; filter: grayscale(100%);">
                <div class="card-icon"><?php echo $detail['icon']; ?></div>
                <h3><?php echo $detail['title']; ?></h3>
                <p><?php echo $detail['desc']; ?></p>
                <button class="btn btn-primary" disabled>Öffnen</button>
                <span class="card-tech-info" title="Required Page: <?php echo $perm['name']; ?>"><i class="fas fa-info-circle"></i> <?php echo $perm['name']; ?></span>
            </div>
            <?php endif; endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>
