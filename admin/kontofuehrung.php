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
            $message = $result['message'];
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
        
        // Check if transaction is locked
        if (is_transaction_locked($id)) {
            if (isset($_POST['ajax'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Transaktion ist gesperrt']);
                exit;
            }
            $error = "Diese Transaktion ist gesperrt (finalisiert in einer Prüfperiode) und kann nicht mehr bearbeitet werden.";
        } else {
            // Build dynamic update query - only update fields that are actually submitted
            $updateFields = [];
            $params = ['id' => $id];
            
            if (isset($_POST['category_id'])) {
                $updateFields[] = "category_id = :category_id";
                $params['category_id'] = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            }
            
            if (isset($_POST['comment'])) {
                $updateFields[] = "comment = :comment";
                $params['comment'] = $_POST['comment'];
            }
            
            if (isset($_POST['business_year'])) {
                $updateFields[] = "business_year = :business_year";
                $params['business_year'] = !empty($_POST['business_year']) ? (int)$_POST['business_year'] : null;
            }
            
            if (!empty($updateFields)) {
                $sql = "UPDATE transactions SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                
                if (isset($_POST['ajax'])) {
                    http_response_code(200);
                    echo json_encode(['success' => true]);
                    exit;
                }
                $message = "Transaktion aktualisiert";
            }
        }
    } elseif ($action === 'delete_transaction') {
        $id = $_POST['id'];
        
        // Check if transaction is locked
        if (is_transaction_locked($id)) {
            $error = "Diese Transaktion ist gesperrt (finalisiert in einer Prüfperiode) und kann nicht gelöscht werden.";
        } else {
            // Delete documents
            $stmt = $db->prepare("SELECT id FROM transaction_documents WHERE transaction_id = :id");
            $stmt->execute(['id' => $id]);
            foreach ($stmt->fetchAll() as $doc) {
                delete_transaction_document($doc['id']);
            }
            
            $stmt = $db->prepare("DELETE FROM transactions WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $message = "Transaktion gelöscht";
        }
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
        $obligation_type = $_POST['obligation_type'] ?? 'fee'; // 'fee' or 'item'
        $amount = $_POST['amount'];
        $payment_date = $_POST['payment_date'];
        
        try {
            if ($obligation_type === 'fee') {
                add_payment_to_obligation($obligation_id, $amount, $payment_date, $transaction_id, 'bank', null, $_SESSION['user_id']);
            } elseif ($obligation_type === 'item') {
                // Update item obligation
                $db->beginTransaction();
                
                $stmt = $db->prepare("SELECT total_amount, paid_amount, status FROM item_obligations WHERE id = :id");
                $stmt->execute([':id' => $obligation_id]);
                $obl = $stmt->fetch();
                
                if (!$obl) {
                    throw new Exception('Artikel-Forderung nicht gefunden');
                }
                
                $new_paid = $obl['paid_amount'] + $amount;
                $new_status = 'open';
                
                if ($new_paid >= $obl['total_amount']) {
                    $new_status = 'paid';
                } elseif ($new_paid > 0) {
                    $new_status = 'open'; // item obligations don't have 'partial' status
                }
                
                $stmt = $db->prepare("UPDATE item_obligations SET paid_amount = :paid, status = :status WHERE id = :id");
                $stmt->execute([
                    ':paid' => $new_paid,
                    ':status' => $new_status,
                    ':id' => $obligation_id
                ]);
                
                // Insert payment record
                $stmt = $db->prepare("INSERT INTO item_obligation_payments 
                                     (obligation_id, transaction_id, payment_date, amount, payment_method, created_by) 
                                     VALUES (:obligation_id, :transaction_id, :payment_date, :amount, :payment_method, :created_by)");
                $stmt->execute([
                    ':obligation_id' => $obligation_id,
                    ':transaction_id' => $transaction_id,
                    ':payment_date' => $payment_date,
                    ':amount' => $amount,
                    ':payment_method' => 'bank',
                    ':created_by' => $_SESSION['user_id']
                ]);
                
                $db->commit();
            }
            $message = "Verpflichtung erfolgreich verknüpft";
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Fehler: " . $e->getMessage();
        }
    } elseif ($action === 'unlink_obligation') {
        $payment_id = $_POST['payment_id'];
        $payment_type = $_POST['payment_type'] ?? 'fee';
        
        try {
            $db->beginTransaction();
            
            if ($payment_type === 'item') {
                // Get item payment details
                $stmt = $db->prepare("SELECT p.*, o.total_amount, o.paid_amount 
                                     FROM item_obligation_payments p 
                                     JOIN item_obligations o ON p.obligation_id = o.id 
                                     WHERE p.id = :id");
                $stmt->execute([':id' => $payment_id]);
                $payment = $stmt->fetch();
                
                if ($payment) {
                    // Delete payment
                    $stmt = $db->prepare("DELETE FROM item_obligation_payments WHERE id = :id");
                    $stmt->execute([':id' => $payment_id]);
                    
                    // Update obligation
                    $new_paid = $payment['paid_amount'] - $payment['amount'];
                    $status = 'open';
                    if ($new_paid >= $payment['total_amount']) {
                        $status = 'paid';
                    }
                    
                    $stmt = $db->prepare("UPDATE item_obligations 
                                         SET paid_amount = :paid_amount, status = :status 
                                         WHERE id = :id");
                    $stmt->execute([
                        ':paid_amount' => $new_paid,
                        ':status' => $status,
                        ':id' => $payment['obligation_id']
                    ]);
                }
            } else {
                // Get fee payment details
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
            }
            
            $db->commit();
            $message = "Verknüpfung entfernt";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Fehler: " . $e->getMessage();
        }
    } elseif ($action === 'mark_obligation_paid') {
        $obligation_id = $_POST['obligation_id'];
        $obligation_type = $_POST['obligation_type'] ?? 'fee';
        
        try {
            if ($obligation_type === 'item') {
                // Mark item obligation as paid without changing the paid amount
                $stmt = $db->prepare("UPDATE item_obligations SET status = 'paid' WHERE id = :id");
                $stmt->execute([':id' => $obligation_id]);
            } else {
                // Mark fee obligation as paid without changing the paid amount
                $stmt = $db->prepare("UPDATE member_fee_obligations SET status = 'paid' WHERE id = :id");
                $stmt->execute([':id' => $obligation_id]);
            }
            $message = "Forderung als bezahlt markiert";
        } catch (Exception $e) {
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$category_filter = $_GET['category_id'] ?? '';
$business_year_filter = $_GET['business_year'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status_filter'] ?? ''; // 'open', 'income', 'expense'
$search_text = trim($_GET['search'] ?? '');

// Set default date range to current year if not specified
if (empty($start_date) && empty($end_date)) {
    $start_date = date('Y') . '-01-01';
    $end_date = date('Y') . '-12-31';
}

// Convert German date format (dd.mm.yyyy) to SQL format (yyyy-mm-dd) if needed
// HTML5 date input already provides yyyy-mm-dd format
if ($start_date && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $start_date, $matches)) {
    $start_date = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
}
if ($end_date && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $end_date, $matches)) {
    $end_date = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
}

// Get transactions with category info
$sql = "SELECT t.*, tc.id as cat_id, tc.name as cat_name, tc.color as cat_color, tc.icon as cat_icon 
        FROM transactions t 
        LEFT JOIN transaction_categories tc ON t.category_id = tc.id 
        WHERE 1=1";
$params = [];

if ($category_filter === 'none') {
    $sql .= " AND t.category_id IS NULL";
} elseif ($category_filter) {
    $sql .= " AND t.category_id = :category_id";
    $params['category_id'] = (int)$category_filter;
}

if ($business_year_filter === 'none') {
    $sql .= " AND t.business_year IS NULL";
} elseif ($business_year_filter) {
    $sql .= " AND t.business_year = :business_year";
    $params['business_year'] = (int)$business_year_filter;
}

if ($search_text !== '') {
    $searchLower = '%' . strtolower($search_text) . '%';
    $sql .= " AND (LOWER(t.purpose) LIKE :search1 OR LOWER(t.payer) LIKE :search2 OR LOWER(t.comment) LIKE :search3 OR LOWER(tc.name) LIKE :search4)";
    $params['search1'] = $searchLower;
    $params['search2'] = $searchLower;
    $params['search3'] = $searchLower;
    $params['search4'] = $searchLower;
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

// Debug logging for search
if ($search_text !== '') {
    error_log('[kontofuehrung] SEARCH DEBUG - search_text="' . $search_text . '" SQL=' . $sql . ' PARAMS=' . json_encode($params));
}

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
    
    if ($search_text !== '') {
        error_log('[kontofuehrung] SEARCH DEBUG - Found ' . count($transactions) . ' results before status filter');
    }
} catch (PDOException $e) {
    error_log('[kontofuehrung] Transaction query failed: ' . $e->getMessage() . ' SQL=' . $sql . ' PARAMS=' . json_encode($params));
    $error = 'Fehler beim Laden der Transaktionen. Bitte Kassenwart informieren.';
    $transactions = [];
}

// Apply status filter (post-query filter)
if ($status_filter === 'open') {
    // Only open (not linked) transactions
    $transactions = array_filter($transactions, function($t) use ($db) {
        if ($t['amount'] > 0) {
            // Income: check if no obligation links (neither fee nor item obligations)
            $stmt = $db->prepare("SELECT COUNT(*) FROM member_payments WHERE transaction_id = :id");
            $stmt->execute(['id' => $t['id']]);
            $hasFeePayment = $stmt->fetchColumn() > 0;
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM item_obligation_payments WHERE transaction_id = :id");
            $stmt->execute(['id' => $t['id']]);
            $hasItemPayment = $stmt->fetchColumn() > 0;
            
            return !$hasFeePayment && !$hasItemPayment;
        } else {
            // Expense: check if no documents
            $stmt = $db->prepare("SELECT COUNT(*) FROM transaction_documents WHERE transaction_id = :id");
            $stmt->execute(['id' => $t['id']]);
            return $stmt->fetchColumn() == 0;
        }
    });
} elseif ($status_filter === 'income') {
    // Only income (positive amounts)
    $transactions = array_filter($transactions, function($t) {
        return $t['amount'] > 0;
    });
} elseif ($status_filter === 'expense') {
    // Only expenses (negative amounts)
    $transactions = array_filter($transactions, function($t) {
        return $t['amount'] < 0;
    });
}

if ($search_text !== '') {
    error_log('[kontofuehrung] SEARCH DEBUG - Final result count after filters: ' . count($transactions));
}

// Get categories for dropdown
$stmt = $db->query("SELECT id, name, color, icon FROM transaction_categories WHERE active = 1 ORDER BY sort_order");
$categories = $stmt->fetchAll();

// Get available business years
$stmt = $db->query("SELECT DISTINCT business_year FROM transactions WHERE business_year IS NOT NULL ORDER BY business_year DESC");
$business_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Build active filter hints for UI
$active_filters = [];
if ($category_filter === 'none') {
    $active_filters[] = 'Kategorie: ohne';
} elseif ($category_filter !== '') {
    foreach ($categories as $cat) {
        if ((string)$cat['id'] === (string)$category_filter) {
            $active_filters[] = 'Kategorie: ' . $cat['name'];
            break;
        }
    }
}

if ($business_year_filter === 'none') {
    $active_filters[] = 'Geschäftsjahr: ohne';
} elseif ($business_year_filter !== '') {
    $active_filters[] = 'Geschäftsjahr: ' . $business_year_filter;
}

if ($start_date || $end_date) {
    $range = ($start_date ?: '…') . ' bis ' . ($end_date ?: '…');
    $active_filters[] = 'Zeitraum: ' . $range;
}

if ($status_filter === 'open') {
    $active_filters[] = 'Status: offene (nicht verknüpft)';
} elseif ($status_filter === 'income') {
    $active_filters[] = 'Status: Einnahmen';
} elseif ($status_filter === 'expense') {
    $active_filters[] = 'Status: Ausgaben';
}

if ($search_text !== '') {
    $active_filters[] = 'Suche: "' . $search_text . '"';
}

// Calculate start and end saldo if date filter is used
$start_saldo = null;
$end_saldo = null;

if ($start_date || $end_date) {
    // Calculate start saldo (all transactions before start_date)
    if ($start_date) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as saldo FROM transactions WHERE booking_date < :start_date");
        $stmt->execute(['start_date' => $start_date]);
        $start_saldo = $stmt->fetchColumn();
    } else {
        // If only end_date is set, start from 0
        $start_saldo = 0;
    }
    
    // Calculate end saldo (all transactions up to end_date, or all if no end_date)
    if ($end_date) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as saldo FROM transactions WHERE booking_date <= :end_date");
        $stmt->execute(['end_date' => $end_date]);
        $end_saldo = $stmt->fetchColumn();
    } else {
        // If only start_date is set, calculate up to today
        $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) as saldo FROM transactions");
        $end_saldo = $stmt->fetchColumn();
    }
}

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
    <?php if ($start_saldo !== null): ?>
    <div class="card">
        <div class="card-label">Startsaldo<?php echo $start_date ? ' (' . date('d.m.Y', strtotime($start_date)) . ')' : ''; ?></div>
        <div class="card-value <?php echo $start_saldo >= 0 ? 'income' : 'expense'; ?>"><?php echo number_format($start_saldo, 2, ',', '.'); ?> €</div>
    </div>
    <?php endif; ?>
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
    <?php if ($end_saldo !== null): ?>
    <div class="card">
        <div class="card-label">Endsaldo<?php echo $end_date ? ' (' . date('d.m.Y', strtotime($end_date)) . ')' : ''; ?></div>
        <div class="card-value <?php echo $end_saldo >= 0 ? 'income' : 'expense'; ?>"><?php echo number_format($end_saldo, 2, ',', '.'); ?> €</div>
    </div>
    <?php endif; ?>
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
                <small>Format: Deutscher Bankexport mit Semikolon-Trennung (Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;Zahlungspflichtiger;IBAN;BIC;Betrag;...)<br>
                Import-Logik: Nur Transaktionen für neue Tage werden importiert. Bereits importierte Tage werden komplett übersprungen (inkl. identischer Transaktionen).</small>
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
    <form method="GET" class="filter-form" style="display: flex; flex-direction: column; gap: 0.75rem;">
        <!-- Row 1: main filters -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group">
                <label for="category_id">Kategorie</label>
                <select id="category_id" name="category_id">
                    <option value="">Alle Kategorien</option>
                    <option value="none" <?php echo $category_filter === 'none' ? 'selected' : ''; ?>>⊘ Ohne Kategorie</option>
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
                    <option value="none" <?php echo $business_year_filter === 'none' ? 'selected' : ''; ?>>⊘ Ohne Geschäftsjahr</option>
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
                <input type="date" id="start_date" name="start_date" 
                       value="<?php echo $start_date ? $start_date : ''; ?>">
            </div>
            <div class="form-group">
                <label for="end_date">Bis Datum</label>
                <input type="date" id="end_date" name="end_date"
                       value="<?php echo $end_date ? $end_date : ''; ?>">
            </div>
            <div class="form-group">
                <label for="status_filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="">Alle anzeigen</option>
                    <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Nur offene (nicht verknüpft)</option>
                    <option value="income" <?php echo $status_filter === 'income' ? 'selected' : ''; ?>>Nur Einnahmen</option>
                    <option value="expense" <?php echo $status_filter === 'expense' ? 'selected' : ''; ?>>Nur Ausgaben</option>
                </select>
            </div>
        </div>

        <!-- Row 2: full text search -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group" style="min-width: 280px; flex: 1 1 100%;">
                <label for="search">Suche</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_text); ?>" placeholder="Text, Zahler, Notiz, Kategorie" style="width: 100%;">
            </div>
        </div>

        <!-- Row 3: buttons right aligned -->
        <div class="filter-row" style="display: flex; justify-content: flex-end; gap: 0.5rem; width: 100%;">
            <button type="submit" class="btn btn-secondary">Filtern</button>
            <a href="?" class="btn btn-secondary">Zurücksetzen</a>
        </div>
    </form>
</div>

<?php if (!empty($active_filters)): ?>
<div class="alert" style="background-color: #e8f4fd; color: #0b4f7d; border-left: 4px solid #1976d2; margin-top: 0.5rem;">
    Aktive Filter: <?php echo htmlspecialchars(implode(' · ', $active_filters)); ?>
</div>
<?php endif; ?>

<!-- Transactions Table -->
<div class="section-card transactions-section">
    <h2>Transaktionen (<?php echo count($transactions); ?>)</h2>
    
    <div class="table-responsive">
        <table class="data-table transactions-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Verwendungszweck / Zahler</th>
                    <th>Betrag</th>
                    <th>Kategorie / GJ / Status</th>
                    <th>Dokumente / Verpflichtungen</th>
                    <th>Notizen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $transaction): 
                    $is_locked = !empty($transaction['checked_in_period_id']);
                ?>
                    <tr class="<?php echo $transaction['amount'] > 0 ? 'income-row' : 'expense-row'; ?> <?php echo $is_locked ? 'locked-row' : ''; ?>">
                        <td class="date"><?php echo date('d.m.Y', strtotime($transaction['booking_date'])); ?></td>
                        <td class="purpose-payer">
                            <div style="font-weight: 500; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($transaction['purpose'] ?? $transaction['booking_text']); ?></div>
                            <div style="border-top: 1px solid #ddd; padding-top: 0.5rem; color: #666; font-size: 0.9rem;"><?php echo htmlspecialchars($transaction['payer'] ?? ''); ?></div>
                        </td>
                        <td class="amount <?php echo $transaction['amount'] > 0 ? 'income' : 'expense'; ?>">
                            <?php echo number_format($transaction['amount'], 2, ',', '.'); ?> €
                        </td>
                        <td class="category-gj-status" style="background-color: <?php echo !empty($transaction['cat_color']) ? htmlspecialchars($transaction['cat_color']) . '20' : '#f9f9f9'; ?>; border-left: 4px solid <?php echo !empty($transaction['cat_color']) ? htmlspecialchars($transaction['cat_color']) : '#ccc'; ?>;">
                            <!-- Kategorie -->
                            <div style="margin-bottom: 0.75rem;">
                                <form method="POST" class="inline-form" onsubmit="return updateCategory(event, <?php echo $transaction['id']; ?>);">
                                    <input type="hidden" name="action" value="update_transaction">
                                    <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                    <input type="hidden" name="ajax" value="1">
                                    <select name="category_id" class="category-select" onchange="this.form.requestSubmit()" <?php echo $is_locked ? 'disabled' : ''; ?>>
                                        <option value="">—</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat['id']); ?>"
                                                    <?php echo $transaction['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                            <!-- GJ -->
                            <div style="margin-bottom: 0.75rem;">
                                <form method="POST" class="inline-form" onsubmit="return updateBusinessYear(event, <?php echo $transaction['id']; ?>);">
                                    <input type="hidden" name="action" value="update_transaction">
                                    <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="number" name="business_year" value="<?php echo htmlspecialchars($transaction['business_year']); ?>" 
                                           min="2000" max="2100" style="width: 90px;" onchange="this.form.requestSubmit()" onkeydown="if(event.key==='Enter'){event.preventDefault();}" <?php echo $is_locked ? 'disabled' : ''; ?>>
                                </form>
                            </div>
                            <!-- Status -->
                            <div>
                                <?php if ($is_locked): ?>
                                    <span class="badge badge-locked" title="Transaktion ist finalisiert und gesperrt">🔒 Gesperrt</span>
                                <?php elseif ($transaction['check_status'] === 'checked'): ?>
                                    <span class="badge badge-checked" title="Transaktion wurde geprüft">✓ Geprüft</span>
                                <?php elseif ($transaction['check_status'] === 'under_investigation'): ?>
                                    <span class="badge badge-investigation" title="Transaktion wird geprüft">⚠️ In Prüfung</span>
                                <?php else: ?>
                                    <span class="badge badge-unchecked" title="Transaktion noch nicht geprüft">⏳ Ungeprüft</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="docs-obligations">
                            <?php $is_income = $transaction['amount'] > 0; ?>
                            <?php if ($is_income): ?>
                                <!-- Show Obligations for income -->
                                <?php 
                                // Count both fee and item payments
                                $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total_linked FROM member_payments WHERE transaction_id = :id");
                                $stmt->execute([':id' => $transaction['id']]);
                                $linkInfo = $stmt->fetch();
                                $fee_count = $linkInfo['count'];
                                $fee_linked = $linkInfo['total_linked'];
                                
                                $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total_linked FROM item_obligation_payments WHERE transaction_id = :id");
                                $stmt->execute([':id' => $transaction['id']]);
                                $linkInfo = $stmt->fetch();
                                $item_count = $linkInfo['count'];
                                $item_linked = $linkInfo['total_linked'];
                                
                                $count = $fee_count + $item_count;
                                $linked_total_overview = $fee_linked + $item_linked;
                                $remaining_overview = max(0, $transaction['amount'] - $linked_total_overview);
                                ?>
                                <button class="btn btn-sm btn-secondary" 
                                        onclick="toggleObligations(<?php echo $transaction['id']; ?>)">
                                    <i class="fas fa-link"></i> 
                                    <?php echo $count > 0 ? $count : 'Verknüpfen'; ?>
                                </button>
                                <div style="font-size: 0.85rem; color: #555; margin-top: 0.25rem;">
                                    Offen: <?php echo number_format($remaining_overview, 2, ',', '.'); ?> €
                                </div>
                            <?php else: ?>
                                <!-- Show Documents for expenses -->
                                <button class="btn btn-sm btn-secondary" 
                                        onclick="toggleDocs(<?php echo $transaction['id']; ?>)">
                                    <i class="fas fa-file-pdf"></i> 
                                    <?php 
                                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM transaction_documents WHERE transaction_id = :id");
                                    $stmt->execute([':id' => $transaction['id']]);
                                    $doc_count = $stmt->fetch()['count'];
                                    echo $doc_count > 0 ? $doc_count : 'Hochladen';
                                    ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="notes">
                            <form method="POST" class="inline-form" onsubmit="return updateComment(event, <?php echo $transaction['id']; ?>);">
                                <input type="hidden" name="action" value="update_transaction">
                                <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                <input type="hidden" name="ajax" value="1">
                                <textarea name="comment" placeholder="Notiz..." class="note-input" onchange="this.form.requestSubmit()" rows="2" <?php echo $is_locked ? 'disabled' : ''; ?>><?php echo htmlspecialchars($transaction['comment'] ?? ''); ?></textarea>
                            </form>
                        </td>
                    </tr>
                    
                    <!-- Documents Row (hidden by default) -->
                    <tr id="docs-<?php echo $transaction['id']; ?>" class="documents-row" style="display: none;">
                        <td colspan="9">
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
                                    <?php if ($is_locked): ?>
                                        <p class="alert alert-warning">🔒 Diese Transaktion ist gesperrt. Dokumente können nicht mehr hinzugefügt werden.</p>
                                    <?php else: ?>
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
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Obligations Row (hidden by default) -->
                    <tr id="obls-<?php echo $transaction['id']; ?>" class="obligations-row" style="display: none;">
                        <td colspan="9" data-transaction-id="<?php echo $transaction['id']; ?>">
                            <div class="obligations-panel" style="padding: 1.5rem; overflow-x: auto;">
                                <?php 
                                // Get all open/partial member fee obligations
                                $stmt = $db->query("SELECT o.id, o.fee_year, o.fee_amount as total_amount, o.paid_amount, o.status, 
                                                   m.first_name, m.last_name, m.member_number, 
                                                   'fee' as obligation_type,
                                                   CONCAT('Mitgliedsbeitrag ', o.fee_year) as description
                                                   FROM member_fee_obligations o 
                                                   JOIN members m ON o.member_id = m.id 
                                                   WHERE o.status IN ('open', 'partial') 
                                                   ORDER BY m.last_name, m.first_name, o.fee_year DESC");
                                $fee_obligations = $stmt->fetchAll();
                                
                                // Get all open item obligations
                                $stmt = $db->query("SELECT io.id, io.total_amount, io.paid_amount, io.status,
                                                   COALESCE(m.first_name, '') as first_name,
                                                   COALESCE(m.last_name, io.receiver_name) as last_name,
                                                   COALESCE(m.member_number, '') as member_number,
                                                   'item' as obligation_type,
                                                   CONCAT('Artikel-Forderung #', io.id) as description,
                                                   NULL as fee_year
                                                   FROM item_obligations io
                                                   LEFT JOIN members m ON io.member_id = m.id
                                                   WHERE io.status = 'open'
                                                   ORDER BY io.created_at DESC");
                                $item_obligations = $stmt->fetchAll();
                                
                                // Merge both types
                                $all_obligations = array_merge($fee_obligations, $item_obligations);
                                ?>
                                    <?php 
                                    // Get fee payments
                                    $stmt = $db->prepare("SELECT p.*, o.fee_year, o.fee_amount, o.status, 
                                                          m.first_name, m.last_name, m.member_number, 
                                                          'fee' as payment_type,
                                                          CONCAT('Mitgliedsbeitrag ', o.fee_year) as description
                                                          FROM member_payments p 
                                                          JOIN member_fee_obligations o ON p.obligation_id = o.id 
                                                          JOIN members m ON o.member_id = m.id 
                                                          WHERE p.transaction_id = :id 
                                                          ORDER BY p.payment_date DESC");
                                    $stmt->execute([':id' => $transaction['id']]);
                                    $fee_payments = $stmt->fetchAll();
                                    
                                    // Get item payments
                                    $stmt = $db->prepare("SELECT p.*, o.total_amount, o.status, 
                                                          COALESCE(m.first_name, '') as first_name,
                                                          COALESCE(m.last_name, o.receiver_name) as last_name,
                                                          COALESCE(m.member_number, '') as member_number,
                                                          'item' as payment_type,
                                                          CONCAT('Artikel-Forderung #', o.id) as description,
                                                          NULL as fee_year
                                                          FROM item_obligation_payments p 
                                                          JOIN item_obligations o ON p.obligation_id = o.id 
                                                          LEFT JOIN members m ON o.member_id = m.id 
                                                          WHERE p.transaction_id = :id 
                                                          ORDER BY p.payment_date DESC");
                                    $stmt->execute([':id' => $transaction['id']]);
                                    $item_payments = $stmt->fetchAll();
                                    
                                    // Merge both types
                                    $all_payments = array_merge($fee_payments, $item_payments);
                                    ?>
                                    
                                    <div class="obligations-list">
                                        <?php if (!empty($all_payments)): ?>
                                            <h4><i class="fas fa-link"></i> Verknüpfte Forderungen (<?php echo count($all_payments); ?>):</h4>
                                            <div class="obligations-items">
                                                <?php foreach ($all_payments as $payment): ?>
                                                    <div class="obligation-item" style="background: #f8f9fa; padding: 0.75rem; margin: 0.5rem 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                                        <div style="flex: 1; min-width: 300px;">
                                                            <?php if ($payment['payment_type'] === 'item'): ?>
                                                                <span style="color: #2196f3; font-size: 0.85rem; font-weight: 600; margin-right: 0.5rem;">[ARTIKEL]</span>
                                                            <?php endif; ?>
                                                            <strong><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></strong>
                                                            <?php if (!empty($payment['member_number'])): ?>
                                                                <span style="color: #666;">(<?php echo htmlspecialchars($payment['member_number']); ?>)</span>
                                                            <?php endif; ?>
                                                            <br>
                                                            <small>
                                                                <?php echo htmlspecialchars($payment['description']); ?> | 
                                                                Betrag: <?php echo number_format($payment['amount'], 2, ',', '.'); ?> € | 
                                                                Status: <span class="badge badge-<?php echo $payment['status']; ?>"><?php echo ucfirst($payment['status']); ?></span>
                                                            </small>
                                                        </div>
                                                        <div style="display: flex; gap: 0.5rem; flex-shrink: 0; flex-wrap: wrap;">
                                                            <?php if ($payment['status'] === 'partial' || ($payment['status'] === 'open' && $payment['amount'] > 0)): ?>
                                                                <form method="POST" style="margin: 0;">
                                                                    <input type="hidden" name="action" value="mark_obligation_paid">
                                                                    <input type="hidden" name="obligation_id" value="<?php echo $payment['obligation_id']; ?>">
                                                                    <input type="hidden" name="obligation_type" value="<?php echo $payment['payment_type']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Forderung als bezahlt markieren? Der Restbetrag wird nicht weiter eingefordert.')" title="Forderung als vollständig bezahlt markieren">
                                                                        <i class="fas fa-check-circle"></i> Als bezahlt markieren
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="POST" style="margin: 0;">
                                                                <input type="hidden" name="action" value="unlink_obligation">
                                                                <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                                <input type="hidden" name="payment_type" value="<?php echo $payment['payment_type']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Verknüpfung wirklich entfernen?')">
                                                                    <i class="fas fa-unlink"></i> Entfernen
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="no-obligations" style="color: #999; font-size: 0.9rem;"><i class="fas fa-info-circle"></i> Keine Forderungen verknüpft</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="link-obligation" style="margin-top: 1.5rem; background: #f9f9f9; padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0;">
                                        <h4 style="margin: 0 0 1rem 0; color: #333; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-link" style="color: #2196f3;"></i> Forderung verknüpfen
                                        </h4>
                                        <form method="POST" class="link-form" id="link-form-<?php echo $transaction['id']; ?>" onsubmit="handleLinkObligation(event, <?php echo $transaction['id']; ?>)">
                                            <input type="hidden" name="action" value="link_obligation">
                                            <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                            <input type="hidden" name="obligation_id" id="selected-obl-<?php echo $transaction['id']; ?>" required>
                                            <input type="hidden" name="obligation_type" id="selected-type-<?php echo $transaction['id']; ?>" value="fee">
                                            
                                            <!-- Search Section -->
                                            <div class="form-group" style="margin-bottom: 1rem;">
                                                <label style="font-weight: 600; margin-bottom: 0.5rem; display: block; color: #555;">Mitglied oder Forderung suchen</label>
                                                <div style="position: relative;">
                                                    <input type="text" 
                                                           id="member-search-<?php echo $transaction['id']; ?>" 
                                                           placeholder="Name, Mitgliedsnummer oder Forderungs-ID eingeben..." 
                                                           autocomplete="off"
                                                           style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 6px; font-size: 1rem; transition: border-color 0.2s;"
                                                           onfocus="this.style.borderColor='#2196f3'" 
                                                           onblur="this.style.borderColor='#ddd'">
                                                    <div id="search-results-<?php echo $transaction['id']; ?>" 
                                                         class="search-results" 
                                                         style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 350px; overflow-y: auto; background: white; border: 2px solid #2196f3; border-top: none; border-radius: 0 0 6px 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; margin-top: -2px;">
                                                        <!-- Results will be populated by JavaScript -->
                                                    </div>
                                                    <div id="selected-display-<?php echo $transaction['id']; ?>" 
                                                         style="display: none; margin-top: 0.75rem; padding: 0.75rem 1rem; background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border-radius: 6px; border-left: 4px solid #4caf50;">
                                                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                                                            <div>
                                                                <strong style="color: #2e7d32; font-size: 0.85rem;">AUSGEWÄHLT:</strong>
                                                                <div style="color: #1b5e20; font-weight: 600; margin-top: 0.25rem;" id="selected-text-<?php echo $transaction['id']; ?>"></div>
                                                            </div>
                                                            <button type="button" onclick="clearSelection(<?php echo $transaction['id']; ?>)" 
                                                                    style="padding: 0.5rem 1rem; color: white; background: #f44336; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9rem; font-weight: 600; transition: background 0.2s;"
                                                                    onmouseover="this.style.background='#d32f2f'" 
                                                                    onmouseout="this.style.background='#f44336'">
                                                                <i class="fas fa-times"></i> Ändern
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <script type="application/json" id="obligations-data-<?php echo $transaction['id']; ?>">
                                                        <?php echo json_encode($all_obligations); ?>
                                                    </script>
                                                    <script type="application/json" id="transaction-data-<?php echo $transaction['id']; ?>">
                                                        <?php 
                                                        // Calculate total already linked to this transaction (both fee and item)
                                                        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total_linked 
                                                                             FROM member_payments 
                                                                             WHERE transaction_id = :id");
                                                        $stmt->execute([':id' => $transaction['id']]);
                                                        $fee_linked = $stmt->fetch()['total_linked'];
                                                        
                                                        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total_linked 
                                                                             FROM item_obligation_payments 
                                                                             WHERE transaction_id = :id");
                                                        $stmt->execute([':id' => $transaction['id']]);
                                                        $item_linked = $stmt->fetch()['total_linked'];
                                                        
                                                        $linked_total = $fee_linked + $item_linked;
                                                        echo json_encode([
                                                            'amount' => $transaction['amount'],
                                                            'linked_total' => $linked_total
                                                        ]); 
                                                        ?>
                                                    </script>
                                                </div>
                                            </div>
                                            
                                            <!-- Payment Details Section -->
                                            <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1rem; padding-top: 1rem; padding-bottom: 2rem; border-top: 1px solid #e0e0e0; flex-wrap: wrap;">
                                                <div style="flex: 1; min-width: 200px;">
                                                    <label style="font-weight: 600; margin-bottom: 0.5rem; display: block; color: #555; font-size: 0.9rem;">Betrag (€)</label>
                                                    <div style="position: relative;">
                                                        <input type="number" 
                                                               name="amount" 
                                                               id="amount-<?php echo $transaction['id']; ?>"
                                                               step="0.01" 
                                                               required 
                                                               style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 6px; font-size: 1rem; font-weight: 600;"
                                                               title="Wird automatisch auf Transaktionsbetrag minus bereits verknüpfte Beträge gesetzt">
                                                        <?php 
                                                        // Calculate remaining amount info for display
                                                        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total_linked 
                                                                             FROM member_payments 
                                                                             WHERE transaction_id = :id");
                                                        $stmt->execute([':id' => $transaction['id']]);
                                                        $current_linked = $stmt->fetch()['total_linked'];
                                                        $remaining = abs($transaction['amount']) - $current_linked;
                                                        ?>
                                                        <small id="remaining-<?php echo $transaction['id']; ?>" style="position: absolute; top: 100%; left: 0; color: #666; margin-top: 0.25rem; font-size: 0.8rem; white-space: nowrap;">
                                                            <?php if ($current_linked > 0): ?>
                                                                <i class="fas fa-info-circle"></i> Verfügbar: <strong><?php echo number_format($remaining, 2, ',', '.'); ?> €</strong>
                                                            <?php else: ?>
                                                                <i class="fas fa-coins"></i> Transaktion: <strong><?php echo number_format(abs($transaction['amount']), 2, ',', '.'); ?> €</strong>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                <div style="flex: 0 0 180px;">
                                                    <label style="font-weight: 600; margin-bottom: 0.5rem; display: block; color: #555; font-size: 0.9rem;">Zahlungsdatum</label>
                                                    <input type="date" 
                                                           name="payment_date" 
                                                           value="<?php echo $transaction['booking_date']; ?>" 
                                                           required 
                                                           style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 6px; font-size: 0.95rem;">
                                                </div>
                                                <div style="flex: 0 0 auto; padding-top: 1.75rem;">
                                                    <button type="submit" 
                                                            class="btn btn-success" 
                                                            style="padding: 0.75rem 1.5rem; font-size: 1rem; font-weight: 600; white-space: nowrap; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
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

.transactions-table th.category-gj-status, .transactions-table td.category-gj-status {
    width: 480px;
    vertical-align: middle;
    padding: 0.75rem !important;
}

.transactions-table th.notes, .transactions-table td.notes {
    min-width: 260px;
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
}

.inline-form {
    display: block;
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
    document.getElementById('member-search-' + transactionId).focus();
}

function updateTransactionData(transactionId) {
    // Refresh the transaction data from the DOM
    const transactionDataEl = document.getElementById('transaction-data-' + transactionId);
    if (transactionDataEl) {
        const transactionData = JSON.parse(transactionDataEl.textContent);
        const remaining = Math.abs(transactionData.amount) - transactionData.linked_total;
        const remainingEl = document.getElementById('remaining-' + transactionId);
        if (remainingEl && remaining > 0) {
            remainingEl.textContent = 'Verfügbar: ' + remaining.toFixed(2).replace('.', ',') + ' €';
        }
    }
}

// Real-time search for obligations + re-open last section
document.addEventListener('DOMContentLoaded', function() {
    const openSectionId = sessionStorage.getItem('openObligationSection');
    if (openSectionId) {
        const row = document.getElementById('obls-' + openSectionId);
        if (row) {
            row.style.display = 'table-row';
        }
        const searchField = document.getElementById('member-search-' + openSectionId);
        if (searchField) {
            searchField.focus();
        }
        sessionStorage.removeItem('openObligationSection');
    }

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
                const description = (obl.description || '').toLowerCase();
                const oblId = obl.id ? obl.id.toString() : '';
                
                return fullName.includes(searchTerm) || 
                       memberNumber.includes(searchTerm) ||
                       year.includes(searchTerm) ||
                       description.includes(searchTerm) ||
                       oblId.includes(searchTerm);
            });
            
            if (filtered.length === 0) {
                resultsDiv.innerHTML = '<div style="padding: 1rem; color: #999; text-align: center;">Keine Ergebnisse gefunden</div>';
                resultsDiv.style.display = 'block';
                return;
            }
            
            // Display results
            let html = '';
            filtered.slice(0, 10).forEach(obl => {
                const outstanding = obl.total_amount - obl.paid_amount;
                const memberNum = obl.member_number || '';
                const memberNumDisplay = memberNum ? ` (${memberNum})` : '';
                const typeLabel = obl.obligation_type === 'item' ? '[Artikel] ' : '';
                const displayText = `${typeLabel}${obl.last_name || ''}, ${obl.first_name || ''}${memberNumDisplay} - ${obl.description || ''}`;
                const yearInfo = obl.fee_year ? `Jahr: ${obl.fee_year} | ` : '';
                html += `<div class="search-result-item" 
                             style="padding: 0.75rem; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s;"
                             onmouseover="this.style.background='#f5f5f5'"
                             onmouseout="this.style.background='white'"
                             onclick="selectObligation(${transactionId}, ${obl.id}, '${displayText.replace(/'/g, "\\'")}', ${outstanding}, '${obl.obligation_type}')">
                            <div style="font-weight: 600;">${typeLabel ? '<span style="color: #2196f3; font-size: 0.85rem; margin-right: 0.25rem;">' + typeLabel + '</span>' : ''}${obl.last_name || ''}, ${obl.first_name || ''} ${memberNum ? '<span style="color: #666;">(' + memberNum + ')</span>' : ''}</div>
                            <div style="font-size: 0.85rem; color: #666;">${yearInfo}${obl.description || ''} | Offen: ${outstanding.toFixed(2).replace('.', ',')} €</div>
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

    // Validate amount vs remaining for each transaction on input changes
    document.querySelectorAll('[id^="amount-"]').forEach(function(amountInput) {
        const transactionId = amountInput.id.replace('amount-', '');
        amountInput.addEventListener('input', function() {
            updateLinkButtonState(transactionId);
        });
    });

    // Initial state update
    document.querySelectorAll('[id^="amount-"]').forEach(function(amountInput) {
        const transactionId = amountInput.id.replace('amount-', '');
        updateLinkButtonState(transactionId);
    });
});

function selectObligation(transactionId, obligationId, displayText, amount, obligationType) {
    document.getElementById('selected-obl-' + transactionId).value = obligationId;
    document.getElementById('selected-type-' + transactionId).value = obligationType || 'fee';
    document.getElementById('selected-text-' + transactionId).textContent = displayText;
    const remaining = getRemaining(transactionId);
    const clampedAmount = Math.min(amount, remaining);
    document.getElementById('amount-' + transactionId).value = clampedAmount.toFixed(2);
    document.getElementById('selected-display-' + transactionId).style.display = 'block';
    document.getElementById('member-search-' + transactionId).style.display = 'none';
    document.getElementById('search-results-' + transactionId).style.display = 'none';
    updateLinkButtonState(transactionId);
}

function getRemaining(transactionId) {
    const dataEl = document.getElementById('transaction-data-' + transactionId);
    if (!dataEl) return 0;
    const data = JSON.parse(dataEl.textContent);
    const amount = parseFloat(data.amount);
    if (amount <= 0) return 0;
    return Math.max(0, Math.abs(amount) - parseFloat(data.linked_total));
}

function updateLinkButtonState(transactionId) {
    const remaining = getRemaining(transactionId);
    const amountInput = document.getElementById('amount-' + transactionId);
    const form = document.getElementById('link-form-' + transactionId);
    const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    const remainingEl = document.getElementById('remaining-' + transactionId);
    const amount = parseFloat(amountInput.value || '0');

    if (remainingEl) {
        if (remaining > 0) {
            remainingEl.textContent = 'Verfügbar: ' + remaining.toFixed(2).replace('.', ',') + ' €' + (amount > remaining ? ' (Betrag zu hoch)' : '');
        } else {
            remainingEl.textContent = 'Alle Beträge verknüpft';
        }
    }

    if (submitBtn) {
        submitBtn.disabled = amount > remaining || remaining <= 0;
    }
}

function updateBusinessYear(event, transactionId) {
    event.preventDefault();
    const form = event.target;
    const input = form.querySelector('input[name="business_year"]');
    const originalBorder = input.style.borderColor;
    const formData = new FormData(form);
    
    input.disabled = true;
    input.style.borderColor = '#999';

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            input.style.borderColor = '#4caf50';
            setTimeout(() => {
                input.style.borderColor = originalBorder;
            }, 800);
        } else {
            throw new Error(data.error || 'Update failed');
        }
    })
    .catch(error => {
        console.error('Error updating business year:', error);
        input.style.borderColor = '#f44336';
        setTimeout(() => {
            input.style.borderColor = originalBorder;
        }, 1500);
        alert('Speichern fehlgeschlagen: ' + error.message);
    })
    .finally(() => {
        input.disabled = false;
    });

    return false;
}

function updateCategory(event, transactionId) {
    event.preventDefault();
    const form = event.target;
    const select = form.querySelector('select[name="category_id"]');
    const originalBorder = select.style.borderColor;
    const formData = new FormData(form);
    
    select.disabled = true;
    select.style.borderColor = '#999';

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            select.style.borderColor = '#4caf50';
            setTimeout(() => {
                select.style.borderColor = originalBorder;
            }, 800);
        } else {
            throw new Error(data.error || 'Update failed');
        }
    })
    .catch(error => {
        console.error('Error updating category:', error);
        select.style.borderColor = '#f44336';
        setTimeout(() => {
            select.style.borderColor = originalBorder;
        }, 1500);
        alert('Speichern fehlgeschlagen: ' + error.message);
    })
    .finally(() => {
        select.disabled = false;
    });

    return false;
}

function updateComment(event, transactionId) {
    event.preventDefault();
    const form = event.target;
    const textarea = form.querySelector('textarea[name="comment"]');
    const originalBorder = textarea.style.borderColor;
    const formData = new FormData(form);
    
    textarea.disabled = true;
    textarea.style.borderColor = '#999';

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            textarea.style.borderColor = '#4caf50';
            setTimeout(() => {
                textarea.style.borderColor = originalBorder;
            }, 800);
        } else {
            throw new Error(data.error || 'Update failed');
        }
    })
    .catch(error => {
        console.error('Error updating comment:', error);
        textarea.style.borderColor = '#f44336';
        setTimeout(() => {
            textarea.style.borderColor = originalBorder;
        }, 1500);
        alert('Speichern fehlgeschlagen: ' + error.message);
    })
    .finally(() => {
        textarea.disabled = false;
    });

    return false;
}

function handleLinkObligation(event, transactionId) {
    event.preventDefault();

    const form = document.getElementById('link-form-' + transactionId);
    const formData = new FormData(form);

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wird verknüpft...';

    // Remember which section was open
    sessionStorage.setItem('openObligationSection', transactionId);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        return response.text();
    })
    .then(() => {
        // Reload via GET to avoid Firefox POST-resubmission warning
        window.location = window.location.pathname + window.location.search;
    })
    .catch(error => {
        console.error('Error in handleLinkObligation:', error);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        alert('Fehler beim Verknüpfen: ' + error.message);
        sessionStorage.removeItem('openObligationSection');
    });
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

/* Check Status Badges */
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: bold;
    white-space: nowrap;
}

.badge-locked {
    background-color: #e91e63;
    color: white;
}

.badge-checked {
    background-color: #4caf50;
    color: white;
}

.badge-investigation {
    background-color: #ff9800;
    color: white;
}

.badge-unchecked {
    background-color: #9e9e9e;
    color: white;
}

/* Locked Row Styling */
.locked-row {
    background-color: #ffebee !important;
    opacity: 0.9;
}

.locked-row select:disabled,
.locked-row input:disabled {
    background-color: #f5f5f5;
    cursor: not-allowed;
    opacity: 0.6;
}
</style>

<?php include 'includes/footer.php'; ?>
