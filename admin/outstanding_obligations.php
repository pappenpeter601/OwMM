<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('outstanding_obligations.php')) {
    header('Location: login.php');
    exit;
}

ensure_financial_reporting_support();

$year = $_GET['year'] ?? date('Y');
$tab = $_GET['tab'] ?? 'fees'; // legacy compatibility
$scope_filter = $_GET['scope'] ?? (($tab === 'items' || $tab === 'fees') ? $tab : 'all');
if (!in_array($scope_filter, ['all', 'fees', 'items', 'receivables', 'reimbursements'], true)) {
    $scope_filter = 'all';
}
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? ''; // filter for fees/items and reimbursement review states
$member_type_filter = $_GET['member_type'] ?? ''; // 'active', 'supporter', 'pensioner'
$category_filter = $_GET['category_id'] ?? ''; // category id or 'none'
$db = getDBConnection();
$stmt = $db->query("SELECT id, name, color, active FROM transaction_categories ORDER BY active DESC, sort_order, name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$category_lookup = [];
foreach ($categories as $categoryRow) {
    $category_lookup[(string)$categoryRow['id']] = $categoryRow['name'];
}
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_obligation_category') {
    $obligationId = (int) ($_POST['obligation_id'] ?? 0);
    $obligationType = $_POST['obligation_type'] ?? '';
    $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
    $targetScope = $_POST['target_scope'] ?? $scope_filter;

    try {
        if ($obligationId <= 0) {
            throw new Exception('Verpflichtung nicht gefunden.');
        }

        if ($obligationType === 'fee') {
            $stmt = $db->prepare("UPDATE member_fee_obligations SET category_id = :category_id WHERE id = :id");
        } elseif ($obligationType === 'item') {
            $stmt = $db->prepare("UPDATE item_obligations SET category_id = :category_id WHERE id = :id");
        } else {
            throw new Exception('Ungültiger Verpflichtungstyp.');
        }

        $stmt->bindValue(':id', $obligationId, PDO::PARAM_INT);
        $stmt->bindValue(':category_id', $categoryId, $categoryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['success'] = $categoryId ? 'Kategorie aktualisiert.' : 'Kategorie entfernt.';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    $redirectParams = [
        'year' => $year,
        'scope' => $targetScope
    ];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }
    if ($status_filter !== '') {
        $redirectParams['status'] = $status_filter;
    }
    if ($member_type_filter !== '') {
        $redirectParams['member_type'] = $member_type_filter;
    }
    if ($category_filter !== '') {
        $redirectParams['category_id'] = $category_filter;
    }

    redirect('outstanding_obligations.php?' . http_build_query($redirectParams));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_expense_request') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $notes = trim($_POST['accountant_notes'] ?? '');

    $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
    $result = update_expense_request_status($requestId, $newStatus, $notes, $_SESSION['user_id'] ?? null, $categoryId);
    if (!empty($result['success'])) {
        $_SESSION['success'] = $result['message'] ?? 'Antrag aktualisiert.';
    } else {
        $_SESSION['error'] = $result['error'] ?? 'Fehler beim Aktualisieren des Antrags.';
    }

    $redirectParams = [
        'year' => $year,
        'scope' => 'reimbursements'
    ];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }
    if ($status_filter !== '') {
        $redirectParams['status'] = $status_filter;
    }
    if ($member_type_filter !== '') {
        $redirectParams['member_type'] = $member_type_filter;
    }
    if ($category_filter !== '') {
        $redirectParams['category_id'] = $category_filter;
    }

    redirect('outstanding_obligations.php?' . http_build_query($redirectParams));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_item_obligation') {
    $obligationId = (int) ($_POST['obligation_id'] ?? 0);

    try {
        if ($obligationId <= 0) {
            throw new Exception('Forderung nicht gefunden.');
        }

        $stmt = $db->prepare("SELECT io.id, io.status, io.notes, io.paid_amount, er.id AS expense_request_id
                              FROM item_obligations io
                              LEFT JOIN expense_requests er ON er.linked_item_obligation_id = io.id
                              WHERE io.id = :id
                              LIMIT 1");
        $stmt->execute([':id' => $obligationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception('Forderung nicht gefunden.');
        }
        if (!empty($row['expense_request_id'])) {
            throw new Exception('Erstattungen werden über den Erstattungsstatus verwaltet, nicht per Storno.');
        }
        if (($row['status'] ?? '') === 'paid') {
            throw new Exception('Bereits bezahlte Forderungen können hier nicht storniert werden.');
        }
        if (($row['status'] ?? '') === 'cancelled') {
            throw new Exception('Die Forderung ist bereits storniert.');
        }

        $noteSuffix = "[STORNIERT am " . date('d.m.Y H:i') . "]";
        $updatedNotes = trim((string) ($row['notes'] ?? ''));
        $updatedNotes = $updatedNotes !== '' ? $updatedNotes . "\n" . $noteSuffix : $noteSuffix;

        $stmt = $db->prepare("UPDATE item_obligations SET status = 'cancelled', notes = :notes WHERE id = :id");
        $stmt->execute([
            ':notes' => $updatedNotes,
            ':id' => $obligationId
        ]);

        $_SESSION['success'] = 'Forderung wurde storniert.';
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    $redirectParams = [
        'year' => $year,
        'scope' => $scope_filter
    ];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }
    if ($status_filter !== '') {
        $redirectParams['status'] = $status_filter;
    }
    if ($member_type_filter !== '') {
        $redirectParams['member_type'] = $member_type_filter;
    }
    if ($category_filter !== '') {
        $redirectParams['category_id'] = $category_filter;
    }

    redirect('outstanding_obligations.php?' . http_build_query($redirectParams));
}

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Get fee obligations for the year
$open_obligations = get_open_obligations($year);

// Apply filters to fee obligations
if (!empty($search)) {
    $search_lower = strtolower($search);
    $open_obligations = array_filter($open_obligations, function($obl) use ($search_lower) {
        return strpos(strtolower($obl['first_name'] . ' ' . $obl['last_name']), $search_lower) !== false ||
               strpos(strtolower($obl['member_number']), $search_lower) !== false;
    });
}

if (!empty($status_filter)) {
    $open_obligations = array_filter($open_obligations, function($obl) use ($status_filter) {
        return $obl['status'] === $status_filter;
    });
}

if (!empty($member_type_filter)) {
    $open_obligations = array_filter($open_obligations, function($obl) use ($member_type_filter) {
        return $obl['member_type'] === $member_type_filter;
    });
}

if ($category_filter !== '') {
    $open_obligations = array_filter($open_obligations, function($obl) use ($category_filter) {
        $currentCategoryId = $obl['category_id'] ?? null;
        if ($category_filter === 'none') {
            return empty($currentCategoryId);
        }
        return (int)$currentCategoryId === (int)$category_filter;
    });
}

// Get ALL fee obligations for the year (including paid) for accurate totals
$stmt = $db->prepare("SELECT fee_amount, paid_amount, (fee_amount - paid_amount) as outstanding
                      FROM member_fee_obligations
                      WHERE fee_year = :year");
$stmt->execute(['year' => $year]);
$all_obligations = $stmt->fetchAll();

// Calculate totals from ALL fee obligations (including paid ones)
$total_expected = array_sum(array_column($all_obligations, 'fee_amount'));
$total_paid = array_sum(array_column($all_obligations, 'paid_amount'));
$total_outstanding = array_sum(array_column($all_obligations, 'outstanding'));

// Get item obligations (open only)
$open_item_obligations = [];
$all_item_obligations = [];
$item_total_amount = 0;
$item_total_paid = 0;
$item_total_outstanding = 0;

try {
    $stmt = $db->prepare("SELECT io.*, 
                                  COALESCE(m.first_name, '') as member_first_name,
                                  COALESCE(m.last_name, '') as member_last_name,
                                  COALESCE(m.member_number, '') as member_number,
                                  COALESCE(m.member_type, '') as member_type,
                                  COALESCE(om.first_name, '') as org_first_name,
                                  COALESCE(om.last_name, '') as org_last_name,
                                  er.id AS expense_request_id,
                                  er.status AS expense_request_status,
                                  er.transfer_reference,
                                  er.expense_context,
                                  er.accountant_notes,
                                  er.created_at AS expense_requested_at,
                                  (SELECT COUNT(*) FROM expense_request_documents erd WHERE erd.expense_request_id = er.id) AS document_count,
                                  (SELECT COUNT(*) FROM item_obligation_documents iod WHERE iod.obligation_id = io.id) AS obligation_document_count,
                                  tc.name AS category_name,
                                  tc.color AS category_color
                          FROM item_obligations io
                          LEFT JOIN members m ON io.member_id = m.id
                          LEFT JOIN members om ON io.organizing_member_id = om.id
                          LEFT JOIN expense_requests er ON er.linked_item_obligation_id = io.id
                          LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                          ORDER BY CASE COALESCE(er.status, '')
                                     WHEN 'submitted' THEN 0
                                     WHEN 'approved' THEN 1
                                     WHEN 'paid' THEN 2
                                     WHEN 'rejected' THEN 3
                                     ELSE 4
                                   END,
                                   ISNULL(io.due_date) ASC, io.due_date ASC, io.created_at DESC");
    $stmt->execute();
    $open_item_obligations = $stmt->fetchAll();

    $selectedYear = (int) $year;
    $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($selectedYear) {
        $referenceDate = $obl['due_date']
            ?: ($obl['expense_requested_at'] ?? null)
            ?: ($obl['created_at'] ?? null);

        if (empty($referenceDate)) {
            return true;
        }

        return (int) date('Y', strtotime($referenceDate)) === $selectedYear;
    });
    
    // Apply search filter to item obligations
    if (!empty($search)) {
        $search_lower = strtolower($search);
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($search_lower) {
            $name = (!empty($obl['member_first_name']) || !empty($obl['member_last_name'])) 
                    ? $obl['member_first_name'] . ' ' . $obl['member_last_name']
                    : $obl['receiver_name'];
            return strpos(strtolower($name), $search_lower) !== false ||
                   strpos(strtolower($obl['member_number']), $search_lower) !== false ||
                   strpos(strtolower($obl['transfer_reference'] ?? ''), $search_lower) !== false ||
                   strpos(strtolower($obl['expense_context'] ?? ''), $search_lower) !== false;
        });
    }
    
    // Apply status filter to item obligations
    if (!empty($status_filter)) {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($status_filter) {
            if (!empty($obl['expense_request_id']) && in_array($status_filter, ['submitted', 'approved', 'rejected', 'paid'], true)) {
                return ($obl['expense_request_status'] ?? '') === $status_filter;
            }
            return $obl['status'] === $status_filter;
        });
    }
    
    // Apply member_type filter to item obligations
    if (!empty($member_type_filter)) {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($member_type_filter) {
            return $obl['member_type'] === $member_type_filter;
        });
    }

    if ($category_filter !== '') {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($category_filter) {
            $currentCategoryId = $obl['category_id'] ?? null;
            if ($category_filter === 'none') {
                return empty($currentCategoryId);
            }
            return (int)$currentCategoryId === (int)$category_filter;
        });
    }

    if ($scope_filter === 'receivables') {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) {
            return empty($obl['expense_request_id']);
        });
    } elseif ($scope_filter === 'reimbursements') {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) {
            return !empty($obl['expense_request_id']);
        });
    }

    // Calculate totals from ALL item obligations in the selected year (including paid)
    $stmt = $db->prepare("SELECT io.total_amount,
                                 io.paid_amount,
                                 (io.total_amount - io.paid_amount) AS outstanding,
                                 io.due_date,
                                 io.created_at,
                                 er.created_at AS expense_requested_at
                          FROM item_obligations io
                          LEFT JOIN expense_requests er ON er.linked_item_obligation_id = io.id");
    $stmt->execute();
    $all_item_obligations = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function($obl) use ($selectedYear) {
        $referenceDate = $obl['due_date']
            ?: ($obl['expense_requested_at'] ?? null)
            ?: ($obl['created_at'] ?? null);

        if (empty($referenceDate)) {
            return true;
        }

        return (int) date('Y', strtotime($referenceDate)) === $selectedYear;
    });
    
    $item_total_amount = array_sum(array_column($all_item_obligations, 'total_amount'));
    $item_total_paid = array_sum(array_column($all_item_obligations, 'paid_amount'));
    $item_total_outstanding = array_sum(array_column($all_item_obligations, 'outstanding'));
} catch (PDOException $e) {
    error_log("Item obligations query error: " . $e->getMessage());
}

