<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = SITE_NAME . ' - Startseite';
$page_description = 'Freiwillige Feuerwehr - Wir sind für Sie da';

// Get page content
$hero_content = get_page_content('hero_welcome');
$about_content = get_page_content('about');

// Get gallery images
$db = getDBConnection();
$stmt = $db->query("SELECT * FROM gallery_images WHERE is_active = 1 ORDER BY sort_order ASC, created_at DESC");
$gallery_images = $stmt->fetchAll();

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

<!-- Gallery Carousel -->
<?php if (!empty($gallery_images)): ?>
<section class="section gallery-section">
    <div class="container">
        <div class="section-header">
            <h2>Bildergalerie</h2>
        </div>
        
        <div class="gallery-carousel">
            <div class="carousel-container">
                <button class="carousel-btn prev" onclick="moveCarousel(-1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                
                <div class="carousel-track-container">
                    <div class="carousel-track">
                        <?php foreach ($gallery_images as $index => $img): ?>
                            <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?>">
                                <img src="uploads/<?php echo htmlspecialchars($img['image_url']); ?>" alt="Gallery image">
                                <?php if (!empty($img['caption'])): ?>
                                    <div class="carousel-caption">
                                        <?php echo htmlspecialchars($img['caption']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button class="carousel-btn next" onclick="moveCarousel(1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            
            <div class="carousel-dots">
                <?php foreach ($gallery_images as $index => $img): ?>
                    <span class="dot <?php echo $index === 0 ? 'active' : ''; ?>" onclick="goToSlide(<?php echo $index; ?>)"></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<style>
.gallery-carousel {
    max-width: 1000px;
    margin: 0 auto;
}

.carousel-container {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #000;
    border-radius: 12px;
    overflow: hidden;
}

.carousel-track-container {
    width: 100%;
    height: 100%;
    position: relative;
    overflow: hidden;
}

.carousel-track {
    display: flex;
    height: 100%;
    transition: transform 0.5s ease-in-out;
}

.carousel-slide {
    min-width: 100%;
    height: 100%;
    position: relative;
    display: none;
}

.carousel-slide.active {
    display: block;
}

.carousel-slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.carousel-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
    color: white;
    padding: 2rem 1.5rem 1rem;
    font-size: 1.1rem;
    text-align: center;
}

.carousel-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255,255,255,0.9);
    border: none;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: var(--dark-color);
    transition: all 0.3s ease;
    z-index: 10;
}

.carousel-btn:hover {
    background: white;
    transform: translateY(-50%) scale(1.1);
}

.carousel-btn.prev {
    left: 1rem;
}

.carousel-btn.next {
    right: 1rem;
}

.carousel-dots {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 1.5rem;
}

.dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #ccc;
    cursor: pointer;
    transition: all 0.3s ease;
}

.dot.active {
    background: var(--primary-color);
    transform: scale(1.3);
}

.dot:hover {
    background: var(--primary-color);
    opacity: 0.7;
}

@media (max-width: 768px) {
    .carousel-btn {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .carousel-btn.prev {
        left: 0.5rem;
    }
    
    .carousel-btn.next {
        right: 0.5rem;
    }
    
    .carousel-caption {
        font-size: 0.95rem;
        padding: 1.5rem 1rem 0.75rem;
    }
}
</style>

<script>
let currentSlide = 0;
const slides = document.querySelectorAll('.carousel-slide');
const dots = document.querySelectorAll('.dot');

function showSlide(index) {
    slides.forEach(slide => slide.classList.remove('active'));
    dots.forEach(dot => dot.classList.remove('active'));
    
    if (index >= slides.length) {
        currentSlide = 0;
    } else if (index < 0) {
        currentSlide = slides.length - 1;
    } else {
        currentSlide = index;
    }
    
    slides[currentSlide].classList.add('active');
    dots[currentSlide].classList.add('active');
}

function moveCarousel(direction) {
    showSlide(currentSlide + direction);
}

function goToSlide(index) {
    showSlide(index);
}

// Auto-advance carousel every 5 seconds
setInterval(() => {
    moveCarousel(1);
}, 5000);
</script>
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

<?php include 'includes/footer.php'; ?>
