<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !has_permission('trucks.php')) {
    redirect('dashboard.php');
}

$db = getDBConnection();
$success = '';
$error = '';

// Handle truck deletion
if (isset($_POST['delete_truck'])) {
    $truck_id = (int)$_POST['truck_id'];
    
    try {
        // Get truck images to delete from filesystem
        $stmt = $db->prepare("SELECT cover_image FROM trucks WHERE id = :id");
        $stmt->execute(['id' => $truck_id]);
        $truck = $stmt->fetch();
        
        if ($truck && $truck['cover_image']) {
            delete_image($truck['cover_image']);
        }
        
        // Get gallery images
        $stmt = $db->prepare("SELECT image_url FROM gallery_images WHERE truck_id = :truck_id");
        $stmt->execute(['truck_id' => $truck_id]);
        $images = $stmt->fetchAll();
        foreach ($images as $img) {
            delete_image($img['image_url']);
        }
        
        // Get specification images
        $stmt = $db->prepare("SELECT image_url FROM truck_specifications WHERE truck_id = :truck_id AND image_url IS NOT NULL");
        $stmt->execute(['truck_id' => $truck_id]);
        $spec_images = $stmt->fetchAll();
        foreach ($spec_images as $img) {
            delete_image($img['image_url']);
        }
        
        // Delete truck (cascade will handle related records)
        $stmt = $db->prepare("DELETE FROM trucks WHERE id = :id");
        $stmt->execute(['id' => $truck_id]);
        
        $success = "Fahrzeug erfolgreich gelöscht";
    } catch (Exception $e) {
        $error = "Fehler beim Löschen: " . $e->getMessage();
    }
}

// Handle truck add/edit
if (isset($_POST['save_truck'])) {
    $truck_id = !empty($_POST['truck_id']) ? (int)$_POST['truck_id'] : null;
    $name = sanitize_input($_POST['name']);
    $description = $_POST['description'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        if (empty($name)) {
            throw new Exception('Name ist erforderlich');
        }
        
        // Handle cover image upload
        $cover_image = null;
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['size'] > 0) {
            $result = upload_image($_FILES['cover_image'], 'trucks');
            if ($result['success']) {
                $cover_image = $result['url'];
                
                // Delete old cover image if updating
                if ($truck_id) {
                    $stmt = $db->prepare("SELECT cover_image FROM trucks WHERE id = :id");
                    $stmt->execute(['id' => $truck_id]);
                    $old = $stmt->fetch();
                    if ($old && $old['cover_image']) {
                        delete_image($old['cover_image']);
                    }
                }
            }
        }
        
        if ($truck_id) {
            // Update existing truck
            if ($cover_image) {
                $stmt = $db->prepare("UPDATE trucks SET name = :name, description = :description, cover_image = :cover, is_active = :active, updated_at = NOW() WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'cover' => $cover_image,
                    'active' => $is_active,
                    'id' => $truck_id
                ]);
            } else {
                $stmt = $db->prepare("UPDATE trucks SET name = :name, description = :description, is_active = :active, updated_at = NOW() WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'active' => $is_active,
                    'id' => $truck_id
                ]);
            }
            $success = "Fahrzeug erfolgreich aktualisiert";
        } else {
            // Insert new truck
            $stmt = $db->prepare("INSERT INTO trucks (name, description, cover_image, is_active, created_by) VALUES (:name, :description, :cover, :active, :user_id)");
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'cover' => $cover_image,
                'active' => $is_active,
                'user_id' => $_SESSION['user_id']
            ]);
            $truck_id = $db->lastInsertId();
            $success = "Fahrzeug erfolgreich erstellt";
        }
        
        // Redirect to edit page
        header("Location: trucks.php?edit=" . $truck_id . "&success=1");
        exit;
        
    } catch (Exception $e) {
        $error = "Fehler beim Speichern: " . $e->getMessage();
    }
}

