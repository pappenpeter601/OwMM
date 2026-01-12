<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Veranstaltungen - ' . SITE_NAME;

// Get upcoming and past events
$upcoming_events = get_events('upcoming');
$past_events = get_events('past', 6);

include 'includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <h1>Veranstaltungen</h1>
        <p>Unsere Events und Termine</p>
    </div>
</section>

<!-- Upcoming Events -->
<section class="section">
    <div class="container">
        <h2>Anstehende Veranstaltungen</h2>
        
        <?php if (empty($upcoming_events)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar fa-3x"></i>
                <h3>Keine anstehenden Veranstaltungen</h3>
                <p>Aktuell sind keine zukünftigen Events geplant.</p>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($upcoming_events as $event): ?>
                    <div class="event-card event-card-large">
                        <?php
                        $images = get_event_images($event['id']);
                        if (!empty($images)):
                        ?>
                            <div class="card-image">
                                <img src="uploads/<?php echo htmlspecialchars($images[0]['image_url']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
                            </div>
                        <?php endif; ?>
                        
                        <div class="event-date-badge">
                            <span class="day"><?php echo date('d', strtotime($event['event_date'])); ?></span>
                            <span class="month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                        </div>
                        
                        <div class="card-content">
                            <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                            
                            <div class="event-details">
                                <p><i class="fas fa-clock"></i> <?php echo format_datetime($event['event_date']); ?> Uhr</p>
                                <?php if (!empty($event['end_date'])): ?>
                                    <p><i class="fas fa-clock"></i> bis <?php echo format_datetime($event['end_date']); ?> Uhr</p>
                                <?php endif; ?>
                                <?php if (!empty($event['location'])): ?>
                                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($event['description'])): ?>
                                <div class="event-description">
                                    <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Past Events -->
<?php if (!empty($past_events)): ?>
<section class="section bg-light">
    <div class="container">
        <h2>Vergangene Veranstaltungen</h2>
        
        <div class="past-events-grid">
            <?php foreach ($past_events as $event): ?>
                <div class="past-event-card">
                    <?php
                    $images = get_event_images($event['id']);
                    ?>
                    
                    <div class="past-event-header">
                        <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                        <span class="event-date"><?php echo format_date($event['event_date']); ?></span>
                    </div>
                    
                    <?php if (!empty($event['description'])): ?>
                        <p><?php echo htmlspecialchars(mb_substr($event['description'], 0, 100)); ?>...</p>
                    <?php endif; ?>
                    
                    <?php if (!empty($images)): ?>
                        <div class="event-thumbnails">
                            <?php foreach (array_slice($images, 0, 4) as $image): ?>
                                <img src="uploads/<?php echo htmlspecialchars($image['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($event['title']); ?>"
                                     onclick="openEventGallery(<?php echo $event['id']; ?>)">
                            <?php endforeach; ?>
                            <?php if (count($images) > 4): ?>
                                <div class="more-images" onclick="openEventGallery(<?php echo $event['id']; ?>)">
                                    +<?php echo count($images) - 4; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
