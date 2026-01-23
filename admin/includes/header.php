<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin'; ?> - <?php echo get_org_setting('site_name'); ?></title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Admin</h2>
                <p><?php echo $_SESSION['user_name'] ?? $_SESSION['username']; ?></p>
                <span class="user-role"><?php echo $_SESSION['is_admin'] ? 'Admin' : 'Benutzer'; ?></span>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                
                <?php if (can_edit_operations()): ?>
                <a href="operations.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'operations.php' ? 'active' : ''; ?>">
                    <i class="fas fa-fire"></i> Einsätze
                </a>
                <?php endif; ?>
                
                <?php if (can_edit_events()): ?>
                <a href="events.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'events.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar"></i> Veranstaltungen
                </a>
                <?php endif; ?>

                <?php if (has_permission('calendar.php')): ?>
                <a href="calendar.php" class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'calendar.php' || basename($_SERVER['PHP_SELF']) == 'calendar_settings.php') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> Kalender
                </a>
                <?php endif; ?>
                
                <?php if (has_permission('trucks.php')): ?>
                <a href="trucks.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'trucks.php' ? 'active' : ''; ?>">
                    <i class="fas fa-truck"></i> Fahrzeuge
                </a>
                <?php endif; ?>
                
                <?php if (can_edit_page_content()): ?>
                <a href="content.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'content.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i> Seiteninhalte
                </a>
                <a href="board.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'board.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Kommando
                </a>
                <?php endif; ?>
                
                <?php if (has_permission('selfservice.php')): ?>
                <a href="selfservice.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'selfservice.php' || basename($_SERVER['PHP_SELF']) == 'selfservice_api.php' ? 'active' : ''; ?>">
                    <i class="fas fa-key"></i> Self-Service
                </a>
                <?php endif; ?>
                
                <?php if (can_edit_cash()): ?>
                <a href="kontofuehrung.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'kontofuehrung.php' ? 'active' : ''; ?>">
                    <i class="fas fa-wallet"></i> Kontoführung
                </a>
                <a href="members.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'members.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> Mitglieder
                </a>
                <a href="generate_obligations.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'generate_obligations.php' ? 'active' : ''; ?>">
                    <i class="fas fa-receipt"></i> Beitragsforderungen
                </a>
                <a href="items.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'items.php' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i> Artikel
                </a>
                <a href="outstanding_obligations.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'outstanding_obligations.php' || basename($_SERVER['PHP_SELF']) == 'create_item_obligation.php' || basename($_SERVER['PHP_SELF']) == 'view_item_obligation.php' ? 'active' : ''; ?>">
                    <i class="fas fa-link"></i> Offene Forderungen
                </a>
                <a href="payment_reminders.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'payment_reminders.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Zahlungserinnerungen
                </a>
                <?php endif; ?>
                
                <?php if (can_check_transactions()): ?>
                <a href="check_periods.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'check_periods.php' || basename($_SERVER['PHP_SELF']) == 'transaction_checking.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tasks"></i> Prüfperioden
                </a>
                <?php endif; ?>
                
                <?php if (has_role('admin')): ?>
                <a href="kassenpruefer_assignments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'kassenpruefer_assignments.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i> Kassenprüfer
                </a>
                <a href="messages.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Nachrichten
                </a>
                <a href="approve_registrations.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'approve_registrations.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-plus"></i> Registrierungen
                </a>
                <a href="settings.php" class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings.php' || basename($_SERVER['PHP_SELF']) == 'email_settings.php') ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Einstellungen
                </a>
                <?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <a href="../index.php" target="_blank" class="btn btn-outline">
                    <i class="fas fa-external-link-alt"></i> Website ansehen
                </a>
                <a href="logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Abmelden
                </a>
            </div>
        </aside>
        
        <main class="main-content">
            <div class="container">