include 'includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="content-header">
    <div>
        <a href="generate_obligations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">
            Offene Verpflichtungen
        </h1>
        <div style="float: right; display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="expense_requests.php" class="btn btn-secondary">
                <i class="fas fa-receipt"></i> Erstattung einreichen
            </a>
            <a href="create_item_obligation.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Forderung hinzufügen
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
    <?php if ($showFeesSection): ?>
        <div class="stat-card">
            <div class="stat-icon" style="background: #f44336;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= count($open_obligations) ?></div>
                <div class="stat-label">Offene Positionen</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #ff9800;">
                <i class="fas fa-euro-sign"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_outstanding, 2, ',', '.') ?> €</div>
                <div class="stat-label">Ausstehender Betrag</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #9e9e9e;">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_expected, 2, ',', '.') ?> €</div>
                <div class="stat-label">Sollbetrag gesamt</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #4caf50;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_paid, 2, ',', '.') ?> €</div>
                <div class="stat-label">Bereits eingegangen</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showItemsSection): ?>
        <div class="stat-card">
            <div class="stat-icon" style="background: #f44336;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= count($open_item_obligations) ?></div>
                <div class="stat-label">Offene Forderungen</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #ff9800;">
                <i class="fas fa-euro-sign"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($item_total_outstanding, 2, ',', '.') ?> €</div>
                <div class="stat-label">Ausstehender Betrag</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #9e9e9e;">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($item_total_amount, 2, ',', '.') ?> €</div>
                <div class="stat-label">Gesamtbetrag</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #4caf50;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($item_total_paid, 2, ',', '.') ?> €</div>
                <div class="stat-label">Bereits eingegangen</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Year/Tab and Filter Section -->
