<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('members.php')) {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();
$message = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';

// Clear session messages after reading
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
            case 'edit':
                $id = $_POST['id'] ?? null;
                $data = [
                    'member_type' => $_POST['member_type'],
                    'salutation' => !empty($_POST['salutation']) ? $_POST['salutation'] : null,
                    'first_name' => $_POST['first_name'],
                    'last_name' => $_POST['last_name'],
                    'street' => !empty($_POST['street']) ? $_POST['street'] : null,
                    'postal_code' => !empty($_POST['postal_code']) ? $_POST['postal_code'] : null,
                    'city' => !empty($_POST['city']) ? $_POST['city'] : null,
                    'email' => !empty($_POST['email']) ? $_POST['email'] : null,
                    'telephone' => !empty($_POST['telephone']) ? $_POST['telephone'] : null,
                    'mobile' => !empty($_POST['mobile']) ? $_POST['mobile'] : null,
                    'iban' => !empty($_POST['iban']) ? $_POST['iban'] : null,
                    'member_number' => !empty($_POST['member_number']) ? $_POST['member_number'] : null,
                    'join_date' => !empty($_POST['join_date']) ? $_POST['join_date'] : null,
                    'active' => isset($_POST['active']) ? 1 : 0,
                    'notes' => !empty($_POST['notes']) ? $_POST['notes'] : null
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
                                iban = :iban,
                                member_number = :member_number,
                                join_date = :join_date,
                                active = :active,
                                notes = :notes
                                WHERE id = :id";
                        $data['id'] = $id;
                        $stmt = $db->prepare($sql);
                        $stmt->execute($data);
                        
                        // Return JSON for AJAX requests
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo json_encode(['success' => true, 'message' => 'Mitglied erfolgreich aktualisiert.']);
                            exit;
                        }
                        $_SESSION['success_message'] = 'Mitglied erfolgreich aktualisiert.';
                    } else {
                        // Insert new member
                        $sql = "INSERT INTO members (member_type, salutation, first_name, last_name, street, postal_code, city, 
                                email, telephone, mobile, iban, member_number, join_date, active, notes)
                                VALUES (:member_type, :salutation, :first_name, :last_name, :street, :postal_code, :city, 
                                :email, :telephone, :mobile, :iban, :member_number, :join_date, :active, :notes)";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($data);
                        
                        // Return JSON for AJAX requests
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo json_encode(['success' => true, 'message' => 'Mitglied erfolgreich hinzugefügt.']);
                            exit;
                        }
                        $_SESSION['success_message'] = 'Mitglied erfolgreich hinzugefügt.';
                    }
                    // For non-AJAX, redirect
                    header('Location: members.php');
                    exit;
                } catch (PDOException $e) {
                    // Return JSON error for AJAX requests
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern: ' . $e->getMessage()]);
                        exit;
                    }
                    $_SESSION['error_message'] = 'Fehler beim Speichern: ' . $e->getMessage();
                    header('Location: members.php');
                    exit;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'];
                // Check if member has obligations (which may have payments)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM member_fee_obligations WHERE member_id = :id");
                $stmt->execute(['id' => $id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $_SESSION['error_message'] = 'Mitglied kann nicht gelöscht werden, da Beitragsforderungen vorhanden sind.';
                } else {
                    $stmt = $db->prepare("DELETE FROM members WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    $_SESSION['success_message'] = 'Mitglied erfolgreich gelöscht.';
                }
                header('Location: members.php');
                exit;
                
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
$sql = "SELECT m.*, 
               u.id as linked_user_id, u.email as user_email
        FROM members m
        LEFT JOIN users u ON u.member_id = m.id
        WHERE 1=1";
$params = [];

if ($filter_type !== 'all') {
    $sql .= " AND m.member_type = :filter_type";
    $params['filter_type'] = $filter_type;
}

if ($search) {
    // Use unique named parameters for each search field
    $search_term = '%' . $search . '%';
    $sql .= " AND (m.first_name LIKE :search_first OR m.last_name LIKE :search_last OR m.member_number LIKE :search_member OR m.email LIKE :search_email)";
    $params['search_first'] = $search_term;
    $params['search_last'] = $search_term;
    $params['search_member'] = $search_term;
    $params['search_email'] = $search_term;
}

$sql .= " ORDER BY m.active DESC, m.last_name, m.first_name";

$stmt = $db->prepare($sql);

// Debug: Log the query and params
error_log("Query: " . $sql);
error_log("Params: " . json_encode($params));

try {
    $stmt->execute($params);
    $members = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Query execution error: " . $e->getMessage());
    error_log("Full query: " . $sql);
    error_log("Params: " . json_encode($params));
    $members = [];
    $error = 'Fehler beim Abrufen der Mitglieder: ' . $e->getMessage();
}

// Get statistics
$stmt = $db->query("SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN member_type = 'active' AND active = 1 THEN 1 ELSE 0 END) as active_members,
                    SUM(CASE WHEN member_type = 'supporter' AND active = 1 THEN 1 ELSE 0 END) as supporters,
                    SUM(CASE WHEN member_type = 'pensioner' AND active = 1 THEN 1 ELSE 0 END) as pensioners,
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
        <div class="stat-icon" style="background: #9c27b0;">
            <i class="fas fa-user-friends"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pensioners'] ?></div>
            <div class="stat-label">Altersabteilung</div>
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

<!-- Filter Section -->
<div class="section-card">
    <h2>Filter</h2>
    <form method="GET" class="filter-form" style="display: flex; flex-direction: column; gap: 0.75rem;">
        <!-- Row 1: Type filter -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group">
                <label for="type">Mitgliedertyp</label>
                <select id="type" name="type">
                    <option value="all" <?= $filter_type === 'all' ? 'selected' : '' ?>>Alle Typen</option>
                    <option value="active" <?= $filter_type === 'active' ? 'selected' : '' ?>>Einsatzeinheit</option>
                    <option value="supporter" <?= $filter_type === 'supporter' ? 'selected' : '' ?>>Förderer</option>
                    <option value="pensioner" <?= $filter_type === 'pensioner' ? 'selected' : '' ?>>Altersabteilung</option>
                </select>
            </div>
        </div>
        
        <!-- Row 2: Search -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group" style="min-width: 280px; flex: 1 1 100%;">
                <label for="search">Suche</label>
                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name, Mitgliedsnummer, E-Mail..." style="width: 100%;">
            </div>
        </div>
        
        <!-- Row 3: Buttons -->
        <div class="filter-row" style="display: flex; justify-content: flex-end; gap: 0.5rem; width: 100%;">
            <button type="submit" class="btn btn-secondary">Filtern</button>
            <a href="members.php" class="btn btn-secondary">Zurücksetzen</a>
        </div>
    </form>
</div>

<?php 
// Build active filter hints for UI
$active_filters = [];
if ($filter_type !== 'all') {
    $type_labels = [
        'active' => 'Einsatzeinheit',
        'supporter' => 'Förderer',
        'pensioner' => 'Altersabteilung'
    ];
    $active_filters[] = 'Typ: ' . ($type_labels[$filter_type] ?? $filter_type);
}
if ($search !== '') {
    $active_filters[] = 'Suche: "' . $search . '"';
}
?>

<?php if (!empty($active_filters)): ?>
<div class="alert" style="background-color: #e8f4fd; color: #0b4f7d; border-left: 4px solid #1976d2; margin-top: 0.5rem;">
    Aktive Filter: <?= htmlspecialchars(implode(' · ', $active_filters)) ?>
</div>
<?php endif; ?>

<!-- Members Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nr.</th>
                <th>Name</th>
                <th>Typ</th>
                <th>Adresse</th>
                <th>Kontakt</th>
                <th>Beitritt</th>
                <th>Status</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($members)): ?>
                <tr>
                    <td colspan="8" style="text-align: center;">Keine Mitglieder gefunden.</td>
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
                            <?php if ($member['street']): ?>
                                <?= htmlspecialchars($member['street']) ?><br>
                            <?php endif; ?>
                            <?php if ($member['postal_code'] || $member['city']): ?>
                                <small><?= htmlspecialchars(trim(($member['postal_code'] ?? '') . ' ' . ($member['city'] ?? ''))) ?></small>
                            <?php endif; ?>
                            <?php if (!$member['street'] && !$member['postal_code'] && !$member['city']): ?>
                                <small style="color: #999;">-</small>
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
        <form method="POST" id="memberForm" onsubmit="saveMember(event)">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="memberId">
            
            <div class="form-row">
                <div class="form-column">
                    <h3>Stammdaten</h3>
                    
                    <div class="form-group">
                        <label>Typ: *</label>
                        <select name="member_type" id="member_type" required>
                            <option value="active">Einsatzeinheit</option>
                            <option value="supporter">Förderer</option>
                            <option value="pensioner">Altersabteilung</option>
                        </select>
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
                        <input type="email" name="email" id="emailField">
                        <small id="emailHint" style="color: #d32f2f; display: none; margin-top: 0.5rem; font-weight: 600;">
                            <i class="fas fa-lock"></i> E-Mail wird durch verknüpften Benutzer verwaltet
                        </small>
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
                        <label>IBAN:</label>
                        <input type="text" name="iban" placeholder="DE89 3704 0044 0532 0130 00" maxlength="34">
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
    document.querySelector('[name="member_type"]').value = member.member_type;
    document.querySelector('[name="salutation"]').value = member.salutation || '';
    document.querySelector('[name="first_name"]').value = member.first_name;
    document.querySelector('[name="last_name"]').value = member.last_name;
    document.querySelector('[name="street"]').value = member.street || '';
    document.querySelector('[name="postal_code"]').value = member.postal_code || '';
    document.querySelector('[name="city"]').value = member.city || '';
    document.querySelector('[name="email"]').value = member.email || '';
    document.querySelector('[name="telephone"]').value = member.telephone || '';
    document.querySelector('[name="mobile"]').value = member.mobile || '';
    document.querySelector('[name="iban"]').value = member.iban || '';
    document.querySelector('[name="member_number"]').value = member.member_number || '';
    document.querySelector('[name="join_date"]').value = member.join_date || '';
    document.querySelector('[name="active"]').checked = member.active == 1;
    document.querySelector('[name="notes"]').value = member.notes || '';
    
    // Make email field read-only if linked to a user
    const emailField = document.getElementById('emailField');
    const emailHint = document.getElementById('emailHint');
    if (member.linked_user_id) {
        emailField.readOnly = true;
        emailField.style.backgroundColor = '#f5f5f5';
        emailField.style.cursor = 'not-allowed';
        emailHint.style.display = 'block';
    } else {
        emailField.readOnly = false;
        emailField.style.backgroundColor = '';
        emailField.style.cursor = '';
        emailHint.style.display = 'none';
    }
    
    document.getElementById('memberModal').style.display = 'flex';
}

function saveMember(event) {
    event.preventDefault();
    
    const form = document.getElementById('memberForm');
    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    
    // Disable submit button
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Speichern...';
    
    fetch('members.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            closeModal();
            
            // Show success message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success';
            alertDiv.textContent = data.message;
            alertDiv.style.animation = 'fadeIn 0.3s';
            
            const contentHeader = document.querySelector('.content-header');
            contentHeader.parentNode.insertBefore(alertDiv, contentHeader.nextSibling);
            
            // Auto-remove alert after 3 seconds
            setTimeout(() => {
                alertDiv.style.animation = 'fadeOut 0.3s';
                setTimeout(() => alertDiv.remove(), 300);
            }, 3000);
            
            // Reload page to show updated data
            setTimeout(() => location.reload(), 500);
        } else {
            // Re-enable submit button
            submitButton.disabled = false;
            submitButton.innerHTML = 'Speichern';
            
            alert(data.message || 'Fehler beim Speichern. Bitte versuchen Sie es erneut.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitButton.disabled = false;
        submitButton.innerHTML = 'Speichern';
        alert('Fehler beim Speichern. Bitte versuchen Sie es erneut.');
    });
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
