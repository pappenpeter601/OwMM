<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_cash()) {
    redirect('dashboard.php');
}

$db = getDBConnection();
$message = '';
$error = '';

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $result = upload_csv_transactions($_FILES['csv_file']);
        if ($result['success']) {
            $message = "Erfolgreich {$result['inserted']} Transaktionen importiert";
        } else {
            $error = $result['error'];
        }
    } else {
        $error = "Fehler beim Datei-Upload";
    }
}

// Handle transaction actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'update_transaction') {
        $id = $_POST['id'];
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $comment = $_POST['comment'];
        $business_year = !empty($_POST['business_year']) ? (int)$_POST['business_year'] : null;
        
        $stmt = $db->prepare("UPDATE transactions SET category_id = :category_id, comment = :comment, business_year = :business_year WHERE id = :id");
        $stmt->execute([
            'category_id' => $category_id,
            'comment' => $comment,
            'business_year' => $business_year,
            'id' => $id
        ]);
        $message = "Transaktion aktualisiert";
    } elseif ($action === 'delete_transaction') {
        $id = $_POST['id'];
        
        // Delete documents
        $stmt = $db->prepare("SELECT id FROM transaction_documents WHERE transaction_id = :id");
        $stmt->execute(['id' => $id]);
        foreach ($stmt->fetchAll() as $doc) {
            delete_transaction_document($doc['id']);
        }
        
        $stmt = $db->prepare("DELETE FROM transactions WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $message = "Transaktion gelöscht";
    } elseif ($action === 'upload_document') {
        $transaction_id = $_POST['transaction_id'];
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $result = upload_transaction_document($_FILES['document'], $transaction_id);
            if ($result['success']) {
                $message = "Dokument erfolgreich hochgeladen";
            } else {
                $error = $result['error'];
            }
        } else {
            $error = "Fehler beim Datei-Upload";
        }
    } elseif ($action === 'delete_document') {
        $doc_id = $_POST['document_id'];
        if (delete_transaction_document($doc_id)) {
            $message = "Dokument gelöscht";
        } else {
            $error = "Fehler beim Löschen des Dokuments";
        }
    } elseif ($action === 'link_obligation') {
        $transaction_id = $_POST['transaction_id'];
        $obligation_id = $_POST['obligation_id'];
        $amount = $_POST['amount'];
        $payment_date = $_POST['payment_date'];
        
        try {
            add_payment_to_obligation($obligation_id, $amount, $payment_date, $transaction_id, 'bank', null, $_SESSION['user_id']);
            $message = "Verpflichtung erfolgreich verknüpft";
        } catch (Exception $e) {
            $error = "Fehler: " . $e->getMessage();
        }
    } elseif ($action === 'unlink_obligation') {
        $payment_id = $_POST['payment_id'];
        
        try {
            $db->beginTransaction();
            
            // Get payment details
            $stmt = $db->prepare("SELECT p.*, o.fee_amount, o.paid_amount 
                                 FROM member_payments p 
                                 JOIN member_fee_obligations o ON p.obligation_id = o.id 
                                 WHERE p.id = :id");
            $stmt->execute([':id' => $payment_id]);
            $payment = $stmt->fetch();
            
            if ($payment) {
                // Delete payment
                $stmt = $db->prepare("DELETE FROM member_payments WHERE id = :id");
                $stmt->execute([':id' => $payment_id]);
                
                // Update obligation
                $new_paid = $payment['paid_amount'] - $payment['amount'];
                $status = 'partial';
                if ($new_paid <= 0) {
                    $status = 'open';
                } elseif ($new_paid >= $payment['fee_amount']) {
                    $status = 'paid';
                }
                
                $stmt = $db->prepare("UPDATE member_fee_obligations 
                                     SET paid_amount = :paid_amount, status = :status 
                                     WHERE id = :id");
                $stmt->execute([
                    ':paid_amount' => $new_paid,
                    ':status' => $status,
                    ':id' => $payment['obligation_id']
                ]);
            }
            
            $db->commit();
            $message = "Verknüpfung entfernt";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$category_filter = $_GET['category_id'] ?? '';
$business_year_filter = $_GET['business_year'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Get transactions with category info
$sql = "SELECT t.*, tc.id as cat_id, tc.name as cat_name, tc.color as cat_color, tc.icon as cat_icon 
        FROM transactions t 
        LEFT JOIN transaction_categories tc ON t.category_id = tc.id 
        WHERE 1=1";
$params = [];

if ($category_filter) {
    $sql .= " AND t.category_id = :category_id";
    $params['category_id'] = (int)$category_filter;
}

if ($business_year_filter) {
    $sql .= " AND t.business_year = :business_year";
    $params['business_year'] = (int)$business_year_filter;
}

if ($start_date) {
    $sql .= " AND t.booking_date >= :start_date";
    $params['start_date'] = $start_date;
}

if ($end_date) {
    $sql .= " AND t.booking_date <= :end_date";
    $params['end_date'] = $end_date;
}

$sql .= " ORDER BY t.booking_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Get categories for dropdown
$stmt = $db->query("SELECT id, name, color, icon FROM transaction_categories WHERE active = 1 ORDER BY sort_order");
$categories = $stmt->fetchAll();

// Get available business years
$stmt = $db->query("SELECT DISTINCT business_year FROM transactions WHERE business_year IS NOT NULL ORDER BY business_year DESC");
$business_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate totals
$total_income = 0;
$total_expense = 0;
foreach ($transactions as $t) {
    if ($t['amount'] > 0) {
        $total_income += $t['amount'];
    } else {
        $total_expense += abs($t['amount']);
    }
}

$page_title = 'Kontoführung';
include 'includes/header.php';
?>

<?php if (!empty($message)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Kontoführung - Kassenprüfung</h1>
</div>

<!-- Summary Cards -->
<div class="summary-cards">
    <div class="card">
        <div class="card-label">Gesamte Einnahmen</div>
        <div class="card-value income"><?php echo number_format($total_income, 2, ',', '.'); ?> €</div>
    </div>
    <div class="card">
        <div class="card-label">Gesamte Ausgaben</div>
        <div class="card-value expense"><?php echo number_format($total_expense, 2, ',', '.'); ?> €</div>
    </div>
    <div class="card">
        <div class="card-label">Nettosaldo</div>
        <div class="card-value <?php echo ($total_income - $total_expense) >= 0 ? 'income' : 'expense'; ?>">
            <?php echo number_format($total_income - $total_expense, 2, ',', '.'); ?> €
        </div>
    </div>
</div>

<!-- Import Section -->
<div class="section-card">
    <h2>CSV-Datei importieren</h2>
    <form method="POST" enctype="multipart/form-data" class="import-form">
        <input type="hidden" name="action" value="import_csv">
        <div class="form-row">
            <div class="form-group flex-grow">
                <label for="csv_file">CSV-Datei (Bankexport)</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                <small>Spalten: Buchungstag, Buchungstext, Verwendungszweck, Zahlungspflichtiger, IBAN, Betrag, Kategorie</small>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">Importieren</button>
            </div>
        </div>
    </form>
</div>

<!-- Filter Section -->
<div class="section-card">
    <h2>Filter</h2>
    <form method="GET" class="filter-form">
        <div class="form-row">
            <div class="form-group">
                <label for="category_id">Kategorie</label>
                <select id="category_id" name="category_id">
                    <option value="">Alle Kategorien</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['id']); ?>" 
                                <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                            <span style="color: <?php echo htmlspecialchars($cat['color']); ?>;">■</span>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="business_year">Geschäftsjahr</label>
                <select id="business_year" name="business_year">
                    <option value="">Alle Jahre</option>
                    <?php foreach ($business_years as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" 
                                <?php echo $business_year_filter == $year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="start_date">Von Datum</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="form-group">
                <label for="end_date">Bis Datum</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-secondary">Filtern</button>
                <a href="?" class="btn btn-secondary">Zurücksetzen</a>
            </div>
        </div>
    </form>
</div>

<!-- Transactions Table -->
<div class="section-card transactions-section">
    <h2>Transaktionen (<?php echo count($transactions); ?>)</h2>
    
    <div class="table-responsive">
        <table class="data-table transactions-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Verwendungszweck</th>
                    <th>Zahler</th>
                    <th>Betrag</th>
                    <th>Kategorie</th>
                    <th>GJ</th>
                    <th>Dokumente</th>
                    <th>Verpflichtungen</th>
                    <th>Notizen</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <tr class="<?php echo $transaction['amount'] > 0 ? 'income-row' : 'expense-row'; ?>">
                        <td class="date"><?php echo date('d.m.Y', strtotime($transaction['booking_date'])); ?></td>
                        <td class="purpose"><?php echo htmlspecialchars($transaction['purpose'] ?? $transaction['booking_text']); ?></td>
                        <td class="payer"><?php echo htmlspecialchars($transaction['payer'] ?? ''); ?></td>
                        <td class="amount <?php echo $transaction['amount'] > 0 ? 'income' : 'expense'; ?>">
                            <?php echo number_format($transaction['amount'], 2, ',', '.'); ?> €
                        </td>
                        <td class="category" style="background-color: <?php echo !empty($transaction['cat_color']) ? htmlspecialchars($transaction['cat_color']) . '20' : '#f9f9f9'; ?>; border-left: 4px solid <?php echo !empty($transaction['cat_color']) ? htmlspecialchars($transaction['cat_color']) : '#ccc'; ?>;">
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="update_transaction">
                                <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                <select name="category_id" class="category-select" onchange="this.form.submit()" style="min-width: 150px;">
                                    <option value="">—</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['id']); ?>"
                                                <?php echo $transaction['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="business-year">
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="update_transaction">
                                <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                <input type="number" name="business_year" value="<?php echo htmlspecialchars($transaction['business_year']); ?>" 
                                       min="2000" max="2100" style="width: 60px;" onchange="this.form.submit()">
                            </form>
                        </td>
                        <td class="documents">
                            <button class="btn btn-sm btn-secondary" 
                                    onclick="toggleDocs(<?php echo $transaction['id']; ?>)">
                                <i class="fas fa-file-pdf"></i> 
                                <?php 
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM transaction_documents WHERE transaction_id = :id");
                                $stmt->execute([':id' => $transaction['id']]);
                                $count = $stmt->fetch()['count'];
                                echo $count > 0 ? $count : 'Hochladen';
                                ?>
                            </button>
                        </td>
                        <td class="obligations">
                            <button class="btn btn-sm btn-secondary" 
                                    onclick="toggleObligations(<?php echo $transaction['id']; ?>)">
                                <i class="fas fa-link"></i> 
                                <?php 
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM member_payments WHERE transaction_id = :id");
                                $stmt->execute([':id' => $transaction['id']]);
                                $count = $stmt->fetch()['count'];
                                echo $count > 0 ? $count : 'Verknüpfen';
                                ?>
                            </button>
                        </td>
                        <td class="notes">
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="update_transaction">
                                <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                <textarea name="comment" placeholder="Notiz..." class="note-input" onchange="this.form.submit()" rows="2" style="min-height: 50px; min-width: 250px;"><?php echo htmlspecialchars($transaction['comment'] ?? ''); ?></textarea>
                            </form>
                        </td>
                        <td class="actions">
                            <button class="btn btn-sm btn-info" onclick="toggleDocs(<?php echo $transaction['id']; ?>); toggleObligations(<?php echo $transaction['id']; ?>);" title="Alle Details anzeigen/verbergen">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </td>
                    </tr>
                    
                    <!-- Documents Row (hidden by default) -->
                    <tr id="docs-<?php echo $transaction['id']; ?>" class="documents-row" style="display: none;">
                        <td colspan="10">
                            <div class="documents-panel">
                                <?php 
                                $stmt = $db->prepare("SELECT * FROM transaction_documents WHERE transaction_id = :id ORDER BY uploaded_at DESC");
                                $stmt->execute(['id' => $transaction['id']]);
                                $documents = $stmt->fetchAll();
                                ?>
                                
                                <div class="documents-list">
                                    <?php if (!empty($documents)): ?>
                                        <h4><i class="fas fa-file-pdf"></i> Verknüpfte Dokumente (<?php echo count($documents); ?>):</h4>
                                        <div class="doc-items">
                                            <?php foreach ($documents as $doc): ?>
                                                <div class="doc-item">
                                                    <a href="../uploads/<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                       target="_blank" class="doc-link" onclick="openDocumentPreview('<?php echo htmlspecialchars($doc['file_path']); ?>', '<?php echo htmlspecialchars($doc['file_name']); ?>'); return false;">
                                                        <i class="fas fa-file-pdf"></i>
                                                        <strong><?php echo htmlspecialchars($doc['file_name']); ?></strong>
                                                        <span class="doc-size">(<?php echo round($doc['file_size'] / 1024, 1); ?> KB)</span>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="no-docs" style="color: #999; font-size: 0.9rem;"><i class="fas fa-info-circle"></i> Keine Dokumente verknüpft</p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="upload-document">
                                    <h4>Neues Dokument verknüpfen</h4>
                                    <form method="POST" enctype="multipart/form-data" class="upload-form">
                                        <input type="hidden" name="action" value="upload_document">
                                        <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                        <div class="form-row">
                                            <div class="form-group flex-grow">
                                                <input type="file" name="document" accept=".pdf,.jpg,.png" required>
                                                <small>PDF, JPG oder PNG (max. 5MB)</small>
                                            </div>
                                            <button type="submit" class="btn btn-primary">Hochladen</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Obligations Row (hidden by default) -->
                    <tr id="obls-<?php echo $transaction['id']; ?>" class="obligations-row" style="display: none;">
                        <td colspan="10">
                            <div class="obligations-panel" style="padding: 1.5rem; overflow-x: auto;">
                                <?php 
                                // Get all open/partial obligations for search
                                $stmt = $db->query("SELECT o.id, o.fee_year, o.fee_amount, o.paid_amount, o.status, 
                                                   m.first_name, m.last_name, m.member_number 
                                                   FROM member_fee_obligations o 
                                                   JOIN members m ON o.member_id = m.id 
                                                   WHERE o.status IN ('open', 'partial') 
                                                   ORDER BY m.last_name, m.first_name, o.fee_year DESC");
                                $all_obligations = $stmt->fetchAll();
                                ?>
                                    <?php 
                                    $stmt = $db->prepare("SELECT p.*, o.fee_year, o.fee_amount, o.status, 
                                                          m.first_name, m.last_name, m.member_number 
                                                          FROM member_payments p 
                                                          JOIN member_fee_obligations o ON p.obligation_id = o.id 
                                                          JOIN members m ON o.member_id = m.id 
                                                          WHERE p.transaction_id = :id 
                                                          ORDER BY p.payment_date DESC");
                                    $stmt->execute([':id' => $transaction['id']]);
                                    $payments = $stmt->fetchAll();
                                    ?>
                                    
                                    <div class="obligations-list">
                                        <?php if (!empty($payments)): ?>
                                            <h4><i class="fas fa-link"></i> Verknüpfte Mitgliedsbeiträge (<?php echo count($payments); ?>):</h4>
                                            <div class="obligations-items">
                                                <?php foreach ($payments as $payment): ?>
                                                    <div class="obligation-item" style="background: #f8f9fa; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                                        <div style="flex: 1; min-width: 300px;">
                                                            <strong><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></strong>
                                                            <span style="color: #666;">(<?php echo htmlspecialchars($payment['member_number']); ?>)</span>
                                                            <br>
                                                            <small>
                                                                Jahr: <?php echo htmlspecialchars($payment['fee_year']); ?> | 
                                                                Betrag: <?php echo number_format($payment['amount'], 2, ',', '.'); ?> € | 
                                                                Status: <span class="badge badge-<?php echo $payment['status']; ?>"><?php echo ucfirst($payment['status']); ?></span>
                                                            </small>
                                                        </div>
                                                        <form method="POST" style="margin: 0; flex-shrink: 0;">
                                                            <input type="hidden" name="action" value="unlink_obligation">
                                                            <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Verknüpfung wirklich entfernen?')">
                                                                <i class="fas fa-unlink"></i> Entfernen
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="no-obligations" style="color: #999; font-size: 0.9rem;"><i class="fas fa-info-circle"></i> Keine Beitragsverpflichtungen verknüpft</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="link-obligation" style="margin-top: 1rem;">
                                        <h4>Beitragsverpflichtung verknüpfen</h4>
                                        <form method="POST" class="link-form" id="link-form-<?php echo $transaction['id']; ?>">
                                            <input type="hidden" name="action" value="link_obligation">
                                            <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                            <input type="hidden" name="obligation_id" id="selected-obl-<?php echo $transaction['id']; ?>" required>
                                            <div class="form-row" style="display: flex; gap: 0.75rem; align-items: flex-end;">
                                                <div class="form-group" style="flex: 1; min-width: 200px; max-width: 350px;">
                                                    <label>Mitglied suchen</label>
                                                    <div style="position: relative;">
                                                        <input type="text" 
                                                               id="member-search-<?php echo $transaction['id']; ?>" 
                                                               placeholder="Name oder Mitgliedsnummer eingeben..." 
                                                               autocomplete="off"
                                                               style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                                        <div id="search-results-<?php echo $transaction['id']; ?>" 
                                                             class="search-results" 
                                                             style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 300px; overflow-y: auto; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 100;">
                                                            <!-- Results will be populated by JavaScript -->
                                                        </div>
                                                        <div id="selected-display-<?php echo $transaction['id']; ?>" 
                                                             style="display: none; margin-top: 0.5rem; padding: 0.5rem; background: #e8f5e9; border-radius: 4px; font-size: 0.9rem;">
                                                            <strong>Ausgewählt:</strong> <span id="selected-text-<?php echo $transaction['id']; ?>"></span>
                                                            <button type="button" onclick="clearSelection(<?php echo $transaction['id']; ?>)" style="margin-left: 1rem; color: #f44336; background: none; border: none; cursor: pointer;">
                                                                <i class="fas fa-times"></i> Ändern
                                                            </button>
                                                        </div>
                                                        <script type="application/json" id="obligations-data-<?php echo $transaction['id']; ?>">
                                                            <?php echo json_encode($all_obligations); ?>
                                                        </script>
                                                    </div>
                                                </div>
                                                <div class="form-group" style="flex: 0 0 120px;">
                                                    <label>Betrag (€)</label>
                                                    <input type="number" 
                                                           name="amount" 
                                                           id="amount-<?php echo $transaction['id']; ?>"
                                                           step="0.01" 
                                                           required 
                                                           style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                                </div>
                                                <div class="form-group" style="flex: 0 0 140px;">
                                                    <label>Datum</label>
                                                    <input type="date" name="payment_date" value="<?php echo $transaction['booking_date']; ?>" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                                </div>
                                                <div class="form-group" style="flex: 0 0 auto;">
                                                    <button type="submit" class="btn btn-success" style="white-space: nowrap;">
                                                        <i class="fas fa-link"></i> Verknüpfen
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
}

.card-label {
    font-size: 0.9rem;
    color: #666;
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    font-weight: 600;
}

.card-value {
    font-size: 2rem;
    font-weight: 700;
}

.card-value.income {
    color: #4caf50;
}

.card-value.expense {
    color: #f44336;
}

.section-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.section-card h2 {
    margin-top: 0;
    color: var(--dark-color);
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: 1rem;
}

.import-form .form-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.import-form .flex-grow {
    flex: 1;
}

.filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.filter-form .form-row {
    display: contents;
}

.transactions-table {
    font-size: 0.9rem;
}

.transactions-table tbody tr.income-row {
    background-color: #f0f8f0;
}

.transactions-table tbody tr.expense-row {
    background-color: #fef5f5;
}

.transactions-table .amount.income {
    color: #4caf50;
    font-weight: 600;
}

.transactions-table .amount.expense {
    color: #f44336;
    font-weight: 600;
}

.category-select {
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 0.9rem;
    width: 100%;
    min-width: 150px;
}

.note-input {
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 0.9rem;
    width: 100%;
    min-width: 250px;
    min-height: 50px;
    font-family: inherit;
    resize: vertical;
}

.inline-form {
    display: inline;
}

.documents-row td {
    padding: 0 !important;
    background-color: #f9f9f9 !important;
}

.documents-panel {
    padding: 1.5rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.documents-list h4,
.upload-document h4 {
    margin-top: 0;
    color: var(--dark-color);
    font-size: 0.95rem;
}

.doc-items {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.doc-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: white;
    border-radius: 4px;
    border: 1px solid var(--border-color);
}

.doc-link {
    flex: 1;
    color: var(--primary-color);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.doc-link:hover {
    text-decoration: underline;
    background-color: rgba(33, 150, 243, 0.1);
    color: var(--primary-color);
}

.doc-link .doc-size {
    color: #999;
    font-size: 0.85rem;
    margin-left: auto;
}

.no-docs {
    padding: 1rem;
    text-align: center;
    background: #f9f9f9;
    border-radius: 4px;
}

.upload-form {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
}

.upload-form .form-group {
    flex: 1;
}

.upload-form input[type="file"] {
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
}

.upload-form small {
    display: block;
    margin-top: 0.25rem;
    color: #666;
    font-size: 0.85rem;
}

.badge {
    display: inline-block;
    padding: 0.25em 0.6em;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    text-align: center;
    white-space: nowrap;
    vertical-align: baseline;
    border-radius: 0.25rem;
}

.badge-open {
    background-color: #ff9800;
    color: white;
}

.badge-partial {
    background-color: #2196f3;
    color: white;
}

.badge-paid {
    background-color: #4caf50;
    color: white;
}

.badge-cancelled {
    background-color: #9e9e9e;
    color: white;
}

@media (max-width: 1024px) {
    .transactions-table {
        font-size: 0.85rem;
    }
    
    .documents-panel {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .filter-form {
        grid-template-columns: 1fr;
    }
    
    .import-form .form-row {
        flex-direction: column;
    }
    
    .transactions-table {
        min-width: 800px;
    }
}
</style>

<script>
function toggleDocs(transactionId) {
    const row = document.getElementById('docs-' + transactionId);
    if (row.style.display === 'none') {
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}

function toggleObligations(transactionId) {
    const row = document.getElementById('obls-' + transactionId);
    if (row.style.display === 'none') {
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}

function openDocumentPreview(filePath, fileName) {
    const modal = document.getElementById('pdf-preview-modal');
    const iframe = document.getElementById('pdf-preview-iframe');
    const title = document.getElementById('pdf-preview-title');
    
    title.textContent = fileName;
    iframe.src = '../uploads/' + filePath;
    modal.style.display = 'block';
}

function closePdfPreview() {
    const modal = document.getElementById('pdf-preview-modal');
    modal.style.display = 'none';
    document.getElementById('pdf-preview-iframe').src = '';
}

window.onclick = function(event) {
    const modal = document.getElementById('pdf-preview-modal');
    if (event.target == modal) {
        modal.style.display = 'none';
        document.getElementById('pdf-preview-iframe').src = '';
    }
}

function clearSelection(transactionId) {
    document.getElementById('selected-obl-' + transactionId).value = '';
    document.getElementById('member-search-' + transactionId).value = '';
    document.getElementById('amount-' + transactionId).value = '';
    document.getElementById('selected-display-' + transactionId).style.display = 'none';
    document.getElementById('member-search-' + transactionId).style.display = 'block';
}

// Real-time search for obligations
document.addEventListener('DOMContentLoaded', function() {
    // Setup search for each transaction
    document.querySelectorAll('[id^="member-search-"]').forEach(function(input) {
        const transactionId = input.id.replace('member-search-', '');
        const resultsDiv = document.getElementById('search-results-' + transactionId);
        const obligationsData = JSON.parse(document.getElementById('obligations-data-' + transactionId).textContent);
        
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            if (searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            // Filter obligations
            const filtered = obligationsData.filter(obl => {
                const fullName = ((obl.last_name || '') + ' ' + (obl.first_name || '')).toLowerCase();
                const memberNumber = (obl.member_number || '').toLowerCase();
                const year = obl.fee_year ? obl.fee_year.toString() : '';
                
                return fullName.includes(searchTerm) || 
                       memberNumber.includes(searchTerm) ||
                       year.includes(searchTerm);
            });
            
            if (filtered.length === 0) {
                resultsDiv.innerHTML = '<div style="padding: 1rem; color: #999; text-align: center;">Keine Ergebnisse gefunden</div>';
                resultsDiv.style.display = 'block';
                return;
            }
            
            // Display results
            let html = '';
            filtered.slice(0, 10).forEach(obl => {
                const outstanding = obl.fee_amount - obl.paid_amount;
                const memberNum = obl.member_number || 'N/A';
                const displayText = `${obl.last_name || ''}, ${obl.first_name || ''} (${memberNum}) - Jahr ${obl.fee_year || ''}`;
                html += `<div class="search-result-item" 
                             style="padding: 0.75rem; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s;"
                             onmouseover="this.style.background='#f5f5f5'"
                             onmouseout="this.style.background='white'"
                             onclick="selectObligation(${transactionId}, ${obl.id}, '${displayText.replace(/'/g, "\\'")}', ${outstanding})">
                            <div style="font-weight: 600;">${obl.last_name || ''}, ${obl.first_name || ''} <span style="color: #666;">(${memberNum})</span></div>
                            <div style="font-size: 0.85rem; color: #666;">Jahr: ${obl.fee_year || ''} | Offen: ${outstanding.toFixed(2).replace('.', ',')} €</div>
                        </div>`;
            });
            
            if (filtered.length > 10) {
                html += `<div style="padding: 0.5rem; text-align: center; color: #999; font-size: 0.85rem;">... und ${filtered.length - 10} weitere</div>`;
            }
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        });
        
        // Hide results when clicking outside
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        });
    });
});

function selectObligation(transactionId, obligationId, displayText, amount) {
    document.getElementById('selected-obl-' + transactionId).value = obligationId;
    document.getElementById('selected-text-' + transactionId).textContent = displayText;
    document.getElementById('amount-' + transactionId).value = amount.toFixed(2);
    document.getElementById('selected-display-' + transactionId).style.display = 'block';
    document.getElementById('member-search-' + transactionId).style.display = 'none';
    document.getElementById('search-results-' + transactionId).style.display = 'none';
}
</script>

<!-- PDF Preview Modal -->
<div id="pdf-preview-modal" class="pdf-modal" style="display: none;">
    <div class="pdf-modal-content">
        <div class="pdf-modal-header">
            <span id="pdf-preview-title"></span>
            <button class="pdf-modal-close" onclick="closePdfPreview()">&times;</button>
        </div>
        <div class="pdf-modal-body">
            <iframe id="pdf-preview-iframe" src="" style="width: 100%; height: 600px; border: none;"></iframe>
        </div>
    </div>
</div>

<style>
.pdf-modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    display: none;
}

.pdf-modal-content {
    background-color: white;
    margin: 2% auto;
    padding: 0;
    border: 1px solid #888;
    border-radius: 8px;
    width: 90%;
    height: 90%;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
}

.pdf-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e0e0e0;
    background-color: #f5f5f5;
}

.pdf-modal-header span {
    font-weight: 600;
    color: #333;
}

.pdf-modal-close {
    background: none;
    border: none;
    font-size: 2rem;
    font-weight: bold;
    color: #999;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pdf-modal-close:hover {
    color: #333;
}

.pdf-modal-body {
    flex: 1;
    padding: 1rem;
    overflow: auto;
}
</style>

<?php include 'includes/footer.php'; ?>
