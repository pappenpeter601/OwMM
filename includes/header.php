<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <!-- SEO Meta Tags -->
    <title><?php echo isset($page_title) ? $page_title . ' | ' . SITE_NAME : SITE_NAME . ' - Freiwillige Feuerwehr Meinern-Mittelstendorf'; ?></title>
    <meta name="description" content="<?php echo isset($page_description) ? $page_description : 'Freiwillige Feuerwehr Meinern-Mittelstendorf (OWMM) - Einsatzberichte, Übungen, Veranstaltungen und Informationen über unsere Feuerwehr.'; ?>">
    <meta name="keywords" content="Feuerwehr, Meinern, Mittelstendorf, OWMM, Einsätze, Rettung, Löschzug, Technische Hilfe">
    <meta name="author" content="Freiwillige Feuerwehr Meinern-Mittelstendorf">
    <meta name="robots" content="index, follow">
    <meta name="language" content="de">
    <meta name="revisit-after" content="7 days">
    
    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo SITE_URL . $_SERVER['REQUEST_URI']; ?>">
    <meta property="og:title" content="<?php echo isset($page_title) ? $page_title . ' | ' . SITE_NAME : SITE_NAME; ?>">
    <meta property="og:description" content="<?php echo isset($page_description) ? $page_description : 'Freiwillige Feuerwehr Meinern-Mittelstendorf - Einsatzberichte, Übungen und Events'; ?>">
    <meta property="og:locale" content="de_DE">
    <meta property="og:site_name" content="<?php echo SITE_NAME; ?>">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?php echo SITE_URL . strtok($_SERVER['REQUEST_URI'], '?'); ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- Structured Data for Search Engines -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "<?php echo SITE_NAME; ?>",
      "description": "Freiwillige Feuerwehr Meinern-Mittelstendorf",
      "url": "<?php echo SITE_URL; ?>",
      "logo": "<?php echo SITE_URL; ?>/favicon.svg",
      "contactPoint": {
        "@type": "ContactPoint",
        "email": "<?php echo ADMIN_EMAIL; ?>",
        "contactType": "customer service",
        "availableLanguage": ["German"]
      },
      "areaServed": {
        "@type": "City",
        "name": "Meinern, Mittelstendorf"
      }
    }
    </script>
</head>
<body>
    <header class="site-header">
        <div class="header-top">
            <div class="container">
                <div class="nav-brand">
                    <a href="index.php"><?php echo SITE_NAME; ?></a>
                </div>
                
                <button class="nav-toggle" id="navToggle">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
        <nav class="navbar">
            <div class="container">
                <ul class="nav-menu" id="navMenu">
                    <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">Start</a></li>
                    <li><a href="operations.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'operations.php' ? 'active' : ''; ?>">Einsätze</a></li>
                    <li><a href="events.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'events.php' ? 'active' : ''; ?>">Veranstaltungen</a></li>
                    <li><a href="trucks.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'trucks.php' ? 'active' : ''; ?>">Fahrzeuge</a></li>
                    <li><a href="board.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'board.php' ? 'active' : ''; ?>">Kommando</a></li>
                    <li><a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">Kontakt</a></li>
                    <li><a href="impressum.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'impressum.php' ? 'active' : ''; ?>">Impressum</a></li>
                    <li><a href="request_magiclink.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'request_magiclink.php' ? 'active' : ''; ?>">Anmelden</a></li>
                    <li><a href="register.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'register.php' ? 'active' : ''; ?>">Registrieren</a></li>
                    <li><a href="admin/login.php" class="admin-link" title="Admin-Bereich"><i class="fas fa-user-shield"></i></a></li>
                </ul>
            </div>
        </nav>
    </header>
    
    <main class="main-content">
