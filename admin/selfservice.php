<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !has_permission('selfservice.php')) {
    redirect('dashboard.php');
}

$db = getDBConnection();
$page_title = 'Self-Service';

// Obfuscation helpers: store values as base64 (no encryption)
function encode_value($value) {
    if ($value === null || $value === '') return null;
    return base64_encode($value);
}

function decode_value($encoded) {
    if ($encoded === null || $encoded === '') return '';
    $decoded = base64_decode($encoded, true);
    return $decoded !== false ? $decoded : $encoded;
}

// Handle delete
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    try {
        $stmt = $db->prepare("DELETE FROM credentials WHERE id = :id");
        $stmt->execute(['id' => $_POST['id']]);
        $_SESSION['success'] = 'Eintrag wurde erfolgreich gelöscht.';
        header('Location: selfservice.php');
        exit;
    } catch (Exception $e) {
        $error = "Fehler beim Löschen: " . $e->getMessage();
    }
}

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    try {
        $id = $_POST['id'] ?? null;
        $name = sanitize_input($_POST['name']);
        $description = $_POST['description'] ?? null;
        $login = $_POST['login'] ?? null;
        $value = $_POST['value'] ?? null;
        $website = $_POST['website'] ?? null;
        
        if (empty($name)) {
            throw new Exception('Name ist erforderlich.');
        }
        
        // Encode the value (base64) if provided
        if (!empty($value)) {
            $value = encode_value($value);
        }
        
        if ($id) {
            // Update existing
            $sql = "UPDATE credentials SET 
                    name = :name,
                    description = :description,
                    login = :login,
                    " . (!empty($_POST['value']) ? "value = :value," : "") . "
                    website = :website,
                    updated_by = :user_id,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            
            $params = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'login' => $login,
                'website' => $website,
                'user_id' => $_SESSION['user_id']
            ];
            
            if (!empty($_POST['value'])) {
                $params['value'] = $value;
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $_SESSION['success'] = 'Eintrag wurde erfolgreich aktualisiert.';
        } else {
            // Create new
            $stmt = $db->prepare("INSERT INTO credentials (name, description, login, value, website, created_by) 
                                  VALUES (:name, :description, :login, :value, :website, :user_id)");
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'login' => $login,
                'value' => $value,
                'website' => $website,
                'user_id' => $_SESSION['user_id']
            ]);
            $_SESSION['success'] = 'Eintrag wurde erfolgreich erstellt.';
        }
        
        header('Location: selfservice.php');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch credentials
$search = $_GET['search'] ?? '';
$sql = "SELECT * FROM credentials";
if (!empty($search)) {
    $sql .= " WHERE name LIKE :search OR description LIKE :search OR login LIKE :search";
}
$sql .= " ORDER BY name ASC";

$stmt = $db->prepare($sql);
if (!empty($search)) {
    $stmt->execute(['search' => '%' . $search . '%']);
} else {
    $stmt->execute();
}
$credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
.credential-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.credential-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
}

.credential-name {
    font-size: 1.3rem;
    font-weight: bold;
    color: #333;
}

.credential-actions {
    display: flex;
    gap: 10px;
}

.credential-field {
    margin-bottom: 15px;
}

