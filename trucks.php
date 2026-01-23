<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Fahrzeuge - ' . get_org_setting('site_name');

// Get all active trucks
$db = getDBConnection();
$stmt = $db->query("SELECT * FROM trucks WHERE is_active = 1 ORDER BY sort_order ASC, created_at ASC");
$trucks = $stmt->fetchAll();

include 'includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <h1>Unsere Fahrzeuge</h1>
        <p>Moderne Ausrüstung für effektiven Brandschutz</p>
    </div>
</section>

<section class="section trucks-section">
    <div class="container">
        <?php if (empty($trucks)): ?>
            <div class="empty-state">
                <i class="fas fa-truck" style="font-size: 4rem; color: #ccc; margin-bottom: 1rem;"></i>
                <p>Derzeit sind keine Fahrzeuge verfügbar.</p>
            </div>
        <?php else: ?>
            <div class="trucks-list">
                <?php foreach ($trucks as $index => $truck): ?>
                    <?php
                    // Get gallery images
                    $stmt = $db->prepare("SELECT * FROM gallery_images WHERE truck_id = :truck_id ORDER BY sort_order ASC, created_at ASC");
                    $stmt->execute(['truck_id' => $truck['id']]);
                    $gallery_images = $stmt->fetchAll();
                    
                    // Get specifications
                    $stmt = $db->prepare("SELECT * FROM truck_specifications WHERE truck_id = :truck_id ORDER BY sort_order ASC, created_at ASC");
                    $stmt->execute(['truck_id' => $truck['id']]);
                    $specifications = $stmt->fetchAll();
                    ?>
                    
                    <div class="truck-card" id="truck-<?php echo $truck['id']; ?>">
                        <div class="truck-header">
                            <?php if (!empty($truck['cover_image'])): ?>
                                <div class="truck-cover">
                                    <img src="uploads/<?php echo htmlspecialchars($truck['cover_image']); ?>" alt="<?php echo htmlspecialchars($truck['name']); ?>">
                                </div>
                            <?php endif; ?>
                            <div class="truck-info">
                                <h2><?php echo htmlspecialchars($truck['name']); ?></h2>
                                <?php if (!empty($truck['description'])): ?>
                                    <p class="truck-description"><?php echo nl2br(htmlspecialchars($truck['description'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($gallery_images)): ?>
                            <div class="truck-gallery">
                                <h3>Bildergalerie</h3>
                                <div class="gallery-carousel" data-truck="<?php echo $truck['id']; ?>">
                                    <div class="carousel-container">
                                        <button class="carousel-btn prev" onclick="moveTruckCarousel(<?php echo $truck['id']; ?>, -1)">
                                            <i class="fas fa-chevron-left"></i>
                                        </button>
                                        
                                        <div class="carousel-track-container">
                                            <div class="carousel-track">
                                                <?php foreach ($gallery_images as $imgIndex => $img): ?>
                                                    <div class="carousel-slide <?php echo $imgIndex === 0 ? 'active' : ''; ?>">
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
                                        
                                        <button class="carousel-btn next" onclick="moveTruckCarousel(<?php echo $truck['id']; ?>, 1)">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="carousel-dots">
                                        <?php foreach ($gallery_images as $imgIndex => $img): ?>
                                            <span class="dot <?php echo $imgIndex === 0 ? 'active' : ''; ?>" onclick="goToTruckSlide(<?php echo $truck['id']; ?>, <?php echo $imgIndex; ?>)"></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($specifications)): ?>
                            <div class="truck-specs">
                                <h3>Ausstattung & Spezifikationen</h3>
                                <div class="specs-grid">
                                    <?php foreach ($specifications as $spec): ?>
                                        <div class="spec-item">
                                            <?php if (!empty($spec['image_url'])): ?>
                                                <div class="spec-image">
                                                    <img src="uploads/<?php echo htmlspecialchars($spec['image_url']); ?>" alt="<?php echo htmlspecialchars($spec['name']); ?>">
                                                </div>
                                            <?php endif; ?>
                                            <div class="spec-content">
                                                <h4><?php echo htmlspecialchars($spec['name']); ?></h4>
                                                <?php if (!empty($spec['description'])): ?>
                                                    <p><?php echo nl2br(htmlspecialchars($spec['description'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.trucks-list {
    display: flex;
    flex-direction: column;
    gap: 3rem;
}

.truck-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.truck-header {
    display: grid;
    gap: 2rem;
}

.truck-cover {
    width: 100%;
    aspect-ratio: 16 / 9;
    overflow: hidden;
}

.truck-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.truck-info {
    padding: 2rem;
}

.truck-info h2 {
    color: var(--dark-color);
    margin-bottom: 1rem;
    font-size: 2rem;
}

.truck-description {
    color: #666;
    line-height: 1.6;
    font-size: 1.1rem;
}

.truck-gallery {
    padding: 2rem;
    border-top: 1px solid #e0e0e0;
}

.truck-gallery h3 {
    margin-bottom: 1.5rem;
    color: var(--dark-color);
}

.truck-specs {
    padding: 2rem;
    background: #f9f9f9;
    border-top: 1px solid #e0e0e0;
}

.truck-specs h3 {
    margin-bottom: 1.5rem;
    color: var(--dark-color);
}

.specs-grid {
    display: grid;
    gap: 1.5rem;
}

.spec-item {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

.spec-image {
    flex-shrink: 0;
    width: 100px;
    height: 100px;
    border-radius: 8px;
    overflow: hidden;
}

.spec-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.spec-content h4 {
    color: var(--dark-color);
    margin-bottom: 0.5rem;
}

.spec-content p {
    color: #666;
    line-height: 1.6;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #999;
}

/* Carousel styles for trucks */
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

/* Responsive design */
@media (max-width: 768px) {
    .trucks-list {
        gap: 2rem;
    }
    
    .truck-card {
        margin: 0 -10px;
        border-radius: 0;
    }
    
    .truck-info {
        padding: 1.5rem;
    }
    
    .truck-info h2 {
        font-size: 1.5rem;
    }
    
    .truck-description {
        font-size: 1rem;
    }
    
    .truck-gallery,
    .truck-specs {
        padding: 1.5rem;
    }
    
    .spec-item {
        flex-direction: column;
        padding: 1rem;
    }
    
    .spec-image {
        width: 100%;
        height: 200px;
    }
    
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
    
    .carousel-container {
        border-radius: 8px;
    }
}

@media (max-width: 480px) {
    .truck-info h2 {
        font-size: 1.25rem;
    }
    
    .truck-info {
        padding: 1rem;
    }
    
    .truck-gallery h3,
    .truck-specs h3 {
        font-size: 1.1rem;
    }
    
    .spec-content h4 {
        font-size: 1rem;
    }
    
    .carousel-btn {
        width: 35px;
        height: 35px;
        font-size: 0.9rem;
    }
    
    .carousel-caption {
        font-size: 0.85rem;
        padding: 1rem 0.75rem 0.5rem;
    }
    
    .carousel-dots {
        margin-top: 1rem;
    }
    
    .dot {
        width: 10px;
        height: 10px;
    }
}
</style>

<script>
const truckCarousels = {};

// Initialize carousel states for each truck
document.querySelectorAll('.gallery-carousel').forEach(carousel => {
    const truckId = carousel.getAttribute('data-truck');
    truckCarousels[truckId] = {
        currentSlide: 0,
        slides: carousel.querySelectorAll('.carousel-slide'),
        dots: carousel.querySelectorAll('.dot')
    };
});

function showTruckSlide(truckId, index) {
    const carousel = truckCarousels[truckId];
    if (!carousel) return;
    
    carousel.slides.forEach(slide => slide.classList.remove('active'));
    carousel.dots.forEach(dot => dot.classList.remove('active'));
    
    if (index >= carousel.slides.length) {
        carousel.currentSlide = 0;
    } else if (index < 0) {
        carousel.currentSlide = carousel.slides.length - 1;
    } else {
        carousel.currentSlide = index;
    }
    
    carousel.slides[carousel.currentSlide].classList.add('active');
    carousel.dots[carousel.currentSlide].classList.add('active');
}

function moveTruckCarousel(truckId, direction) {
    const carousel = truckCarousels[truckId];
    if (!carousel) return;
    showTruckSlide(truckId, carousel.currentSlide + direction);
}

function goToTruckSlide(truckId, index) {
    showTruckSlide(truckId, index);
}
</script>

<?php include 'includes/footer.php'; ?>
