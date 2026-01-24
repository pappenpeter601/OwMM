<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check permissions
if (!is_logged_in() || !has_permission('board.php')) {
    redirect('dashboard.php');
}

$db = getDBConnection();
$message = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';

// Clear session messages after reading
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $member_id = $_POST['member_id'] ?? null;
        $position = !empty($_POST['position']) ? $_POST['position'] : null;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_board_member = isset($_POST['is_board_member']) ? 1 : 0;
        
        // Handle image upload
        $image_url = null;
        if (isset($_FILES['image']) && $_FILES['image']['size'] > 0) {
            $result = upload_image($_FILES['image'], 'board');
            if ($result['success']) {
                $image_url = $result['url'];
            }
        }
        
        if ($action === 'add' && $member_id) {
            // Check if member is already on board
            $stmt = $db->prepare("SELECT id FROM members WHERE id = :id AND is_board_member = 1");
            $stmt->execute(['id' => $member_id]);
            if ($stmt->fetch()) {
                $_SESSION['error_message'] = 'Dieses Mitglied ist bereits im Kommando.';
            } else {
                $stmt = $db->prepare("UPDATE members SET 
                                     is_board_member = 1,
                                     board_position = :position,
                                     board_sort_order = :sort_order
                                     WHERE id = :member_id AND member_type = 'active'");
                $stmt->execute([
                    'member_id' => $member_id,
                    'position' => $position,
                    'sort_order' => $sort_order
                ]);
                $_SESSION['success_message'] = 'Mitglied erfolgreich zum Kommando hinzugefügt.';
                header('Location: board.php');
                exit;
            }
        } elseif ($action === 'edit' && $id) {
            if ($image_url) {
                $stmt = $db->prepare("UPDATE members SET 
                                     board_position = :position,
                                     board_image_url = :image_url,
                                     board_sort_order = :sort_order,
                                     is_board_member = :is_board_member
                                     WHERE id = :id");
            } else {
                $stmt = $db->prepare("UPDATE members SET 
                                     board_position = :position,
                                     board_sort_order = :sort_order,
                                     is_board_member = :is_board_member
                                     WHERE id = :id");
            }
            
            $params = [
                'id' => $id,
                'position' => $position,
                'sort_order' => $sort_order,
                'is_board_member' => $is_board_member
            ];
            
            if ($image_url) {
                $params['image_url'] = $image_url;
            }
            
            $stmt->execute($params);
            $_SESSION['success_message'] = 'Kommandomitglied erfolgreich aktualisiert.';
            header('Location: board.php');
            exit;
        }
    } elseif ($action === 'remove') {
        $id = $_POST['id'];
        $stmt = $db->prepare("UPDATE members SET is_board_member = 0, board_position = NULL, board_image_url = NULL WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $_SESSION['success_message'] = 'Mitglied erfolgreich aus dem Kommando entfernt.';
        header('Location: board.php');
        exit;
    }
}

// Get all active members who are on the board
$stmt = $db->prepare("SELECT id, first_name, last_name, email, board_position, board_image_url, board_sort_order, is_board_member, active
                      FROM members 
                      WHERE member_type = 'active' AND is_board_member = 1
                      ORDER BY board_sort_order ASC, last_name ASC, first_name ASC");
$stmt->execute();
$board_members = $stmt->fetchAll();

// Get all active members not on the board for selection
$stmt = $db->prepare("SELECT id, first_name, last_name, email, member_number
                      FROM members 
                      WHERE member_type = 'active' AND is_board_member = 0 AND active = 1
                      ORDER BY last_name ASC, first_name ASC");
$stmt->execute();
$available_members = $stmt->fetchAll();

$page_title = 'Kommando verwalten';
include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="content-header">
    <h1>Kommando verwalten</h1>
    <button class="btn btn-primary" onclick="showAddModal()">
        <i class="fas fa-plus"></i> Mitglied hinzufügen
    </button>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Reihenfolge</th>
                <th>Foto</th>
                <th>Name</th>
                <th>Position</th>
                <th>Aktiv</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($board_members)): ?>
                <tr>
                    <td colspan="6" style="text-align: center;">Keine Kommandomitglieder vorhanden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($board_members as $member): ?>
                    <tr>
                        <td><?= $member['board_sort_order'] ?></td>
                        <td>
                            <?php if ($member['board_image_url']): ?>
                                <img src="<?= htmlspecialchars($member['board_image_url']) ?>" 
                                     alt="<?= htmlspecialchars($member['first_name']) ?>" 
                                     style="width: 50px; height: 50px; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fas fa-user" style="font-size: 2rem; color: #999;"></i>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></td>
                        <td><?= htmlspecialchars($member['board_position'] ?? '-') ?></td>
                        <td>
                            <span class="badge <?= $member['active'] ? 'badge-success' : 'badge-warning' ?>">
                                <?= $member['active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td class="action-buttons">
                            <button class="btn btn-sm btn-secondary" onclick="editMember(<?= htmlspecialchars(json_encode($member)) ?>)" title="Bearbeiten">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="removeMember(<?= $member['id'] ?>)" title="Entfernen">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Member to Board Modal -->
<div id="addMemberModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Mitglied zum Kommando hinzufügen</h2>
            <span class="close" onclick="closeAddModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label>Mitglied: *</label>
                <select name="member_id" required>
                    <option value="">- Bitte wählen -</option>
                    <?php foreach ($available_members as $member): ?>
                        <option value="<?= $member['id'] ?>">
                            <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                            <?php if ($member['member_number']): ?>
                                (<?= htmlspecialchars($member['member_number']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Position:</label>
                <input type="text" name="position" placeholder="z.B. 1. Vorsitzender">
            </div>
            
            <div class="form-group">
                <label>Reihenfolge:</label>
                <input type="number" name="sort_order" value="0" min="0">
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Member Modal -->
<div id="editMemberModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Kommandomitglied bearbeiten</h2>
            <span class="close" onclick="closeEditModal()">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="memberId">
            
            <div class="form-group">
                <label>Name:</label>
                <input type="text" id="memberName" readonly style="background-color: #f5f5f5;">
            </div>
            
            <div class="form-group">
                <label>Position:</label>
                <input type="text" name="position" id="position" placeholder="z.B. 1. Vorsitzender">
            </div>
            
            <div class="form-group">
                <label>Foto:</label>
                <input type="file" name="image" accept="image/*">
                <small>Neue Datei ersetzt das aktuelle Foto.</small>
            </div>
            
            <div class="form-group">
                <label>Reihenfolge:</label>
                <input type="number" name="sort_order" id="sortOrder" value="0" min="0">
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_board_member" id="isBoardMember" checked>
                    Im Kommando aktiv
                </label>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('addMemberModal').style.display = 'flex';
}

function closeAddModal() {
    document.getElementById('addMemberModal').style.display = 'none';
}

function editMember(member) {
    document.getElementById('memberId').value = member.id;
    document.getElementById('memberName').value = member.first_name + ' ' + member.last_name;
    document.getElementById('position').value = member.board_position || '';
    document.getElementById('sortOrder').value = member.board_sort_order;
    document.getElementById('isBoardMember').checked = member.is_board_member == 1;
    document.getElementById('editMemberModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editMemberModal').style.display = 'none';
}

function removeMember(id) {
    if (confirm('Möchten Sie dieses Mitglied wirklich aus dem Kommando entfernen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    const addModal = document.getElementById('addMemberModal');
    const editModal = document.getElementById('editMemberModal');
    
    if (event.target === addModal) {
        closeAddModal();
    }
    if (event.target === editModal) {
        closeEditModal();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
