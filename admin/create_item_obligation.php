<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('outstanding_obligations.php')) {
    header('Location: login.php');
    exit;
}

ensure_financial_reporting_support();

$db = getDBConnection();
$message = '';
$error = '';

// Handle form submission for creating obligation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_obligation') {
    $uploadedFsPaths = [];
    try {
        $receiver_type = $_POST['receiver_type']; // 'member' or 'non-member'
        $member_id = ($receiver_type === 'member') ? $_POST['member_id'] : null;
        $receiver_name = ($receiver_type === 'non-member') ? trim((string) ($_POST['receiver_name'] ?? '')) : null;
        $receiver_phone = $_POST['receiver_phone'] ?? null;
        $receiver_email = $_POST['receiver_email'] ?? null;
        $organizing_member_id = $_POST['organizing_member_id'] ?? null;
        $category_id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        $due_date = $_POST['due_date'] ?? null;
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $manual_amount = (float) str_replace(',', '.', (string) ($_POST['manual_amount'] ?? '0'));
        
        // Validate
        if ($receiver_type === 'member' && !$member_id) {
            throw new Exception('Bitte wählen Sie ein Mitglied aus.');
        }
        if ($receiver_type === 'non-member' && !$receiver_name) {
            throw new Exception('Bitte geben Sie den Namen der Person oder Organisation ein.');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Calculate total amount from optionally linked items
        $total_amount = 0;
        $obligation_items = [];
        
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item_id => $qty) {
                $qty = (int)$qty;
                if ($qty <= 0) continue;
                
                $stmt = $db->prepare("SELECT id, name, price FROM items WHERE id = :id AND active = 1");
                $stmt->execute([':id' => $item_id]);
                $item = $stmt->fetch();
                
                if (!$item) {
                    throw new Exception("Artikel mit ID $item_id nicht gefunden oder inaktiv.");
                }
                
                $subtotal = $qty * $item['price'];
                $total_amount += $subtotal;
                $obligation_items[$item_id] = [
                    'name' => $item['name'],
                    'quantity' => $qty,
                    'unit_price' => $item['price'],
                    'subtotal' => $subtotal
                ];
            }
        }
        
        if ($total_amount <= 0) {
            $total_amount = $manual_amount;
        }

        if ($total_amount <= 0) {
            throw new Exception('Bitte geben Sie einen Betrag an oder wählen Sie mindestens einen optionalen Artikel aus.');
        }
        
        // Create obligation
        $stmt = $db->prepare("INSERT INTO item_obligations 
                              (member_id, receiver_name, receiver_phone, receiver_email, organizing_member_id, category_id,
                               total_amount, status, notes, due_date, created_by)
                              VALUES (:member_id, :receiver_name, :receiver_phone, :receiver_email, :organizing_member_id, :category_id,
                                      :total_amount, 'open', :notes, :due_date, :created_by)");
        $stmt->execute([
            ':member_id' => $member_id,
            ':receiver_name' => $receiver_name,
            ':receiver_phone' => $receiver_phone,
            ':receiver_email' => $receiver_email,
            ':organizing_member_id' => $organizing_member_id ?: null,
            ':category_id' => $category_id,
            ':total_amount' => $total_amount,
            ':notes' => $notes,
            ':due_date' => $due_date ?: null,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        
        $obligation_id = $db->lastInsertId();
        
        // Add obligation items
        foreach ($obligation_items as $item_id => $item_data) {
            $stmt = $db->prepare("INSERT INTO obligation_items (obligation_id, item_id, quantity, unit_price, subtotal)
                                  VALUES (:obligation_id, :item_id, :quantity, :unit_price, :subtotal)");
            $stmt->execute([
                ':obligation_id' => $obligation_id,
                ':item_id' => $item_id,
                ':quantity' => $item_data['quantity'],
                ':unit_price' => $item_data['unit_price'],
                ':subtotal' => $item_data['subtotal']
            ]);
        }

        $documents = normalize_uploaded_files_array($_FILES['documents'] ?? []);
        foreach ($documents as $document) {
            if (($document['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($document['name'])) {
                continue;
            }
            if (($document['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new Exception('Ein Dokument konnte nicht hochgeladen werden.');
            }

            $uploadResult = upload_item_obligation_document($document, (int) $obligation_id, $db);
            if (empty($uploadResult['success'])) {
                throw new Exception($uploadResult['error'] ?? 'Dokument konnte nicht verknüpft werden.');
            }
            if (!empty($uploadResult['fs_path'])) {
                $uploadedFsPaths[] = $uploadResult['fs_path'];
            }
        }
        
        $db->commit();
        $message = 'Forderung erfolgreich erstellt. <a href="view_item_obligation.php?id=' . (int) $obligation_id . '">Details öffnen</a>';
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($uploadedFsPaths as $fsPath) {
            if ($fsPath && file_exists($fsPath)) {
                @unlink($fsPath);
            }
        }
        $error = 'Fehler beim Erstellen der Forderung: ' . $e->getMessage();
    }
}

// Get all members
$stmt = $db->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM members WHERE active = 1 ORDER BY last_name, first_name");
$stmt->execute();
$members = $stmt->fetchAll();

// Get all active items with price
$stmt = $db->prepare("SELECT id, name, price FROM items WHERE active = 1 ORDER BY name");
$stmt->execute();
$items = $stmt->fetchAll();

$stmt = $db->query("SELECT id, name FROM transaction_categories WHERE active = 1 ORDER BY sort_order, name");
$categories = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="outstanding_obligations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zu Forderungen
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">Neue Forderung</h1>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="form-card">
    <input type="hidden" name="action" value="create_obligation">
    
    <div class="form-grid">
        <!-- Receiver Type Toggle -->
        <div style="grid-column: 1 / -1; margin-bottom: 1rem;">
            <label style="display: block; margin-bottom: 0.5rem; font-weight: bold;">Empfänger-Typ</label>
            <div style="display: flex; gap: 1rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="radio" name="receiver_type" value="member" checked onchange="toggleReceiverType()">
                    <span>Mitglied</span>
                </label>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="radio" name="receiver_type" value="non-member" onchange="toggleReceiverType()">
                    <span>Externe Person</span>
                </label>
            </div>
        </div>
        
        <!-- Member Receiver Section -->
        <div id="member-receiver" class="form-group">
            <label for="member_id">Empfänger (Mitglied) *</label>
            <select id="member_id" name="member_id">
                <option value="">-- Bitte wählen --</option>
                <?php foreach ($members as $member): ?>
                    <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Non-Member Receiver Section (hidden by default) -->
        <div id="non-member-receiver" style="display: none; grid-column: 1 / -1;">
            <div class="form-group">
                <label for="receiver_name">Name des Empfängers *</label>
                <input type="text" id="receiver_name" name="receiver_name">
            </div>
            
            <div class="form-grid" style="grid-column: 1 / -1;">
                <div class="form-group">
                    <label for="receiver_phone">Telefon</label>
                    <input type="tel" id="receiver_phone" name="receiver_phone">
                </div>
                
                <div class="form-group">
                    <label for="receiver_email">E-Mail</label>
                    <input type="email" id="receiver_email" name="receiver_email">
                </div>
            </div>
        </div>
        
        <!-- Organizing Member (optional) -->
        <div class="form-group">
            <label for="organizing_member_id">Organisierendes Mitglied (optional)</label>
            <select id="organizing_member_id" name="organizing_member_id">
                <option value="">-- Keine --</option>
                <?php foreach ($members as $member): ?>
                    <option value="<?= $member['id'] ?>"><?= htmlspecialchars($member['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="due_date">Zahlungsfrist</label>
            <input type="date" id="due_date" name="due_date">
        </div>

        <div class="form-group">
            <label for="manual_amount">Betrag der Forderung *</label>
            <input type="text" id="manual_amount" name="manual_amount" inputmode="decimal" placeholder="0,00" oninput="normalizeGermanAmountInput(this); updateTotalSum()">
            <small>Bitte im Format 12,34 eingeben. Wird verwendet, wenn keine Artikel ausgewählt werden.</small>
        </div>

        <div class="form-group">
            <label for="category_id">Kategorie</label>
            <select id="category_id" name="category_id">
                <option value="">-- Optional wählen --</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
            <label for="notes">Beschreibung / Notizen</label>
            <textarea id="notes" name="notes" rows="3" placeholder="z. B. Teilnahmebeitrag, Materialkosten oder sonstige Forderung"></textarea>
        </div>

        <div class="form-group" style="grid-column: 1 / -1;">
            <label for="documents">Dokumente / Belege optional</label>
            <input type="file" id="documents" name="documents[]" class="form-control" accept="image/*,application/pdf" multiple>
            <small>Optional für positive Forderungen. Erlaubt sind PDF, JPG, PNG und WEBP bis 5MB pro Datei.</small>
            <div id="file-preview" class="file-preview" style="margin-top: 0.75rem;"></div>
        </div>
        
        <!-- Items Selection -->
        <div style="grid-column: 1 / -1;">
            <h3 style="margin-top: 2rem; margin-bottom: 0.35rem;">Optionale Artikelverknüpfung</h3>
            <p style="margin: 0 0 1rem 0; color: #666;">Wenn passende Artikel existieren, können Sie diese zusätzlich verknüpfen. Andernfalls wird nur die allgemeine Forderung angelegt.</p>
        </div>
        
        <div class="table-responsive" style="grid-column: 1 / -1;">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Artikel</th>
                        <th>Preis pro Stück</th>
                        <th style="width: 120px;">Menge</th>
                        <th style="width: 150px; text-align: right;">Summe</th>
                    </tr>
                </thead>
                <tbody id="items-tbody">
                    <?php foreach ($items as $item): ?>
                        <tr class="item-row" data-item-id="<?= $item['id'] ?>" data-price="<?= $item['price'] ?>">
                            <td>
                                <input type="checkbox" name="item_select" value="<?= $item['id'] ?>" 
                                       onchange="toggleItemRow(this)">
                            </td>
                            <td><?= htmlspecialchars($item['name']) ?></td>
                            <td><?= number_format($item['price'], 2, ',', '.') ?> €</td>
                            <td>
                                <input type="number" name="items[<?= $item['id'] ?>]" 
                                       class="item-qty" value="1" min="0" step="1"
                                       onchange="updateRowSum(this)" disabled>
                            </td>
                            <td style="text-align: right;">
                                <span class="item-sum">0,00 €</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary -->
        <div style="grid-column: 1 / -1; text-align: right; margin-top: 2rem; padding-top: 1rem; border-top: 2px solid #ddd;">
            <h3>Gesamtsumme: <span id="total-sum">0,00 €</span></h3>
        </div>
        
        <!-- Submit -->
        <div class="form-actions" style="grid-column: 1 / -1; margin-top: 2rem;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Allgemeine Forderung erstellen
            </button>
            <a href="outstanding_obligations.php" class="btn btn-secondary">Abbrechen</a>
        </div>
    </div>
</form>

<script>
function normalizeGermanAmountInput(input) {
    if (!input) return;
    let value = String(input.value || '');
    value = value.replace(/\./g, ',');
    value = value.replace(/[^0-9,]/g, '');

    const firstComma = value.indexOf(',');
    if (firstComma !== -1) {
        const before = value.slice(0, firstComma + 1);
        const after = value.slice(firstComma + 1).replace(/,/g, '').slice(0, 2);
        value = before + after;
    }

    input.value = value;
}

function parseGermanAmount(value) {
    return parseFloat(String(value || '0').replace(/\./g, '').replace(',', '.')) || 0;
}

function formatGermanCurrency(value) {
    return Number(value || 0).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}

function toggleReceiverType() {
    const memberType = document.querySelector('input[name="receiver_type"]:checked').value;
    const memberSection = document.getElementById('member-receiver');
    const nonMemberSection = document.getElementById('non-member-receiver');
    
    if (memberType === 'member') {
        memberSection.style.display = 'block';
        nonMemberSection.style.display = 'none';
        document.getElementById('member_id').required = true;
        document.getElementById('receiver_name').required = false;
    } else {
        memberSection.style.display = 'none';
        nonMemberSection.style.display = 'block';
        document.getElementById('member_id').required = false;
        document.getElementById('receiver_name').required = true;
    }
}

function toggleItemRow(checkbox) {
    const row = checkbox.closest('.item-row');
    const input = row.querySelector('.item-qty');
    
    if (checkbox.checked) {
        input.disabled = false;
        updateRowSum(input);
    } else {
        input.disabled = true;
        input.value = 1;
        row.querySelector('.item-sum').textContent = '0,00 €';
    }
    updateTotalSum();
}

function updateRowSum(input) {
    const row = input.closest('.item-row');
    const price = parseFloat(row.dataset.price);
    const qty = parseInt(input.value) || 0;
    const sum = price * qty;
    
    row.querySelector('.item-sum').textContent = formatGermanCurrency(sum);
    
    updateTotalSum();
}

function updateTotalSum() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const input = row.querySelector('.item-qty');
        if (!input.disabled && input.value) {
            const price = parseFloat(row.dataset.price);
            const qty = parseInt(input.value) || 0;
            total += price * qty;
        }
    });

    const manualInput = document.getElementById('manual_amount');
    const manualAmount = parseGermanAmount(manualInput?.value || '0');
    const effectiveTotal = total > 0 ? total : manualAmount;
    
    document.getElementById('total-sum').textContent = formatGermanCurrency(effectiveTotal);
}

document.addEventListener('DOMContentLoaded', function() {
    const manualInput = document.getElementById('manual_amount');
    if (manualInput) {
        manualInput.addEventListener('blur', function() {
            const parsed = parseGermanAmount(this.value);
            this.value = parsed > 0 ? parsed.toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) : '';
            updateTotalSum();
        });
    }

    const fileInput = document.getElementById('documents');
    const preview = document.getElementById('file-preview');
    if (fileInput && preview) {
        fileInput.addEventListener('change', function() {
            preview.innerHTML = '';
            Array.from(this.files || []).forEach(function(file) {
                const item = document.createElement('div');
                item.className = 'file-preview-item';
                item.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                preview.appendChild(item);
            });
        });
    }

    updateTotalSum();
});
</script>

<?php include 'includes/footer.php'; ?>
