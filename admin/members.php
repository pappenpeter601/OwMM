<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
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
                    'member_type' => $_POST['member_type'],
                    'salutation' => $_POST['salutation'] ?? null,
                    'first_name' => $_POST['first_name'],
                    'last_name' => $_POST['last_name'],
                    'street' => $_POST['street'] ?? null,
                    'postal_code' => $_POST['postal_code'] ?? null,
                    'city' => $_POST['city'] ?? null,
                    'email' => $_POST['email'] ?? null,
                    'telephone' => $_POST['telephone'] ?? null,
                    'mobile' => $_POST['mobile'] ?? null,
                    'member_number' => $_POST['member_number'] ?? null,
                    'join_date' => $_POST['join_date'] ?? null,
                    'active' => isset($_POST['active']) ? 1 : 0,
                    'notes' => $_POST['notes'] ?? null
                ];
                
                try {
                    if ($id) {
                        // Update existing member
                        $sql = "UPDATE members SET 
                                member_type = :member_type,
                                salutation = :salutation,
                                first_name = :first_name,
                                last_name = :last_name,
                                street = :street,
                                postal_code = :postal_code,
                                city = :city,
                                email = :email,
                                telephone = :telephone,
                                mobile = :mobile,
                                member_number = :member_number,
                                join_date = :join_date,
                                active = :active,
                                notes = :notes
                                WHERE id = :id";
                        $data['id'] = $id;
                        $stmt = $db->prepare($sql);
                        $stmt->execute($data);
                        $message = 'Mitglied erfolgreich aktualisiert.';
                    } else {
                        // Insert new member
                        $sql = "INSERT INTO members (member_type, salutation, first_name, last_name, street, postal_code, city, 
                                email, telephone, mobile, member_number, join_date, active, notes)
                                VALUES (:member_type, :salutation, :first_name, :last_name, :street, :postal_code, :city, 
                                :email, :telephone, :mobile, :member_number, :join_date, :active, :notes)";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($data);
                        $message = 'Mitglied erfolgreich hinzugefügt.';
                    }
                } catch (PDOException $e) {
                    $error = 'Fehler beim Speichern: ' . $e->getMessage();
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                // Check if member has obligations (which may have payments)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM member_fee_obligations WHERE member_id = :id");
                $stmt->execute(['id' => $id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $error = 'Mitglied kann nicht gelöscht werden, da Beitragsforderungen vorhanden sind.';
                } else {
                    $stmt = $db->prepare("DELETE FROM members WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $message = 'Mitglied erfolgreich gelöscht.';
                }
                break;
                
            case 'toggle_active':
                $id = $_POST['id'];
                $stmt = $db->prepare("UPDATE members SET active = NOT active WHERE id = :id");
                $stmt->execute(['id' => $id]);
                echo json_encode(['success' => true]);
                exit;
        }
    }
}

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$sql = "SELECT * FROM members WHERE 1=1";
$params = [];

if ($filter_type !== 'all') {
    $sql .= " AND member_type = :member_type";
    $params['member_type'] = $filter_type;
}

