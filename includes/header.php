<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? SITE_NAME; ?></title>
    <meta name="description" content="<?php echo $page_description ?? 'Freiwillige Feuerwehr - Immer für Sie da'; ?>">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <header class="site-header">
        <nav class="navbar">
            <div class="container">
                <div class="nav-brand">
                    <a href="index.php"><?php echo SITE_NAME; ?></a>
                </div>
                
                <button class="nav-toggle" id="navToggle">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                
                <ul class="nav-menu" id="navMenu">
                    <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">Start</a></li>
                    <li><a href="operations.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'operations.php' ? 'active' : ''; ?>">Einsätze</a></li>
                    <li><a href="events.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'events.php' ? 'active' : ''; ?>">Veranstaltungen</a></li>
                    <li><a href="board.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'board.php' ? 'active' : ''; ?>">Vorstandschaft</a></li>
                    <li><a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">Kontakt</a></li>
                    <li><a href="impressum.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'impressum.php' ? 'active' : ''; ?>">Impressum</a></li>
                    <li><a href="admin/login.php" class="admin-link" title="Admin-Bereich"><i class="fas fa-user-shield"></i></a></li>
                </ul>
            </div>
        </nav>
    </header>
    
    <main class="main-content">
