<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/EmailService.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check permissions
if (!is_logged_in() || !has_permission('payment_reminders.php')) {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();
$message = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Load possible contact persons (active members)
$contactPersonsStmt = $db->query("SELECT id, first_name, last_name, email, mobile, telephone FROM members WHERE active = 1 AND member_type = 'active' ORDER BY last_name, first_name");
$contactPersons = $contactPersonsStmt->fetchAll(PDO::FETCH_ASSOC);
$contactMemberId = $_POST['contact_member_id'] ?? '';

// Handle bulk send action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_bulk') {
    $selectedMembers = $_POST['selected_members'] ?? [];
    $contactMemberId = $_POST['contact_member_id'] ?? '';
    $year = $_POST['year'] ?? date('Y');
    
    if (empty($selectedMembers)) {
        $error = 'Bitte wählen Sie mindestens ein Mitglied aus.';
    } elseif (empty($contactMemberId)) {
        $error = 'Bitte wählen Sie eine Ansprechpartnerin / einen Ansprechpartner aus der Einsatzeinheit.';
    } else {
        try {
            // Validate contact person exists and is active
            $contactStmt = $db->prepare("SELECT id, first_name, last_name, email, mobile, telephone FROM members WHERE id = :id AND active = 1 AND member_type = 'active'");
            $contactStmt->execute(['id' => $contactMemberId]);
            $contactPerson = $contactStmt->fetch(PDO::FETCH_ASSOC);

            if (!$contactPerson) {
                throw new Exception('Ausgewählter Ansprechpartner ist nicht gültig.');
            }
            
            // Use contact person's email as CC
            $ccEmail = $contactPerson['email'];
            if (empty($ccEmail)) {
                throw new Exception('Ausgewählter Ansprechpartner hat keine E-Mail-Adresse.');
            }
            
            // Get member and obligation data for selected members
            $placeholders = str_repeat('?,', count($selectedMembers) - 1) . '?';
            $stmt = $db->prepare("
                SELECT 
                    m.id, m.first_name, m.last_name, m.salutation, m.email, 
                    m.iban, m.member_number, m.member_type,
                    o.id as obligation_id, o.fee_year, o.fee_amount, o.paid_amount,
                    o.status, o.due_date,
                    (SELECT MAX(mp.payment_date) FROM member_payments mp WHERE mp.obligation_id = o.id) as last_payment_date,
                    (SELECT MAX(mp.payment_date) FROM member_payments mp INNER JOIN member_fee_obligations mfo ON mp.obligation_id = mfo.id WHERE mfo.member_id = m.id) as member_last_payment_date
                FROM members m
                INNER JOIN member_fee_obligations o ON m.id = o.member_id
                WHERE o.id IN ($placeholders)
                  AND m.active = 1
            ");
            $stmt->execute($selectedMembers);
            $membersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Prepare data for bulk send
            $membersWithObligations = [];
            
            // Get PayPal link from organization settings
            $orgStmt = $db->query("SELECT paypal_link FROM organization WHERE id = 1");
            $org = $orgStmt->fetch(PDO::FETCH_ASSOC);
            $paypalLink = $org['paypal_link'] ?? '';
            
            foreach ($membersData as $row) {
                // Generate PayPal payment link with amount
                $paypalPaymentLink = '';
                if ($paypalLink) {
                    $paypalPaymentLink = $paypalLink . '/' . number_format($row['fee_amount'], 2);
                }
                
                $membersWithObligations[] = [
                    'member' => [
                        'id' => $row['id'],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'salutation' => $row['salutation'],
                        'email' => $row['email'],
                        'iban' => $row['iban'],
                        'member_number' => $row['member_number'],
                        'member_type' => $row['member_type']
                    ],
                    'obligation' => [
                        'id' => $row['obligation_id'],
                        'fee_year' => $row['fee_year'],
                        'fee_amount' => $row['fee_amount'],
                        'paid_amount' => $row['paid_amount'],
                        'status' => $row['status'],
                        'due_date' => $row['due_date'],
                        'last_payment_date' => $row['member_last_payment_date'] ?? $row['last_payment_date']
                    ],
                    'paypal_link' => $paypalPaymentLink,
                    'qr_code_url' => $paypalPaymentLink ? 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paypalPaymentLink) : ''
                ];
            }
            
            // Send bulk reminders
            $emailService = new EmailService();
            $results = $emailService->sendBulkPaymentReminders(
                $membersWithObligations, 
                $ccEmail, 
                $_SESSION['user_id'] ?? null, 
                'first',
                $contactPerson
            );
            
            $message = sprintf(
                'Versand abgeschlossen: %d erfolgreich, %d fehlgeschlagen, %d übersprungen',
                $results['success'],
                $results['failed'],
                $results['skipped']
            );
            
        } catch (Exception $e) {
            $error = 'Fehler beim Versenden: ' . $e->getMessage();
        }
    }
}

