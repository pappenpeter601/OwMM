<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_page_content()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle gallery image upload
if (isset($_POST['action']) && $_POST['action'] === 'upload_gallery') {
    try {
        if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
            $uploaded = 0;
            foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['gallery_images']['size'][$key] > 0) {
                    // Create a single file array for upload_image function
                    $single_file = [
                        'name' => $_FILES['gallery_images']['name'][$key],
                        'type' => $_FILES['gallery_images']['type'][$key],
                        'tmp_name' => $tmp_name,
                        'error' => $_FILES['gallery_images']['error'][$key],
                        'size' => $_FILES['gallery_images']['size'][$key]
                    ];
                    
                    $result = upload_image($single_file, 'gallery');
                    if ($result['success']) {
                        $caption = $_POST['captions'][$key] ?? '';
                        $stmt = $db->prepare("INSERT INTO gallery_images (image_url, caption, uploaded_by, sort_order) 
                                              VALUES (:url, :caption, :user_id, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gallery_images g))");
                        $stmt->execute([
                            'url' => $result['url'],
                            'caption' => $caption,
                            'user_id' => $_SESSION['user_id']
                        ]);
                        $uploaded++;
                    }
                }
            }
            header('Location: content.php?success=1&uploaded=' . $uploaded);
            exit;
        }
    } catch (Exception $e) {
        $error = "Fehler beim Hochladen: " . $e->getMessage();
    }
}