// Handle gallery image upload (use action param like content.php for reliability)
if ((isset($_POST['action']) && $_POST['action'] === 'upload_gallery_truck') || isset($_POST['upload_gallery'])) {
    error_log('[trucks.php] Upload handler entered. Truck ID: ' . (isset($_POST['truck_id']) ? (int)$_POST['truck_id'] : -1));
    $truck_id = (int)$_POST['truck_id'];
    
    try {
        if (!isset($_FILES['gallery_images'])) {
            $error = "Keine Dateien empfangen. Bitte prüfen Sie das Formular (enctype) und Serverlimits.";
            error_log('[trucks.php] No $_FILES received for gallery_images');
        } elseif (empty($_FILES['gallery_images']['name'][0])) {
            $error = "Es wurden keine Dateien ausgewählt.";
            error_log('[trucks.php] gallery_images empty name[0]');
        } else {
            $uploaded_count = 0;
            $failed_messages = [];
            foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
                $fileError = isset($_FILES['gallery_images']['error'][$key]) ? (int)$_FILES['gallery_images']['error'][$key] : 0;
                $fileSize = isset($_FILES['gallery_images']['size'][$key]) ? (int)$_FILES['gallery_images']['size'][$key] : 0;
                
                // Map common upload errors for clarity
                if ($fileError !== UPLOAD_ERR_OK) {
                    $msg = 'Unbekannter Fehler beim Upload.';
                    switch ($fileError) {
                        case UPLOAD_ERR_INI_SIZE: $msg = 'Datei überschreitet upload_max_filesize.'; break;
                        case UPLOAD_ERR_FORM_SIZE: $msg = 'Datei überschreitet MAX_FILE_SIZE im Formular.'; break;
                        case UPLOAD_ERR_PARTIAL: $msg = 'Datei wurde nur teilweise hochgeladen.'; break;
                        case UPLOAD_ERR_NO_FILE: $msg = 'Keine Datei hochgeladen.'; break;
                        case UPLOAD_ERR_NO_TMP_DIR: $msg = 'Kein temporäres Verzeichnis vorhanden.'; break;
                        case UPLOAD_ERR_CANT_WRITE: $msg = 'Fehler beim Schreiben der Datei auf die Festplatte.'; break;
                        case UPLOAD_ERR_EXTENSION: $msg = 'PHP-Erweiterung hat den Upload gestoppt.'; break;
                    }
                    $failed_messages[] = $_FILES['gallery_images']['name'][$key] . ': ' . $msg;
                    continue;
                }
                
                if ($fileSize <= 0) {
                    $failed_messages[] = $_FILES['gallery_images']['name'][$key] . ': Datei ist leer oder fehlerhaft.';
                    continue;
                }
                
                $single_file = [
                    'name' => $_FILES['gallery_images']['name'][$key],
                    'type' => $_FILES['gallery_images']['type'][$key],
                    'tmp_name' => $tmp_name,
                    'error' => $_FILES['gallery_images']['error'][$key],
                    'size' => $_FILES['gallery_images']['size'][$key]
                ];
                
                $result = upload_image($single_file, 'trucks');
                if ($result['success']) {
                    $caption = isset($_POST['captions'][$key]) ? $_POST['captions'][$key] : '';
                    
                    // Get current max sort order for this truck
                    $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) as max_order FROM gallery_images WHERE truck_id = :truck_id");
                    $stmt->execute(['truck_id' => $truck_id]);
                    $max_order = $stmt->fetch()['max_order'];
                    
                    // Insert the image
                    $stmt = $db->prepare("INSERT INTO gallery_images (truck_id, image_url, caption, uploaded_by, sort_order) 
                                          VALUES (:truck_id, :url, :caption, :user_id, :sort_order)");
                    $stmt->execute([
                        'truck_id' => $truck_id,
                        'url' => $result['url'],
                        'caption' => $caption,
                        'user_id' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                        'sort_order' => $max_order + 1
                    ]);
                    error_log('[trucks.php] Inserted gallery image for truck ' . $truck_id . ' URL=' . $result['url']);
                    $uploaded_count++;
                } else {
                    $failed_messages[] = $_FILES['gallery_images']['name'][$key] . ': ' . ($result['error'] ?? 'Upload fehlgeschlagen');
                    error_log('[trucks.php] Upload failed for ' . $_FILES['gallery_images']['name'][$key] . ' reason=' . ($result['error'] ?? 'unknown'));
                }
            }
            if ($uploaded_count > 0) {
                header("Location: trucks.php?edit=" . $truck_id . "&success=1");
                exit;
            }
            if (!empty($failed_messages)) {
                $error = "Fehler beim Hochladen: \n" . implode("\n", $failed_messages);
            } else {
                $error = "Keine Bilder konnten hochgeladen werden. Bitte überprüfen Sie die Dateien.";
            }
        }
    } catch (Exception $e) {
        $error = "Fehler beim Hochladen: " . $e->getMessage();
    }
}

