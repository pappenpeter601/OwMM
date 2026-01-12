<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Einsätze - ' . SITE_NAME;

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * OPERATIONS_PER_PAGE;

// Get total count
$db = getDBConnection();
$stmt = $db->query("SELECT COUNT(*) as total FROM operations WHERE published = 1");
$total = $stmt->fetch()['total'];
$total_pages = ceil($total / OPERATIONS_PER_PAGE);

// Get operations
$operations = get_operations(OPERATIONS_PER_PAGE, $offset);

include 'includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <h1>Unsere Einsätze</h1>
        <p>Chronologische Übersicht unserer Einsätze</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (empty($operations)): ?>
            <div class="empty-state">
                <i class="fas fa-fire fa-3x"></i>
                <h3>Keine Einsätze vorhanden</h3>
                <p>Aktuell sind keine Einsätze dokumentiert.</p>
            </div>
        <?php else: ?>
            <div class="operations-list">
                <?php foreach ($operations as $operation): ?>
                    <article class="operation-item">
                        <div class="operation-header">
                            <h2><?php echo htmlspecialchars($operation['title']); ?></h2>
                            <div class="operation-meta">
                                <span class="date"><i class="fas fa-calendar"></i> <?php echo format_datetime($operation['operation_date']); ?></span>
                                <?php if (!empty($operation['location'])): ?>
                                    <span class="location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($operation['location']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($operation['operation_type'])): ?>
                                    <span class="badge"><?php echo htmlspecialchars($operation['operation_type']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($operation['description'])): ?>
                            <div class="operation-description">
                                <?php echo nl2br(htmlspecialchars($operation['description'])); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php
                        $images = get_operation_images($operation['id']);
                        if (!empty($images)):
                        ?>
                            <div class="operation-gallery">
                                <?php foreach ($images as $image): ?>
                                    <div class="gallery-item">
                                        <img src="uploads/<?php echo htmlspecialchars($image['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($image['caption'] ?? 'Einsatzbild'); ?>"
                                             onclick="openLightbox('<?php echo htmlspecialchars($image['image_url']); ?>')">
                                        <?php if (!empty($image['caption'])): ?>
                                            <p class="image-caption"><?php echo htmlspecialchars($image['caption']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="btn btn-outline">← Zurück</a>
                    <?php endif; ?>
                    
                    <span class="page-info">Seite <?php echo $page; ?> von <?php echo $total_pages; ?></span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="btn btn-outline">Weiter →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Lightbox for images -->
<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <span class="close">&times;</span>
    <img id="lightbox-img" src="" alt="">
</div>

<script>
function openLightbox(imageUrl) {
    document.getElementById('lightbox').style.display = 'flex';
    document.getElementById('lightbox-img').src = 'uploads/' + imageUrl;
}

function closeLightbox() {
    document.getElementById('lightbox').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
