<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_page_content()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $first_name = sanitize_input($_POST['first_name']);
        $last_name = sanitize_input($_POST['last_name']);
        $position = sanitize_input($_POST['position']);
        $bio = $_POST['bio'];
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $sort_order = (int)$_POST['sort_order'];
        $active = isset($_POST['active']) ? 1 : 0;
        
        // Handle image upload
        $image_url = null;
        if (isset($_FILES['image']) && $_FILES['image']['size'] > 0) {
            $result = upload_image($_FILES['image'], 'board');
            if ($result['success']) {
                $image_url = $result['url'];
            }
        }
        
        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO board_members (first_name, last_name, position, image_url, bio, email, phone, sort_order, active) 
                                   VALUES (:first_name, :last_name, :position, :image_url, :bio, :email, :phone, :sort_order, :active)");
            $stmt->execute([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'position' => $position,
                'image_url' => $image_url,
                'bio' => $bio,
                'email' => $email,
                'phone' => $phone,
                'sort_order' => $sort_order,
                'active' => $active
            ]);
            $success = "Vorstandsmitglied erfolgreich hinzugefügt";
        } else {
            if ($image_url) {
                $stmt = $db->prepare("UPDATE board_members SET first_name = :first_name, last_name = :last_name, 
                                       position = :position, image_url = :image_url, bio = :bio, email = :email, 
                                       phone = :phone, sort_order = :sort_order, active = :active 
                                       WHERE id = :id");
                $stmt->execute([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'position' => $position,
                    'image_url' => $image_url,
                    'bio' => $bio,
                    'email' => $email,
                    'phone' => $phone,
                    'sort_order' => $sort_order,
                    'active' => $active,
                    'id' => $id
                ]);
            } else {
                $stmt = $db->prepare("UPDATE board_members SET first_name = :first_name, last_name = :last_name, 
                                       position = :position, bio = :bio, email = :email, 
                                       phone = :phone, sort_order = :sort_order, active = :active 
                                       WHERE id = :id");
                $stmt->execute([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'position' => $position,
                    'bio' => $bio,
                    'email' => $email,
                    'phone' => $phone,
                    'sort_order' => $sort_order,
                    'active' => $active,
                    'id' => $id
                ]);
            }
            $success = "Vorstandsmitglied erfolgreich aktualisiert";
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        // Delete image
        $stmt = $db->prepare("SELECT image_url FROM board_members WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $member = $stmt->fetch();
        if ($member && $member['image_url']) {
            delete_image($member['image_url']);
        }
        
        $stmt = $db->prepare("DELETE FROM board_members WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $success = "Vorstandsmitglied erfolgreich gelöscht";
    }
}

// Get all board members
$board_members = get_board_members(false);

$page_title = 'Vorstandschaft verwalten';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Vorstandschaft verwalten</h1>
    <button class="btn btn-primary" onclick="showAddModal()">+ Neues Mitglied</button>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Sortierung</th>
                <th>Foto</th>
                <th>Name</th>
                <th>Position</th>
                <th>E-Mail</th>
                <th>Status</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($board_members as $member): ?>
                <tr>
                    <td><?php echo $member['sort_order']; ?></td>
                    <td>
                        <?php if ($member['image_url']): ?>
                            <img src="../uploads/<?php echo htmlspecialchars($member['image_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($member['first_name']); ?>" 
                                 style="width: 50px; height: 50px; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <i class="fas fa-user" style="font-size: 2rem; color: #999;"></i>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($member['position']); ?></td>
                    <td><?php echo htmlspecialchars($member['email']); ?></td>
                    <td>
                        <span class="badge <?php echo $member['active'] ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $member['active'] ? 'Aktiv' : 'Inaktiv'; ?>
                        </span>
                    </td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" onclick='editMember(<?php echo json_encode($member); ?>)'>Bearbeiten</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteMember(<?php echo $member['id']; ?>)">Löschen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add/Edit Modal -->
<div id="memberModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Vorstandsmitglied hinzufügen</h2>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="memberId">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">Vorname *</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name">Nachname *</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="position">Position *</label>
                <input type="text" id="position" name="position" required placeholder="z.B. 1. Vorsitzender">
            </div>
            
            <div class="form-group">
                <label for="image">Foto</label>
                <input type="file" id="image" name="image" accept="image/*">
            </div>
            
            <div class="form-group">
                <label for="bio">Biografie</label>
                <textarea id="bio" name="bio" rows="4"></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="email">E-Mail</label>
                    <input type="email" id="email" name="email">
                </div>
                
                <div class="form-group">
                    <label for="phone">Telefon</label>
                    <input type="tel" id="phone" name="phone">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="sort_order">Sortierreihenfolge</label>
                    <input type="number" id="sort_order" name="sort_order" value="0" min="0">
                </div>
                
                <div class="form-group checkbox">
                    <label>
                        <input type="checkbox" id="active" name="active" checked>
                        Aktiv anzeigen
                    </label>
                </div>
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
    document.getElementById('modalTitle').textContent = 'Vorstandsmitglied hinzufügen';
    document.getElementById('formAction').value = 'add';
    document.getElementById('memberId').value = '';
    document.getElementById('first_name').value = '';
    document.getElementById('last_name').value = '';
    document.getElementById('position').value = '';
    document.getElementById('bio').value = '';
    document.getElementById('email').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('sort_order').value = '0';
    document.getElementById('active').checked = true;
    document.getElementById('memberModal').style.display = 'block';
}

function editMember(member) {
    document.getElementById('modalTitle').textContent = 'Vorstandsmitglied bearbeiten';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('memberId').value = member.id;
    document.getElementById('first_name').value = member.first_name;
    document.getElementById('last_name').value = member.last_name;
    document.getElementById('position').value = member.position;
    document.getElementById('bio').value = member.bio || '';
    document.getElementById('email').value = member.email || '';
    document.getElementById('phone').value = member.phone || '';
    document.getElementById('sort_order').value = member.sort_order;
    document.getElementById('active').checked = member.active == 1;
    document.getElementById('memberModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('memberModal').style.display = 'none';
}

function deleteMember(id) {
    if (confirm('Sind Sie sicher, dass Sie dieses Vorstandsmitglied löschen möchten?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
