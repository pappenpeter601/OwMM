<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

ensure_expense_request_support();

if (!is_logged_in() || !has_permission('expense_requests.php')) {
    redirect('dashboard.php');
}

$page_title = 'Belege Einreichen';
$db = getDBConnection();
$currentUserId = $_SESSION['user_id'] ?? null;
$currentMember = get_current_member_record($currentUserId);
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_request') {
        $items = [];
        $totalAmount = str_replace(',', '.', (string) ($_POST['total_amount'] ?? '0'));
        if ((float) $totalAmount > 0) {
            $items[] = [
                'description' => trim($_POST['transfer_reference'] ?? '') ?: 'Erstattungsantrag',
                'amount' => $totalAmount
            ];
        }

        $result = create_expense_request([
            'full_name' => $_POST['full_name'] ?? '',
            'iban' => $_POST['iban'] ?? '',
            'transfer_reference' => $_POST['transfer_reference'] ?? '',
            'expense_context' => $_POST['expense_context'] ?? '',
            'total_amount' => $_POST['total_amount'] ?? ''
        ], $items, $_FILES['documents'] ?? [], $currentUserId);

        if ($result['success']) {
            $_SESSION['success'] = 'Ihr Antrag wurde gespeichert. Referenz: ' . $result['reference'];
            header('Location: expense_requests.php');
            exit;
        }

        $error = $result['error'] ?? 'Fehler beim Speichern des Antrags.';
    }

}

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

$myRequests = get_expense_requests(null, $currentUserId, 50);
$requestIds = array_map('intval', array_column($myRequests, 'id'));

$docsByRequest = [];
if (!empty($requestIds)) {
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));

    $stmt = $db->prepare("SELECT * FROM expense_request_documents WHERE expense_request_id IN ($placeholders) ORDER BY uploaded_at DESC");
    $stmt->execute($requestIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $docsByRequest[$doc['expense_request_id']][] = $doc;
    }
}

function expense_request_badge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge badge-success">Genehmigt</span>';
        case 'rejected':
            return '<span class="badge badge-danger">Abgelehnt</span>';
        case 'paid':
            return '<span class="badge badge-primary">Ausgezahlt</span>';
        default:
            return '<span class="badge badge-warning">Eingereicht</span>';
    }
}

include 'includes/header.php';
?>

<div class="page-header request-page-header">
    <div>
        <h1><i class="fas fa-receipt"></i> <?php echo $page_title; ?></h1>
        <p>Auslagen schnell mobil erfassen, Belege hochladen und Erstattung anfordern.</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="mobile-process card">
    <div class="card-body">
        <div class="process-grid">
            <div class="process-step"><strong>1.</strong> Beleg fotografieren oder PDF wählen</div>
            <div class="process-step"><strong>2.</strong> Betrag und Kontext ergänzen</div>
            <div class="process-step"><strong>3.</strong> Antrag absenden</div>
        </div>
    </div>
</div>

<div class="card mobile-request-card">
    <div class="card-header">
        <h2>Neuen Antrag erfassen</h2>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="expense-request-form">
            <input type="hidden" name="action" value="create_request">

            <div class="mobile-form-grid">
                <div class="form-group">
                    <label for="full_name">Name *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required
                           value="<?php echo htmlspecialchars(trim(($currentMember['first_name'] ?? '') . ' ' . ($currentMember['last_name'] ?? ''))); ?>">
                </div>

                <div class="form-group">
                    <label for="iban">IBAN</label>
                    <input type="text" id="iban" name="iban" class="form-control" inputmode="text"
                           placeholder="DE12 3456 7890 1234 5678 90"
                           value="<?php echo htmlspecialchars($currentMember['iban'] ?? ''); ?>">
                    <small>Optional – falls bereits im System hinterlegt, kann das Feld leer bleiben.</small>
                </div>
            </div>

            <div class="mobile-form-grid">
                <div class="form-group">
                    <label for="transfer_reference">Verwendungszweck / Referenz *</label>
                    <input type="text" id="transfer_reference" name="transfer_reference" class="form-control" required maxlength="80"
                           pattern="[A-Za-z0-9\/\-\?:\(\)\.,'\+ ]+"
                           title="Erlaubt sind Buchstaben, Zahlen, Leerzeichen sowie / - ? : ( ) . , ' +"
                           placeholder="Eindeutige Referenz für die Überweisung">
                    <small>Pflichtfeld für den späteren automatischen Abgleich in der Kontoführung.</small>
                </div>

                <div class="form-group">
                    <label for="total_amount">Betrag *</label>
                    <input type="text" id="total_amount" name="total_amount" class="form-control total-amount-input" inputmode="decimal" placeholder="0,00" required>
                    <small>Bitte im Format 12,34 eingeben.</small>
                </div>
            </div>

            <div class="form-group">
                <label for="expense_context">Wofür wurde das Geld ausgegeben? *</label>
                <textarea id="expense_context" name="expense_context" class="form-control" rows="4" required
                          placeholder="Kurz erklären, wofür die Auslage war..."></textarea>
            </div>

            <div class="form-group">
                <label for="documents">Belege hochladen *</label>
                <input type="file" id="documents" name="documents[]" class="form-control" accept="image/*,application/pdf" capture="environment" multiple required>
                <small>Mobil können Sie direkt ein Foto aufnehmen. Erlaubt sind PDF, JPG, PNG und WEBP bis 5MB pro Datei.</small>
                <div id="file-preview" class="file-preview"></div>
            </div>

            <div class="request-total-box">
                <span>Beantragter Betrag</span>
                <strong id="request-total">0,00 €</strong>
            </div>

            <div class="submit-bar">
                <button type="submit" class="btn btn-primary submit-request-btn">
                    <i class="fas fa-paper-plane"></i> Antrag absenden
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Meine Anträge</h2>
    </div>
    <div class="card-body">
        <?php if (empty($myRequests)): ?>
            <p class="text-muted">Sie haben noch keine Anträge eingereicht.</p>
        <?php else: ?>
            <p class="text-muted" style="margin-bottom: 1rem;">Hier sehen Sie nur Ihre eigenen Anträge. Die Freigabe und Auszahlung ist ausschließlich für die Buchhaltung sichtbar.</p>
            <div class="request-list">
                <?php foreach ($myRequests as $request): ?>
                    <div class="request-item-card">
                        <div class="request-item-head">
                            <div>
                                <strong><?php echo htmlspecialchars($request['transfer_reference']); ?></strong><br>
                                <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($request['created_at'])); ?></small>
                            </div>
                            <?php echo expense_request_badge($request['status']); ?>
                        </div>
                        <div class="request-item-body">
                            <div><strong>Betrag:</strong> <?php echo number_format($request['requested_amount_total'], 2, ',', '.'); ?> €</div>
                            <div><strong>Belege:</strong> <?php echo (int) ($request['document_count'] ?? 0); ?></div>
                            <div class="request-context"><?php echo nl2br(htmlspecialchars($request['expense_context'])); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>



