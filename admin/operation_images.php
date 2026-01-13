<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_operations()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Get operation ID
$operation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$operation_id) {
    redirect('operations.php');
}

// Get operation
$stmt = $db->prepare("SELECT * FROM operations WHERE id = :id");
$stmt->execute(['id' => $operation_id]);
$operation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$operation) {
    redirect('operations.php');
}

// Handle image deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_image') {
        $image_id = $_POST['image_id'] ?? 0;
        
        $stmt = $db->prepare("SELECT image_url FROM operation_images WHERE id = :id AND operation_id = :operation_id");
        $stmt->execute(['id' => $image_id, 'operation_id' => $operation_id]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($image) {
            $file_path = '../' . $image['image_url'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            $stmt = $db->prepare("DELETE FROM operation_images WHERE id = :id");
            $stmt->execute(['id' => $image_id]);
            
            header('Location: operation_images.php?id=' . $operation_id . '&success=1');
            exit;
        }
    } elseif ($action === 'reorder') {
        $order = $_POST['order'] ?? [];
        
        try {
            foreach ($order as $sort_order => $image_id) {
                $stmt = $db->prepare("UPDATE operation_images SET sort_order = :sort_order WHERE id = :id AND operation_id = :operation_id");
                $stmt->execute([
                    'sort_order' => $sort_order,
                    'id' => $image_id,
                    'operation_id' => $operation_id
                ]);
            }
            header('Location: operation_images.php?id=' . $operation_id . '&success=1');
            exit;
        } catch (Exception $e) {
            $error = "Fehler beim Neuordnen der Bilder: " . $e->getMessage();
        }
    }
}

// Get operation images
$stmt = $db->prepare("SELECT * FROM operation_images WHERE operation_id = :operation_id ORDER BY sort_order ASC");
$stmt->execute(['operation_id' => $operation_id]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einsatz Bilder - <?php echo htmlspecialchars($operation['title']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }
        
        .image-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: move;
        }
        
        .image-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .image-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
        }
        
        .image-item .overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .image-item:hover .overlay {
            opacity: 1;
        }
        
        .overlay-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            background: #dc3545;
            color: white;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s ease;
        }
        
        .overlay-btn:hover {
            background: #c82333;
        }
        
        .image-item.dragging {
            opacity: 0.5;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 2rem;
            color: #007bff;
            text-decoration: none;
            font-size: 0.95rem;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 8px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<main class="admin-main">
    <div class="container">
        <a href="operations.php" class="back-link"><i class="fas fa-arrow-left"></i> Zurück zu Einsätze</a>
        
        <div class="page-header">
            <h1>Bilder verwalten</h1>
            <p><?php echo htmlspecialchars($operation['title']); ?></p>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Erfolgreich gespeichert!
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (count($images) > 0): ?>
            <div class="info-box">
                <p><i class="fas fa-info-circle"></i> Sie können die Bilder durch Ziehen neu ordnen. Die neue Reihenfolge wird automatisch gespeichert.</p>
            </div>
            
            <div class="images-grid" id="imagesGrid">
                <?php foreach ($images as $image): ?>
                    <div class="image-item" draggable="true" data-image-id="<?php echo $image['id']; ?>">
                        <img src="../<?php echo htmlspecialchars($image['image_url']); ?>" alt="Einsatz Bild">
                        <div class="overlay">
                            <button class="overlay-btn" onclick="deleteImage(<?php echo $image['id']; ?>)">
                                <i class="fas fa-trash-alt"></i> Löschen
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <form method="POST" action="" id="reorderForm" style="display: none;">
                <input type="hidden" name="action" value="reorder">
                <div id="orderInputs"></div>
            </form>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-image"></i>
                <h3>Keine Bilder vorhanden</h3>
                <p>Fügen Sie Bilder hinzu, wenn Sie diesen Einsatz erstellen oder bearbeiten.</p>
                <a href="operations.php?edit=<?php echo $operation_id; ?>" class="btn btn-primary" style="margin-top: 1rem;">
                    <i class="fas fa-edit"></i> Einsatz bearbeiten
                </a>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'includes/footer.php'; ?>

<script>
function deleteImage(imageId) {
    if (confirm('Sind Sie sicher, dass Sie dieses Bild löschen möchten?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="' + imageId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Drag and drop reordering
const grid = document.getElementById('imagesGrid');
if (grid) {
    let draggedElement = null;
    
    grid.addEventListener('dragstart', function(e) {
        if (e.target.classList.contains('image-item')) {
            draggedElement = e.target;
            e.target.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        }
    });
    
    grid.addEventListener('dragend', function(e) {
        if (draggedElement) {
            draggedElement.classList.remove('dragging');
            draggedElement = null;
            saveOrder();
        }
    });
    
    grid.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        if (draggedElement && e.target.classList.contains('image-item') && e.target !== draggedElement) {
            const allItems = [...grid.querySelectorAll('.image-item')];
            const draggedIndex = allItems.indexOf(draggedElement);
            const targetIndex = allItems.indexOf(e.target);
            
            if (draggedIndex < targetIndex) {
                e.target.parentNode.insertBefore(draggedElement, e.target.nextSibling);
            } else {
                e.target.parentNode.insertBefore(draggedElement, e.target);
            }
        }
    });
}

function saveOrder() {
    const form = document.getElementById('reorderForm');
    const orderInputs = document.getElementById('orderInputs');
    orderInputs.innerHTML = '';
    
    const items = document.querySelectorAll('.image-item');
    items.forEach((item, index) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'order[' + index + ']';
        input.value = item.dataset.imageId;
        orderInputs.appendChild(input);
    });
    
    form.submit();
}
</script>
</body>
</html>
