<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_operations()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $title = sanitize_input($_POST['title']);
        $description = $_POST['description'];
        $operation_date = $_POST['operation_date'];
        $location = sanitize_input($_POST['location']);
        $operation_type = sanitize_input($_POST['operation_type']);
        $published = isset($_POST['published']) ? 1 : 0;
        
        try {
            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO operations (title, description, operation_date, location, operation_type, published, created_by) 
                                       VALUES (:title, :description, :operation_date, :location, :operation_type, :published, :user_id)");
                $stmt->execute([
                    'title' => $title,
                    'description' => $description,
                    'operation_date' => $operation_date,
                    'location' => $location,
                    'operation_type' => $operation_type,
                    'published' => $published,
                    'user_id' => $_SESSION['user_id']
                ]);
                $operation_id = $db->lastInsertId();
                $success = "Einsatz erfolgreich hinzugefügt";
            } else {
                $operation_id = $id;
                $stmt = $db->prepare("UPDATE operations SET title = :title, description = :description, 
                                       operation_date = :operation_date, location = :location, 
                                       operation_type = :operation_type, published = :published 
                                       WHERE id = :id");
                $stmt->execute([
                    'title' => $title,
                    'description' => $description,
                    'operation_date' => $operation_date,
                    'location' => $location,
                    'operation_type' => $operation_type,
                    'published' => $published,
                    'id' => $id
                ]);
                $success = "Einsatz erfolgreich aktualisiert";
            }
            
            // Handle image uploads
            if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
                foreach ($_FILES['images']['name'] as $key => $name) {
                    if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['images']['name'][$key],
                            'type' => $_FILES['images']['type'][$key],
                            'tmp_name' => $_FILES['images']['tmp_name'][$key],
                            'error' => $_FILES['images']['error'][$key],
                            'size' => $_FILES['images']['size'][$key]
                        ];
                        $result = upload_image($file, 'operations');
                        if ($result['success']) {
                            $caption = sanitize_input($_POST['captions'][$key] ?? '');
                            $stmt = $db->prepare("INSERT INTO operation_images (operation_id, image_url, caption, sort_order) VALUES (:operation_id, :image_url, :caption, :sort_order)");
                            $stmt->execute([
                                'operation_id' => $operation_id,
                                'image_url' => $result['url'],
                                'caption' => $caption,
                                'sort_order' => $key
                            ]);
                        }
                    }
                }
            }
            
            header('Location: operations.php?success=1');
            exit;
        } catch (Exception $e) {
            $error = "Fehler beim Speichern: " . $e->getMessage();
            error_log("Operations error: " . $e->getMessage());
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        // Delete associated images first
        $stmt = $db->prepare("SELECT image_url FROM operation_images WHERE operation_id = :id");
        $stmt->execute(['id' => $id]);
        foreach ($stmt->fetchAll() as $img) {
            delete_image($img['image_url']);
        }
        
        $stmt = $db->prepare("DELETE FROM operations WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $success = "Einsatz erfolgreich gelöscht";
    } elseif ($action === 'upload_image') {
        $operation_id = $_POST['operation_id'];
        $caption = sanitize_input($_POST['caption'] ?? '');
        
        $result = upload_image($_FILES['image'], 'operations');
        if ($result['success']) {
            $stmt = $db->prepare("INSERT INTO operation_images (operation_id, image_url, caption) 
                                   VALUES (:operation_id, :image_url, :caption)");
            $stmt->execute([
                'operation_id' => $operation_id,
                'image_url' => $result['url'],
                'caption' => $caption
            ]);
            $success = "Bild erfolgreich hochgeladen";
        } else {
            $error = $result['error'];
        }
    }
}

// Get all operations
$operations = get_operations(null, 0, false);

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = "Einsatz erfolgreich aktualisiert";
}