// Get filter parameters
$year = $_GET['year'] ?? date('Y');
$statusFilter = $_GET['status'] ?? 'open_partial'; // open_partial, open, partial, all
$memberTypeFilter = $_GET['member_type'] ?? 'all'; // all, active, supporter, pensioner
$search = $_GET['search'] ?? '';
$tab = $_GET['tab'] ?? 'send'; // send or history

// Get members with open/partial obligations for selected year
$statusConditions = [
    'open_partial' => "o.status IN ('open', 'partial')",
    'open' => "o.status = 'open'",
    'partial' => "o.status = 'partial'",
    'all' => "o.status IN ('open', 'partial', 'paid')"
];
$statusWhere = $statusConditions[$statusFilter] ?? $statusConditions['open_partial'];

$sql = "
    SELECT 
        m.id, m.first_name, m.last_name, m.email, m.member_number, m.member_type,
        o.id as obligation_id, o.fee_year, o.fee_amount, o.paid_amount,
        (o.fee_amount - o.paid_amount) as outstanding,
        o.status, o.due_date,
        (SELECT MAX(mp.payment_date) FROM member_payments mp WHERE mp.obligation_id = o.id) as last_payment_date,
        (SELECT MAX(mp.payment_date) FROM member_payments mp INNER JOIN member_fee_obligations mfo ON mp.obligation_id = mfo.id WHERE mfo.member_id = m.id) as member_last_payment_date,
        (SELECT COUNT(*) FROM payment_reminders pr WHERE pr.obligation_id = o.id AND pr.success = 1) as reminder_count,
        (SELECT MAX(pr.sent_at) FROM payment_reminders pr WHERE pr.obligation_id = o.id AND pr.success = 1) as last_reminder_sent
    FROM members m
    INNER JOIN member_fee_obligations o ON m.id = o.member_id
    WHERE o.fee_year = :year
      AND $statusWhere
      AND m.active = 1
";

$params = ['year' => $year];

// Add member_type filter
if ($memberTypeFilter !== 'all') {
    $sql .= " AND m.member_type = :member_type";
    $params['member_type'] = $memberTypeFilter;
}

if (!empty($search)) {
    $searchTerm = '%' . strtolower($search) . '%';
    $sql .= " AND (LOWER(m.first_name) LIKE :search_first OR LOWER(m.last_name) LIKE :search_last OR LOWER(m.member_number) LIKE :search_member)";
    $params['search_first'] = $searchTerm;
    $params['search_last'] = $searchTerm;
    $params['search_member'] = $searchTerm;
}

$sql .= " ORDER BY m.last_name, m.first_name";

$stmt = $db->prepare($sql);
try {
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Payment Reminders Query Error: " . $e->getMessage());
    error_log("SQL: " . $sql);
    error_log("Params: " . json_encode($params));
    throw $e;
}

// Calculate statistics
$totalOutstanding = array_sum(array_column($members, 'outstanding'));
$membersWithEmail = array_filter($members, fn($m) => !empty($m['email']));
$membersWithoutEmail = count($members) - count($membersWithEmail);

