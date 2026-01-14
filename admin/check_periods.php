<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
check_auth();

// Only admins and kassenprüfer can access
if (!has_role('admin') && !has_role('kassenpruefer')) {
    $_SESSION['error'] = 'Zugriff verweigert.';
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Prüfperioden';
$db = getDBConnection();

// Get current user's member_id if they are kassenprüfer
$user_member_id = null;
if (has_role('kassenpruefer')) {
    $stmt = $db->prepare("SELECT m.id FROM members m 
                         JOIN users u ON m.email = u.email 
                         WHERE u.id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_member_id = $result['id'] ?? null;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create' && has_role('admin')) {
            // Create new check period
            $period_name = $_POST['period_name'];
            $business_year = intval($_POST['business_year']);
            $date_from = $_POST['date_from'];
            $date_to = $_POST['date_to'];
            $leader_id = intval($_POST['leader_id']);
            $assistant_id = intval($_POST['assistant_id']);
            $notes = $_POST['notes'] ?? '';
            
            // Validate that leader and assistant are different
            if ($leader_id === $assistant_id) {
                $_SESSION['error'] = 'Leiter und Assistent müssen unterschiedliche Personen sein.';
            } else {
                $stmt = $db->prepare("INSERT INTO check_periods 
                                     (period_name, business_year, date_from, date_to, leader_id, assistant_id, notes, created_by) 
                                     VALUES (:period_name, :business_year, :date_from, :date_to, :leader_id, :assistant_id, :notes, :created_by)");
                $stmt->execute([
                    'period_name' => $period_name,
                    'business_year' => $business_year,
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'leader_id' => $leader_id,
                    'assistant_id' => $assistant_id,
                    'notes' => $notes,
                    'created_by' => $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = 'Prüfperiode erfolgreich erstellt.';
            }
            
            header('Location: check_periods.php');
            exit;
            
        } elseif ($_POST['action'] === 'finalize') {
            // Finalize a check period (only leader can do this)
            $period_id = intval($_POST['period_id']);
            
            // Check if user is the leader of this period
            $stmt = $db->prepare("SELECT leader_id FROM check_periods WHERE id = :id AND status = 'in_progress'");
            $stmt->execute(['id' => $period_id]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$period) {
                $_SESSION['error'] = 'Prüfperiode nicht gefunden oder bereits finalisiert.';
            } elseif ($period['leader_id'] != $user_member_id && !has_role('admin')) {
                $_SESSION['error'] = 'Nur der Prüfleiter kann die Periode finalisieren.';
            } else {
                // Check if all transactions are checked
                $stmt = $db->prepare("SELECT COUNT(*) as unchecked 
                                     FROM transactions 
                                     WHERE booking_date BETWEEN 
                                         (SELECT date_from FROM check_periods WHERE id = :id) AND 
                                         (SELECT date_to FROM check_periods WHERE id = :id)
                                     AND check_status = 'unchecked'");
                $stmt->execute(['id' => $period_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['unchecked'] > 0) {
                    $_SESSION['error'] = 'Es gibt noch ' . $result['unchecked'] . ' ungeprüfte Transaktionen. Alle Transaktionen müssen geprüft sein.';
                } else {
                    $db->beginTransaction();
                    try {
                        // Finalize the period
                        $stmt = $db->prepare("UPDATE check_periods 
                                            SET status = 'finalized', 
                                                finalized_at = NOW(), 
                                                finalized_by = :finalized_by 
                                            WHERE id = :id");
                        $stmt->execute([
                            'finalized_by' => $_SESSION['user_id'],
                            'id' => $period_id
                        ]);
                        
                        // Lock all checked transactions in this period
                        $stmt = $db->prepare("UPDATE transactions 
                                            SET checked_in_period_id = :period_id 
                                            WHERE booking_date BETWEEN 
                                                (SELECT date_from FROM check_periods WHERE id = :period_id2) AND 
                                                (SELECT date_to FROM check_periods WHERE id = :period_id3)
                                            AND check_status IN ('checked', 'under_investigation')");
                        $stmt->execute([
                            'period_id' => $period_id,
                            'period_id2' => $period_id,
                            'period_id3' => $period_id
                        ]);
                        
                        $db->commit();
                        $_SESSION['success'] = 'Prüfperiode erfolgreich finalisiert. Alle Transaktionen sind jetzt gesperrt.';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $_SESSION['error'] = 'Fehler beim Finalisieren: ' . $e->getMessage();
                    }
                }
            }
            
            header('Location: check_periods.php');
            exit;
        }
    }
}

// Get check periods
$stmt = $db->query("SELECT cp.*, 
                   ml.first_name as leader_first, ml.last_name as leader_last,
                   ma.first_name as assistant_first, ma.last_name as assistant_last,
                   u.first_name as finalized_by_first, u.last_name as finalized_by_last,
                   (SELECT COUNT(*) FROM transactions t 
                    WHERE t.booking_date BETWEEN cp.date_from AND cp.date_to) as total_transactions,
                   (SELECT COUNT(*) FROM transactions t 
                    WHERE t.booking_date BETWEEN cp.date_from AND cp.date_to 
                    AND t.check_status = 'checked') as checked_transactions,
                   (SELECT COUNT(*) FROM transactions t 
                    WHERE t.booking_date BETWEEN cp.date_from AND cp.date_to 
                    AND t.check_status = 'under_investigation') as investigation_transactions
                   FROM check_periods cp
                   JOIN members ml ON cp.leader_id = ml.id
                   JOIN members ma ON cp.assistant_id = ma.id
                   LEFT JOIN users u ON cp.finalized_by = u.id
                   ORDER BY cp.business_year DESC, cp.date_from DESC");
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current kassenprüfer for dropdown
$stmt = $db->query("SELECT ka.member_id, ka.role_type, m.first_name, m.last_name 
                   FROM kassenpruefer_assignments ka
                   JOIN members m ON ka.member_id = m.id
                   WHERE ka.valid_until IS NULL");
$current_kassenpruefer = $stmt->fetchAll(PDO::FETCH_ASSOC);

$leader = null;
$assistant = null;
foreach ($current_kassenpruefer as $kp) {
    if ($kp['role_type'] === 'leader') $leader = $kp;
    if ($kp['role_type'] === 'assistant') $assistant = $kp;
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-calendar-check"></i> <?php echo $page_title; ?></h1>
    <p>Prüfperioden erstellen und verwalten</p>
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

<?php if (has_role('admin')): ?>
<div class="card">
    <div class="card-header">
        <h2>Neue Prüfperiode erstellen</h2>
    </div>
    <div class="card-body">
        <?php if (!$leader || !$assistant): ?>
            <div class="alert alert-warning">
                <strong>Achtung:</strong> Es sind nicht beide Kassenprüfer-Rollen zugewiesen.
                Bitte weisen Sie zuerst einen <a href="kassenpruefer_assignments.php">Leiter und Assistenten</a> zu.
            </div>
        <?php else: ?>
            <form method="POST" class="form">
                <input type="hidden" name="action" value="create">
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="period_name">Periodenname *</label>
                        <input type="text" name="period_name" id="period_name" class="form-control" 
                               placeholder="z.B. Jahresprüfung 2025" required>
                    </div>
                    
                    <div class="form-group col-md-6">
                        <label for="business_year">Geschäftsjahr *</label>
                        <input type="number" name="business_year" id="business_year" class="form-control" 
                               value="<?php echo date('Y') - 1; ?>" min="2020" max="<?php echo date('Y'); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="date_from">Datum von *</label>
                        <input type="date" name="date_from" id="date_from" class="form-control" required>
                    </div>
                    
                    <div class="form-group col-md-6">
                        <label for="date_to">Datum bis *</label>
                        <input type="date" name="date_to" id="date_to" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="leader_id">Prüfleiter *</label>
                        <select name="leader_id" id="leader_id" class="form-control" required>
                            <option value="<?php echo $leader['member_id']; ?>" selected>
                                👑 <?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?> (aktueller Leiter)
                            </option>
                        </select>
                    </div>
                    
                    <div class="form-group col-md-6">
                        <label for="assistant_id">Prüfassistent *</label>
                        <select name="assistant_id" id="assistant_id" class="form-control" required>
                            <option value="<?php echo $assistant['member_id']; ?>" selected>
                                🆕 <?php echo htmlspecialchars($assistant['first_name'] . ' ' . $assistant['last_name']); ?> (aktueller Assistent)
                            </option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notizen</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Prüfperiode erstellen
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Prüfperioden</h2>
    </div>
    <div class="card-body">
        <?php if (empty($periods)): ?>
            <p class="text-muted">Keine Prüfperioden vorhanden.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Periodenname</th>
                            <th>Zeitraum</th>
                            <th>Prüfer</th>
                            <th>Fortschritt</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $period): ?>
                            <tr>
                                <td>
                                    <?php if ($period['status'] === 'finalized'): ?>
                                        <span class="badge badge-success">✓ Finalisiert</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">⏳ In Bearbeitung</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($period['period_name']); ?></strong><br>
                                    <small class="text-muted">Jahr: <?php echo $period['business_year']; ?></small>
                                </td>
                                <td>
                                    <?php echo date('d.m.Y', strtotime($period['date_from'])); ?><br>
                                    bis <?php echo date('d.m.Y', strtotime($period['date_to'])); ?>
                                </td>
                                <td>
                                    👑 <?php echo htmlspecialchars($period['leader_first'] . ' ' . $period['leader_last']); ?><br>
                                    🆕 <?php echo htmlspecialchars($period['assistant_first'] . ' ' . $period['assistant_last']); ?>
                                </td>
                                <td>
                                    <div class="progress-info">
                                        <strong><?php echo $period['checked_transactions']; ?></strong> / 
                                        <?php echo $period['total_transactions']; ?> geprüft
                                        <?php if ($period['investigation_transactions'] > 0): ?>
                                            <br><small class="text-warning">⚠️ <?php echo $period['investigation_transactions']; ?> in Prüfung</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($period['status'] === 'in_progress'): ?>
                                        <a href="transaction_checking.php?period_id=<?php echo $period['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-check"></i> Prüfen
                                        </a>
                                        <?php if (($user_member_id == $period['leader_id']) || has_role('admin')): ?>
                                            <?php if ($period['total_transactions'] == $period['checked_transactions'] + $period['investigation_transactions']): ?>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Möchten Sie diese Prüfperiode wirklich finalisieren? Dies kann nicht rückgängig gemacht werden!');">
                                                    <input type="hidden" name="action" value="finalize">
                                                    <input type="hidden" name="period_id" value="<?php echo $period['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">
                                                        <i class="fas fa-lock"></i> Finalisieren
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a href="transaction_checking.php?period_id=<?php echo $period['id']; ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-eye"></i> Ansehen
                                        </a>
                                        <?php if ($period['finalized_at']): ?>
                                            <br><small class="text-muted">
                                                Finalisiert am <?php echo date('d.m.Y H:i', strtotime($period['finalized_at'])); ?>
                                                <?php if ($period['finalized_by_first']): ?>
                                                    von <?php echo htmlspecialchars($period['finalized_by_first'] . ' ' . $period['finalized_by_last']); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.form-row {
    display: flex;
    gap: 15px;
    margin: 0 -7.5px;
}

.form-row .form-group {
    flex: 1;
    padding: 0 7.5px;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: bold;
}

.badge-success {
    background-color: #4caf50;
    color: white;
}

.badge-warning {
    background-color: #ff9800;
    color: white;
}

.progress-info {
    font-size: 0.9em;
}
</style>

<?php include 'includes/footer.php'; ?>