// Handle gallery image deletion
if (isset($_POST['delete_gallery_image'])) {
    $image_id = (int)$_POST['image_id'];
    $truck_id = (int)$_POST['truck_id'];
    
    try {
        $stmt = $db->prepare("SELECT image_url FROM gallery_images WHERE id = :id");
        $stmt->execute(['id' => $image_id]);
        $image = $stmt->fetch();
        
        if ($image) {
            delete_image($image['image_url']);
            $stmt = $db->prepare("DELETE FROM gallery_images WHERE id = :id");
            $stmt->execute(['id' => $image_id]);
        }
        
        header("Location: trucks.php?edit=" . $truck_id . "&success=1");
        exit;
    } catch (Exception $e) {
        $error = "Fehler beim Löschen: " . $e->getMessage();
    }
}

// Handle specification add/edit
if (isset($_POST['save_specification'])) {
    $spec_id = !empty($_POST['spec_id']) ? (int)$_POST['spec_id'] : null;
    $truck_id = (int)$_POST['truck_id'];
    $name = sanitize_input($_POST['spec_name']);
    $description = $_POST['spec_description'];
    
    try {
        if (empty($name)) {
            throw new Exception('Name ist erforderlich');
        }
        
        // Handle spec image upload
        $spec_image = null;
        if (isset($_FILES['spec_image']) && $_FILES['spec_image']['size'] > 0) {
            $result = upload_image($_FILES['spec_image'], 'trucks');
            if ($result['success']) {
                $spec_image = $result['url'];
                
                // Delete old image if updating
                if ($spec_id) {
                    $stmt = $db->prepare("SELECT image_url FROM truck_specifications WHERE id = :id");
                    $stmt->execute(['id' => $spec_id]);
                    $old = $stmt->fetch();
                    if ($old && $old['image_url']) {
                        delete_image($old['image_url']);
                    }
                }
            }
        }
        
        if ($spec_id) {
            // Update
            if ($spec_image) {
                $stmt = $db->prepare("UPDATE truck_specifications SET name = :name, description = :description, image_url = :image WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'image' => $spec_image,
                    'id' => $spec_id
                ]);
            } else {
                $stmt = $db->prepare("UPDATE truck_specifications SET name = :name, description = :description WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'id' => $spec_id
                ]);
            }
        } else {
            // Insert - get max sort order first
            $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) as max_order FROM truck_specifications WHERE truck_id = :truck_id");
            $stmt->execute(['truck_id' => $truck_id]);
            $max_order = $stmt->fetch()['max_order'];
            
            $stmt = $db->prepare("INSERT INTO truck_specifications (truck_id, name, description, image_url, sort_order) 
                                  VALUES (:truck_id, :name, :description, :image, :sort_order)");
            $stmt->execute([
                'truck_id' => $truck_id,
                'name' => $name,
                'description' => $description,
                'image' => $spec_image,
                'sort_order' => $max_order + 1
            ]);
        }
        
        header("Location: trucks.php?edit=" . $truck_id . "&success=1#specs");
        exit;
        
    } catch (Exception $e) {
        $error = "Fehler beim Speichern: " . $e->getMessage();
    }
}

