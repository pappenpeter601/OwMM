<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !can_edit_cash()) {
    header('Location: login.php');
    exit;
}

$year = $_GET['year'] ?? date('Y');
$tab = $_GET['tab'] ?? 'fees'; // 'fees' or 'items'
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? ''; // 'open', 'partial', 'paid' for fees; 'open', 'partial', 'paid' for items
$member_type_filter = $_GET['member_type'] ?? ''; // 'active', 'supporter', 'pensioner'
$db = getDBConnection();
$error = '';

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
                                  COALESCE(om.last_name, '') as org_last_name
                          FROM item_obligations io
                          LEFT JOIN members m ON io.member_id = m.id
                          LEFT JOIN members om ON io.organizing_member_id = om.id
                          ORDER BY ISNULL(io.due_date) ASC, io.due_date ASC, io.created_at DESC");
    $stmt->execute();
    $open_item_obligations = $stmt->fetchAll();
    
    // Apply search filter to item obligations
    if (!empty($search)) {
        $search_lower = strtolower($search);
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($search_lower) {
            $name = (!empty($obl['member_first_name']) || !empty($obl['member_last_name'])) 
                    ? $obl['member_first_name'] . ' ' . $obl['member_last_name']
                    : $obl['receiver_name'];
            return strpos(strtolower($name), $search_lower) !== false ||
                   strpos(strtolower($obl['member_number']), $search_lower) !== false;
        });
    }
    
    // Apply status filter to item obligations
    if (!empty($status_filter)) {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($status_filter) {
            return $obl['status'] === $status_filter;
        });
    }
    
    // Apply member_type filter to item obligations
    if (!empty($member_type_filter)) {
        $open_item_obligations = array_filter($open_item_obligations, function($obl) use ($member_type_filter) {
            return $obl['member_type'] === $member_type_filter;
        });
    }

    // Calculate totals from ALL item obligations (including paid) 
    $stmt = $db->prepare("SELECT total_amount, paid_amount, (total_amount - paid_amount) as outstanding
                          FROM item_obligations");
    $stmt->execute();
    $all_item_obligations = $stmt->fetchAll();
    
    $item_total_amount = array_sum(array_column($all_item_obligations, 'total_amount'));
    $item_total_paid = array_sum(array_column($all_item_obligations, 'paid_amount'));
    $item_total_outstanding = array_sum(array_column($all_item_obligations, 'outstanding'));
} catch (PDOException $e) {
    error_log("Item obligations query error: " . $e->getMessage());
}

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="generate_obligations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">
            Offene Forderungen
        </h1>
        <a href="create_item_obligation.php" class="btn btn-primary" style="float: right;">
            <i class="fas fa-plus"></i> Artikel-Forderung hinzufügen
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
    <?php if ($tab === 'fees'): ?>
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
    <?php else: ?>
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
        <!-- Row 1: Year, Member Type and Status filters -->
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
                </select>
            </div>
        </div>
        
        <!-- Row 2: Search -->
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 1rem; width: 100%;">
            <div class="form-group" style="min-width: 280px; flex: 1 1 100%;">
                <label for="search">Suche</label>
                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Mitgliedsname oder Mitgliedsnummer..." style="width: 100%;">
            </div>
        </div>
        
        <!-- Row 3: Buttons -->
        <div class="filter-row" style="display: flex; justify-content: flex-end; gap: 0.5rem; width: 100%;">
            <button type="submit" class="btn btn-secondary">Filtern</button>
            <a href="outstanding_obligations.php?tab=<?= htmlspecialchars($tab) ?>" class="btn btn-secondary">Zurücksetzen</a>
        </div>
    </form>
</div>

<?php 
// Build active filter hints for UI
$active_filters = [];
if ($year != date('Y')) {
    $active_filters[] = 'Jahr: ' . $year;
}
if ($member_type_filter !== '') {
    $member_type_labels = ['active' => 'Einsatzeinheit', 'supporter' => 'Förderer', 'pensioner' => 'Altersabteilung'];
    $active_filters[] = 'Typ: ' . ($member_type_labels[$member_type_filter] ?? $member_type_filter);
}
if ($status_filter !== '') {
    $status_labels = ['open' => 'Offen', 'partial' => 'Teilzahlung', 'paid' => 'Bezahlt'];
    $active_filters[] = 'Status: ' . ($status_labels[$status_filter] ?? $status_filter);
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

<!-- Tab Navigation -->
<div class="tabs" style="margin: 1rem 0; border-bottom: 2px solid #e0e0e0; display: flex; gap: 0;">
    <a href="?year=<?= $year ?>&tab=fees&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&member_type=<?= urlencode($member_type_filter) ?>" 
       class="tab-button" style="padding: 0.75rem 1.5rem; border-bottom: 3px solid transparent; text-decoration: none; font-weight: 600; color: #666; <?= $tab === 'fees' ? 'border-bottom-color: #2196f3; color: #2196f3;' : '' ?>">
        <i class="fas fa-file-invoice-dollar"></i> Mitgliedsbeiträge
    </a>
    <a href="?year=<?= $year ?>&tab=items&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&member_type=<?= urlencode($member_type_filter) ?>" 
       class="tab-button" style="padding: 0.75rem 1.5rem; border-bottom: 3px solid transparent; text-decoration: none; font-weight: 600; color: #666; <?= $tab === 'items' ? 'border-bottom-color: #2196f3; color: #2196f3;' : '' ?>">
        <i class="fas fa-boxes"></i> Artikel-Forderungen
    </a>
</div>

<!-- Outstanding Obligations Table -->
<div class="card">
    <div class="card-header">
        <h2><?= $tab === 'fees' ? 'Offene Mitgliedsbeiträge' : 'Offene Artikel-Forderungen' ?></h2>
    </div>
    <div class="card-body">
        <?php if ($tab === 'fees'): ?>
            <!-- Fee Obligations Tab -->
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
        
        <?php else: ?>
            <!-- Item Obligations Tab -->
            <?php if (empty($open_item_obligations)): ?>
                <div class="info-box success">
                    <p><i class="fas fa-check-circle"></i> <strong>Keine offenen Artikel-Forderungen!</strong></p>
                    <p>Alle Artikel-Forderungen wurden bezahlt.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Empfänger</th>
                            <th>Typ</th>
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
                            $receiver_display = $is_member 
                                ? htmlspecialchars($obl['member_first_name'] . ' ' . $obl['member_last_name'])
                                : htmlspecialchars($obl['receiver_name']);
                            ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td>
                                    <strong><?= $receiver_display ?></strong>
                                    <?php if (!$is_member && $obl['receiver_phone']): ?>
                                        <br><small style="color: #666;"><?= htmlspecialchars($obl['receiver_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_member): ?>
                                        <span class="badge badge-primary">Mitglied</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Extern</span>
                                    <?php endif; ?>
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
                                    <?php if ($outstanding == 0): ?>
                                        <span class="badge badge-success">Bezahlt</span>
                                    <?php elseif ($obl['paid_amount'] > 0): ?>
                                        <span class="badge badge-warning">Teilzahlung</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Offen</span>
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
                                    <a href="view_item_obligation.php?id=<?= $obl['id'] ?>" 
                                       class="btn btn-sm btn-secondary" title="Details anzeigen">
                                        <i class="fas fa-eye"></i>
                                    </a>
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
        <?php endif; ?>
    </div>
</div>

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