$page_title = 'Einsätze verwalten';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Einsätze verwalten</h1>
    <button class="btn btn-primary" onclick="showAddModal()">+ Neuer Einsatz</button>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Datum</th>
                <th>Titel</th>
                <th>Ort</th>
                <th>Typ</th>
                <th>Status</th>
                <th>Bilder</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($operations as $op): ?>
                <tr>
                    <td><?php echo format_datetime($op['operation_date']); ?></td>
                    <td><?php echo htmlspecialchars($op['title']); ?></td>
                    <td><?php echo htmlspecialchars($op['location']); ?></td>
                    <td><?php echo htmlspecialchars($op['operation_type']); ?></td>
                    <td>
                        <span class="badge <?php echo $op['published'] ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $op['published'] ? 'Veröffentlicht' : 'Entwurf'; ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $images = get_operation_images($op['id']);
                        echo count($images);
                        ?>
                    </td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" onclick='editOperation(<?php echo json_encode($op); ?>)'>Bearbeiten</button>
                        <button class="btn btn-sm btn-info" onclick="manageImages(<?php echo $op['id']; ?>, '<?php echo htmlspecialchars($op['title']); ?>')">Bilder</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteOperation(<?php echo $op['id']; ?>)">Löschen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add/Edit Modal -->
<div id="operationModal" class="modal">
    <div class="modal-content" style="max-height: 90vh; overflow-y: auto;">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Einsatz hinzufügen</h2>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="operationId">
            
            <div class="form-group">
                <label for="title">Titel *</label>
                <input type="text" id="title" name="title" required>
            </div>
            
            <div class="form-group">
                <label for="operation_date">Datum und Uhrzeit *</label>
                <input type="datetime-local" id="operation_date" name="operation_date" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="location">Ort</label>
                    <input type="text" id="location" name="location">
                </div>
                
                <div class="form-group">
                    <label for="operation_type">Einsatzart</label>
                    <input type="text" id="operation_type" name="operation_type" placeholder="z.B. Brand, Technische Hilfe">
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Beschreibung</label>
                <textarea id="description" name="description" rows="5"></textarea>
            </div>
            
            <div class="form-group">
                <label for="images">Bilder hochladen</label>
                <input type="file" id="images" name="images[]" accept="image/*" multiple>
                <small>Sie können mehrere Bilder auswählen (JPG, PNG, max. 5MB pro Bild)</small>
                <div id="imagePreview" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; margin-top: 1rem;"></div>
            </div>
            
            <div class="form-group checkbox">
                <label>
                    <input type="checkbox" id="published" name="published">
                    Veröffentlichen
                </label>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('modalTitle').textContent = 'Einsatz hinzufügen';
    document.getElementById('formAction').value = 'add';
    document.getElementById('operationId').value = '';
    document.getElementById('title').value = '';
    document.getElementById('operation_date').value = '';
    document.getElementById('location').value = '';
    document.getElementById('operation_type').value = '';
    document.getElementById('description').value = '';
    document.getElementById('published').checked = false;
    document.getElementById('images').value = '';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('operationModal').style.display = 'block';
}

function editOperation(operation) {
    document.getElementById('modalTitle').textContent = 'Einsatz bearbeiten';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('operationId').value = operation.id;
    document.getElementById('title').value = operation.title;
    document.getElementById('operation_date').value = operation.operation_date.replace(' ', 'T');
    document.getElementById('location').value = operation.location || '';
    document.getElementById('operation_type').value = operation.operation_type || '';
    document.getElementById('description').value = operation.description || '';
    document.getElementById('published').checked = operation.published == 1;
    document.getElementById('images').value = '';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('operationModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('operationModal').style.display = 'none';
}

function deleteOperation(id) {
    if (confirm('Sind Sie sicher, dass Sie diesen Einsatz löschen möchten?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function manageImages(id, title) {
    window.location.href = 'operation_images.php?id=' + id;
}

// Image preview handler
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('images');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            const preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            
            Array.from(this.files).forEach((file, index) => {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        const div = document.createElement('div');
                        div.style.position = 'relative';
                        div.innerHTML = `
                            <img src="${event.target.result}" style="width: 100%; height: 150px; object-fit: cover; border-radius: 4px;">
                            <small style="display: block; margin-top: 0.5rem; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${file.name}</small>
                        `;
                        preview.appendChild(div);
                    };
                    reader.readAsDataURL(file);
                }
            });
        });
    }
});

window.onclick = function(event) {
    const modal = document.getElementById('operationModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