// Handle specification deletion
if (isset($_POST['delete_specification'])) {
    $spec_id = (int)$_POST['spec_id'];
    $truck_id = (int)$_POST['truck_id'];
    
    try {
        $stmt = $db->prepare("SELECT image_url FROM truck_specifications WHERE id = :id");
        $stmt->execute(['id' => $spec_id]);
        $spec = $stmt->fetch();
        
        if ($spec && $spec['image_url']) {
            delete_image($spec['image_url']);
        }
        
        $stmt = $db->prepare("DELETE FROM truck_specifications WHERE id = :id");
        $stmt->execute(['id' => $spec_id]);
        
        header("Location: trucks.php?edit=" . $truck_id . "&success=1#specs");
        exit;
    } catch (Exception $e) {
        $error = "Fehler beim Löschen: " . $e->getMessage();
    }
}

// Get all trucks
$stmt = $db->query("SELECT * FROM trucks ORDER BY sort_order ASC, created_at ASC");
$trucks = $stmt->fetchAll();

// Check if editing
$editing_truck = null;
$truck_gallery = [];
$truck_specifications = [];
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM trucks WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $editing_truck = $stmt->fetch();
    
    if ($editing_truck) {
        // Get gallery images
        $stmt = $db->prepare("SELECT * FROM gallery_images WHERE truck_id = :truck_id ORDER BY sort_order ASC");
        $stmt->execute(['truck_id' => $edit_id]);
        $truck_gallery = $stmt->fetchAll();
        
        // Get specifications
        $stmt = $db->prepare("SELECT * FROM truck_specifications WHERE truck_id = :truck_id ORDER BY sort_order ASC");
        $stmt->execute(['truck_id' => $edit_id]);
        $truck_specifications = $stmt->fetchAll();
    }
}

if (isset($_GET['success'])) {
    $success = $editing_truck ? "Änderungen erfolgreich gespeichert" : "Fahrzeug erfolgreich erstellt";
}

$page_title = 'Fahrzeuge verwalten';
include 'includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Fahrzeuge verwalten</h1>
    <a href="trucks.php" class="btn btn-outline">Zurück zur Übersicht</a>
</div>

