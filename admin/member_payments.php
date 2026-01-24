<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('kontofuehrung.php')) {
    header('Location: login.php');
    exit;
}

$member_id = $_GET['id'] ?? null;
if (!$member_id) {
    header('Location: members.php');
    exit;
}

$db = getDBConnection();
$member = get_member($member_id);

if (!$member) {
    header('Location: members.php');
    exit;
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate_obligation') {
        $fee_year = $_POST['fee_year'];
        $fee = get_current_membership_fee($member['member_type'], "{$fee_year}-01-01");
        
        if (!$fee) {
            $error = 'Kein Beitragssatz für dieses Jahr definiert.';
        } else {
            // Check if already exists
            $stmt = $db->prepare("SELECT id FROM member_fee_obligations WHERE member_id = :member_id AND fee_year = :year");
            $stmt->execute(['member_id' => $member_id, 'year' => $fee_year]);
            if ($stmt->fetch()) {
                $error = 'Beitragsforderung für dieses Jahr existiert bereits.';
            } else {
                $stmt = $db->prepare("INSERT INTO member_fee_obligations 
                                     (member_id, fee_year, fee_amount, generated_date, due_date, created_by)
                                     VALUES (:member_id, :year, :amount, :generated_date, :due_date, :created_by)");
                $stmt->execute([
                    'member_id' => $member_id,
                    'year' => $fee_year,
                    'amount' => $fee['minimum_amount'],
                    'generated_date' => date('Y-m-d'),
                    'due_date' => "{$fee_year}-03-31",
                    'created_by' => $_SESSION['user_id']
                ]);
                $message = 'Beitragsforderung erfolgreich erstellt.';
                $member = get_member($member_id); // Refresh
            }
        }
    } elseif ($_POST['action'] === 'add_payment') {
        $obligation_id = $_POST['obligation_id'];
        $amount = $_POST['amount'];
        $payment_date = $_POST['payment_date'];
        $payment_method = $_POST['payment_method'] ?? null;
        $notes = $_POST['notes'] ?? null;
        
        $result = add_payment_to_obligation($obligation_id, $amount, $payment_date, null, $payment_method, $notes, $_SESSION['user_id']);
        
        if ($result['success']) {
            $message = 'Zahlung erfolgreich hinzugefügt.';
            $member = get_member($member_id); // Refresh
        } else {
            $error = 'Fehler: ' . $result['error'];
        }
    } elseif ($_POST['action'] === 'delete_payment') {
        $payment_id = $_POST['payment_id'];
        
        try {
            $db->beginTransaction();
            
            // Get payment details
            $stmt = $db->prepare("SELECT obligation_id, amount FROM member_payments WHERE id = :id");
            $stmt->execute(['id' => $payment_id]);
            $payment = $stmt->fetch();
            
            if ($payment) {
                // Delete payment
                $stmt = $db->prepare("DELETE FROM member_payments WHERE id = :id");
                $stmt->execute(['id' => $payment_id]);
                
                // Update obligation
                $stmt = $db->prepare("UPDATE member_fee_obligations 
                                     SET paid_amount = paid_amount - :amount,
                                     status = CASE 
                                         WHEN (paid_amount - :amount) <= 0 THEN 'open'
                                         WHEN (paid_amount - :amount) >= fee_amount THEN 'paid'
                                         ELSE 'partial'
                                     END
                                     WHERE id = :obligation_id");
                $stmt->execute([
                    'amount' => $payment['amount'],
                    'obligation_id' => $payment['obligation_id']
                ]);
            }
            
            $db->commit();
            $message = 'Zahlung erfolgreich gelöscht.';
            $member = get_member($member_id); // Refresh
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Fehler beim Löschen: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'cancel_obligation') {
        $obligation_id = $_POST['obligation_id'];
        $cancellation_reason = $_POST['cancellation_reason'] ?? '';
        
        // Update the obligation with cancellation status and reason
        $stmt = $db->prepare("UPDATE member_fee_obligations 
                            SET status = 'cancelled', 
                                notes = CONCAT(COALESCE(notes, ''), 
                                             IF(COALESCE(notes, '') = '', '', '\n---\n'),
                                             'Storniert am ', DATE_FORMAT(NOW(), '%d.%m.%Y %H:%i'), ' durch ', :user_name, '\n', 'Grund: ', :reason)
                            WHERE id = :id AND member_id = :member_id");
        $stmt->execute([
            'id' => $obligation_id,
            'member_id' => $member_id,
            'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'],
            'reason' => $cancellation_reason
        ]);
        $message = 'Forderung erfolgreich storniert.';
        $member = get_member($member_id); // Refresh
    }
}

// Get obligations with payment details
$obligations = [];
if (isset($member['obligations'])) {
    foreach ($member['obligations'] as $obl) {
        $obligation = get_obligation($obl['id']);
        $obligations[] = $obligation;
    }
}

// Get current fee
$current_fee = get_current_membership_fee($member['member_type']);

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="members.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">
            Beitragsforderungen: <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
        </h1>
    </div>
    <button class="btn btn-primary" onclick="showGenerateObligationModal()">
        <i class="fas fa-plus"></i> Forderung erstellen
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Member Info Card -->
<div class="card">
    <div class="card-header">
        <h2>Mitgliedsinformationen</h2>
    </div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item">
                <strong>Typ:</strong>
                <?php if ($member['member_type'] === 'active'): ?>
                    <span class="badge badge-primary">Einsatzeinheit</span>
                <?php else: ?>
                    <span class="badge badge-info">Förderer</span>
                <?php endif; ?>
            </div>
            <div class="info-item">
                <strong>Mitgliedsnummer:</strong>
                <?= htmlspecialchars($member['member_number'] ?? '-') ?>
            </div>
            <div class="info-item">
                <strong>Beitritt:</strong>
                <?= $member['join_date'] ? date('d.m.Y', strtotime($member['join_date'])) : '-' ?>
            </div>
            <div class="info-item">
                <strong>Aktueller Jahresbeitrag:</strong>
                <?= $current_fee ? number_format($current_fee['minimum_amount'], 2, ',', '.') . ' €' : '-' ?>
            </div>
        </div>
    </div>
</div>

<!-- Payment Status by Year -->
<div class="card">
    <div class="card-header">
        <h2>Beitragsforderungen</h2>
    </div>
    <div class="card-body">
        <?php if (empty($obligations)): ?>
            <p style="text-align: center; color: #666;">
                Noch keine Forderungen erstellt. Klicken Sie auf "Forderung erstellen" um eine Jahresforderung anzulegen.
            </p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Jahr</th>
                        <th>Soll</th>
                        <th>Gezahlt</th>
                        <th>Offen</th>
                        <th>Status</th>
                        <th>Fällig</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($obligations as $obl): ?>
                        <tr class="<?= $obl['status'] === 'paid' ? 'paid-row' : ($obl['status'] === 'cancelled' ? 'cancelled-row' : 'unpaid-row') ?>">
                            <td><strong><?= $obl['fee_year'] ?></strong></td>
                            <td><?= number_format($obl['fee_amount'], 2, ',', '.') ?> €</td>
                            <td><?= number_format($obl['paid_amount'], 2, ',', '.') ?> €</td>
                            <td class="<?= $obl['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <strong><?= number_format($obl['outstanding'], 2, ',', '.') ?> €</strong>
                            </td>
                            <td>
                                <?php if ($obl['status'] === 'paid'): ?>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check"></i> Bezahlt
                                    </span>
                                <?php elseif ($obl['status'] === 'partial'): ?>
                                    <span class="badge badge-warning">
                                        <i class="fas fa-clock"></i> Teilzahlung
                                    </span>
                                <?php elseif ($obl['status'] === 'cancelled'): ?>
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-ban"></i> Storniert
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger">
                                        <i class="fas fa-exclamation-triangle"></i> Offen
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $obl['due_date'] ? date('d.m.Y', strtotime($obl['due_date'])) : '-' ?>
                                <?php if ($obl['due_date'] && $obl['status'] !== 'paid' && strtotime($obl['due_date']) < time()): ?>
                                    <br><small class="text-danger"><i class="fas fa-exclamation-circle"></i> Überfällig</small>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <?php if ($obl['status'] !== 'paid' && $obl['status'] !== 'cancelled'): ?>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="showAddPaymentModal(<?= $obl['id'] ?>, <?= $obl['outstanding'] ?>)" 
                                            title="Zahlung hinzufügen">
                                        <i class="fas fa-euro-sign"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-info" 
                                        onclick="showObligationDetails(<?= htmlspecialchars(json_encode($obl)) ?>)" 
                                        title="Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($obl['status'] === 'open' && $obl['paid_amount'] == 0): ?>
                                    <button class="btn btn-sm btn-danger" 
                                            onclick="cancelObligation(<?= $obl['id'] ?>)" 
                                            title="Stornieren">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Obligation Details Modal -->
<div id="obligationDetailsModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2 id="detailsTitle">Forderungsdetails</h2>
            <span class="close" onclick="closeDetailsModal()">&times;</span>
        </div>
        <div id="obligationDetailsContent">
            <!-- Will be filled dynamically -->
        </div>
    </div>
</div>

<!-- Generate Obligation Modal -->
<div id="generateObligationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Beitragsforderung erstellen</h2>
            <span class="close" onclick="closeGenerateModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="generate_obligation">
            
            <div class="form-group">
                <label>Beitragsjahr: *</label>
                <select name="fee_year" required>
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="info-box">
                <p><i class="fas fa-info-circle"></i> Der Beitragssatz wird automatisch basierend auf dem Mitgliedertyp (<?= $member['member_type'] === 'active' ? 'Einsatzeinheit' : 'Förderer' ?>) und dem gewählten Jahr ermittelt.</p>
                <p>Aktueller Beitragssatz: <strong><?= $current_fee ? number_format($current_fee['minimum_amount'], 2, ',', '.') . ' €' : 'Nicht definiert' ?></strong></p>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeGenerateModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Forderung erstellen</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Payment Modal -->
<div id="addPaymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Zahlung hinzufügen</h2>
            <span class="close" onclick="closePaymentModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="obligation_id" id="paymentObligationId">
            
            <div class="form-group">
                <label>Zahlungsdatum: *</label>
                <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <div class="form-group">
                <label>Betrag (€): *</label>
                <input type="number" name="amount" id="paymentAmount" step="0.01" min="0.01" required>
                <small id="outstandingInfo" style="color: #666;"></small>
            </div>
            
            <div class="form-group">
                <label>Zahlungsart:</label>
                <select name="payment_method">
                    <option value="">- Bitte wählen -</option>
                    <option value="Überweisung">Überweisung</option>
                    <option value="Bar">Bar</option>
                    <option value="Lastschrift">Lastschrift</option>
                    <option value="PayPal">PayPal</option>
                    <option value="Sonstige">Sonstige</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Notizen:</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Zahlung speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Obligation Modal -->
<div id="cancelObligationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Forderung stornieren</h2>
            <span class="close" onclick="closeCancelModal()">&times;</span>
        </div>
        <form method="POST" id="cancelObligationForm">
            <input type="hidden" name="action" value="cancel_obligation">
            <input type="hidden" name="obligation_id" id="cancelObligationId">
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> <strong>Warnung:</strong> Diese Aktion kann nicht rückgängig gemacht werden. Die Forderung wird als storniert markiert.
            </div>
            
            <div class="form-group">
                <label>Stornierungsgrund: *</label>
                <textarea name="cancellation_reason" id="cancellationReason" rows="4" placeholder="Bitte geben Sie den Grund für die Stornierung ein..." required></textarea>
                <small style="color: #666;">Ein Grund ist erforderlich für Audit- und Nachverfolgungszwecke.</small>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Abbrechen</button>
                <button type="submit" class="btn btn-danger">Forderung stornieren</button>
            </div>
        </form>
    </div>
</div>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.info-item {
    padding: 0.5rem;
}

.paid-row {
    background-color: #e8f5e9;
}

.unpaid-row {
    background-color: #ffebee;
}

.cancelled-row {
    background-color: #f5f5f5;
    opacity: 0.7;
    text-decoration: line-through;
}

.text-danger {
    color: #f44336;
    font-weight: bold;
}

.text-success {
    color: #4caf50;
    font-weight: bold;
}

.info-box {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 1rem;
    margin: 1rem 0;
}

.info-box p {
    margin: 0.5rem 0;
}

#obligationDetailsContent {
    padding: 1rem;
}

.payment-history {
    margin-top: 1rem;
}

.payment-history h3 {
    color: #d32f2f;
    border-bottom: 2px solid #d32f2f;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
}
</style>

<script>
function showGenerateObligationModal() {
    document.getElementById('generateObligationModal').style.display = 'flex';
}

function closeGenerateModal() {
    document.getElementById('generateObligationModal').style.display = 'none';
}

function showAddPaymentModal(obligationId, outstanding) {
    document.getElementById('paymentObligationId').value = obligationId;
    document.getElementById('paymentAmount').value = outstanding.toFixed(2);
    document.getElementById('outstandingInfo').textContent = 'Offener Betrag: ' + outstanding.toFixed(2).replace('.', ',') + ' €';
    document.getElementById('addPaymentModal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('addPaymentModal').style.display = 'none';
}

function showObligationDetails(obligation) {
    const content = document.getElementById('obligationDetailsContent');
    const payments = obligation.payments || [];
    
    let html = `
        <div class="info-grid">
            <div class="info-item"><strong>Jahr:</strong> ${obligation.fee_year}</div>
            <div class="info-item"><strong>Soll:</strong> ${parseFloat(obligation.fee_amount).toFixed(2).replace('.', ',')} €</div>
            <div class="info-item"><strong>Gezahlt:</strong> ${parseFloat(obligation.paid_amount).toFixed(2).replace('.', ',')} €</div>
            <div class="info-item"><strong>Offen:</strong> ${parseFloat(obligation.outstanding).toFixed(2).replace('.', ',')} €</div>
            <div class="info-item"><strong>Status:</strong> ${getStatusBadge(obligation.status)}</div>
            <div class="info-item"><strong>Fällig:</strong> ${obligation.due_date ? new Date(obligation.due_date).toLocaleDateString('de-DE') : '-'}</div>
        </div>
    `;
    
    if (payments.length > 0) {
        html += '<div class="payment-history"><h3>Zahlungshistorie</h3><table class="data-table"><thead><tr><th>Datum</th><th>Betrag</th><th>Zahlungsart</th><th>Quelle</th><th>Notizen</th><th>Aktion</th></tr></thead><tbody>';
        
        payments.forEach(payment => {
            const canDelete = !payment.transaction_id;
            html += `<tr>
                <td>${new Date(payment.payment_date).toLocaleDateString('de-DE')}</td>
                <td><strong>${parseFloat(payment.amount).toFixed(2).replace('.', ',')} €</strong></td>
                <td>${payment.payment_method || '-'}</td>
                <td>${payment.transaction_id ? '<span class="badge badge-primary"><i class="fas fa-link"></i> Transaktion</span>' : '<span class="badge badge-warning"><i class="fas fa-hand-holding-usd"></i> Manuell</span>'}</td>
                <td>${payment.notes || '-'}</td>
                <td>${canDelete ? '<button class="btn btn-sm btn-danger" onclick="deletePayment(' + payment.id + ')"><i class="fas fa-trash"></i></button>' : '-'}</td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
    }
    
    content.innerHTML = html;
    document.getElementById('obligationDetailsModal').style.display = 'flex';
}

function closeDetailsModal() {
    document.getElementById('obligationDetailsModal').style.display = 'none';
}

function getStatusBadge(status) {
    const badges = {
        'paid': '<span class="badge badge-success"><i class="fas fa-check"></i> Bezahlt</span>',
        'partial': '<span class="badge badge-warning"><i class="fas fa-clock"></i> Teilzahlung</span>',
        'open': '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> Offen</span>',
        'cancelled': '<span class="badge badge-secondary"><i class="fas fa-ban"></i> Storniert</span>'
    };
    return badges[status] || status;
}

function deletePayment(id) {
    if (confirm('Möchten Sie diese Zahlung wirklich löschen? Der Betrag wird von der Forderung abgezogen.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_payment">
            <input type="hidden" name="payment_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function cancelObligation(id) {
    document.getElementById('cancelObligationId').value = id;
    document.getElementById('cancellationReason').value = '';
    document.getElementById('cancelObligationModal').style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancelObligationModal').style.display = 'none';
}

function closePaymentModal() {
    document.getElementById('addPaymentModal').style.display = 'none';
}

function closeDetailsModal() {
    document.getElementById('obligationDetailsModal').style.display = 'none';
}

function closeGenerateModal() {
    document.getElementById('generateObligationModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
