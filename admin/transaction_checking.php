<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();

// Check permissions - only admins and kassenprüfer
if (!is_logged_in() || (!is_admin() && !has_permission('check_periods.php'))) {
    redirect('dashboard.php');
}

$page_title = 'Transaktionen prüfen';
$db = getDBConnection();

// Get period_id from URL
$period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;

if (!$period_id) {
    $_SESSION['error'] = 'Keine Prüfperiode angegeben.';
    header('Location: check_periods.php');
    exit;
}

// Get period details
$stmt = $db->prepare("SELECT cp.*, 
                     ml.first_name as leader_first, ml.last_name as leader_last,
                     ma.first_name as assistant_first, ma.last_name as assistant_last
                     FROM check_periods cp
                     JOIN members ml ON cp.leader_id = ml.id
                     JOIN members ma ON cp.assistant_id = ma.id
                     WHERE cp.id = :id");
$stmt->execute(['id' => $period_id]);
$period = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$period) {
    $_SESSION['error'] = 'Prüfperiode nicht gefunden.';
    header('Location: check_periods.php');
    exit;
}

// Get current user's member_id
$user_member_id = null;
$stmt = $db->prepare("SELECT m.id FROM members m 
                     JOIN users u ON m.email = u.email 
                     WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$user_member_id = $result['id'] ?? null;

// For admins without a member record, use a safe placeholder or get any member id
if (!$user_member_id && has_role('admin')) {
    // Try to use period leader's id for tracking
    $user_member_id = $period['leader_id'];
}

$is_leader = ($user_member_id == $period['leader_id']);
$is_assistant = ($user_member_id == $period['assistant_id']);
$is_checker = ($is_leader || $is_assistant || has_role('admin'));
$is_finalized = ($period['status'] === 'finalized');

// Handle check action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $is_checker && !$is_finalized) {
    $transaction_id = intval($_POST['transaction_id']);
    $next_transaction_id = null;

    // Determine next transaction in chronological order for auto-advance
    $stmt = $db->prepare("SELECT booking_date FROM transactions WHERE id = ?");
    $stmt->execute([$transaction_id]);
    $current_tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current_tx) {
        $stmt = $db->prepare("SELECT id FROM transactions
                              WHERE booking_date BETWEEN ? AND ?
                                AND (booking_date > ? OR (booking_date = ? AND id > ?))
                              ORDER BY booking_date ASC, id ASC
                              LIMIT 1");
        $stmt->execute([
            $period['date_from'],
            $period['date_to'],
            $current_tx['booking_date'],
            $current_tx['booking_date'],
            $transaction_id
        ]);
        $next_tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($next_tx) {
            $next_transaction_id = $next_tx['id'];
        }
    }
    
    if ($_POST['action'] === 'approve') {
        $remarks = $_POST['remarks'] ?? '';
        
        // Update transaction status
        $stmt = $db->prepare("UPDATE transactions SET check_status = 'checked' WHERE id = ?");
        $stmt->execute([$transaction_id]);
        
        // Record the check
        $stmt = $db->prepare("INSERT INTO transaction_checks 
                             (transaction_id, check_period_id, checked_by_member_id, check_date, check_result, remarks) 
                             VALUES (?, ?, ?, CURDATE(), 'approved', ?)
                             ON DUPLICATE KEY UPDATE 
                             checked_by_member_id = VALUES(checked_by_member_id), 
                             check_date = CURDATE(), 
                             check_result = VALUES(check_result), 
                             remarks = VALUES(remarks)");
        $stmt->execute([
            $transaction_id,
            $period_id,
            $user_member_id,
            $remarks
        ]);
        
        $_SESSION['success'] = 'Transaktion als geprüft markiert.';
        
    } elseif ($_POST['action'] === 'investigate') {
        $remarks = $_POST['remarks'];
        
        if (empty($remarks)) {
            $_SESSION['error'] = 'Bitte geben Sie eine Bemerkung ein, warum die Transaktion untersucht werden muss.';
        } else {
            // Update transaction status
            $stmt = $db->prepare("UPDATE transactions SET check_status = 'under_investigation' WHERE id = ?");
            $stmt->execute([$transaction_id]);
            
            // Record the check
            $stmt = $db->prepare("INSERT INTO transaction_checks 
                                 (transaction_id, check_period_id, checked_by_member_id, check_date, check_result, remarks) 
                                 VALUES (?, ?, ?, CURDATE(), 'under_investigation', ?)
                                 ON DUPLICATE KEY UPDATE 
                                 checked_by_member_id = VALUES(checked_by_member_id), 
                                 check_date = CURDATE(), 
                                 check_result = VALUES(check_result), 
                                 remarks = VALUES(remarks)");
            $stmt->execute([
                $transaction_id,
                $period_id,
                $user_member_id,
                $remarks
            ]);
            
            $_SESSION['success'] = 'Transaktion zur Untersuchung markiert.';
        }
    }
    
    $redirect = 'transaction_checking.php?period_id=' . $period_id;
    if ($next_transaction_id) {
        $redirect .= '&selected=' . $next_transaction_id;
    }
    header('Location: ' . $redirect);
    exit;
}

// Get transactions for this period with check details
$stmt = $db->prepare("SELECT t.*, 
                     tc.name as category_name, tc.color as category_color,
                     t.check_status,
                     chk.checked_by_member_id, chk.check_date, chk.remarks,
                     m.first_name as checker_first, m.last_name as checker_last,
                     td.file_name as document_name
                     FROM transactions t
                     LEFT JOIN transaction_categories tc ON t.category_id = tc.id
                     LEFT JOIN transaction_checks chk ON t.id = chk.transaction_id AND chk.check_period_id = :pid
                     LEFT JOIN members m ON chk.checked_by_member_id = m.id
                     LEFT JOIN transaction_documents td ON t.document_id = td.id
                     WHERE t.booking_date BETWEEN :date_from AND :date_to
                     ORDER BY t.booking_date ASC, t.id ASC");
$stmt->execute([
    'pid' => $period_id,
    'date_from' => $period['date_from'],
    'date_to' => $period['date_to']
]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine selected transaction (default: oldest in list)
$selected_transaction_id = null;
if (!empty($transactions)) {
    $selected_transaction_id = isset($_GET['selected']) ? intval($_GET['selected']) : $transactions[0]['id'];
    $validIds = array_column($transactions, 'id');
    if (!in_array($selected_transaction_id, $validIds, true)) {
        $selected_transaction_id = $transactions[0]['id'];
    }
}

// Prefetch linked documents and obligations for efficient checking
$transactionIds = array_column($transactions, 'id');
$documentsByTransaction = [];
$obligationsByTransaction = [];
$linkSummaryByTransaction = [];
$transactionMeta = [];

foreach ($transactions as $tx) {
    $txId = (int) $tx['id'];
    $documentsByTransaction[$txId] = [];
    $obligationsByTransaction[$txId] = [];
    $linkSummaryByTransaction[$txId] = [
        'doc_count' => 0,
        'obligation_count' => 0,
        'linked_total' => 0.0
    ];
    $transactionMeta[$txId] = [
        'id' => $txId,
        'booking_date' => $tx['booking_date'],
        'booking_text' => $tx['booking_text'] ?? '',
        'purpose' => $tx['purpose'] ?? '',
        'payer' => $tx['payer'] ?? '',
        'amount' => (float) $tx['amount'],
        'category_name' => $tx['category_name'] ?? '',
        'category_color' => $tx['category_color'] ?? '',
        'check_status' => $tx['check_status'] ?? 'unchecked',
        'checker_name' => trim(($tx['checker_first'] ?? '') . ' ' . ($tx['checker_last'] ?? '')),
        'check_date' => $tx['check_date'] ?? '',
        'remarks' => $tx['remarks'] ?? ''
    ];
}

if (!empty($transactionIds)) {
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));

    // All documents per transaction
    $stmt = $db->prepare("SELECT transaction_id, file_name, file_path, file_size, uploaded_at
                          FROM transaction_documents
                          WHERE transaction_id IN ($placeholders)
                          ORDER BY uploaded_at DESC");
    $stmt->execute($transactionIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $txId = (int) $doc['transaction_id'];
        $documentsByTransaction[$txId][] = $doc;
        $linkSummaryByTransaction[$txId]['doc_count']++;
    }

    // Linked member fee obligations per transaction
    $stmt = $db->prepare("SELECT p.transaction_id, p.amount, p.payment_date,
                                 o.id as obligation_id, o.fee_year, o.status,
                                 m.id as member_id, m.first_name, m.last_name, m.member_number,
                                 'fee' as obligation_type,
                                 CONCAT('Mitgliedsbeitrag ', o.fee_year) as description
                          FROM member_payments p
                          JOIN member_fee_obligations o ON p.obligation_id = o.id
                          JOIN members m ON o.member_id = m.id
                          WHERE p.transaction_id IN ($placeholders)
                          ORDER BY p.payment_date DESC");
    $stmt->execute($transactionIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $obl) {
        $txId = (int) $obl['transaction_id'];
        $obligationsByTransaction[$txId][] = $obl;
        $linkSummaryByTransaction[$txId]['obligation_count']++;
        $linkSummaryByTransaction[$txId]['linked_total'] += (float) $obl['amount'];
    }

    // Linked item obligations per transaction
    $stmt = $db->prepare("SELECT p.transaction_id, p.amount, p.payment_date,
                                 o.id as obligation_id, NULL as fee_year, o.status,
                                 COALESCE(m.id, 0) as member_id,
                                 COALESCE(m.first_name, '') as first_name,
                                 COALESCE(m.last_name, o.receiver_name) as last_name,
                                 COALESCE(m.member_number, '') as member_number,
                                 'item' as obligation_type,
                                 CONCAT('Artikel-Forderung #', o.id) as description
                          FROM item_obligation_payments p
                          JOIN item_obligations o ON p.obligation_id = o.id
                          LEFT JOIN members m ON o.member_id = m.id
                          WHERE p.transaction_id IN ($placeholders)
                          ORDER BY p.payment_date DESC");
    $stmt->execute($transactionIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $obl) {
        $txId = (int) $obl['transaction_id'];
        $obligationsByTransaction[$txId][] = $obl;
        $linkSummaryByTransaction[$txId]['obligation_count']++;
        $linkSummaryByTransaction[$txId]['linked_total'] += (float) $obl['amount'];
    }
}

// Calculate statistics
$total = count($transactions);
$checked = 0;
$under_investigation = 0;
$unchecked = 0;

foreach ($transactions as $t) {
    if ($t['check_status'] === 'checked') $checked++;
    elseif ($t['check_status'] === 'under_investigation') $under_investigation++;
    else $unchecked++;
}

include 'includes/header.php';
?>

<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1><i class="fas fa-tasks"></i> <?php echo htmlspecialchars($period['period_name']); ?></h1>
            <p>
                <?php echo date('d.m.Y', strtotime($period['date_from'])); ?> - 
                <?php echo date('d.m.Y', strtotime($period['date_to'])); ?>
                (Geschäftsjahr <?php echo $period['business_year']; ?>)
            </p>
        </div>
        <div>
            <a href="check_periods.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Zurück
            </a>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<?php if ($is_finalized): ?>
    <div class="alert alert-info">
        <strong>🔒 Diese Prüfperiode ist finalisiert.</strong> Keine Änderungen mehr möglich.
        Finalisiert am <?php echo date('d.m.Y H:i', strtotime($period['finalized_at'])); ?> Uhr
    </div>
<?php endif; ?>

<div class="stats-cards">
    <div class="stat-card stat-total">
        <div class="stat-value"><?php echo $total; ?></div>
        <div class="stat-label">Gesamt</div>
    </div>
    <div class="stat-card stat-checked">
        <div class="stat-value"><?php echo $checked; ?></div>
        <div class="stat-label">✓ Geprüft</div>
    </div>
    <div class="stat-card stat-investigation">
        <div class="stat-value"><?php echo $under_investigation; ?></div>
        <div class="stat-label">⚠️ In Prüfung</div>
    </div>
    <div class="stat-card stat-unchecked">
        <div class="stat-value"><?php echo $unchecked; ?></div>
        <div class="stat-label">⏳ Ungeprüft</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Prüfer</h2>
    </div>
    <div class="card-body">
        <div class="checker-info">
            <div>
                <strong>👑 Leiter:</strong> <?php echo htmlspecialchars($period['leader_first'] . ' ' . $period['leader_last']); ?>
                <?php if ($is_leader): ?><span class="badge badge-primary">Sie</span><?php endif; ?>
            </div>
            <div>
                <strong>🆕 Assistent:</strong> <?php echo htmlspecialchars($period['assistant_first'] . ' ' . $period['assistant_last']); ?>
                <?php if ($is_assistant): ?><span class="badge badge-primary">Sie</span><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Transaktionen</h2>
    </div>
    <div class="card-body">
        <div class="table-toolbar">
            <div class="legend">
                <span class="legend-item"><span class="legend-dot legend-doc"></span> Ausgaben mit Belegen/Bildern</span>
                <span class="legend-item"><span class="legend-dot legend-obl"></span> Einnahmen mit verknüpften Forderungen</span>
                <span class="legend-item"><span class="legend-dot legend-ok"></span> ✓ Geprüft</span>
                <span class="legend-item"><span class="legend-dot legend-warn"></span> ⚠️ In Prüfung</span>
                <span class="legend-item"><span class="legend-dot legend-pending"></span> ⏳ Ungeprüft</span>
            </div>
            <div class="hint">Klicken Sie eine Zeile an, prüfen Sie rechts alle Verknüpfungen und markieren Sie die Buchung direkt als korrekt oder zur Nachprüfung.</div>
        </div>

        <?php if (empty($transactions)): ?>
            <p class="text-muted">Keine Transaktionen in diesem Zeitraum.</p>
        <?php else: ?>
            <div class="transactions-layout">
                <div class="table-responsive table-side">
                    <table class="data-table transactions-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Buchungstext</th>
                                <th>Betrag</th>
                                <th>Verknüpfungen</th>
                                <th>Status</th>
                                <th>Geprüft von</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <?php
                                $row_selected = ($t['id'] == $selected_transaction_id);
                                $summary = $linkSummaryByTransaction[$t['id']] ?? ['doc_count' => 0, 'obligation_count' => 0, 'linked_total' => 0];
                                $is_income = $t['amount'] >= 0;
                                ?>
                                <tr class="transaction-row status-<?php echo $t['check_status']; ?> amount-<?php echo $is_income ? 'positive' : 'negative'; ?> <?php echo $row_selected ? 'is-selected' : ''; ?>"
                                    data-tx-id="<?php echo $t['id']; ?>">
                                    <td><?php echo date('d.m.Y', strtotime($t['booking_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($t['booking_text'] ?? ''); ?></strong>
                                        <?php if ($t['purpose']): ?>
                                            <br><small><?php echo htmlspecialchars(substr($t['purpose'], 0, 110)); ?></small>
                                        <?php endif; ?>
                                        <?php if ($t['payer']): ?>
                                            <br><small class="text-muted">Von: <?php echo htmlspecialchars($t['payer']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($t['category_name']): ?>
                                            <br><span class="category-badge-inline" style="background-color: <?php echo htmlspecialchars($t['category_color']); ?>">
                                                <?php echo htmlspecialchars($t['category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount <?php echo $is_income ? 'positive' : 'negative'; ?>">
                                        <?php echo number_format($t['amount'], 2, ',', '.'); ?> €
                                    </td>
                                    <td class="linked-info-cell">
                                        <button class="link-btn <?php echo $is_income ? 'obligation-btn' : 'doc-btn'; ?>" onclick="selectTransaction(<?php echo $t['id']; ?>); return false;">
                                            <i class="fas <?php echo $is_income ? 'fa-link' : 'fa-file-alt'; ?>"></i>
                                            <?php if ($is_income): ?>
                                                <?php echo $summary['obligation_count']; ?> Forderung<?php echo $summary['obligation_count'] === 1 ? '' : 'en'; ?>
                                            <?php else: ?>
                                                <?php echo $summary['doc_count']; ?> Beleg<?php echo $summary['doc_count'] === 1 ? '' : 'e'; ?>
                                            <?php endif; ?>
                                        </button>
                                        <?php if ($is_income): ?>
                                            <div class="link-meta">Verknüpft: <?php echo number_format($summary['linked_total'], 2, ',', '.'); ?> €</div>
                                            <?php if ($summary['obligation_count'] === 0): ?>
                                                <div class="link-warning">Noch keine Forderung verknüpft</div>
                                            <?php endif; ?>
                                            <?php if ($summary['doc_count'] > 0): ?>
                                                <div class="link-secondary"><?php echo $summary['doc_count']; ?> Beleg<?php echo $summary['doc_count'] === 1 ? '' : 'e'; ?> zusätzlich vorhanden</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="link-meta"><?php echo $summary['doc_count'] > 0 ? 'Dokumente zur Prüfung vorhanden' : 'Kein Beleg verknüpft'; ?></div>
                                            <?php if ($summary['obligation_count'] > 0): ?>
                                                <div class="link-secondary"><?php echo $summary['obligation_count']; ?> Forderung<?php echo $summary['obligation_count'] === 1 ? '' : 'en'; ?> zusätzlich verknüpft</div>
                                            <?php elseif ($summary['doc_count'] === 0): ?>
                                                <div class="link-warning">Bitte Beleg oder Bild prüfen</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['check_status'] === 'checked'): ?>
                                            <span class="status-badge status-checked">✓ Geprüft</span>
                                        <?php elseif ($t['check_status'] === 'under_investigation'): ?>
                                            <span class="status-badge status-investigation">⚠️ In Prüfung</span>
                                        <?php else: ?>
                                            <span class="status-badge status-unchecked">⏳ Ungeprüft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['checker_first']): ?>
                                            <?php echo htmlspecialchars($t['checker_first'] . ' ' . $t['checker_last']); ?>
                                            <br><small class="text-muted"><?php echo date('d.m.Y', strtotime($t['check_date'])); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                        <?php if ($t['remarks']): ?>
                                            <br><small class="remarks">💬 <?php echo htmlspecialchars($t['remarks']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="preview-side">
                    <div class="card preview-card">
                        <div class="card-header"><h3>Prüfansicht</h3></div>
                        <div class="card-body" id="preview-panel">
                            <p class="text-muted" id="preview-placeholder">Wählen Sie eine Transaktion, um Belege anzuzeigen.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <h3>Transaktion als geprüft markieren</h3>
        <form method="POST" id="approveForm">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="transaction_id" id="approve_transaction_id">
            
            <div class="form-group">
                <label for="approve_remarks">Bemerkungen (optional)</label>
                <textarea name="remarks" id="approve_remarks" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Abbrechen</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Als geprüft markieren
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Investigate Modal -->
<div id="investigateModal" class="modal">
    <div class="modal-content">
        <h3>Transaktion zur Prüfung markieren</h3>
        <form method="POST" id="investigateForm">
            <input type="hidden" name="action" value="investigate">
            <input type="hidden" name="transaction_id" id="investigate_transaction_id">
            
            <div class="form-group">
                <label for="investigate_remarks">Bemerkungen (erforderlich) *</label>
                <textarea name="remarks" id="investigate_remarks" class="form-control" rows="3" required 
                          placeholder="Bitte beschreiben Sie, was geprüft werden muss..."></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Abbrechen</button>
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-exclamation"></i> Zur Prüfung markieren
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const docsData = <?php echo json_encode($documentsByTransaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const obligationsData = <?php echo json_encode($obligationsByTransaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const txMetaData = <?php echo json_encode($transactionMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const selectedInit = <?php echo $selected_transaction_id ? intval($selected_transaction_id) : 'null'; ?>;
const canCheck = <?php echo ($is_checker && !$is_finalized) ? 'true' : 'false'; ?>;
let currentSelectedId = selectedInit;

function approveTransaction(id) {
    const form = document.getElementById('approveForm');
    if (!form) return;
    form.reset();
    form.dataset.submitted = 'false';
    document.getElementById('approve_transaction_id').value = id;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Als geprüft markieren';
    }
    document.getElementById('approveModal').style.display = 'flex';
}

function investigateTransaction(id) {
    const form = document.getElementById('investigateForm');
    if (!form) return;
    form.reset();
    form.dataset.submitted = 'false';
    document.getElementById('investigate_transaction_id').value = id;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-exclamation"></i> Zur Prüfung markieren';
    }
    document.getElementById('investigateModal').style.display = 'flex';
}

function closeModals() {
    document.getElementById('approveModal').style.display = 'none';
    document.getElementById('investigateModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        closeModals();
    }
};

function safeColor(color) {
    return /^#[0-9a-fA-F]{3,8}$/.test(color || '') ? color : '#607d8b';
}

function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(m) {
        return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m]);
    });
}

function formatCurrency(value) {
    return Number(value || 0).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}

function formatDate(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return escapeHtml(value);
    return d.toLocaleDateString('de-DE');
}

function selectTransaction(id) {
    currentSelectedId = String(id);
    document.querySelectorAll('.transaction-row').forEach(r => r.classList.remove('is-selected'));

    const row = document.querySelector(`.transaction-row[data-tx-id="${id}"]`);
    const tableSide = document.querySelector('.table-side');
    if (row) {
        row.classList.add('is-selected');
        if (tableSide) {
            const targetTop = row.offsetTop - (tableSide.clientHeight / 2) + (row.offsetHeight / 2);
            tableSide.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
        }
    }

    renderPreview(id);
}

function selectAdjacent(step) {
    const rows = Array.from(document.querySelectorAll('.transaction-row'));
    if (!rows.length) return;
    const ids = rows.map(row => String(row.dataset.txId));
    let index = ids.indexOf(String(currentSelectedId));
    if (index === -1) index = 0;
    index = Math.max(0, Math.min(ids.length - 1, index + step));
    selectTransaction(ids[index]);
}

function selectNextPending() {
    const rows = Array.from(document.querySelectorAll('.transaction-row'));
    if (!rows.length) return;

    const ids = rows.map(row => String(row.dataset.txId));
    let start = ids.indexOf(String(currentSelectedId));
    if (start === -1) start = -1;

    for (let offset = 1; offset <= rows.length; offset++) {
        const row = rows[(start + offset) % rows.length];
        if (row && !row.classList.contains('status-checked')) {
            selectTransaction(row.dataset.txId);
            return;
        }
    }
}

function renderPreview(id) {
    const panel = document.getElementById('preview-panel');
    if (!panel) return;

    const tx = txMetaData[id];
    const docs = docsData[id] || [];
    const obligations = obligationsData[id] || [];

    if (!tx) {
        panel.innerHTML = '<p class="text-muted">Transaktion nicht gefunden.</p>';
        return;
    }

    const statusMap = {
        checked: { label: '✓ Geprüft', className: 'status-checked' },
        under_investigation: { label: '⚠️ In Prüfung', className: 'status-investigation' },
        unchecked: { label: '⏳ Ungeprüft', className: 'status-unchecked' }
    };
    const status = statusMap[tx.check_status] || statusMap.unchecked;

    const checkerInfo = tx.checker_name
        ? `${escapeHtml(tx.checker_name)}${tx.check_date ? ' · ' + formatDate(tx.check_date) : ''}`
        : 'Noch nicht geprüft';

    const navHtml = `
        <div class="quick-nav">
            <button type="button" class="btn btn-sm btn-secondary" onclick="selectAdjacent(-1); return false;">
                <i class="fas fa-arrow-up"></i> Vorherige
            </button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="selectAdjacent(1); return false;">
                <i class="fas fa-arrow-down"></i> Nächste
            </button>
            <button type="button" class="btn btn-sm btn-info" onclick="selectNextPending(); return false;">
                <i class="fas fa-forward"></i> Nächste offene
            </button>
        </div>
    `;

    const actionHtml = canCheck ? `
        <div class="quick-check-actions">
            <button type="button" class="btn btn-success" onclick="approveTransaction(${id})">
                <i class="fas fa-check"></i> Als korrekt markieren
            </button>
            <button type="button" class="btn btn-warning" onclick="investigateTransaction(${id})">
                <i class="fas fa-search"></i> Zur Nachprüfung
            </button>
        </div>
    ` : '';

    const obligationHtml = obligations.length
        ? obligations.map(obl => {
            const targetUrl = obl.obligation_type === 'item'
                ? `view_item_obligation.php?id=${encodeURIComponent(obl.obligation_id)}`
                : `member_payments.php?id=${encodeURIComponent(obl.member_id)}`;
            const typeLabel = obl.obligation_type === 'item' ? 'ARTIKEL' : 'BEITRAG';
            const statusClass = obl.status === 'paid'
                ? 'pill-success'
                : (obl.status === 'partial' ? 'pill-warning' : 'pill-danger');
            const yearInfo = obl.fee_year ? `Jahr ${escapeHtml(obl.fee_year)} · ` : '';
            const memberInfo = obl.member_number ? ` (${escapeHtml(obl.member_number)})` : '';

            return `
                <a href="${targetUrl}" target="_blank" class="obligation-card">
                    <div class="obligation-card-title">
                        <span class="type-tag">${typeLabel}</span>
                        ${escapeHtml((obl.first_name || '') + ' ' + (obl.last_name || ''))}${memberInfo}
                    </div>
                    <div class="obligation-card-meta">
                        ${yearInfo}${escapeHtml(obl.description || '')}
                    </div>
                    <div class="obligation-card-meta">
                        Betrag: ${formatCurrency(obl.amount)} ·
                        <span class="status-pill ${statusClass}">${escapeHtml(obl.status || 'offen')}</span>
                    </div>
                </a>
            `;
        }).join('')
        : '<div class="empty-state">Keine verknüpften Forderungen vorhanden.</div>';

    const docButtonsHtml = docs.length
        ? docs.map((doc, idx) => {
            const size = doc.file_size ? ` · ${(Number(doc.file_size) / 1024).toFixed(1).replace('.', ',')} KB` : '';
            return `
                <button type="button" class="doc-picker ${idx === 0 ? 'is-active' : ''}" onclick="showDoc(${id}, ${idx}); return false;">
                    <i class="fas fa-file-alt"></i>
                    ${escapeHtml(doc.file_name || 'Beleg')}${size}
                </button>
            `;
        }).join('')
        : '<div class="empty-state">Keine verknüpften Belege oder Bilder vorhanden.</div>';

    const categoryHtml = tx.category_name
        ? `<span class="category-badge-inline" style="background-color: ${safeColor(tx.category_color)}">${escapeHtml(tx.category_name)}</span>`
        : '<span class="text-muted">Keine Kategorie</span>';

    panel.innerHTML = `
        <div class="selected-summary">
            <div class="selected-summary-header">
                <div>
                    <div class="selected-date">${formatDate(tx.booking_date)}</div>
                    <div class="selected-title">${escapeHtml(tx.booking_text || 'Transaktion')}</div>
                </div>
                <div class="selected-amount ${Number(tx.amount) >= 0 ? 'positive' : 'negative'}">${formatCurrency(tx.amount)}</div>
            </div>
            <div class="selected-summary-grid">
                <div>
                    <strong>Zahler/Empfänger</strong><br>
                    ${escapeHtml(tx.payer || '—')}
                </div>
                <div>
                    <strong>Status</strong><br>
                    <span class="status-badge ${status.className}">${status.label}</span>
                </div>
                <div class="full-row">
                    <strong>Verwendungszweck</strong><br>
                    ${escapeHtml(tx.purpose || '—')}
                </div>
                <div>
                    <strong>Kategorie</strong><br>
                    ${categoryHtml}
                </div>
                <div>
                    <strong>Prüfung</strong><br>
                    ${checkerInfo}
                </div>
                ${tx.remarks ? `<div class="full-row"><strong>Bemerkung</strong><br>${escapeHtml(tx.remarks)}</div>` : ''}
            </div>
        </div>
        ${navHtml}
        ${actionHtml}
        <div class="preview-section">
            <h4><i class="fas fa-link"></i> Verknüpfte Forderungen</h4>
            <div class="obligation-preview-list">${obligationHtml}</div>
        </div>
        <div class="preview-section">
            <h4><i class="fas fa-file-alt"></i> Belege / Bilder</h4>
            <div class="preview-doc-buttons">${docButtonsHtml}</div>
            ${docs.length ? '<div class="preview-frame" id="preview-frame"></div>' : ''}
        </div>
    `;

    if (docs.length) {
        showDoc(id, 0);
    }
}

function showDoc(id, idx) {
    const panel = document.getElementById('preview-frame');
    if (!panel) return;

    const docs = docsData[id] || [];
    const doc = docs[idx];
    if (!doc) {
        panel.innerHTML = '<div class="error-message"><p>Beleg nicht gefunden.</p></div>';
        return;
    }

    document.querySelectorAll('#preview-panel .doc-picker').forEach((btn, buttonIdx) => {
        btn.classList.toggle('is-active', buttonIdx === idx);
    });

    let filePath = doc.file_path || '';
    if (filePath.startsWith('/')) {
        filePath = filePath.substring(1);
    }
    const path = '../uploads/' + filePath;
    const ext = filePath.split('.').pop().toLowerCase();

    if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) {
        panel.innerHTML = `<img src="${path}" alt="${escapeHtml(doc.file_name)}" class="preview-image"
            onerror="this.parentElement.innerHTML='<div class=\\"error-message\\"><i class=\\"fas fa-exclamation-triangle\\"></i><p><strong>Bild nicht gefunden</strong></p><p>Datei: ${escapeHtml(doc.file_name)}</p><p>Pfad: <span>${escapeHtml(path)}</span></p></div>';
        ">`;
        return;
    }

    panel.innerHTML = '<div class="loading-message"><i class="fas fa-spinner fa-spin"></i> Lade Beleg...</div>';
    fetch(path, { method: 'HEAD' })
        .then(response => {
            if (response.ok) {
                panel.innerHTML = `<iframe src="${path}" class="preview-object"></iframe>`;
            } else {
                panel.innerHTML = `<div class="error-message"><i class="fas fa-exclamation-triangle"></i><p><strong>Beleg nicht gefunden (${response.status})</strong></p><p>Datei: ${escapeHtml(doc.file_name)}</p><p>Pfad: <span>${escapeHtml(path)}</span></p></div>`;
            }
        })
        .catch(() => {
            panel.innerHTML = `<div class="error-message"><i class="fas fa-exclamation-triangle"></i><p><strong>Fehler beim Laden des Belegs</strong></p><p>Datei: ${escapeHtml(doc.file_name)}</p><p>Pfad: <span>${escapeHtml(path)}</span></p></div>`;
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const initial = selectedInit || (document.querySelector('.transaction-row') ? document.querySelector('.transaction-row').dataset.txId : null);
    if (initial) {
        selectTransaction(initial);
    }

    const approveForm = document.getElementById('approveForm');
    if (approveForm) {
        approveForm.addEventListener('submit', function(e) {
            if (this.dataset.submitted === 'true') {
                e.preventDefault();
                return false;
            }
            this.dataset.submitted = 'true';
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wird gespeichert...';
            }
        });
    }

    const investigateForm = document.getElementById('investigateForm');
    if (investigateForm) {
        investigateForm.addEventListener('submit', function(e) {
            if (this.dataset.submitted === 'true') {
                e.preventDefault();
                return false;
            }
            this.dataset.submitted = 'true';
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wird gespeichert...';
            }
        });
    }

    document.querySelectorAll('.transaction-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('button') || e.target.closest('a')) {
                return;
            }
            selectTransaction(this.dataset.txId);
        });
    });
});
</script>

<style>
.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-value {
    font-size: 2em;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-total { border-left: 4px solid #2196f3; }
.stat-checked { border-left: 4px solid #4caf50; }
.stat-investigation { border-left: 4px solid #ff9800; }
.stat-unchecked { border-left: 4px solid #9e9e9e; }

.checker-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}

.transactions-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(340px, 1fr);
    gap: 18px;
    align-items: start;
}

@media (max-width: 1024px) {
    .transactions-layout {
        grid-template-columns: 1fr;
    }

    .table-side {
        max-height: none;
    }

    .preview-side {
        position: static;
        top: auto;
        transform: none;
    }
}

.table-side {
    overflow-x: auto;
    overflow-y: auto;
    max-height: calc(100vh - 40px);
    scroll-behavior: smooth;
}

.transactions-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.transactions-table thead th {
    position: sticky;
    top: 0;
    background: #edf2f7;
    z-index: 2;
    box-shadow: 0 1px 0 #e5e5e5;
    color: #0f172a !important;
    border-bottom: 2px solid #d0d7e2;
    font-weight: 700;
}

.transactions-table tbody tr {
    background: #ffffff;
    border-bottom: 1px solid #e0e0e0;
}

.transactions-table tbody tr:hover {
    background: #f5f5f5;
}

.transactions-table tbody tr:nth-child(even) {
    background: #f8fafc;
}

.transaction-row {
    cursor: pointer;
    transition: background 0.15s ease, box-shadow 0.15s ease;
}

.transaction-row.amount-positive {
    background-color: #f0f9f7 !important;
}

.transaction-row.amount-negative {
    background-color: #fef8f7 !important;
}

.transaction-row.is-selected {
    background: #d3e5ff !important;
    box-shadow: inset 4px 0 0 #2196f3;
    font-weight: 500;
}

.transaction-row.is-selected td {
    color: #0b152c !important;
}

.transaction-row.is-selected .text-muted {
    color: #374151 !important;
}

.transactions-table td,
.transactions-table th {
    color: #0f172a !important;
    font-size: 0.95rem;
    padding: 10px 12px;
    border-right: 1px solid #e0e7ef;
}

.transactions-table td:last-child,
.transactions-table th:last-child {
    border-right: none;
}

.transactions-table td strong,
.transactions-table th strong {
    color: #0b152c !important;
}

.transactions-table td small,
.transactions-table td span,
.transactions-table td a,
.transactions-table th small,
.transactions-table th span,
.transactions-table th a {
    color: #0f172a !important;
}

.amount {
    font-weight: bold;
    text-align: right;
}

.amount.positive { color: #4caf50; }
.amount.negative { color: #f44336; }

.category-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85em;
    color: white;
    font-weight: 500;
}

.category-badge-inline {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.8em;
    color: white;
    font-weight: 500;
    margin: 4px 0;
}

.status-badge {
        text-shadow: 0 1px 1px rgba(0,0,0,0.1);
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: bold;
}

.status-checked { background-color: #4caf50; color: white !important; }
.status-investigation { background-color: #ff9800; color: white !important; }
.status-unchecked { background-color: #9e9e9e; color: white !important; }

.remarks {
    color: #666;
    font-style: italic;
}

.text-muted {
    color: #4b5563 !important;
}

.doc-links,
.obligation-links {
    list-style: none;
    padding-left: 0;
    margin: 0;
}

.doc-links li,
.obligation-links li {
    margin-bottom: 6px;
    line-height: 1.3;
}

.doc-links a,
.obligation-links a {
    color: var(--primary-color);
    text-decoration: none;
}

.doc-links a:hover,
.obligation-links a:hover {
    text-decoration: underline;
}

.status-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    color: #fff;
    font-weight: 600;
}

.pill-success { background: #4caf50; }
.pill-warning { background: #ff9800; }
.pill-danger { background: #f44336; }

.badge-primary {
    background: #1976d2;
    color: #fff;
}

.alert-info {
    background-color: #e8f4fd;
    color: #0b4f7d;
    border-left: 4px solid #1976d2;
}

.btn-warning {
    background-color: #ff9800;
    color: #fff;
}

.btn-warning:hover {
    background-color: #f57c00;
}

.table-toolbar {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    font-size: 0.9rem;
    color: #555;
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.legend-doc { background: #2196f3; }
.legend-obl { background: #9c27b0; }
.legend-ok { background: #4caf50; }
.legend-warn { background: #ff9800; }
.legend-pending { background: #9e9e9e; }

.hint {
    color: #777;
    font-size: 0.85rem;
}

.linked-info-cell {
    min-width: 190px;
}

.link-btn {
    background: #2196f3;
    border: none;
    color: #ffffff !important;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
    text-shadow: 0 1px 1px rgba(0,0,0,0.1);
}

.link-btn:hover {
    background: #1976d2;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    color: #ffffff !important;
}

.obligation-btn {
    background: #7b1fa2;
}

.obligation-btn:hover {
    background: #6a1b9a;
}

.link-btn i {
    margin-right: 4px;
}

.link-meta,
.link-secondary,
.link-warning {
    margin-top: 0.35rem;
    font-size: 0.82rem;
}

.link-meta,
.link-secondary {
    color: #555;
}

.link-warning {
    color: #b26a00;
    font-weight: 600;
}


.preview-side {
    min-width: 0;
    position: sticky;
    top: 50%;
    transform: translateY(-50%);
    align-self: start;
}

.preview-card {
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    z-index: 10;
    margin: 0;
}

.preview-card .card-header {
    background: #f5f5f5;
    border-bottom: 2px solid #ddd;
}

.selected-summary {
    background: #f8fafc;
    border: 1px solid #e0e7ef;
    border-radius: 8px;
    padding: 14px;
    margin-bottom: 12px;
}

.selected-summary-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
}

.selected-date {
    color: #64748b;
    font-size: 0.82rem;
    margin-bottom: 0.2rem;
}

.selected-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.selected-amount {
    font-size: 1.1rem;
    font-weight: 700;
    white-space: nowrap;
}

.selected-summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px 12px;
    font-size: 0.92rem;
}

.selected-summary-grid .full-row {
    grid-column: 1 / -1;
}

.quick-nav,
.quick-check-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.quick-nav .btn,
.quick-check-actions .btn {
    flex: 1;
    min-width: 120px;
}

.preview-section {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #e5e7eb;
}

.preview-section h4 {
    margin: 0 0 10px 0;
    font-size: 0.98rem;
    color: #1f2937;
}

.obligation-preview-list {
    display: grid;
    gap: 8px;
}

.obligation-card {
    display: block;
    text-decoration: none;
    background: #fff;
    border: 1px solid #e0e7ef;
    border-left: 4px solid #9c27b0;
    border-radius: 6px;
    padding: 10px 12px;
    color: #0f172a !important;
}

.obligation-card:hover {
    background: #f8fbff;
}

.obligation-card-title {
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.obligation-card-meta {
    font-size: 0.85rem;
    color: #555;
}

.type-tag {
    display: inline-block;
    margin-right: 0.45rem;
    padding: 2px 6px;
    border-radius: 999px;
    background: #e3f2fd;
    color: #1565c0;
    font-size: 0.72rem;
    font-weight: 700;
}

.preview-doc-buttons {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 10px;
}

.doc-picker {
    width: 100%;
    text-align: left;
    border: 1px solid #d0d7e2;
    background: #fff;
    padding: 8px 10px;
    border-radius: 6px;
    cursor: pointer;
    color: #0f172a;
}

.doc-picker:hover,
.doc-picker.is-active {
    border-color: #1976d2;
    background: #eff6ff;
}

.preview-frame {
    margin-top: 10px;
    min-height: 400px;
    background: #ffffff;
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 0;
    overflow: hidden;
}

.empty-state {
    padding: 12px;
    border-radius: 6px;
    background: #f9fafb;
    color: #666;
}

.error-message {
    padding: 30px;
    text-align: center;
    color: #d32f2f;
}

.error-message i {
    font-size: 3em;
    color: #ff9800;
    margin-bottom: 15px;
}

.error-message p {
    margin: 10px 0;
    color: #333;
}

.error-message strong {
    color: #d32f2f;
    font-size: 1.1em;
}

.error-message span {
    word-break: break-all;
}

.loading-message {
    padding: 50px;
    text-align: center;
    color: #2196f3;
    font-size: 1.1em;
}

.loading-message i {
    font-size: 2em;
    margin-bottom: 10px;
}

.preview-object {
    width: 100%;
    height: 500px;
    border: none;
    display: block;
    background: #fff;
}

.preview-image {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
    padding: 10px;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: #fefefe;
    padding: 20px;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

@media (max-width: 768px) {
    .selected-summary-header,
    .selected-summary-grid {
        grid-template-columns: 1fr;
    }

    .selected-summary-header {
        flex-direction: column;
    }

}
</style>

<?php include 'includes/footer.php'; ?>
