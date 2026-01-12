<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_page_content()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section_key = $_POST['section_key'];
    $title = sanitize_input($_POST['title']);
    $content = $_POST['content'];
    
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
        }
    }
    
    // Update or insert
    if ($image_url) {
        $stmt = $db->prepare("INSERT INTO page_content (section_key, title, content, image_url, updated_by) 
                              VALUES (:key, :title, :content, :image, :user_id)
                              ON DUPLICATE KEY UPDATE 
                              title = :title, content = :content, image_url = :image, updated_by = :user_id");
        $stmt->execute([
            'key' => $section_key,
            'title' => $title,
            'content' => $content,
            'image' => $image_url,
            'user_id' => $_SESSION['user_id']
        ]);
    } else {
        $stmt = $db->prepare("INSERT INTO page_content (section_key, title, content, updated_by) 
                              VALUES (:key, :title, :content, :user_id)
                              ON DUPLICATE KEY UPDATE 
                              title = :title, content = :content, updated_by = :user_id");
        $stmt->execute([
            'key' => $section_key,
            'title' => $title,
            'content' => $content,
            'user_id' => $_SESSION['user_id']
        ]);
    }
    
    $success = "Inhalt erfolgreich aktualisiert";
}

// Get all page content
$stmt = $db->query("SELECT * FROM page_content ORDER BY section_key");
$contents = $stmt->fetchAll();

$page_title = 'Seiteninhalte bearbeiten';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Seiteninhalte bearbeiten</h1>
</div>

<div class="content-sections">
    <?php foreach ($contents as $content): ?>
        <div class="content-section">
            <h3><?php echo ucfirst(str_replace('_', ' ', $content['section_key'])); ?></h3>
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

.content-section h3 {
    margin-bottom: 1.5rem;
    color: var(--dark-color);
    text-transform: capitalize;
}

.content-form {
    max-width: 800px;
}
</style>

<?php include 'includes/footer.php'; ?>
