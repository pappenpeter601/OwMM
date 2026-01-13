<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
if (!is_logged_in() || !can_edit_cash()) {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
            case 'edit':
                $id = $_POST['id'] ?? null;
                $data = [
                    'name' => $_POST['name'],
                    'remark' => $_POST['remark'] ?? null,
                    'price' => str_replace(',', '.', $_POST['price'] ?? '0.00'),
                    'active' => isset($_POST['active']) ? 1 : 0
                ];
                
                try {
                    if ($id) {
                        // Update existing item
                        $sql = "UPDATE items SET 
                                name = :name,
                                remark = :remark,
                                price = :price,
                                active = :active
                                WHERE id = :id";
                        $data['id'] = $id;
                        $stmt = $db->prepare($sql);
                        $stmt->execute($data);
                        $message = 'Artikel erfolgreich aktualisiert.';
                    } else {
                        // Insert new item
                        $sql = "INSERT INTO items (name, remark, price, active)
                                VALUES (:name, :remark, :price, :active)";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($data);
                        $message = 'Artikel erfolgreich hinzugefügt.';
                    }
                } catch (PDOException $e) {
                    $error = 'Fehler beim Speichern: ' . $e->getMessage();
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                try {
                    $stmt = $db->prepare("DELETE FROM items WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $message = 'Artikel erfolgreich gelöscht.';
                } catch (PDOException $e) {
                    $error = 'Fehler beim Löschen: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get all items
$stmt = $db->prepare("SELECT id, name, remark, price, active, created_at FROM items ORDER BY name ASC");
$stmt->execute();
$items = $stmt->fetchAll();

// Get item to edit if ID is provided
$edit_item = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit']]);
    $edit_item = $stmt->fetch();
}

include 'includes/header.php';
?>

<div class="content-header">
    <h1>Artikel verwalten</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- Add/Edit Form -->
<div class="form-card">
    <h2><?= $edit_item ? 'Artikel bearbeiten' : 'Neuer Artikel' ?></h2>
    
    <form method="POST" class="form-grid">
        <input type="hidden" name="action" value="<?= $edit_item ? 'edit' : 'add' ?>">
        <?php if ($edit_item): ?>
            <input type="hidden" name="id" value="<?= $edit_item['id'] ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" required 
                   value="<?= htmlspecialchars($edit_item['name'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label for="price">Preis (€) *</label>
            <input type="number" id="price" name="price" step="0.01" min="0" required
                   value="<?= number_format($edit_item['price'] ?? 0, 2, ',', '') ?>">
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
            <label for="remark">Anmerkung</label>
            <textarea id="remark" name="remark" rows="4"><?= htmlspecialchars($edit_item['remark'] ?? '') ?></textarea>
        </div>
        
        <div class="form-group checkbox" style="grid-column: 1 / -1;">
            <label>
                <input type="checkbox" name="active" <?= ($edit_item && !$edit_item['active']) ? '' : 'checked' ?>>
                Aktiv
            </label>
        </div>
        
        <div class="form-actions" style="grid-column: 1 / -1;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> 
                <?= $edit_item ? 'Aktualisieren' : 'Hinzufügen' ?>
            </button>
            <?php if ($edit_item): ?>
                <a href="items.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Abbrechen
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Items List -->
<div class="table-responsive">
    <table class="data-table items-table">
        <thead>
            <tr>
                <th style="width: 30%;">Name</th>
                <th style="width: 12%;">Preis</th>
                <th style="width: 35%;">Anmerkung</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 13%;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= number_format($item['price'], 2, ',', '.') ?> €</td>
                    <td><?= htmlspecialchars(substr($item['remark'] ?? '', 0, 100)) ?></td>
                    <td style="text-align: center; white-space: nowrap;">
                        <span class="badge <?= $item['active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $item['active'] ? 'Aktiv' : 'Inaktiv' ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="items.php?edit=<?= $item['id'] ?>" class="btn btn-sm btn-secondary" title="Bearbeiten">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Wirklich löschen?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Löschen">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (empty($items)): ?>
    <div class="alert alert-info">
        Keine Artikel vorhanden. <a href="items.php">Erstelle einen neuen Artikel</a>.
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
