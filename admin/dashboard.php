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
?>

<?php if (can_edit_cash() || has_role('admin')): ?>
<div class="quick-stats">
    <h2>Übersicht</h2>
    <div class="stats-grid">
        <?php
        $db = getDBConnection();
        
        if (can_edit_cash()) {
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
        }
        
        // Messages count (for admins)
        if (has_role('admin')) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'new'");
            $msg_count = $stmt->fetch()['count'];
            echo '<div class="stat-box">
                    <div class="stat-label" style="font-weight: bold;">Neue Nachrichten</div>
                    <div class="stat-number" style="color: ' . ($msg_count > 0 ? '#ff9800' : '#4caf50') . '">' . $msg_count . '</div>
                  </div>';
        }
        ?>
    </div>
</div>
<?php endif; ?>

<div class="dashboard-grid" style="margin-top: 30px;">
    <?php if (can_edit_operations()): ?>
    <div class="dashboard-card">
        <div class="card-icon">📋</div>
        <h3>Einsätze</h3>
        <p>Einsätze verwalten und neue hinzufügen</p>
        <a href="operations.php" class="btn btn-primary">Verwalten</a>
    </div>
    <?php endif; ?>
    
    <?php if (can_edit_events()): ?>
    <div class="dashboard-card">
        <div class="card-icon">📅</div>
        <h3>Veranstaltungen</h3>
        <p>Events und Termine verwalten</p>
        <a href="events.php" class="btn btn-primary">Verwalten</a>
    </div>
    <?php endif; ?>
    
    <?php if (can_edit_page_content()): ?>
    <div class="dashboard-card">
        <div class="card-icon">📝</div>
        <h3>Seiteninhalte</h3>
        <p>Startseite und allgemeine Inhalte bearbeiten</p>
        <a href="content.php" class="btn btn-primary">Bearbeiten</a>
    </div>
    
    <div class="dashboard-card">
        <div class="card-icon">👥</div>
        <h3>Kommando</h3>
        <p>Kommandomitglieder verwalten</p>
        <a href="board.php" class="btn btn-primary">Verwalten</a>
    </div>
    <?php endif; ?>
    
    <?php if (has_role('admin')): ?>
    <div class="dashboard-card">
        <div class="card-icon">💬</div>
        <h3>Kontaktanfragen</h3>
        <p>Eingegangene Nachrichten ansehen</p>
        <a href="messages.php" class="btn btn-primary">Ansehen</a>
    </div>

    <div class="dashboard-card">
        <div class="card-icon">🧾</div>
        <h3>Kassenprüfer</h3>
        <p>Prüferrollen zuweisen und verwalten</p>
        <a href="kassenpruefer_assignments.php" class="btn btn-primary">Verwalten</a>
    </div>
    
    <div class="dashboard-card">
        <div class="card-icon">⚙️</div>
        <h3>Einstellungen</h3>
        <p>System- und Benutzereinstellungen</p>
        <a href="settings.php" class="btn btn-primary">Öffnen</a>
    </div>
    <?php endif; ?>
    
    <?php if (can_edit_cash()): ?>
    <div class="dashboard-card">
        <div class="card-icon">💰</div>
        <h3>Kontoführung</h3>
        <p>Kassenprüfung und Transaktionsverwaltung</p>
        <a href="kontofuehrung.php" class="btn btn-primary">Öffnen</a>
    </div>
    
    <div class="dashboard-card">
        <div class="card-icon">👤</div>
        <h3>Mitglieder</h3>
        <p>Mitgliederverwaltung und Beiträge</p>
        <a href="members.php" class="btn btn-primary">Verwalten</a>
    </div>
    
    <div class="dashboard-card">
        <div class="card-icon">📋</div>
        <h3>Beitragsforderungen</h3>
        <p>Jahresbeiträge generieren und verwalten</p>
        <a href="generate_obligations.php" class="btn btn-primary">Verwalten</a>
    </div>
    
    <div class="dashboard-card">
        <div class="card-icon">📦</div>
        <h3>Artikel</h3>
        <p>Artikel und Gegenstände verwalten</p>
        <a href="items.php" class="btn btn-primary">Verwalten</a>
    </div>
    
    <div class="dashboard-card">
        <div class="card-icon">🔗</div>
        <h3>Artikelverpflichtungen</h3>
        <p>Artikel-Verpflichtungen erstellen und verwalten</p>
        <a href="outstanding_obligations.php" class="btn btn-primary">Verwalten</a>
    </div>
    <?php endif; ?>
</div>

<?php if (can_edit_operations() || can_edit_events() || has_role('admin')): ?>
<div class="quick-stats">
    <h2>Weitere Statistiken</h2>
    <div class="stats-grid">
        <?php
        $db = getDBConnection();
        
        // Operations count
        if (can_edit_operations()) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM operations WHERE published = 1");
            $ops_count = $stmt->fetch()['count'];
            echo '<div class="stat-box">
                    <div class="stat-number">' . $ops_count . '</div>
                    <div class="stat-label">Veröffentlichte Einsätze</div>
                  </div>';
        }
        
        // Events count
        if (can_edit_events()) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM events WHERE status = 'upcoming' AND published = 1");
            $events_count = $stmt->fetch()['count'];
            echo '<div class="stat-box">
                    <div class="stat-number">' . $events_count . '</div>
                    <div class="stat-label">Anstehende Events</div>
                  </div>';
        }
        
        // Messages count
        if (has_role('admin')) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'new'");
            $msg_count = $stmt->fetch()['count'];
            echo '<div class="stat-box">
                    <div class="stat-number">' . $msg_count . '</div>
                    <div class="stat-label">Neue Nachrichten</div>
                  </div>';
        }
        ?>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