.credential-label {
    font-weight: 600;
    color: #666;
    font-size: 0.85rem;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.credential-value-wrapper {
    display: flex;
    gap: 10px;
    align-items: center;
}

.credential-value {
    flex: 1;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-family: monospace;
    word-break: break-all;
}

.credential-value.masked {
    letter-spacing: 2px;
}

.btn-copy, .btn-toggle {
    padding: 8px 12px;
    font-size: 0.9rem;
    white-space: nowrap;
}

.search-box {
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
}

.search-box input {
    flex: 1;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 30px;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.close {
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #aaa;
}

.close:hover {
    color: #000;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
}
</style>

<div class="page-header">
    <h1><?php echo $page_title; ?></h1>
    <div class="page-actions">
        <button onclick="openModal()" class="btn btn-primary">
            <i class="fas fa-plus"></i> Neuen Eintrag erstellen
        </button>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <?php 
    echo $_SESSION['success']; 
    unset($_SESSION['success']);
    ?>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Search Box -->
<div class="search-box">
    <input type="text" 
           id="searchInput" 
           placeholder="Suchen nach Name, Beschreibung oder Login..." 
           value="<?php echo htmlspecialchars($search); ?>"
           onkeyup="handleSearch(event)">
    <button onclick="clearSearch()" class="btn btn-secondary">
        <i class="fas fa-times"></i> Zurücksetzen
    </button>
</div>

<!-- Credentials List -->
<div class="content-section">
    <?php if (empty($credentials)): ?>
        <div class="empty-state">
            <i class="fas fa-key"></i>
            <h3>Keine Einträge gefunden</h3>
            <p>Erstellen Sie einen neuen Eintrag, um zu beginnen.</p>
        </div>
    <?php else: ?>
        <?php foreach ($credentials as $cred): ?>
            <div class="credential-card">
                <div class="credential-header">
                    <div class="credential-name"><?php echo htmlspecialchars($cred['name']); ?></div>
                    <div class="credential-actions">
                        <button onclick="editCredential(<?php echo $cred['id']; ?>)" class="btn btn-secondary btn-sm">
                            <i class="fas fa-edit"></i> Bearbeiten
                        </button>
                        <button onclick="deleteCredential(<?php echo $cred['id']; ?>, '<?php echo htmlspecialchars($cred['name'], ENT_QUOTES); ?>')" 
                                class="btn btn-danger btn-sm">
                            <i class="fas fa-trash"></i> Löschen
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($cred['description'])): ?>
                <div class="credential-field">
                    <div class="credential-label">Beschreibung</div>
                    <div class="credential-value-wrapper">
                        <div class="credential-value"><?php echo nl2br(htmlspecialchars($cred['description'])); ?></div>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars(trim($cred['description']), ENT_QUOTES); ?>', this)" 
                                class="btn btn-secondary btn-sm btn-copy">
                            <i class="fas fa-copy"></i> Kopieren
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($cred['login'])): ?>
                <div class="credential-field">
                    <div class="credential-label">Login / Benutzername</div>
                    <div class="credential-value-wrapper">
                        <div class="credential-value"><?php echo htmlspecialchars($cred['login']); ?></div>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars(trim($cred['login']), ENT_QUOTES); ?>', this)" 
                                class="btn btn-secondary btn-sm btn-copy">
                            <i class="fas fa-copy"></i> Kopieren
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($cred['value'])): ?>
                <div class="credential-field">
                    <div class="credential-label">Passwort / IBAN / Wert</div>
                    <div class="credential-value-wrapper">
                        <div class="credential-value masked" id="value-<?php echo $cred['id']; ?>" 
                             data-encrypted="<?php echo htmlspecialchars($cred['value']); ?>"
                             data-decrypted="">
                            ••••••••••••••••
                        </div>
                        <button onclick="toggleValue(<?php echo $cred['id']; ?>)" 
                                class="btn btn-secondary btn-sm btn-toggle" 
                                id="toggle-<?php echo $cred['id']; ?>">
                            <i class="fas fa-eye"></i> Anzeigen
                        </button>
                        <button onclick="copyDecryptedValue(<?php echo $cred['id']; ?>, this)" 
                                class="btn btn-secondary btn-sm btn-copy">
                            <i class="fas fa-copy"></i> Kopieren
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($cred['website'])): ?>
                <div class="credential-field">
                    <div class="credential-label">Website / Link</div>
                    <div class="credential-value-wrapper">
                        <div class="credential-value">
                            <a href="<?php echo htmlspecialchars($cred['website']); ?>" 
                               target="_blank" 
                               style="color: #1976d2; text-decoration: none;">
                                <?php echo htmlspecialchars($cred['website']); ?>
                                <i class="fas fa-external-link-alt" style="font-size: 0.8rem; margin-left: 5px;"></i>
                            </a>
                        </div>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars(trim($cred['website']), ENT_QUOTES); ?>', this)" 
                                class="btn btn-secondary btn-sm btn-copy">
                            <i class="fas fa-copy"></i> Kopieren
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Create/Edit Modal -->
<div id="credentialModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Neuen Eintrag erstellen</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        
        <form method="POST" id="credentialForm">
            <input type="hidden" name="id" id="credentialId">
            
            <div class="form-group">
                <label class="required">Name</label>
                <input type="text" name="name" id="credentialName" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Beschreibung</label>
                <textarea name="description" id="credentialDescription" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label>Login / Benutzername</label>
                <input type="text" name="login" id="credentialLogin" class="form-control">
            </div>
            
            <div class="form-group">
                <label>Passwort / IBAN / Wert</label>
                <input type="text" name="value" id="credentialValue" class="form-control" 
                       placeholder="Leer lassen, um unverändert zu lassen (bei Bearbeitung)">
                <small style="color: #666;">Dieser Wert wird Base64-kodiert gespeichert (reine Verschleierung, keine Verschlüsselung).</small>
            </div>
            
            <div class="form-group">
                <label>Website / Link</label>
                <input type="url" name="website" id="credentialWebsite" class="form-control" 
                       placeholder="https://example.com">
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Form -->
<form method="POST" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
// Search functionality
function handleSearch(event) {
    if (event.key === 'Enter') {
        const search = document.getElementById('searchInput').value;
        window.location.href = 'selfservice.php?search=' + encodeURIComponent(search);
    }
}

