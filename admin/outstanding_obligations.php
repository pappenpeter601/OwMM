<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !can_edit_cash()) {
    header('Location: login.php');
    exit;
}

$year = $_GET['year'] ?? date('Y');
$db = getDBConnection();

// Get open obligations for the year
$open_obligations = get_open_obligations($year);

// Get ALL obligations for the year (including paid) for accurate totals
$stmt = $db->prepare("SELECT fee_amount, paid_amount, (fee_amount - paid_amount) as outstanding
                      FROM member_fee_obligations
                      WHERE fee_year = :year");
$stmt->execute([':year' => $year]);
$all_obligations = $stmt->fetchAll();

// Calculate totals from ALL obligations (including paid ones)
$total_expected = array_sum(array_column($all_obligations, 'fee_amount'));
$total_paid = array_sum(array_column($all_obligations, 'paid_amount'));
$total_outstanding = array_sum(array_column($all_obligations, 'outstanding'));

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="generate_obligations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">
            Offene Forderungen <?= $year ?>
        </h1>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
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
</div>

<!-- Year Filter -->
<div class="filters-bar">
    <form method="GET" class="filters-form">
        <div class="filter-group">
            <label>Jahr:</label>
            <select name="year" onchange="this.form.submit()">
                <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </form>
</div>

<!-- Outstanding Obligations Table -->
<div class="card">
    <div class="card-header">
        <h2>Offene Forderungen</h2>
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
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
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
    .content-header, .filters-bar, .card-footer, .action-buttons, .btn {
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
    link.download = 'offene_forderungen_<?= $year ?>.csv';
    link.click();
}
</script>

<?php include 'includes/footer.php'; ?>