<div class="section-card">
    <h2>Filter</h2>
    <form method="GET" class="filter-form" style="display: flex; flex-direction: column; gap: 0.75rem;">
        <!-- Row 1: Year, Member Type, Status and Category filters -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group">
                <label for="year">Jahr</label>
                <select id="year" name="year">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="scope">Bereich</label>
                <select id="scope" name="scope">
                    <option value="all" <?= $scope_filter === 'all' ? 'selected' : '' ?>>Alles anzeigen</option>
                    <option value="fees" <?= $scope_filter === 'fees' ? 'selected' : '' ?>>Nur Mitgliedsbeiträge</option>
                    <option value="items" <?= $scope_filter === 'items' ? 'selected' : '' ?>>Forderungen & Erstattungen</option>
                    <option value="receivables" <?= $scope_filter === 'receivables' ? 'selected' : '' ?>>Nur Forderungen</option>
                    <option value="reimbursements" <?= $scope_filter === 'reimbursements' ? 'selected' : '' ?>>Nur Erstattungen</option>
                </select>
            </div>
            <div class="form-group">
                <label for="member_type">Mitgliedertyp</label>
                <select id="member_type" name="member_type">
                    <option value="">Alle Typen</option>
                    <option value="active" <?= $member_type_filter === 'active' ? 'selected' : '' ?>>Einsatzeinheit</option>
                    <option value="supporter" <?= $member_type_filter === 'supporter' ? 'selected' : '' ?>>Förderer</option>
                    <option value="pensioner" <?= $member_type_filter === 'pensioner' ? 'selected' : '' ?>>Altersabteilung</option>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Alle Status</option>
                    <option value="open" <?= $status_filter === 'open' ? 'selected' : '' ?>>Offen</option>
                    <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Teilzahlung</option>
                    <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Bezahlt</option>
                    <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Eingereicht</option>
                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Genehmigt</option>
                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Abgelehnt</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Storniert</option>
                </select>
            </div>
            <div class="form-group">
                <label for="category_id">Kategorie</label>
                <select id="category_id" name="category_id">
                    <option value="">Alle Kategorien</option>
                    <option value="none" <?= $category_filter === 'none' ? 'selected' : '' ?>>Ohne Kategorie</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= ((string)$category_filter === (string)$cat['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name'] . (!empty($cat['active']) ? '' : ' (inaktiv)')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Row 2: Search -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group" style="min-width: 280px; flex: 1 1 100%;">
                <label for="search">Suche</label>
                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name, Mitgliedsnummer, Referenz oder Notiz..." style="width: 100%;">
            </div>
        </div>
        
        <!-- Row 3: Buttons -->
        <div class="filter-row" style="display: flex; justify-content: flex-end; gap: 0.5rem; width: 100%;">
            <button type="submit" class="btn btn-secondary">Filtern</button>
            <a href="outstanding_obligations.php" class="btn btn-secondary">Zurücksetzen</a>
        </div>
    </form>
</div>

<?php 
$showFeesSection = in_array($scope_filter, ['all', 'fees'], true);
$showItemsSection = in_array($scope_filter, ['all', 'items', 'receivables', 'reimbursements'], true);

// Build active filter hints for UI
$active_filters = [];
if ($year != date('Y')) {
    $active_filters[] = 'Jahr: ' . $year;
}
if ($scope_filter !== 'all') {
    $scope_labels = [
        'fees' => 'Mitgliedsbeiträge',
        'items' => 'Forderungen & Erstattungen',
        'receivables' => 'Nur Forderungen',
        'reimbursements' => 'Nur Erstattungen'
    ];
    $active_filters[] = 'Bereich: ' . ($scope_labels[$scope_filter] ?? $scope_filter);
}
if ($member_type_filter !== '') {
    $member_type_labels = ['active' => 'Einsatzeinheit', 'supporter' => 'Förderer', 'pensioner' => 'Altersabteilung'];
    $active_filters[] = 'Typ: ' . ($member_type_labels[$member_type_filter] ?? $member_type_filter);
}
if ($status_filter !== '') {
    $status_labels = ['open' => 'Offen', 'partial' => 'Teilzahlung', 'paid' => 'Bezahlt', 'submitted' => 'Eingereicht', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt', 'cancelled' => 'Storniert'];
    $active_filters[] = 'Status: ' . ($status_labels[$status_filter] ?? $status_filter);
}
if ($category_filter !== '') {
    $active_filters[] = 'Kategorie: ' . ($category_filter === 'none' ? 'Ohne Kategorie' : ($category_lookup[(string)$category_filter] ?? $category_filter));
}
if ($search !== '') {
    $active_filters[] = 'Suche: "' . htmlspecialchars($search) . '"';
}
?>

<?php if (!empty($active_filters)): ?>
<div class="alert" style="background-color: #e8f4fd; color: #0b4f7d; border-left: 4px solid #1976d2; margin-top: 0.5rem;">
    Aktive Filter: <?= htmlspecialchars(implode(' · ', $active_filters)) ?>
</div>
<?php endif; ?>

<!-- Outstanding Obligations Table -->
<?php if ($showFeesSection): ?>
<div class="card">
    <div class="card-header">
        <h2>Offene Mitgliedsbeiträge</h2>
    </div>
    <div class="card-body">
            <?php if (empty($open_obligations)): ?>
                <div class="info-box success">
                    <p><i class="fas fa-check-circle"></i> <strong>Alle Beiträge für <?= $year ?> wurden bezahlt!</strong></p>
                    <p>Es gibt keine offenen Forderungen für dieses Jahr.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Mitgliedsnr.</th>
                            <th>Name</th>
                            <th>Typ</th>
                            <th>Sollbetrag</th>
                            <th>Gezahlt</th>
                            <th>Offen</th>
                            <th>Kategorie</th>
                            <th>Status</th>
                            <th>Fälligkeitsdatum</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($open_obligations as $obl): ?>
                            <?php 
                            $is_overdue = $obl['due_date'] && strtotime($obl['due_date']) < time();
                            ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td><?= htmlspecialchars($obl['member_number'] ?? '-') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($obl['first_name'] . ' ' . $obl['last_name']) ?></strong>
                                </td>
                                <td>
                                    <?php if ($obl['member_type'] === 'active'): ?>
                                        <span class="badge badge-primary">Einsatzeinheit</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">Förderer</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($obl['fee_amount'], 2, ',', '.') ?> €</td>
                                <td><?= number_format($obl['paid_amount'], 2, ',', '.') ?> €</td>
                                <td class="text-danger">
                                    <strong><?= number_format($obl['outstanding'], 2, ',', '.') ?> €</strong>
                                </td>
                                <td>
                                    <?php if (!empty($obl['category_name'])): ?>
                                        <span class="badge" style="background: <?= htmlspecialchars($obl['category_color'] ?: '#607d8b') ?>; color: #fff;"><?= htmlspecialchars($obl['category_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #777; font-size: 0.9rem;">Ohne Kategorie</span>
                                    <?php endif; ?>
                                    <form method="POST" style="margin-top: 0.4rem; display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                                        <input type="hidden" name="action" value="update_obligation_category">
                                        <input type="hidden" name="obligation_type" value="fee">
                                        <input type="hidden" name="obligation_id" value="<?= (int)$obl['id'] ?>">
                                        <input type="hidden" name="target_scope" value="<?= htmlspecialchars($scope_filter) ?>">
                                        <select name="category_id" style="min-width: 150px; padding: 0.3rem 0.45rem; border: 1px solid #ddd; border-radius: 4px;">
                                            <option value="">Ohne Kategorie</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= (int)$cat['id'] ?>" <?= ((int)($obl['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cat['name'] . (!empty($cat['active']) ? '' : ' (inaktiv)')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-secondary">Speichern</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($obl['status'] === 'partial'): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-clock"></i> Teilzahlung
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-exclamation-triangle"></i> Offen
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $obl['due_date'] ? date('d.m.Y', strtotime($obl['due_date'])) : '-' ?>
                                    <?php if ($is_overdue): ?>
                                        <br><span class="overdue-badge">
                                            <i class="fas fa-exclamation-circle"></i> Überfällig
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-buttons">
                                    <a href="member_payments.php?id=<?= $obl['member_id'] ?>" 
                                       class="btn btn-sm btn-info" title="Zahlungen">
                                        <i class="fas fa-euro-sign"></i>
                                    </a>
                                    <a href="members.php#member-<?= $obl['member_id'] ?>" 
                                       class="btn btn-sm btn-secondary" title="Mitglied anzeigen">
                                        <i class="fas fa-user"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="card-footer">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Mahnliste drucken
                    </button>
                    <button class="btn btn-secondary" onclick="exportCSV()">
                        <i class="fas fa-download"></i> Als CSV exportieren
                    </button>
                    <button class="btn btn-secondary" onclick="copyNamesToClipboard(this)">
                        <i class="fas fa-copy"></i> Namen kopieren
                    </button>
                </div>
            <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($showItemsSection): ?>
<div class="card" style="margin-top: 1rem;">
    <div class="card-header">
        <h2>Forderungen & Erstattungen</h2>
    </div>
    <div class="card-body">
            <?php if (empty($open_item_obligations)): ?>
                <div class="info-box success">
                    <p><i class="fas fa-check-circle"></i> <strong>Keine offenen Forderungen!</strong></p>
                    <p>Alle allgemeinen Forderungen wurden bezahlt oder es liegen aktuell nur Erstattungen vor.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Empfänger</th>
                            <th>Typ</th>
                            <th>Kategorie</th>
                            <th>Organisierendes Mitglied</th>
                            <th>Gesamtbetrag</th>
                            <th>Gezahlt</th>
                            <th>Offen</th>
                            <th>Status</th>
                            <th>Fälligkeitsdatum</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($open_item_obligations as $obl): ?>
                            <?php 
                            $is_overdue = $obl['due_date'] && strtotime($obl['due_date']) < time();
                            $outstanding = $obl['total_amount'] - $obl['paid_amount'];
                            $is_member = !empty($obl['member_id']);
                            $is_expense_request = !empty($obl['expense_request_id']);
                            $receiver_display = $is_member 
                                ? htmlspecialchars(trim($obl['member_first_name'] . ' ' . $obl['member_last_name']))
                                : htmlspecialchars($obl['receiver_name']);
                            $request_status = $obl['expense_request_status'] ?? '';
                            ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td>
                                    <strong><?= $receiver_display ?></strong>
                                    <?php if ($is_expense_request): ?>
                                        <br><small style="color: #666;">Referenz: <?= htmlspecialchars($obl['transfer_reference']) ?></small>
                                        <br><small style="color: #666;"><?= (int) ($obl['document_count'] ?? 0) ?> Beleg<?= ((int) ($obl['document_count'] ?? 0) === 1) ? '' : 'e' ?></small>
                                    <?php else: ?>
                                        <?php if (!empty($obl['notes'])): ?>
                                            <br><small style="color: #666;"><?= htmlspecialchars(mb_strimwidth($obl['notes'], 0, 90, '…')) ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($obl['obligation_document_count'])): ?>
                                            <br><small style="color: #666;"><?= (int) $obl['obligation_document_count'] ?> Dokument<?= ((int) $obl['obligation_document_count'] === 1) ? '' : 'e' ?></small>
                                        <?php endif; ?>
                                        <?php if (!$is_member && $obl['receiver_phone']): ?>
                                            <br><small style="color: #666;"><?= htmlspecialchars($obl['receiver_phone']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_expense_request): ?>
                                        <span class="badge" style="background: #ede7f6; color: #5e35b1;">Erstattung</span>
                                        <br>
                                        <span class="badge <?= $is_member ? 'badge-primary' : 'badge-secondary' ?>" style="margin-top: 0.25rem;"><?= $is_member ? 'Mitglied' : 'Extern' ?></span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #e3f2fd; color: #1565c0;">Forderung</span>
                                        <br>
                                        <span class="badge <?= $is_member ? 'badge-primary' : 'badge-secondary' ?>" style="margin-top: 0.25rem;"><?= $is_member ? 'Mitglied' : 'Extern' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($obl['category_name'])): ?>
                                        <span class="badge" style="background: <?= htmlspecialchars($obl['category_color'] ?: '#607d8b') ?>; color: #fff;"><?= htmlspecialchars($obl['category_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color: #777; font-size: 0.9rem;">Ohne Kategorie</span>
                                    <?php endif; ?>
                                    <form method="POST" style="margin-top: 0.4rem; display: flex; gap: 0.35rem; align-items: center; flex-wrap: wrap;">
                                        <input type="hidden" name="action" value="update_obligation_category">
                                        <input type="hidden" name="obligation_type" value="item">
                                        <input type="hidden" name="obligation_id" value="<?= (int)$obl['id'] ?>">
                                        <input type="hidden" name="target_scope" value="<?= htmlspecialchars($scope_filter) ?>">
                                        <select name="category_id" style="min-width: 150px; padding: 0.3rem 0.45rem; border: 1px solid #ddd; border-radius: 4px;">
                                            <option value="">Ohne Kategorie</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= (int)$cat['id'] ?>" <?= ((int)($obl['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cat['name'] . (!empty($cat['active']) ? '' : ' (inaktiv)')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-secondary">Speichern</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($obl['organizing_member_id']): ?>
                                        <small><?= htmlspecialchars($obl['org_first_name'] . ' ' . $obl['org_last_name']) ?></small>
                                    <?php else: ?>
                                        <small style="color: #999;">-</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($obl['total_amount'], 2, ',', '.') ?> €</td>
                                <td><?= number_format($obl['paid_amount'], 2, ',', '.') ?> €</td>
                                <td class="text-danger">
                                    <strong><?= number_format($outstanding, 2, ',', '.') ?> €</strong>
                                </td>
                                <td>
                                    <?php if ($is_expense_request): ?>
                                        <?php if ($request_status === 'paid'): ?>
                                            <span class="badge badge-success">Ausgezahlt</span>
                                        <?php elseif ($request_status === 'approved'): ?>
                                            <span class="badge badge-primary">Genehmigt</span>
                                        <?php elseif ($request_status === 'rejected'): ?>
                                            <span class="badge badge-secondary">Abgelehnt</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Eingereicht</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($obl['status'] === 'cancelled'): ?>
                                            <span class="badge badge-secondary">Storniert</span>
                                        <?php elseif ($outstanding == 0): ?>
                                            <span class="badge badge-success">Bezahlt</span>
                                        <?php elseif ($obl['paid_amount'] > 0): ?>
                                            <span class="badge badge-warning">Teilzahlung</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Offen</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $obl['due_date'] ? date('d.m.Y', strtotime($obl['due_date'])) : '-' ?>
                                    <?php if ($is_overdue): ?>
                                        <br><span class="overdue-badge">
                                            <i class="fas fa-exclamation-circle"></i> Überfällig
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-buttons">
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center;">
                                        <a href="view_item_obligation.php?id=<?= $obl['id'] ?>" 
                                           class="btn btn-sm btn-secondary" title="Details anzeigen">
                                            <i class="fas fa-eye"></i> Details
                                        </a>
                                        <?php if (!empty($obl['member_id'])): ?>
                                            <a href="members.php?edit=<?= (int) $obl['member_id'] ?>" class="btn btn-sm btn-info" title="Mitglied anzeigen">
                                                <i class="fas fa-user"></i> Mitglied
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!$is_expense_request && ($obl['status'] ?? '') !== 'cancelled' && (float) $obl['paid_amount'] < (float) $obl['total_amount']): ?>
                                            <form method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Forderung wirklich stornieren?');">
                                                <input type="hidden" name="action" value="cancel_item_obligation">
                                                <input type="hidden" name="obligation_id" value="<?= (int) $obl['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Stornieren">
                                                    <i class="fas fa-ban"></i> Storno
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($is_expense_request && in_array($request_status, ['submitted', 'approved'], true)): ?>
                                        <form method="POST" style="display: flex; flex-direction: column; gap: 0.35rem; margin-top: 0.5rem; min-width: 220px;">
                                            <input type="hidden" name="action" value="review_expense_request">
                                            <input type="hidden" name="request_id" value="<?= (int) $obl['expense_request_id'] ?>">
                                            <select name="category_id" style="width: 100%; padding: 0.35rem 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                                <option value="">Kategorie wählen</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($obl['category_id'] ?? 0) === (int) $cat['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($cat['name'] . (!empty($cat['active']) ? '' : ' (inaktiv)')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="accountant_notes" value="<?= htmlspecialchars($obl['accountant_notes'] ?? '') ?>" placeholder="Notiz optional" style="width: 100%; padding: 0.35rem 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                            <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                                                <?php if ($request_status === 'submitted'): ?>
                                                    <button type="submit" name="new_status" value="approved" class="btn btn-sm btn-success">Genehmigen</button>
                                                <?php endif; ?>
                                                <?php if ($request_status === 'approved'): ?>
                                                    <button type="submit" name="new_status" value="paid" class="btn btn-sm btn-primary">Ausgezahlt</button>
                                                <?php endif; ?>
                                                <button type="submit" name="new_status" value="rejected" class="btn btn-sm btn-danger">Ablehnen</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="card-footer">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Liste drucken
                    </button>
                </div>
            <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
.tabs {
    display: flex;
    gap: 0.5rem;
    margin-left: auto;
}

.tab-button {
    padding: 0.5rem 1rem;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 4px 4px 0 0;
    text-decoration: none;
    color: #333;
    cursor: pointer;
    transition: all 0.3s;
}

.tab-button:hover {
    background: #e0e0e0;
}

.tab-button.active {
    background: white;
    border-bottom-color: white;
    color: #1976d2;
    font-weight: bold;
    border-bottom: 2px solid #1976d2;
}

.overdue-row {
    background-color: #ffebee;
    font-weight: 600;
}

.overdue-badge {
    color: #d32f2f;
    font-size: 0.85rem;
    font-weight: bold;
}

.info-box {
    padding: 1.5rem;
    margin: 1rem 0;
    border-left: 4px solid;
    border-radius: 4px;
}

.info-box.success {
    background: #e8f5e9;
    border-color: #4caf50;
}

.card-footer {
    padding: 1rem;
    background: #f5f5f5;
    border-top: 1px solid #ddd;
    display: flex;
    gap: 1rem;
}

@media print {
    .content-header, .filters-bar, .card-footer, .action-buttons, .btn, .tabs {
        display: none !important;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #000;
    }
    
    h1 {
        font-size: 1.5rem;
    }
    
    .data-table {
        font-size: 0.9rem;
    }
}
</style>

<script>
function exportCSV() {
    const table = document.querySelector('.data-table');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        cols.forEach((col, idx) => {
            // Skip last column (actions)
            if (idx < cols.length - 1) {
                rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
            }
        });
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'forderungen.csv';
    link.click();
}

function copyNamesToClipboard(button) {
    const table = document.querySelector('.data-table');
    const rows = table.querySelectorAll('tbody tr');
    let names = [];
    
    rows.forEach(row => {
        // Get the name from the second column (index 1)
        const nameCell = row.querySelector('td:nth-child(2)');
        if (nameCell) {
            // Extract just the name without extra whitespace/formatting
            const name = nameCell.querySelector('strong')?.innerText || nameCell.innerText;
            if (name) {
                names.push(name.trim());
            }
        }
    });
    
    const namesText = names.join('\n');
    
    // Copy to clipboard
    navigator.clipboard.writeText(namesText).then(() => {
        // Show success message
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Kopiert!';
        button.classList.add('btn-success');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('btn-success');
        }, 2000);
    }).catch(err => {
        alert('Fehler beim Kopieren: ' + err);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
