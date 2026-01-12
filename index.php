<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = SITE_NAME . ' - Startseite';
$page_description = 'Freiwillige Feuerwehr - Wir sind für Sie da';

// Get page content
$hero_content = get_page_content('hero_welcome');
$about_content = get_page_content('about');

// Get latest operations
$latest_operations = get_operations(3);

// Get upcoming events
$upcoming_events = get_events('upcoming', 3);

include 'includes/header.php';
?>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <h1><?php echo htmlspecialchars($hero_content['title'] ?? 'Willkommen'); ?></h1>
        <p class="hero-subtitle"><?php echo htmlspecialchars($hero_content['content'] ?? ''); ?></p>
        <div class="hero-buttons">
            <a href="operations.php" class="btn btn-primary btn-lg">Unsere Einsätze</a>
            <a href="contact.php" class="btn btn-outline btn-lg">Kontakt</a>
        </div>
    </div>
    <?php if (!empty($hero_content['image_url'])): ?>
        <img src="uploads/<?php echo htmlspecialchars($hero_content['image_url']); ?>" alt="Hero" class="hero-image">
    <?php endif; ?>
</section>

<!-- About Section -->
<section class="section about-section">
    <div class="container">
        <div class="section-header">
            <h2><?php echo htmlspecialchars($about_content['title'] ?? 'Über uns'); ?></h2>
        </div>
        <div class="about-grid">
            <div class="about-content">
                <?php echo nl2br(htmlspecialchars($about_content['content'] ?? '')); ?>
            </div>
            <?php if (!empty($about_content['image_url'])): ?>
            <div class="about-image">
                <img src="uploads/<?php echo htmlspecialchars($about_content['image_url']); ?>" alt="Über uns">
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Latest Operations -->
<?php if (!empty($latest_operations)): ?>
<section class="section operations-section">
    <div class="container">
        <div class="section-header">
            <h2>Aktuelle Einsätze</h2>
            <a href="operations.php" class="btn btn-outline">Alle Einsätze</a>
        </div>
        
        <div class="operations-grid">
            <?php foreach ($latest_operations as $operation): ?>
                <div class="operation-card">
                    <?php
                    $images = get_operation_images($operation['id']);
                    if (!empty($images)):
                    ?>
                        <div class="card-image">
                            <img src="uploads/<?php echo htmlspecialchars($images[0]['image_url']); ?>" alt="<?php echo htmlspecialchars($operation['title']); ?>">
                        </div>
                    <?php endif; ?>
                    
                    <div class="card-content">
                        <div class="card-meta">
                            <span class="date"><i class="fas fa-calendar"></i> <?php echo format_datetime($operation['operation_date']); ?></span>
                            <?php if (!empty($operation['location'])): ?>
                                <span class="location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($operation['location']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <h3><?php echo htmlspecialchars($operation['title']); ?></h3>
                        
                        <?php if (!empty($operation['operation_type'])): ?>
                            <div class="operation-type">
                                <span class="badge"><?php echo htmlspecialchars($operation['operation_type']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($operation['description'])): ?>
                            <p><?php echo htmlspecialchars(mb_substr($operation['description'], 0, 150)); ?>...</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Upcoming Events -->
<?php if (!empty($upcoming_events)): ?>
<section class="section events-section bg-light">
    <div class="container">
        <div class="section-header">
            <h2>Anstehende Veranstaltungen</h2>
            <a href="events.php" class="btn btn-outline">Alle Veranstaltungen</a>
        </div>
        
        <div class="events-grid">
            <?php foreach ($upcoming_events as $event): ?>
                <div class="event-card">
                    <div class="event-date">
                        <span class="day"><?php echo date('d', strtotime($event['event_date'])); ?></span>
                        <span class="month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                    </div>
                    
                    <div class="event-content">
                        <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                        
                        <div class="event-meta">
                            <span><i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($event['event_date'])); ?> Uhr</span>
                            <?php if (!empty($event['location'])): ?>
                                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($event['description'])): ?>
                            <p><?php echo htmlspecialchars(mb_substr($event['description'], 0, 100)); ?>...</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Call to Action -->
<section class="section cta-section">
    <div class="container">
        <div class="cta-box">
            <h2>Haben Sie Fragen oder möchten Sie uns kontaktieren?</h2>
            <p>Wir freuen uns über Ihre Nachricht und helfen Ihnen gerne weiter.</p>
            <a href="contact.php" class="btn btn-primary btn-lg">Kontaktformular</a>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
