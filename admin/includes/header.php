<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin'; ?> - <?php echo SITE_NAME; ?></title>
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
                <span class="user-role"><?php echo $_SESSION['user_role']; ?></span>
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
                
                <?php if (can_edit_page_content()): ?>
                <a href="content.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'content.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i> Seiteninhalte
                </a>
                <a href="board.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'board.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Vorstandschaft
                </a>
                <?php endif; ?>
                
                <?php if (has_role('admin')): ?>
                <a href="messages.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Nachrichten
                </a>
                <a href="settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
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