<style>
.request-page-header {
    align-items: flex-start;
}

.mobile-process {
    margin-bottom: 1rem;
}

.process-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
}

.process-step {
    background: #f8fafc;
    border-left: 4px solid #1976d2;
    padding: 0.9rem 1rem;
    border-radius: 6px;
}

.mobile-form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.9rem;
}

.btn-block-mobile,
.submit-request-btn {
    width: 100%;
}

.file-preview {
    margin-top: 0.75rem;
    display: grid;
    gap: 0.4rem;
}

.file-preview-item {
    background: #f8fafc;
    padding: 0.6rem 0.75rem;
    border-radius: 6px;
    font-size: 0.9rem;
}

.request-total-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #eef6ff;
    border: 1px solid #d8e7fb;
    border-radius: 8px;
    padding: 0.9rem 1rem;
    margin-top: 1rem;
    font-size: 1rem;
}

.submit-bar {
    position: sticky;
    bottom: 0;
    background: linear-gradient(to top, #fff 75%, rgba(255,255,255,0.85));
    padding-top: 0.9rem;
    margin-top: 1rem;
}

.finance-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.75rem;
}

.summary-box {
    border-radius: 8px;
    padding: 0.9rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.summary-box strong {
    font-size: 1.3rem;
}

.summary-open { background: #fff8e1; }
.summary-approved { background: #e8f5e9; }
.summary-paid { background: #e3f2fd; }
.summary-rejected { background: #ffebee; }

.request-list {
    display: grid;
    gap: 1rem;
}

.request-item-card {
    border: 1px solid #e0e7ef;
    border-radius: 8px;
    padding: 1rem;
    background: #fff;
}

.request-item-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.request-item-body {
    display: grid;
    gap: 0.5rem;
}

.request-context {
    padding: 0.75rem;
    background: #f8fafc;
    border-radius: 6px;
}

.doc-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.doc-chip {
    display: inline-block;
    padding: 0.4rem 0.65rem;
    background: #eef6ff;
    border-radius: 999px;
    text-decoration: none;
}

.admin-action-form {
    display: grid;
    gap: 0.75rem;
    margin-top: 0.5rem;
}

.admin-action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.badge-primary {
    background: #1976d2;
    color: white;
}

@media (min-width: 768px) {
    .mobile-form-grid {
        grid-template-columns: 1fr 1fr;
    }

    .process-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 640px) {
    .request-item-head {
        flex-direction: column;
    }
}
</style>

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

function updateTotal() {
    const totalInput = document.getElementById('total_amount');
    const total = parseGermanAmount(totalInput ? totalInput.value : '0');

    document.getElementById('request-total').textContent = total.toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}

document.addEventListener('DOMContentLoaded', function() {
    const totalInput = document.getElementById('total_amount');
    if (totalInput) {
        totalInput.addEventListener('input', function() {
            normalizeGermanAmountInput(this);
            updateTotal();
        });
        totalInput.addEventListener('blur', function() {
            const amount = parseGermanAmount(this.value);
            this.value = amount > 0 ? amount.toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) : '';
            updateTotal();
        });
    }
    updateTotal();

    const referenceInput = document.getElementById('transfer_reference');
    if (referenceInput) {
        referenceInput.addEventListener('input', function() {
            this.value = this.value
                .replace(/[^A-Za-z0-9\/?:().,'+\- ]+/g, '')
                .replace(/\s{2,}/g, ' ')
                .trimStart();
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
});
</script>

<?php include 'includes/footer.php'; ?>