<?php if ($editing_truck): ?>
    <!-- Edit Truck Form -->
    <div class="truck-edit-container">
        <div class="section-card">
            <h2>Fahrzeug bearbeiten</h2>
            <form method="POST" enctype="multipart/form-data" class="truck-form">
                <input type="hidden" name="truck_id" value="<?php echo $editing_truck['id']; ?>">
                
                <div class="form-group">
                    <label for="name">Fahrzeugname *</label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($editing_truck['name']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="description">Beschreibung</label>
                    <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($editing_truck['description']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="cover_image">Titelbild</label>
                    <?php if (!empty($editing_truck['cover_image'])): ?>
                        <div class="current-image">
                            <img src="../uploads/<?php echo htmlspecialchars($editing_truck['cover_image']); ?>" alt="Current cover">
                        </div>
                    <?php endif; ?>
                    <input type="file" id="cover_image" name="cover_image" accept="image/*">
                    <small>Lassen Sie das Feld leer, um das aktuelle Bild beizubehalten</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" <?php echo $editing_truck['is_active'] ? 'checked' : ''; ?>>
                        Fahrzeug aktiv (auf Website anzeigen)
                    </label>
                </div>
                
                <button type="submit" name="save_truck" class="btn btn-primary">Fahrzeug speichern</button>
            </form>
        </div>
        
        <!-- Gallery Section -->
        <div class="section-card">
            <h2>Bildergalerie</h2>
            
            <?php if (!empty($truck_gallery)): ?>
                <div class="gallery-grid">
                    <?php foreach ($truck_gallery as $img): ?>
                        <div class="gallery-item">
                            <img src="../uploads/<?php echo htmlspecialchars($img['image_url']); ?>" alt="Gallery image">
                            <form method="POST" class="delete-form">
                                <input type="hidden" name="image_id" value="<?php echo $img['id']; ?>">
                                <input type="hidden" name="truck_id" value="<?php echo $editing_truck['id']; ?>">
                                <button type="submit" name="delete_gallery_image" class="delete-btn" onclick="return confirm('Bild wirklich löschen?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php if ($img['caption']): ?>
                                <div class="image-caption"><?php echo htmlspecialchars($img['caption']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="upload-section">
                <h3>Neue Bilder hochladen</h3>
                <form method="POST" enctype="multipart/form-data" id="galleryForm">
                    <input type="hidden" name="action" value="upload_gallery_truck">
                    <input type="hidden" name="truck_id" value="<?php echo $editing_truck['id']; ?>">
                    <div class="form-group">
                        <label for="gallery_images">Bilder auswählen (mehrere möglich)</label>
                        <input type="file" id="gallery_images" name="gallery_images[]" accept="image/*" multiple required>
                        <small>Sie können mehrere Bilder gleichzeitig auswählen</small>
                    </div>
                    <div id="captionFields"></div>
                    <button type="submit" name="upload_gallery" class="btn btn-primary">Bilder hochladen</button>
                </form>
            </div>
        </div>
        
        <script>
        // File preview for gallery uploads
        document.getElementById('gallery_images').addEventListener('change', function(e) {
            const captionFields = document.getElementById('captionFields');
            captionFields.innerHTML = '';
            
            if (this.files.length > 0) {
                captionFields.innerHTML = '<h5 style="margin-top: 1rem;">Gewählte Bilder (' + this.files.length + '):</h5>';
                
                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i];
                    const div = document.createElement('div');
                    div.className = 'form-group';
                    div.style.display = 'flex';
                    div.style.alignItems = 'center';
                    div.style.gap = '1rem';
                    
                    // Create image preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.style.width = '80px';
                        img.style.height = '60px';
                        img.style.objectFit = 'cover';
                        img.style.borderRadius = '4px';
                        div.insertBefore(img, div.firstChild);
                    };
                    reader.readAsDataURL(file);
                    
                    div.innerHTML += `
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 0.25rem;">${file.name}</label>
                            <input type="text" name="captions[]" placeholder="Bildunterschrift (optional)" class="form-control" style="margin-top: 0.25rem;">
                        </div>
                    `;
                    captionFields.appendChild(div);
                }
            }
        });
        
        // Show loading indicator on form submit
        document.getElementById('galleryForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';
        });
        
        // Auto-scroll to gallery on success
        if (window.location.search.includes('success=1')) {
            setTimeout(function() {
                const gallerySection = document.querySelector('.gallery-section');
                if (gallerySection) {
                    gallerySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        }
        </script>
        
        <!-- Specifications Section -->
        <div class="section-card" id="specs">
            <h2>Ausstattung & Spezifikationen</h2>
            
            <?php if (!empty($truck_specifications)): ?>
                <div class="specs-list">
                    <?php foreach ($truck_specifications as $spec): ?>
                        <div class="spec-item-card">
                            <div class="spec-header">
                                <h4><?php echo htmlspecialchars($spec['name']); ?></h4>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="spec_id" value="<?php echo $spec['id']; ?>">
                                    <input type="hidden" name="truck_id" value="<?php echo $editing_truck['id']; ?>">
                                    <button type="submit" name="delete_specification" class="btn btn-danger btn-sm" onclick="return confirm('Spezifikation wirklich löschen?')">
                                        <i class="fas fa-trash"></i> Löschen
                                    </button>
                                </form>
                            </div>
                            <?php if ($spec['image_url']): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($spec['image_url']); ?>" alt="<?php echo htmlspecialchars($spec['name']); ?>" class="spec-img">
                            <?php endif; ?>
                            <p><?php echo nl2br(htmlspecialchars($spec['description'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="upload-section">
                <h3>Neue Spezifikation hinzufügen</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="truck_id" value="<?php echo $editing_truck['id']; ?>">
                    
                    <div class="form-group">
                        <label for="spec_name">Name *</label>
                        <input type="text" id="spec_name" name="spec_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="spec_description">Beschreibung</label>
                        <textarea id="spec_description" name="spec_description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="spec_image">Bild (optional)</label>
                        <input type="file" id="spec_image" name="spec_image" accept="image/*">
                    </div>
                    
                    <button type="submit" name="save_specification" class="btn btn-primary">Spezifikation hinzufügen</button>
                </form>
            </div>
        </div>
    </div>
    
<?php else: ?>
    <!-- Trucks List -->
    <div class="trucks-admin-container">
        <div class="section-card">
            <h2>Neues Fahrzeug hinzufügen</h2>
            <form method="POST" enctype="multipart/form-data" class="truck-form">
                <div class="form-group">
                    <label for="name">Fahrzeugname *</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Beschreibung</label>
                    <textarea id="description" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="cover_image">Titelbild</label>
                    <input type="file" id="cover_image" name="cover_image" accept="image/*">
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" checked>
                        Fahrzeug aktiv (auf Website anzeigen)
                    </label>
                </div>
                
                <button type="submit" name="save_truck" class="btn btn-primary">Fahrzeug erstellen</button>
            </form>
        </div>
        
        <div class="section-card">
            <h2>Vorhandene Fahrzeuge</h2>
            <?php if (empty($trucks)): ?>
                <p>Noch keine Fahrzeuge vorhanden.</p>
            <?php else: ?>
                <div class="trucks-table">
                    <?php foreach ($trucks as $truck): ?>
                        <div class="truck-row">
                            <?php if ($truck['cover_image']): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($truck['cover_image']); ?>" alt="<?php echo htmlspecialchars($truck['name']); ?>" class="truck-thumb">
                            <?php else: ?>
                                <div class="truck-thumb no-image"><i class="fas fa-truck"></i></div>
                            <?php endif; ?>
                            
                            <div class="truck-details">
                                <h3><?php echo htmlspecialchars($truck['name']); ?></h3>
                                <span class="status-badge <?php echo $truck['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $truck['is_active'] ? 'Aktiv' : 'Inaktiv'; ?>
                                </span>
                            </div>
                            
                            <div class="truck-actions">
                                <a href="trucks.php?edit=<?php echo $truck['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Bearbeiten
                                </a>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="truck_id" value="<?php echo $truck['id']; ?>">
                                    <button type="submit" name="delete_truck" class="btn btn-danger btn-sm" onclick="return confirm('Fahrzeug wirklich löschen? Alle zugehörigen Bilder und Spezifikationen werden ebenfalls gelöscht.')">
                                        <i class="fas fa-trash"></i> Löschen
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<style>
.truck-edit-container,
.trucks-admin-container {
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

.section-card {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.section-card h2 {
    margin-bottom: 1.5rem;
    color: var(--dark-color);
}

.section-card h3 {
    margin-top: 2rem;
    margin-bottom: 1rem;
    color: var(--dark-color);
}

.truck-form {
    max-width: 800px;
}

.current-image {
    margin-bottom: 1rem;
}

.current-image img {
    max-width: 300px;
    border-radius: 8px;
}

.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
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

.delete-form {
    position: absolute;
    top: 8px;
    right: 8px;
}

.delete-btn {
    background: rgba(220, 53, 69, 0.9);
    color: white;
    border: none;
    border-radius: 4px;
    padding: 6px 10px;
    cursor: pointer;
}

.delete-btn:hover {
    background: rgba(200, 35, 51, 1);
}

.image-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 8px;
    font-size: 0.85rem;
}

.upload-section {
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid #e0e0e0;
}

.specs-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-bottom: 2rem;
}

.spec-item-card {
    background: #f9f9f9;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.spec-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.spec-header h4 {
    margin: 0;
    color: var(--dark-color);
}

.spec-img {
    max-width: 200px;
    border-radius: 8px;
    margin: 1rem 0;
}

.trucks-table {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.truck-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f9f9f9;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.truck-thumb {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    flex-shrink: 0;
}

.truck-thumb.no-image {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e0e0e0;
    color: #999;
    font-size: 2rem;
}

.truck-details {
    flex: 1;
}

.truck-details h3 {
    margin: 0 0 0.5rem 0;
    color: var(--dark-color);
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.truck-actions {
    display: flex;
    gap: 0.5rem;
}

@media (max-width: 768px) {
    .truck-row {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .truck-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .truck-actions .btn {
        width: 100%;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
