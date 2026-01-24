<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('outstanding_obligations.php')) {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();
$message = '';
$error = '';

// Handle form submission for creating obligation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_obligation') {
    try {
        $receiver_type = $_POST['receiver_type']; // 'member' or 'non-member'
        $member_id = ($receiver_type === 'member') ? $_POST['member_id'] : null;
        $receiver_name = ($receiver_type === 'non-member') ? $_POST['receiver_name'] : null;
        $receiver_phone = $_POST['receiver_phone'] ?? null;
        $receiver_email = $_POST['receiver_email'] ?? null;
        $organizing_member_id = $_POST['organizing_member_id'] ?? null;
        $due_date = $_POST['due_date'] ?? null;
        $notes = $_POST['notes'] ?? null;
        
        // Validate
        if ($receiver_type === 'member' && !$member_id) {
            throw new Exception('Bitte wählen Sie ein Mitglied aus.');
        }
        if ($receiver_type === 'non-member' && !$receiver_name) {
            throw new Exception('Bitte geben Sie den Namen des Empfängers ein.');
        }
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Mindestens ein Artikel muss ausgewählt werden.');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Calculate total amount and validate items
        $total_amount = 0;
        $obligation_items = [];
        
        foreach ($_POST['items'] as $item_id => $qty) {
            $qty = (int)$qty;
            if ($qty <= 0) continue;
            
            // Get item details
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
        
        if ($total_amount <= 0) {
            throw new Exception('Gesamtbetrag muss größer als 0 sein.');
        }
        
        // Create obligation
        $stmt = $db->prepare("INSERT INTO item_obligations 
                              (member_id, receiver_name, receiver_phone, receiver_email, organizing_member_id, 
                               total_amount, status, notes, due_date, created_by)
                              VALUES (:member_id, :receiver_name, :receiver_phone, :receiver_email, :organizing_member_id, 
                                      :total_amount, 'open', :notes, :due_date, :created_by)");
        $stmt->execute([
            ':member_id' => $member_id,
            ':receiver_name' => $receiver_name,
            ':receiver_phone' => $receiver_phone,
            ':receiver_email' => $receiver_email,
            ':organizing_member_id' => $organizing_member_id ?: null,
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
        
        $db->commit();
        $message = 'Forderung erfolgreich erstellt.';
        
    } catch (Exception $e) {
        $db->rollBack();
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

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="outstanding_obligations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zu Forderungen
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">Neue Artikel-Forderung</h1>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<form method="POST" class="form-card">
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
        
        <div class="form-group" style="grid-column: 1 / -1;">
            <label for="notes">Notizen</label>
            <textarea id="notes" name="notes" rows="3"></textarea>
        </div>
        
        <!-- Items Selection -->
        <div style="grid-column: 1 / -1;">
            <h3 style="margin-top: 2rem; margin-bottom: 1rem;">Artikel</h3>
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
                <i class="fas fa-check"></i> Forderung erstellen
            </button>
            <a href="outstanding_obligations.php" class="btn btn-secondary">Abbrechen</a>
        </div>
    </div>
</form>

<script>
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
    
    row.querySelector('.item-sum').textContent = sum.toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
    
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
    
    document.getElementById('total-sum').textContent = total.toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}
</script>

<?php include 'includes/footer.php'; ?>