// Get reminder history if on history tab
$reminderHistory = [];
if ($tab === 'history') {
    $historyYear = $_GET['history_year'] ?? date('Y');
    $stmt = $db->prepare("
        SELECT 
            pr.*,
            m.first_name, m.last_name, m.member_number, m.email as member_email,
            o.fee_year, o.fee_amount, o.paid_amount,
            u.username as sent_by_name
        FROM payment_reminders pr
        INNER JOIN members m ON pr.member_id = m.id
        INNER JOIN member_fee_obligations o ON pr.obligation_id = o.id
        LEFT JOIN users u ON pr.sent_by = u.id
        WHERE o.fee_year = :year
        ORDER BY pr.sent_at DESC
        LIMIT 200
    ");
    $stmt->execute(['year' => $historyYear]);
    $reminderHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Zahlungserinnerungen';
include 'includes/header.php';
?>

<style>
.tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
}
.tab-button {
    padding: 10px 20px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 16px;
    color: #666;
    transition: all 0.3s;
}
.tab-button.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
    font-weight: bold;
}
.tab-button:hover {
    color: var(--primary-color);
}
.member-list-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    margin-bottom: 8px;
    background: white;
    transition: all 0.2s;
}
.member-list-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.member-list-item.no-email {
    background: #fff3cd;
    border-color: #ffc107;
}
.member-checkbox {
    margin-right: 15px;
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.member-info {
    flex: 1;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 100px;
    gap: 15px;
    align-items: center;
}
.member-name {
    font-weight: bold;
    color: #333;
}
.member-type-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}
.member-type-active {
    background: #3b82f6;
    color: white;
}
.member-type-supporter {
    background: #10b981;
    color: white;
}
.email-status {
    font-size: 24px;
}
.email-status.has-email {
    color: #10b981;
}
.email-status.no-email {
    color: #f59e0b;
}
.outstanding-amount {
    color: #dc2626;
    font-weight: bold;
    font-size: 16px;
}
.selection-summary {
    background: #eff6ff;
    border: 2px solid #3b82f6;
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
}
.selection-summary strong {
    color: #1e40af;
}
.preview-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    overflow-y: auto;
}
.preview-modal-content {
    background: white;
    margin: 50px auto;
    padding: 30px;
    max-width: 800px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}
.preview-email-sample {
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 20px;
    background: #f8f8f8;
    max-height: 400px;
    overflow-y: auto;
    margin: 15px 0;
}
.reminder-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    background: #6b7280;
    color: white;
}
.btn:disabled, .btn-primary:disabled, .btn-large:disabled {
    background-color: #d1d5db !important;
    color: #9ca3af !important;
    cursor: not-allowed !important;
    opacity: 0.6;
}
</style>

<div class="content-header">
    <h1><i class="fas fa-envelope"></i> Zahlungserinnerungen</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-button <?= $tab === 'send' ? 'active' : '' ?>" onclick="window.location.href='?tab=send'">
        <i class="fas fa-paper-plane"></i> Erinnerungen versenden
    </button>
    <button class="tab-button <?= $tab === 'history' ? 'active' : '' ?>" onclick="window.location.href='?tab=history'">
        <i class="fas fa-history"></i> Versand-Historie
    </button>
</div>