function clearSearch() {
    window.location.href = 'selfservice.php';
}

// Modal functions
function openModal(id = null) {
    const modal = document.getElementById('credentialModal');
    const form = document.getElementById('credentialForm');
    const title = document.getElementById('modalTitle');
    
    form.reset();
    document.getElementById('credentialId').value = '';
    
    if (id) {
        title.textContent = 'Eintrag bearbeiten';
        loadCredential(id);
    } else {
        title.textContent = 'Neuen Eintrag erstellen';
    }
    
    modal.style.display = 'block';
}

function closeModal() {
    document.getElementById('credentialModal').style.display = 'none';
}

function editCredential(id) {
    if (confirm('Möchten Sie diesen Eintrag wirklich bearbeiten?')) {
        openModal(id);
    }
}

function loadCredential(id) {
    fetch('selfservice_api.php?action=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('credentialId').value = data.id;
            document.getElementById('credentialName').value = data.name;
            document.getElementById('credentialDescription').value = data.description || '';
            document.getElementById('credentialLogin').value = data.login || '';
            document.getElementById('credentialWebsite').value = data.website || '';
            // Don't load the encoded value for security
            document.getElementById('credentialValue').placeholder = 'Leer lassen, um Wert beizubehalten';
        });
}

function deleteCredential(id, name) {
    if (confirm('Möchten Sie "' + name + '" wirklich löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden!')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// Copy to clipboard
function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Kopiert!';
        button.classList.add('btn-success');
        button.classList.remove('btn-secondary');
        
        setTimeout(() => {
            button.innerHTML = originalHtml;
            button.classList.remove('btn-success');
            button.classList.add('btn-secondary');
        }, 2000);
    }).catch(err => {
        alert('Fehler beim Kopieren: ' + err);
    });
}

function copyDecryptedValue(id, button) {
    const valueDiv = document.getElementById('value-' + id);
    let text = valueDiv.dataset.decrypted;
    
    if (!text) {
        // Need to decrypt first
        const encrypted = valueDiv.dataset.encrypted;
        fetch('selfservice_api.php?action=decrypt', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({value: encrypted})
        })
        .then(response => response.json())
        .then(data => {
            text = data.decrypted;
            valueDiv.dataset.decrypted = text;
            copyToClipboard(text.trim(), button);
        });
    } else {
        copyToClipboard(text.trim(), button);
    }
}

// Toggle password visibility
function toggleValue(id) {
    const valueDiv = document.getElementById('value-' + id);
    const toggleBtn = document.getElementById('toggle-' + id);
    
    if (valueDiv.classList.contains('masked')) {
        // Decrypt and show
        const encrypted = valueDiv.dataset.encrypted;
        
        if (!valueDiv.dataset.decrypted) {
            fetch('selfservice_api.php?action=decrypt', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({value: encrypted})
            })
            .then(response => response.json())
            .then(data => {
                valueDiv.textContent = data.decrypted;
                valueDiv.dataset.decrypted = data.decrypted;
                valueDiv.classList.remove('masked');
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Verbergen';
            });
        } else {
            valueDiv.textContent = valueDiv.dataset.decrypted;
            valueDiv.classList.remove('masked');
            toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Verbergen';
        }
    } else {
        // Hide
        valueDiv.textContent = '••••••••••••••••';
        valueDiv.classList.add('masked');
        toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Anzeigen';
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('credentialModal');
    if (event.target == modal) {
        closeModal();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