if ($search) {
    $sql .= " AND (first_name LIKE :search OR last_name LIKE :search OR member_number LIKE :search OR email LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

$sql .= " ORDER BY active DESC, last_name, first_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// Get statistics
$stmt = $db->query("SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN member_type = 'active' AND active = 1 THEN 1 ELSE 0 END) as active_members,
                    SUM(CASE WHEN member_type = 'supporter' AND active = 1 THEN 1 ELSE 0 END) as supporters,
                    SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) as inactive
                    FROM members");
$stats = $stmt->fetch();

include 'includes/header.php';
?>

<div class="content-header">
    <h1>Mitgliederverwaltung</h1>
    <button class="btn btn-primary" onclick="showAddModal()">
        <i class="fas fa-plus"></i> Neues Mitglied
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #4caf50;">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['active_members'] ?></div>
            <div class="stat-label">Einsatzeinheit</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #2196f3;">
            <i class="fas fa-heart"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['supporters'] ?></div>
            <div class="stat-label">Förderer</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #ff9800;">
            <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-label">Gesamt</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #9e9e9e;">
            <i class="fas fa-user-slash"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['inactive'] ?></div>
            <div class="stat-label">Inaktiv</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group">
            <label>Typ:</label>
            <select name="type" onchange="this.form.submit()">
                <option value="all" <?= $filter_type === 'all' ? 'selected' : '' ?>>Alle</option>
                <option value="active" <?= $filter_type === 'active' ? 'selected' : '' ?>>Einsatzeinheit</option>
                <option value="supporter" <?= $filter_type === 'supporter' ? 'selected' : '' ?>>Förderer</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label>Suche:</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                   placeholder="Name, Nummer, E-Mail...">
        </div>
        
        <button type="submit" class="btn btn-secondary">
            <i class="fas fa-search"></i> Filtern
        </button>
        
        <?php if ($filter_type !== 'all' || $search): ?>
            <a href="members.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Zurücksetzen
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Members Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nr.</th>
                <th>Name</th>
                <th>Typ</th>
                <th>Kontakt</th>
                <th>Beitritt</th>
                <th>Status</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($members)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">Keine Mitglieder gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <tr class="<?= !$member['active'] ? 'inactive-row' : '' ?>">
                        <td><?= htmlspecialchars($member['member_number'] ?? '-') ?></td>
                        <td>
                            <strong><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                            <?php if ($member['salutation']): ?>
                                <br><small><?= htmlspecialchars($member['salutation']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($member['member_type'] === 'active'): ?>
                                <span class="badge badge-primary">Einsatzeinheit</span>
                            <?php else: ?>
                                <span class="badge badge-info">Förderer</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($member['email']): ?>
                                <i class="fas fa-envelope"></i> <?= htmlspecialchars($member['email']) ?><br>
                            <?php endif; ?>
                            <?php if ($member['mobile']): ?>
                                <i class="fas fa-mobile"></i> <?= htmlspecialchars($member['mobile']) ?>
                            <?php elseif ($member['telephone']): ?>
                                <i class="fas fa-phone"></i> <?= htmlspecialchars($member['telephone']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $member['join_date'] ? date('d.m.Y', strtotime($member['join_date'])) : '-' ?></td>
                        <td>
                            <label class="toggle-switch">
                                <input type="checkbox" <?= $member['active'] ? 'checked' : '' ?> 
                                       onchange="toggleActive(<?= $member['id'] ?>)">
                                <span class="toggle-slider"></span>
                            </label>
                        </td>
                        <td class="action-buttons">
                            <button class="btn btn-sm btn-secondary" onclick="editMember(<?= htmlspecialchars(json_encode($member)) ?>)" 
                                    title="Bearbeiten">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="member_payments.php?id=<?= $member['id'] ?>" class="btn btn-sm btn-info" 
                               title="Zahlungen">
                                <i class="fas fa-euro-sign"></i>
                            </a>
                            <button class="btn btn-sm btn-danger" onclick="deleteMember(<?= $member['id'] ?>)" 
                                    title="Löschen">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add/Edit Modal -->
<div id="memberModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="modalTitle">Neues Mitglied</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST" id="memberForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="memberId">
            
            <div class="form-row">
                <div class="form-column">
                    <h3>Stammdaten</h3>
                    
                    <div class="form-group">
                        <label>Typ: *</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="member_type" value="active" checked required>
                                Einsatzeinheit
                            </label>
                            <label>
                                <input type="radio" name="member_type" value="supporter">
                                Förderer
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Anrede:</label>
                        <select name="salutation">
                            <option value="">- Bitte wählen -</option>
                            <option value="Herr">Herr</option>
                            <option value="Frau">Frau</option>
                            <option value="Divers">Divers</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Vorname: *</label>
                        <input type="text" name="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Nachname: *</label>
                        <input type="text" name="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Mitgliedsnummer:</label>
                        <input type="text" name="member_number">
                    </div>
                    
                    <div class="form-group">
                        <label>Beitrittsdatum:</label>
                        <input type="date" name="join_date">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="active" checked>
                            Aktiv
                        </label>
                    </div>
                </div>
                
                <div class="form-column">
                    <h3>Kontaktdaten</h3>
                    
                    <div class="form-group">
                        <label>Straße & Hausnummer:</label>
                        <input type="text" name="street">
                    </div>
                    
                    <div class="form-group">
                        <label>PLZ:</label>
                        <input type="text" name="postal_code" maxlength="10">
                    </div>
                    
                    <div class="form-group">
                        <label>Ort:</label>
                        <input type="text" name="city">
                    </div>
                    
                    <div class="form-group">
                        <label>E-Mail:</label>
                        <input type="email" name="email">
                    </div>
                    
                    <div class="form-group">
                        <label>Telefon:</label>
                        <input type="text" name="telephone">
                    </div>
                    
                    <div class="form-group">
                        <label>Mobil:</label>
                        <input type="text" name="mobile">
                    </div>
                    
                    <div class="form-group">
                        <label>Notizen:</label>
                        <textarea name="notes" rows="3"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<style>
.inactive-row {
    opacity: 0.6;
    background-color: #f5f5f5;
}

.toggle-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .toggle-slider {
    background-color: #4caf50;
}

input:checked + .toggle-slider:before {
    transform: translateX(26px);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.form-column h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    color: #d32f2f;
    border-bottom: 2px solid #d32f2f;
    padding-bottom: 0.5rem;
}

.radio-group {
    display: flex;
    gap: 1rem;
}

.radio-group label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
</style>

<script>
function showAddModal() {
    document.getElementById('modalTitle').textContent = 'Neues Mitglied';
    document.getElementById('formAction').value = 'add';
    document.getElementById('memberForm').reset();
    document.getElementById('memberId').value = '';
    document.getElementById('memberModal').style.display = 'flex';
}

function editMember(member) {
    document.getElementById('modalTitle').textContent = 'Mitglied bearbeiten';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('memberId').value = member.id;
    
    // Fill form fields
    document.querySelector('[name="member_type"][value="' + member.member_type + '"]').checked = true;
    document.querySelector('[name="salutation"]').value = member.salutation || '';
    document.querySelector('[name="first_name"]').value = member.first_name;
    document.querySelector('[name="last_name"]').value = member.last_name;
    document.querySelector('[name="street"]').value = member.street || '';
    document.querySelector('[name="postal_code"]').value = member.postal_code || '';
    document.querySelector('[name="city"]').value = member.city || '';
    document.querySelector('[name="email"]').value = member.email || '';
    document.querySelector('[name="telephone"]').value = member.telephone || '';
    document.querySelector('[name="mobile"]').value = member.mobile || '';
    document.querySelector('[name="member_number"]').value = member.member_number || '';
    document.querySelector('[name="join_date"]').value = member.join_date || '';
    document.querySelector('[name="active"]').checked = member.active == 1;
    document.querySelector('[name="notes"]').value = member.notes || '';
    
    document.getElementById('memberModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('memberModal').style.display = 'none';
}

function deleteMember(id) {
    if (confirm('Möchten Sie dieses Mitglied wirklich löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleActive(id) {
    fetch('members.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=toggle_active&id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Fehler beim Aktualisieren des Status');
            location.reload();
        }
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('memberModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