<?php if ($tab === 'send'): ?>
<!-- Send Tab -->
<div class="card">
    <div class="card-header">
        <h2>Zahlungserinnerungen versenden</h2>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="filter-row" style="margin-bottom: 25px;">
            <input type="hidden" name="tab" value="send">
            <div class="form-group" style="margin: 0;">
                <label>Jahr:</label>
                <select name="year" onchange="this.form.submit()" style="width: auto;">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin: 0;">
                <label>Status:</label>
                <select name="status" onchange="this.form.submit()" style="width: auto;">
                    <option value="open_partial" <?= $statusFilter === 'open_partial' ? 'selected' : '' ?>>Offen & Teilzahlung</option>
                    <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Nur Offen</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Nur Teilzahlung</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                </select>
            </div>
            
            <div class="form-group" style="margin: 0;">
                <label>Mitgliedstyp:</label>
                <select name="member_type" onchange="this.form.submit()" style="width: auto;">
                    <option value="all" <?= $memberTypeFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                    <option value="active" <?= $memberTypeFilter === 'active' ? 'selected' : '' ?>>Einsatzeinheit</option>
                    <option value="supporter" <?= $memberTypeFilter === 'supporter' ? 'selected' : '' ?>>Förderer</option>
                    <option value="pensioner" <?= $memberTypeFilter === 'pensioner' ? 'selected' : '' ?>>Altersabteilung</option>
                </select>
            </div>
            
            <div class="form-group" style="margin: 0; flex: 1;">
                <label>Suche:</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name oder Mitgliedsnummer...">
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Filtern
            </button>
        </form>
        
        <!-- Statistics -->
        <div class="info-box info" style="margin-bottom: 20px;">
            <p style="margin: 0;">
                <strong><?= count($members) ?></strong> Mitglieder gefunden | 
                <strong style="color: #dc2626;"><?= number_format($totalOutstanding, 2, ',', '.') ?> €</strong> ausstehend |
                <strong style="color: #10b981;"><?= count($membersWithEmail) ?></strong> mit E-Mail |
                <?php if ($membersWithoutEmail > 0): ?>
                    <strong style="color: #f59e0b;"><?= $membersWithoutEmail ?></strong> ohne E-Mail
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Bulk Actions Form -->
        <form method="POST" id="bulkSendForm">
            <input type="hidden" name="action" value="send_bulk">
            <input type="hidden" name="year" value="<?= $year ?>">
            
            <!-- Contact Person Selection -->
            <div class="form-group">
                <label for="contact_member_id">
                    <i class="fas fa-user-shield"></i> Ansprechpartner (Einsatzeinheit, Pflicht):
                </label>
                <select id="contact_member_id" name="contact_member_id" required style="max-width: 420px;" onchange="updateSelectionCount()">
                    <option value="">-- Bitte auswählen --</option>
                    <?php foreach ($contactPersons as $cp): ?>
                        <option value="<?= $cp['id'] ?>" <?= (!empty($contactMemberId) && $contactMemberId == $cp['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cp['last_name'] . ', ' . $cp['first_name']) ?>
                            <?php if (!empty($cp['mobile'])): ?> (Mobil: <?= htmlspecialchars($cp['mobile']) ?>)<?php endif; ?>
                            <?php if (!empty($cp['email'])): ?> (E-Mail: <?= htmlspecialchars($cp['email']) ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #666; display: block; margin-top: 5px;">
                    Diese Person wird in der E-Mail als Kontakt angegeben und erhält eine Kopie aller versendeten E-Mails.
                </small>
            </div>
            
            <!-- Selection Controls -->
            <div style="margin: 15px 0; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <label style="margin: 0; cursor: pointer;">
                    <input type="checkbox" id="selectAll" onclick="toggleAll(this)"> 
                    <strong>Alle auswählen</strong>
                </label>
                <button type="button" class="btn btn-secondary btn-sm" onclick="selectOnlyWithEmail()">
                    <i class="fas fa-envelope"></i> Nur mit E-Mail
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="selectOnlyWithoutEmail()">
                    <i class="fas fa-print"></i> Nur ohne E-Mail (zum Drucken)
                </button>
                <span id="selectionCount" style="color: #666; margin-left: auto;">
                    0 ausgewählt
                </span>
            </div>
            
            <!-- Member List -->
            <div style="max-height: 500px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px;">
                <?php if (empty($members)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">
                        Keine Mitglieder mit offenen Forderungen gefunden.
                    </p>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                        <div class="member-list-item <?= empty($member['email']) ? 'no-email' : '' ?>">
                            <input type="checkbox" 
                                   class="member-checkbox" 
                                   name="selected_members[]" 
                                   value="<?= $member['obligation_id'] ?>"
                                   data-has-email="<?= empty($member['email']) ? '0' : '1' ?>"
                                   onchange="updateSelectionCount()">
                            
                            <div class="member-info">
                                <div>
                                    <div class="member-name">
                                        <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                                    </div>
                                    <small style="color: #666;">
                                        Mitgl.-Nr: <?= htmlspecialchars($member['member_number'] ?? '-') ?>
                                    </small>
                                    <?php if ($member['reminder_count'] > 0): ?>
                                        <br>
                                        <span class="reminder-badge" title="Bereits <?= $member['reminder_count'] ?>x erinnert">
                                            <?= $member['reminder_count'] ?>x erinnert
                                            <?php if ($member['last_reminder_sent']): ?>
                                                (<?= date('d.m.Y', strtotime($member['last_reminder_sent'])) ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <span class="member-type-badge <?= $member['member_type'] === 'active' ? 'member-type-active' : 'member-type-supporter' ?>">
                                        <?= $member['member_type'] === 'active' ? 'Einsatzeinheit' : 'Förderer' ?>
                                    </span>
                                </div>
                                
                                <div class="outstanding-amount">
                                    <?= number_format($member['outstanding'], 2, ',', '.') ?> €
                                </div>
                                
                                <div style="font-size: 12px; color: #666;">
                                    <?php if ($member['member_last_payment_date']): ?>
                                        Letzte Zahlung:<br>
                                        <?= date('d.m.Y', strtotime($member['member_last_payment_date'])) ?>
                                    <?php else: ?>
                                        Keine Zahlung
                                    <?php endif; ?>
                                </div>
                                
                                <div class="email-status <?= empty($member['email']) ? 'no-email' : 'has-email' ?>" 
                                     title="<?= empty($member['email']) ? 'Keine E-Mail-Adresse' : htmlspecialchars($member['email']) ?>">
                                    <?= empty($member['email']) ? '⚠️' : '✉️' ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Send Button -->
            <div style="margin-top: 20px; text-align: center; display: flex; gap: 15px; justify-content: center;">
                <button type="button" class="btn btn-secondary btn-large" onclick="printLetters()" id="printButton" disabled>
                    <i class="fas fa-print"></i> Briefe drucken
                </button>
                <button type="button" class="btn btn-primary btn-large" onclick="showPreview()" id="previewButton" disabled>
                    <i class="fas fa-eye"></i> Vorschau & Versenden
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="preview-modal">
    <div class="preview-modal-content">
        <h2><i class="fas fa-eye"></i> Vorschau: Zahlungserinnerung</h2>
        
        <div id="previewSummary" class="selection-summary">
            <!-- Will be populated by JavaScript -->
        </div>
        
        <div id="previewWarnings">
            <!-- Will be populated by JavaScript -->
        </div>
        
        <div>
            <h3>Beispiel-E-Mail:</h3>
            <div id="previewEmailSample" class="preview-email-sample">
                <!-- Will be populated by JavaScript -->
            </div>
        </div>
        
        <div style="margin-top: 20px; text-align: center; display: flex; gap: 15px; justify-content: center;">
            <button type="button" class="btn btn-secondary" onclick="closePreview()">
                <i class="fas fa-times"></i> Abbrechen
            </button>
            <button type="button" class="btn btn-primary btn-large" onclick="confirmSend()">
                <i class="fas fa-paper-plane"></i> Jetzt versenden
            </button>
        </div>
    </div>
</div>

<?php elseif ($tab === 'history'): ?>
<!-- History Tab -->
<div class="card">
    <div class="card-header">
        <h2>Versand-Historie</h2>
    </div>
    <div class="card-body">
        <!-- Year Filter -->
        <form method="GET" style="margin-bottom: 20px;">
            <input type="hidden" name="tab" value="history">
            <div class="form-group" style="margin: 0; width: 200px;">
                <label>Jahr:</label>
                <select name="history_year" onchange="this.form.submit()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= ($historyYear ?? date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
        
        <!-- History Table -->
        <?php if (empty($reminderHistory)): ?>
            <p style="text-align: center; color: #666; padding: 40px;">
                Keine Erinnerungen für dieses Jahr gefunden.
            </p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Datum/Zeit</th>
                        <th>Mitglied</th>
                        <th>Jahr</th>
                        <th>Betrag</th>
                        <th>E-Mail</th>
                        <th>Template</th>
                        <th>Versendet von</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reminderHistory as $reminder): ?>
                        <tr class="<?= $reminder['success'] ? '' : 'error-row' ?>">
                            <td><?= date('d.m.Y H:i', strtotime($reminder['sent_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($reminder['first_name'] . ' ' . $reminder['last_name']) ?></strong>
                                <br><small style="color: #666;"><?= htmlspecialchars($reminder['member_number']) ?></small>
                            </td>
                            <td><?= $reminder['fee_year'] ?></td>
                            <td><?= number_format($reminder['fee_amount'], 2, ',', '.') ?> €</td>
                            <td><?= htmlspecialchars($reminder['sent_to_email']) ?></td>
                            <td>
                                <?php if ($reminder['template_used'] === 'payment_reminder_active'): ?>
                                    <span class="badge badge-primary">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Förderer</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($reminder['sent_by_name'] ?? '-') ?></td>
                            <td>
                                <?php if ($reminder['success']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Erfolgreich</span>
                                <?php else: ?>
                                    <span class="badge badge-danger" title="<?= htmlspecialchars($reminder['error_message']) ?>">
                                        <i class="fas fa-times"></i> Fehlgeschlagen
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
// Selection management
function toggleAll(checkbox) {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectionCount();
}

function selectOnlyWithEmail() {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = cb.dataset.hasEmail === '1';
    });
    document.getElementById('selectAll').checked = false;
    updateSelectionCount();
}

function selectOnlyWithoutEmail() {
    const checkboxes = document.querySelectorAll('.member-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = cb.dataset.hasEmail === '0';
    });
    document.getElementById('selectAll').checked = false;
    updateSelectionCount();
}

function updateSelectionCount() {
    const checked = document.querySelectorAll('.member-checkbox:checked');
    const count = checked.length;
    const contactSelected = document.getElementById('contact_member_id').value !== '';
    const hasWithEmail = Array.from(checked).some(cb => cb.dataset.hasEmail === '1');
    
    document.getElementById('selectionCount').textContent = count + ' ausgewählt';
    document.getElementById('previewButton').disabled = count === 0 || !contactSelected || !hasWithEmail;
    document.getElementById('printButton').disabled = count === 0 || !contactSelected;
}

// Print letters function
function printLetters() {
    const checked = Array.from(document.querySelectorAll('.member-checkbox:checked'));
    const obligationIds = checked.map(cb => cb.value).join(',');
    const contactMemberId = document.getElementById('contact_member_id').value;
    
    if (!obligationIds) {
        alert('Bitte wählen Sie mindestens ein Mitglied aus.');
        return;
    }
    
    if (!contactMemberId) {
        alert('Bitte wählen Sie einen Ansprechpartner aus.');
        return;
    }
    
    // Open print view in new tab
    window.open('print_payment_letters.php?ids=' + obligationIds + '&contact=' + contactMemberId, '_blank');
}

// Preview modal
function showPreview() {
    const checked = Array.from(document.querySelectorAll('.member-checkbox:checked'));
    const withEmail = checked.filter(cb => cb.dataset.hasEmail === '1');
    const withoutEmail = checked.filter(cb => cb.dataset.hasEmail === '0');
    
    // Count by type
    const activeCount = checked.filter(cb => {
        const item = cb.closest('.member-list-item');
        return item && item.textContent.includes('Einsatzeinheit');
    }).length;
    const supporterCount = checked.length - activeCount;
    
    // Update summary
    document.getElementById('previewSummary').innerHTML = `
        <p style="margin: 0; font-size: 16px;">
            <strong>Empfänger: ${withEmail.length} Mitglieder</strong><br>
            <span style="margin-left: 20px;">• ${activeCount} Einsatzeinheit (casual Vorlage)</span><br>
            <span style="margin-left: 20px;">• ${supporterCount} Förderer (formale Vorlage)</span>
        </p>
    `;
    
    // Show warnings
    if (withoutEmail.length > 0) {
        document.getElementById('previewWarnings').innerHTML = `
            <div class="info-box warning" style="margin: 15px 0;">
                <p style="margin: 0;">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>${withoutEmail.length} Mitglieder ohne E-Mail-Adresse werden übersprungen</strong>
                </p>
            </div>
        `;
    } else {
        document.getElementById('previewWarnings').innerHTML = '';
    }
    
    // Show sample email (you would need to fetch this via AJAX in production)
    document.getElementById('previewEmailSample').innerHTML = `
        <p style="color: #666; font-style: italic; text-align: center;">
            Die E-Mail wird personalisiert für jedes Mitglied versendet mit:<br>
            • Name und Anrede<br>
            • Beitragsjahr und Beträge<br>
            • Zahlungsinformationen (IBAN, PayPal)<br>
            • Datum der letzten Zahlung
        </p>
    `;
    
    document.getElementById('previewModal').style.display = 'block';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
}

function confirmSend() {
    if (confirm('Möchten Sie wirklich die Zahlungserinnerungen versenden?')) {
        document.getElementById('bulkSendForm').submit();
    }
}

// Close modal on outside click
window.onclick = function(event) {
    const modal = document.getElementById('previewModal');
    if (event.target === modal) {
        closePreview();
    }
}

// Initialize
updateSelectionCount();
</script>

<?php include 'includes/footer.php'; ?>