// Handle gallery image deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_gallery') {
    try {
        $image_id = (int)$_POST['image_id'];
        $stmt = $db->prepare("SELECT image_url FROM gallery_images WHERE id = :id");
        $stmt->execute(['id' => $image_id]);
        $image = $stmt->fetch();
        
        if ($image) {
            delete_image($image['image_url']);
            $stmt = $db->prepare("DELETE FROM gallery_images WHERE id = :id");
            $stmt->execute(['id' => $image_id]);
        }
        
        header('Location: content.php?success=1');
        exit;
    } catch (Exception $e) {
        $error = "Fehler beim Löschen: " . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    try {
        $section_key = $_POST['section_key'] ?? '';
        $title = sanitize_input($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        
        if (empty($section_key)) {
            throw new Exception('Section key is required');
        }
        
        // Handle image upload
        $image_url = null;
        if (isset($_FILES['image']) && $_FILES['image']['size'] > 0) {
            $result = upload_image($_FILES['image'], 'content');
            if ($result['success']) {
                $image_url = $result['url'];
                
                // Delete old image if exists
                $stmt = $db->prepare("SELECT image_url FROM page_content WHERE section_key = :key");
                $stmt->execute(['key' => $section_key]);
                $old = $stmt->fetch();
                if ($old && $old['image_url']) {
                    delete_image($old['image_url']);
                }
            } else {
                throw new Exception('Image upload failed: ' . ($result['error'] ?? 'Unknown error'));
            }
        }
        
        // Check if content exists
        $stmt = $db->prepare("SELECT id FROM page_content WHERE section_key = :key");
        $stmt->execute(['key' => $section_key]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Update existing content
            if ($image_url) {
                $stmt = $db->prepare("UPDATE page_content SET title = :title, content = :content, image_url = :image, updated_by = :user_id WHERE section_key = :key");
                $stmt->execute([
                    'key' => $section_key,
                    'title' => $title,
                    'content' => $content,
                    'image' => $image_url,
                    'user_id' => $_SESSION['user_id']
                ]);
            } else {
                $stmt = $db->prepare("UPDATE page_content SET title = :title, content = :content, updated_by = :user_id WHERE section_key = :key");
                $stmt->execute([
                    'key' => $section_key,
                    'title' => $title,
                    'content' => $content,
                    'user_id' => $_SESSION['user_id']
                ]);
            }
        } else {
            // Insert new content
            if ($image_url) {
                $stmt = $db->prepare("INSERT INTO page_content (section_key, title, content, image_url, updated_by) VALUES (:key, :title, :content, :image, :user_id)");
                $stmt->execute([
                    'key' => $section_key,
                    'title' => $title,
                    'content' => $content,
                    'image' => $image_url,
                    'user_id' => $_SESSION['user_id']
                ]);
            } else {
                $stmt = $db->prepare("INSERT INTO page_content (section_key, title, content, updated_by) VALUES (:key, :title, :content, :user_id)");
                $stmt->execute([
                    'key' => $section_key,
                    'title' => $title,
                    'content' => $content,
                    'user_id' => $_SESSION['user_id']
                ]);
            }
        }
        
        $success = "Inhalt erfolgreich aktualisiert";
        
        // Redirect to prevent form resubmission
        header('Location: content.php?success=1');
        exit;
        
    } catch (Exception $e) {
        $error = "Fehler beim Speichern: " . $e->getMessage();
        error_log("Content save error: " . $e->getMessage());
    }
}

// Get all page content in logical order
$stmt = $db->query("SELECT * FROM page_content 
                    WHERE section_key IN ('hero_welcome', 'about')
                    ORDER BY FIELD(section_key, 'hero_welcome', 'about')");
$contents = $stmt->fetchAll();

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = "Inhalt erfolgreich aktualisiert";
}

$page_title = 'Seiteninhalte bearbeiten';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Seiteninhalte bearbeiten</h1>
</div>

<?php
// Define section descriptions
$section_descriptions = [
    'hero_welcome' => ['page' => 'Startseite', 'description' => 'Großes Titelbild mit Überschrift und Willkommenstext'],
    'about' => ['page' => 'Startseite', 'description' => 'Über uns Sektion mit Bild und Text']
];
?>

<div class="content-sections">
    <?php foreach ($contents as $content): ?>
        <div class="content-section">
            <div class="section-header">
                <?php if (isset($section_descriptions[$content['section_key']])): ?>
                    <div class="section-badge"><?php echo $section_descriptions[$content['section_key']]['page']; ?></div>
                <?php endif; ?>
                <h3><?php echo ucfirst(str_replace('_', ' ', $content['section_key'])); ?></h3>
                <?php if (isset($section_descriptions[$content['section_key']])): ?>
                    <p class="section-description"><?php echo $section_descriptions[$content['section_key']]['description']; ?></p>
                <?php endif; ?>
            </div>
            <form method="POST" enctype="multipart/form-data" class="content-form">
                <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($content['section_key']); ?>">
                
                <div class="form-group">
                    <label for="title_<?php echo $content['section_key']; ?>">Titel</label>
                    <input type="text" 
                           id="title_<?php echo $content['section_key']; ?>" 
                           name="title" 
                           value="<?php echo htmlspecialchars($content['title']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="content_<?php echo $content['section_key']; ?>">Inhalt</label>
                    <textarea id="content_<?php echo $content['section_key']; ?>" 
                              name="content" 
                              rows="6"><?php echo htmlspecialchars($content['content']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="image_<?php echo $content['section_key']; ?>">Bild</label>
                    <?php if (!empty($content['image_url'])): ?>
                        <div class="current-image">
                            <img src="../uploads/<?php echo htmlspecialchars($content['image_url']); ?>" 
                                 alt="Current image" 
                                 style="max-width: 200px; display: block; margin-bottom: 10px;">
                        </div>
                    <?php endif; ?>
                    <input type="file" 
                           id="image_<?php echo $content['section_key']; ?>" 
                           name="image" 
                           accept="image/*">
                    <small>Lassen Sie das Feld leer, um das aktuelle Bild beizubehalten</small>
                </div>
                
                <button type="submit" class="btn btn-primary">Speichern</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<!-- Gallery Section -->
<div class="gallery-section">
    <div class="section-header">
        <div class="section-badge">Startseite</div>
        <h3>Bildergalerie Karussell</h3>
        <p class="section-description">Verwalten Sie die Bilder für das Karussell auf der Startseite</p>
    </div>
    
    <?php
    $stmt = $db->query("SELECT * FROM gallery_images ORDER BY sort_order ASC, created_at DESC");
    $gallery_images = $stmt->fetchAll();
    ?>
    
    <?php if (!empty($gallery_images)): ?>
        <div class="gallery-grid">
            <?php foreach ($gallery_images as $img): ?>
                <div class="gallery-item">
                    <img src="../uploads/<?php echo htmlspecialchars($img['image_url']); ?>" alt="Gallery image">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_gallery">
                        <input type="hidden" name="image_id" value="<?php echo $img['id']; ?>">
                        <button type="submit" class="delete-btn" onclick="return confirm('Bild wirklich löschen?')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php if ($img['caption']): ?>
                        <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); color: white; padding: 8px; font-size: 0.85rem;">
                            <?php echo htmlspecialchars($img['caption']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color: #666; margin: 1rem 0;">Noch keine Bilder in der Galerie.</p>
    <?php endif; ?>
    
    <div class="gallery-upload">
        <h4>Neue Bilder hochladen</h4>
        <form method="POST" enctype="multipart/form-data" id="galleryForm">
            <input type="hidden" name="action" value="upload_gallery">
            <div class="form-group">
                <label for="gallery_images">Bilder auswählen (mehrere möglich)</label>
                <input type="file" id="gallery_images" name="gallery_images[]" accept="image/*" multiple required>
                <small>Sie können mehrere Bilder gleichzeitig auswählen</small>
            </div>
            <div id="captionFields"></div>
            <button type="submit" class="btn btn-primary">Bilder hochladen</button>
        </form>
    </div>
</div>

<script>
document.getElementById('gallery_images').addEventListener('change', function(e) {
    const captionFields = document.getElementById('captionFields');
    captionFields.innerHTML = '';
    
    if (this.files.length > 0) {
        captionFields.innerHTML = '<h5 style="margin-top: 1rem;">Optionale Bildunterschriften:</h5>';
        for (let i = 0; i < this.files.length; i++) {
            const div = document.createElement('div');
            div.className = 'form-group';
            div.innerHTML = `
                <label>${this.files[i].name}</label>
                <input type="text" name="captions[]" placeholder="Bildunterschrift (optional)" class="form-control">
            `;
            captionFields.appendChild(div);
        }
    }
});
</script>

<style>
.content-sections {
    display: grid;
    gap: 2rem;
}

.content-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.section-header {
    margin-bottom: 1.5rem;
}

.section-badge {
    display: inline-block;
    background: var(--primary-color);
    color: white;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.content-section h3 {
    margin: 0.5rem 0;
    color: var(--dark-color);
    text-transform: capitalize;
}

.section-description {
    margin: 0.5rem 0 0 0;
    color: #666;
    font-size: 0.95rem;
}

.content-form {
    max-width: 800px;
}

.gallery-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-top: 2rem;
}

.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.gallery-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid #e0e0e0;
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.gallery-item .delete-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(220, 53, 69, 0.9);
    color: white;
    border: none;
    border-radius: 4px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 0.85rem;
}

.gallery-item .delete-btn:hover {
    background: rgba(200, 35, 51, 1);
}

.gallery-upload {
    margin-top: 1rem;
    padding: 1.5rem;
    border: 2px dashed #ccc;
    border-radius: 8px;
    text-align: center;
}
</style>

<?php include 'includes/footer.php'; ?>
