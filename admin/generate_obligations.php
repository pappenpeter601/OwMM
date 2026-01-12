<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !can_edit_cash()) {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();
$message = '';
$error = '';
$result = null;

// Handle generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_year'])) {
    $year = $_POST['generate_year'];
    $result = generate_fee_obligations($year, $_SESSION['user_id']);
    
    if ($result['generated'] > 0 || $result['skipped'] > 0) {
        $message = "Beitragsforderungen für {$year} erstellt:<br>";
        $message .= "✓ {$result['generated']} neue Forderungen generiert<br>";
        if ($result['skipped'] > 0) {
            $message .= "⚠ {$result['skipped']} übersprungen (bereits vorhanden)";
        }
    } else {
        $error = 'Keine Forderungen erstellt. Prüfen Sie, ob Beitragssätze definiert sind.';
    }
}

// Get existing obligations summary by year
$stmt = $db->query("SELECT 
                    fee_year,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(fee_amount) as total_amount,
                    SUM(paid_amount) as paid_amount
                    FROM member_fee_obligations
                    GROUP BY fee_year
                    ORDER BY fee_year DESC");
$obligations_by_year = $stmt->fetchAll();

// Get active members count
$stmt = $db->query("SELECT COUNT(*) as count FROM members WHERE active = 1");
$active_members = $stmt->fetch()['count'];

// Get current fee configuration
$stmt = $db->query("SELECT * FROM membership_fees WHERE valid_until IS NULL OR valid_until >= CURDATE() ORDER BY member_type, valid_from DESC");
$current_fees = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="content-header">
    <h1>Beitragsforderungen generieren</h1>
    <a href="members.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Zurück zu Mitglieder
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Generate New Obligations -->
<div class="card">
    <div class="card-header">
        <h2>Neue Forderungen erstellen</h2>
    </div>
    <div class="card-body">
        <div class="info-box info">
            <p><i class="fas fa-info-circle"></i> <strong>Massengeneration von Beitragsforderungen</strong></p>
            <p>Erstellt automatisch Beitragsforderungen für alle aktiven Mitglieder basierend auf ihrem Mitgliedertyp und den konfigurierten Beitragssätzen.</p>
            <ul>
                <li>Aktive Mitglieder: <?= $active_members ?></li>
                <li>Bereits vorhandene Forderungen werden übersprungen</li>
                <li>Fälligkeitsdatum wird automatisch auf 31. März gesetzt</li>
            </ul>
        </div>
        
        <form method="POST" style="max-width: 400px; margin: 2rem auto;">
            <div class="form-group">
                <label>Beitragsjahr: *</label>
                <select name="generate_year" required style="width: 100%; padding: 0.5rem; font-size: 1rem;">
                    <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">
                <i class="fas fa-cogs"></i> Forderungen für alle Mitglieder generieren
            </button>
        </form>
    </div>
</div>

<!-- Current Fee Configuration -->
<div class="card">
    <div class="card-header">
        <h2>Aktuelle Beitragssätze</h2>
    </div>
    <div class="card-body">
        <?php if (empty($current_fees)): ?>
            <div class="info-box warning">
                <p><i class="fas fa-exclamation-triangle"></i> <strong>Keine Beitragssätze konfiguriert!</strong></p>
                <p>Bitte definieren Sie Beitragssätze in den <a href="settings.php">Einstellungen</a>, bevor Sie Forderungen generieren.</p>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Mitgliedertyp</th>
                        <th>Mindestbeitrag</th>
                        <th>Gültig ab</th>
                        <th>Gültig bis</th>
                        <th>Beschreibung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($current_fees as $fee): ?>
                        <tr>
                            <td>
                                <?php if ($fee['member_type'] === 'active'): ?>
                                    <span class="badge badge-primary">Einsatzeinheit</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Förderer</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= number_format($fee['minimum_amount'], 2, ',', '.') ?> €</strong></td>
                            <td><?= date('d.m.Y', strtotime($fee['valid_from'])) ?></td>
                            <td><?= $fee['valid_until'] ? date('d.m.Y', strtotime($fee['valid_until'])) : '<span class="badge badge-success">Aktuell gültig</span>' ?></td>
                            <td><?= htmlspecialchars($fee['description'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Existing Obligations Overview -->
<div class="card">
    <div class="card-header">
        <h2>Übersicht vorhandener Forderungen</h2>
    </div>
    <div class="card-body">
        <?php if (empty($obligations_by_year)): ?>
            <p style="text-align: center; color: #666;">Noch keine Forderungen vorhanden.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Jahr</th>
                        <th>Gesamt</th>
                        <th>Offen</th>
                        <th>Teilzahlung</th>
                        <th>Bezahlt</th>
                        <th>Storniert</th>
                        <th>Sollbetrag</th>
                        <th>Eingegangen</th>
                        <th>Ausstehend</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($obligations_by_year as $obl_year): ?>
                        <?php 
                        $outstanding = $obl_year['total_amount'] - $obl_year['paid_amount'];
                        $payment_rate = $obl_year['total_amount'] > 0 ? ($obl_year['paid_amount'] / $obl_year['total_amount']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?= $obl_year['fee_year'] ?></strong></td>
                            <td><?= $obl_year['total'] ?></td>
                            <td>
                                <?php if ($obl_year['open'] > 0): ?>
                                    <span class="badge badge-danger"><?= $obl_year['open'] ?></span>
                                <?php else: ?>
                                    <?= $obl_year['open'] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($obl_year['partial'] > 0): ?>
                                    <span class="badge badge-warning"><?= $obl_year['partial'] ?></span>
                                <?php else: ?>
                                    <?= $obl_year['partial'] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($obl_year['paid'] > 0): ?>
                                    <span class="badge badge-success"><?= $obl_year['paid'] ?></span>
                                <?php else: ?>
                                    <?= $obl_year['paid'] ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $obl_year['cancelled'] ?></td>
                            <td><?= number_format($obl_year['total_amount'], 2, ',', '.') ?> €</td>
                            <td><?= number_format($obl_year['paid_amount'], 2, ',', '.') ?> €</td>
                            <td class="<?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>">
                                <strong><?= number_format($outstanding, 2, ',', '.') ?> €</strong>
                                <br><small><?= number_format($payment_rate, 1) ?>% eingegangen</small>
                            </td>
                            <td>
                                <a href="outstanding_obligations.php?year=<?= $obl_year['fee_year'] ?>" 
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-list"></i> Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.info-box {
    padding: 1rem 1.5rem;
    margin: 1rem 0;
    border-left: 4px solid;
    border-radius: 4px;
}

.info-box.info {
    background: #e3f2fd;
    border-color: #2196f3;
}

.info-box.warning {
    background: #fff3e0;
    border-color: #ff9800;
}

.info-box p {
    margin: 0.5rem 0;
}

.info-box ul {
    margin: 0.5rem 0 0 1.5rem;
}

.btn-large {
    padding: 1rem 2rem;
    font-size: 1.1rem;
}
</style>

<?php include 'includes/footer.php'; ?>
