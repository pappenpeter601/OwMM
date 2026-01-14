<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
check_auth();

// Only admin and kassenpruefer can access
if (!has_role('admin') && !has_role('kassenpruefer')) {
    $_SESSION['error'] = 'Zugriff verweigert.';
    header('Location: dashboard.php');
    exit;
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

// Prefetch documents and linked open obligations for all transactions in this period
$transactionIds = array_column($transactions, 'id');
$documentsByTransaction = [];
$obligationsByTransaction = [];

if (!empty($transactionIds)) {
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));

    // All documents per transaction
    $stmt = $db->prepare("SELECT transaction_id, file_name, file_path, file_size, uploaded_at
                          FROM transaction_documents
                          WHERE transaction_id IN ($placeholders)
                          ORDER BY uploaded_at DESC");
    $stmt->execute($transactionIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $documentsByTransaction[$doc['transaction_id']][] = $doc;
    }

    // Linked obligations (only open/partial) per transaction
    $stmt = $db->prepare("SELECT p.transaction_id, p.amount, p.payment_date,
                                 o.id as obligation_id, o.fee_year, o.status,
                                 m.id as member_id, m.first_name, m.last_name, m.member_number
                          FROM member_payments p
                          JOIN member_fee_obligations o ON p.obligation_id = o.id
                          JOIN members m ON o.member_id = m.id
                          WHERE p.transaction_id IN ($placeholders)
                            AND o.status IN ('open', 'partial')
                          ORDER BY p.payment_date DESC");
    $stmt->execute($transactionIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $obl) {
        $obligationsByTransaction[$obl['transaction_id']][] = $obl;
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
                <span class="legend-item"><span class="legend-dot legend-doc"></span> Belege öffnen (PDF/JPG/PNG)</span>
                <span class="legend-item"><span class="legend-dot legend-obl"></span> Verknüpfte offene Forderungen</span>
                <span class="legend-item"><span class="legend-dot legend-ok"></span> ✓ Geprüft</span>
                <span class="legend-item"><span class="legend-dot legend-warn"></span> ⚠️ In Prüfung</span>
                <span class="legend-item"><span class="legend-dot legend-pending"></span> ⏳ Ungeprüft</span>
            </div>
            <div class="hint">Tipp: Klicken Sie auf eine Transaktion, um sie auszuwählen und Belege/Forderungen anzuzeigen.</div>
        </div>

        <?php if (empty($transactions)): ?>
            <p class="text-muted">Keine Transaktionen in diesem Zeitraum.</p>
        <?php else: ?>
            <div class="transactions-layout">
                <div class="table-responsive table-side">
                    <table class="table transactions-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Buchungstext</th>
                                <th>Betrag</th>
                                <th>Belege</th>
                                <th>Forderungen</th>
                                <th>Status</th>
                                <th>Geprüft von</th>
                                <?php if ($is_checker && !$is_finalized): ?>
                                <th>Aktionen</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <?php $row_selected = ($t['id'] == $selected_transaction_id); ?>
                                <tr class="transaction-row status-<?php echo $t['check_status']; ?> amount-<?php echo $t['amount'] >= 0 ? 'positive' : 'negative'; ?> <?php echo $row_selected ? 'is-selected' : ''; ?>"
                                    data-tx-id="<?php echo $t['id']; ?>">
                                    <td><?php echo date('d.m.Y', strtotime($t['booking_date'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($t['booking_text'] ?? ''); ?></strong>
                                        <?php if ($t['purpose']): ?>
                                            <br><small><?php echo htmlspecialchars(substr($t['purpose'], 0, 80)); ?></small>
                                        <?php endif; ?>
                                        <br><small class="text-muted">
                                            <?php if ($t['payer']): ?>
                                                Von: <?php echo htmlspecialchars($t['payer']); ?>
                                            <?php endif; ?>
                                        </small>
                                        <?php if ($t['category_name']): ?>
                                            <br><span class="category-badge-inline" style="background-color: <?php echo htmlspecialchars($t['category_color']); ?>">
                                                <?php echo htmlspecialchars($t['category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount <?php echo $t['amount'] >= 0 ? 'positive' : 'negative'; ?>">
                                        <?php echo number_format($t['amount'], 2, ',', '.'); ?> €
                                    </td>
                                    <td>
                                        <?php $docs = $documentsByTransaction[$t['id']] ?? []; ?>
                                        <?php if (!empty($docs)): ?>
                                            <?php $docCount = count($docs); ?>
                                            <button class="link-btn" onclick="selectTransaction(<?php echo $t['id']; ?>); return false;">
                                                <i class="fas fa-file-pdf"></i> <?php echo $docCount; ?> Beleg<?php echo $docCount > 1 ? 'e' : ''; ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $obls = $obligationsByTransaction[$t['id']] ?? []; ?>
                                        <?php if (!empty($obls)): ?>
                                            <?php $oblCount = count($obls); ?>
                                            <button class="link-btn" onclick="toggleObligationRow(<?php echo $t['id']; ?>); return false;">
                                                <i class="fas fa-link"></i> <?php echo $oblCount; ?> Forderung<?php echo $oblCount > 1 ? 'en' : ''; ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
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
                                    <?php if ($is_checker && !$is_finalized): ?>
                                    <td class="row-actions">
                                        <button class="btn btn-sm btn-success" onclick="approveTransaction(<?php echo $t['id']; ?>)">
                                            <i class="fas fa-check"></i> OK
                                        </button>
                                        <button class="btn btn-sm btn-warning" onclick="investigateTransaction(<?php echo $t['id']; ?>)">
                                            <i class="fas fa-exclamation"></i> Prüfen
                                        </button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <tr class="obligation-detail" data-for="<?php echo $t['id']; ?>" style="<?php echo $row_selected ? '' : 'display:none;'; ?>">
                                    <td colspan="<?php echo $is_checker && !$is_finalized ? '8' : '7'; ?>">
                                        <?php $obls = $obligationsByTransaction[$t['id']] ?? []; ?>
                                        <?php if (!empty($obls)): ?>
                                            <div class="obligation-block">
                                                <h4><i class="fas fa-link"></i> Verknüpfte Forderungen</h4>
                                                <ul class="obligation-links">
                                                    <?php foreach ($obls as $obl): ?>
                                                        <?php $statusColor = $obl['status'] === 'partial' ? '#ff9800' : '#f44336'; ?>
                                                        <li>
                                                            <a href="member_payments.php?id=<?php echo intval($obl['member_id']); ?>" target="_blank">
                                                                <?php echo htmlspecialchars($obl['first_name'] . ' ' . $obl['last_name']); ?>
                                                                <span class="text-muted">(<?php echo htmlspecialchars($obl['member_number']); ?>)</span>
                                                            </a>
                                                            <small>
                                                                Jahr: <?php echo htmlspecialchars($obl['fee_year']); ?> | 
                                                                Betrag: <?php echo number_format($obl['amount'], 2, ',', '.'); ?> € | 
                                                                Status: <span class="status-pill" style="background: <?php echo $statusColor; ?>;"><?php echo ucfirst($obl['status']); ?></span>
                                                            </small>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php else: ?>
                                            <div class="obligation-block empty">Keine verknüpften Forderungen.</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="preview-side">
                    <div class="card preview-card">
                        <div class="card-header"><h3>Beleg-Vorschau</h3></div>
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
const docsData = <?php echo json_encode($documentsByTransaction); ?>;
const selectedInit = <?php echo $selected_transaction_id ? intval($selected_transaction_id) : 'null'; ?>;

function approveTransaction(id) {
    // Clear previous remarks and ensure clean state
    document.getElementById('approve_remarks').value = '';
    document.getElementById('approve_transaction_id').value = id;
    document.getElementById('approveForm').reset();
    document.getElementById('approveForm').dataset.submitted = 'false';
    document.getElementById('approveModal').style.display = 'flex';
}

function investigateTransaction(id) {
    // Clear previous remarks and ensure clean state
    document.getElementById('investigate_remarks').value = '';
    document.getElementById('investigate_transaction_id').value = id;
    document.getElementById('investigateForm').reset();
    document.getElementById('investigateForm').dataset.submitted = 'false';
    document.getElementById('investigateModal').style.display = 'flex';
}

function closeModals() {
    document.getElementById('approveModal').style.display = 'none';
    document.getElementById('investigateModal').style.display = 'none';
}

// Close modal on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        closeModals();
    }
}

function selectTransaction(id) {
    const rows = document.querySelectorAll('.transaction-row');
    const obligationRows = document.querySelectorAll('.obligation-detail');
    rows.forEach(r => r.classList.remove('is-selected'));
    obligationRows.forEach(r => r.style.display = 'none');

    const row = document.querySelector(`.transaction-row[data-tx-id="${id}"]`);
    const oblRow = document.querySelector(`.obligation-detail[data-for="${id}"]`);
    if (row) {
        row.classList.add('is-selected');
        // Scroll the row into view, centered
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (oblRow) {
        oblRow.style.display = '';
    }
    renderPreview(id);
}

function toggleObligationRow(id) {
    const row = document.querySelector(`.obligation-detail[data-for="${id}"]`);
    if (row) {
        const isHidden = row.style.display === 'none';
        row.style.display = isHidden ? '' : 'none';
    }
}

function renderPreview(id) {
    const panel = document.getElementById('preview-panel');
    if (!panel) return;
    const docs = docsData[id] || [];
    if (!docs.length) {
        panel.innerHTML = '<p class="text-muted">Keine Belege für diese Transaktion.</p>';
        return;
    }

    const listItems = docs.map((doc, idx) => {
        const safeName = escapeHtml(doc.file_name || 'Beleg');
        return `<li><button class="link-btn" onclick="showDoc(${id}, ${idx}); return false;">${safeName}</button></li>`;
    }).join('');

    panel.innerHTML = `
        <div class="preview-list">
            <h4>Belege</h4>
            <ul>${listItems}</ul>
        </div>
        <div class="preview-frame" id="preview-frame"></div>
    `;

    showDoc(id, 0);
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
    // Build correct path - file_path already includes subdirectory like 'documents/filename.pdf'
    let filePath = doc.file_path;
    // Remove leading slash if present
    if (filePath.startsWith('/')) {
        filePath = filePath.substring(1);
    }
    const path = '../uploads/' + filePath;
    const ext = (doc.file_path || '').split('.').pop().toLowerCase();
    
    if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) {
        panel.innerHTML = `<img src="${path}" alt="${escapeHtml(doc.file_name)}" class="preview-image" 
            onerror="this.parentElement.innerHTML='<div class=\\"error-message\\"><i class=\\"fas fa-exclamation-triangle\\"></i><p><strong>Bild nicht gefunden</strong></p><p>Datei: ${escapeHtml(doc.file_name)}</p><p>Pfad: <code>${escapeHtml(path)}</code></p><p class=\\"hint\\">Die Datei wurde möglicherweise noch nicht hochgeladen.</p></div>';">`;
    } else {
        // For PDFs, check if file exists first, then display
        panel.innerHTML = `<div class="loading-message"><i class="fas fa-spinner fa-spin"></i> Lade PDF...</div>`;
        
        fetch(path, { method: 'HEAD' })
            .then(response => {
                if (response.ok) {
                    panel.innerHTML = `<iframe src="${path}" class="preview-object"></iframe>`;
                } else {
                    panel.innerHTML = `<div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p><strong>PDF nicht gefunden (${response.status})</strong></p>
                        <p>Datei: ${escapeHtml(doc.file_name)}</p>
                        <p>Pfad: <code>${escapeHtml(path)}</code></p>
                        <p class="hint">Die Datei wurde möglicherweise noch nicht hochgeladen. Bitte laden Sie die Belege in der Transaktionsverwaltung hoch.</p>
                    </div>`;
                }
            })
            .catch(err => {
                panel.innerHTML = `<div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p><strong>Fehler beim Laden des PDFs</strong></p>
                    <p>Datei: ${escapeHtml(doc.file_name)}</p>
                    <p>Pfad: <code>${escapeHtml(path)}</code></p>
                    <p class="hint">Die Datei wurde möglicherweise noch nicht hochgeladen.</p>
                </div>`;
            });
    }
}

function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(m) {
        return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const initial = selectedInit || (document.querySelector('.transaction-row') ? document.querySelector('.transaction-row').dataset.txId : null);
    if (initial) {
        selectTransaction(initial);
    }

    // Prevent double form submission
    const approveForm = document.getElementById('approveForm');
    if (approveForm) {
        approveForm.addEventListener('submit', function(e) {
            if (this.dataset.submitted === 'true') {
                e.preventDefault();
                return false;
            }
            this.dataset.submitted = 'true';
            // Disable submit button to prevent multiple clicks
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
            // Disable submit button to prevent multiple clicks
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wird gespeichert...';
            }
        });
    }

    // Make whole row clickable for selection (except action buttons)
    document.querySelectorAll('.transaction-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('.row-actions') || e.target.closest('button')) return;
            const id = this.dataset.txId;
            selectTransaction(id);
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
    grid-template-columns: 2fr 1fr;
    gap: 16px;
    align-items: start;
}

@media (max-width: 1024px) {
    .transactions-layout {
        grid-template-columns: 1fr;
    }
}

.table-side { overflow-x: auto; }

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

.link-btn {
    background: #2196f3;
    border: none;
    color: #ffffff !important;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    transition: background 0.2s;
    text-shadow: 0 1px 1px rgba(0,0,0,0.1);
}

.link-btn:hover { 
    background: #1976d2;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    color: #ffffff !important;
}

.link-btn i {
    margin-right: 4px;
}

.row-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.obligation-detail {
    background: #fafafa;
    border-top: 2px solid #e0e0e0 !important;
}

.obligation-block {
    padding: 15px;
    background: #fff;
    border-radius: 4px;
    margin: 5px;
}

.obligation-block h4 {
    margin: 0 0 10px 0;
    font-size: 0.95rem;
    color: #333;
}

.obligation-block.empty {
    color: #999;
    font-style: italic;
}

.preview-card {
    position: sticky;
    top: 0;
    max-height: 100vh;
    overflow-y: auto;
    z-index: 10;
}

.preview-card .card-header {
    background: #f5f5f5;
    border-bottom: 2px solid #ddd;
}

.preview-list {
    margin-bottom: 15px;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
}

.preview-list h4 {
    margin: 0 0 8px 0;
    font-size: 0.9rem;
    color: #555;
}

.preview-list ul {
    margin: 0;
    padding-left: 0;
    list-style: none;
}

.preview-list li {
    margin-bottom: 4px;
}

.preview-frame {
    margin-top: 10px;
    min-height: 400px;
    background: #ffffff;
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 0;
    overflow: hidden;

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

.error-message code {
    background: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.9em;
    color: #333;
    word-break: break-all;
}

.error-message .hint {
    font-size: 0.9em;
    color: #666;
    font-style: italic;
    margin-top: 15px;
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
</style>

<?php include 'includes/footer.php'; ?>
