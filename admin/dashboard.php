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

<div class="dashboard-grid">
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
        <h3>Vorstandschaft</h3>
        <p>Vorstandsmitglieder verwalten</p>
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
    <?php endif; ?>
</div>

<div class="quick-stats">
    <h2>Übersicht</h2>
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
        
        // Cash transactions count
        if (can_edit_cash()) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM transactions");
            $trans_count = $stmt->fetch()['count'];
            $stmt = $db->query("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total FROM transactions");
            $balance = $stmt->fetch()['total'];
            echo '<div class="stat-box">
                    <div class="stat-number">' . $trans_count . '</div>
                    <div class="stat-label">Transaktionen</div>
                  </div>
                  <div class="stat-box">
                    <div class="stat-number" style="color: ' . ($balance >= 0 ? '#4caf50' : '#f44336') . '">' . number_format($balance, 2, ',', '.') . ' €</div>
                    <div class="stat-label">Gesamtsaldo</div>
                  </div>';
            
            // Member statistics
            $stmt = $db->query("SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN member_type = 'active' AND active = 1 THEN 1 ELSE 0 END) as active_members,
                                SUM(CASE WHEN member_type = 'supporter' AND active = 1 THEN 1 ELSE 0 END) as supporters
                                FROM members");
            $member_stats = $stmt->fetch();
            
            echo '<div class="stat-box">
                    <div class="stat-number">' . $member_stats['active_members'] . '</div>
                    <div class="stat-label">Einsatzeinheit</div>
                  </div>
                  <div class="stat-box">
                    <div class="stat-number">' . $member_stats['supporters'] . '</div>
                    <div class="stat-label">Förderer</div>
                  </div>';
            
            // Outstanding payments for current year
            $current_year = date('Y');
            $outstanding_obligations = get_open_obligations($current_year);
            $outstanding_count = count($outstanding_obligations);
            $outstanding_amount = array_sum(array_column($outstanding_obligations, 'outstanding'));
            
            echo '<div class="stat-box">
                    <div class="stat-number" style="color: ' . ($outstanding_count > 0 ? '#ff9800' : '#4caf50') . '">' . $outstanding_count . '</div>
                    <div class="stat-label">Offene Forderungen ' . $current_year . '</div>
                  </div>';
            
            if ($outstanding_amount > 0) {
                echo '<div class="stat-box">
                        <div class="stat-number" style="color: #ff9800">' . number_format($outstanding_amount, 2, ',', '.') . ' €</div>
                        <div class="stat-label">Ausstehender Betrag</div>
                      </div>';
            }
        }
        ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
