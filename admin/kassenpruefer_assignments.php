<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
check_auth();

// Only admins can manage kassenprüfer assignments
if (!has_role('admin')) {
    $_SESSION['error'] = 'Zugriff verweigert.';
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Kassenprüfer Verwaltung';
$db = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            // Add new kassenprüfer assignment
            $member_id = intval($_POST['member_id']);
            $role_type = $_POST['role_type'];
            $valid_from = $_POST['valid_from'];
            $notes = $_POST['notes'] ?? '';
            
            // Check if member is active member (not supporter or pensioner)
            $stmt = $db->prepare("SELECT member_type FROM members WHERE id = :id AND active = 1");
            $stmt->execute(['id' => $member_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member || $member['member_type'] !== 'active') {
                $_SESSION['error'] = 'Nur aktive Mitglieder der Einsatzeinheit können als Kassenprüfer eingesetzt werden.';
            } else {
                try {
                    $db->beginTransaction();

                    if ($role_type === 'assistant') {
                        // We always promote the current assistant to leader when a new assistant is elected
                        $stmt = $db->prepare("SELECT * FROM kassenpruefer_assignments WHERE role_type = 'assistant' AND valid_until IS NULL LIMIT 1");
                        $stmt->execute();
                        $current_assistant = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$current_assistant) {
                            throw new Exception('Es ist kein aktueller Assistent hinterlegt, daher kann keine Beförderung durchgeführt werden.');
                        }

                        if ((int)$current_assistant['member_id'] === $member_id) {
                            throw new Exception('Der gewählte Assistent ist bereits der aktuelle Assistent. Bitte ein anderes Mitglied wählen.');
                        }

                        // End current leader (if any) effective day before the new period
                        $stmt = $db->prepare("SELECT * FROM kassenpruefer_assignments WHERE role_type = 'leader' AND valid_until IS NULL LIMIT 1");
                        $stmt->execute();
                        $current_leader = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($current_leader) {
                            $stmt = $db->prepare("UPDATE kassenpruefer_assignments SET valid_until = DATE_SUB(:valid_from, INTERVAL 1 DAY) WHERE id = :id");
                            $stmt->execute(['valid_from' => $valid_from, 'id' => $current_leader['id']]);
                        }

                        // End current assistant record
                        $stmt = $db->prepare("UPDATE kassenpruefer_assignments SET valid_until = DATE_SUB(:valid_from, INTERVAL 1 DAY) WHERE id = :id");
                        $stmt->execute(['valid_from' => $valid_from, 'id' => $current_assistant['id']]);

                        // Promote assistant to leader
                        $stmt = $db->prepare("INSERT INTO kassenpruefer_assignments (member_id, role_type, valid_from, notes, created_by)
                                             VALUES (:member_id, 'leader', :valid_from, :notes, :created_by)");
                        $stmt->execute([
                            'member_id' => $current_assistant['member_id'],
                            'valid_from' => $valid_from,
                            'notes' => 'Automatische Beförderung zum Leiter',
                            'created_by' => $_SESSION['user_id']
                        ]);

                        // Add new assistant (user selection)
                        $stmt = $db->prepare("INSERT INTO kassenpruefer_assignments (member_id, role_type, valid_from, notes, created_by)
                                             VALUES (:member_id, 'assistant', :valid_from, :notes, :created_by)");
                        $stmt->execute([
                            'member_id' => $member_id,
                            'valid_from' => $valid_from,
                            'notes' => $notes,
                            'created_by' => $_SESSION['user_id']
                        ]);

                        $_SESSION['success'] = 'Neuer Assistent angelegt; bisheriger Assistent wurde zum Leiter befördert.';
                    } else {
                        // Directly assign leader (rare case)
                        $stmt = $db->prepare("UPDATE kassenpruefer_assignments 
                                             SET valid_until = DATE_SUB(:valid_from, INTERVAL 1 DAY) 
                                             WHERE role_type = :role_type AND valid_until IS NULL");
                        $stmt->execute([
                            'valid_from' => $valid_from,
                            'role_type' => $role_type
                        ]);

                        $stmt = $db->prepare("INSERT INTO kassenpruefer_assignments 
                                             (member_id, role_type, valid_from, notes, created_by) 
                                             VALUES (:member_id, :role_type, :valid_from, :notes, :created_by)");
                        $stmt->execute([
                            'member_id' => $member_id,
                            'role_type' => $role_type,
                            'valid_from' => $valid_from,
                            'notes' => $notes,
                            'created_by' => $_SESSION['user_id']
                        ]);

                        $_SESSION['success'] = 'Kassenprüfer erfolgreich zugewiesen.';
                    }

                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $_SESSION['error'] = $e->getMessage();
                }
            }
            
            header('Location: kassenpruefer_assignments.php');
            exit;
            
        } elseif ($_POST['action'] === 'end') {
            // End an assignment
            $id = intval($_POST['id']);
            $valid_until = $_POST['valid_until'];
            
            $stmt = $db->prepare("UPDATE kassenpruefer_assignments SET valid_until = :valid_until WHERE id = :id");
            $stmt->execute(['valid_until' => $valid_until, 'id' => $id]);
            
            $_SESSION['success'] = 'Kassenprüfer-Zuweisung beendet.';
            header('Location: kassenpruefer_assignments.php');
            exit;
        }
    }
}

// Get current kassenprüfer
$stmt = $db->query("SELECT ka.*, m.first_name, m.last_name, m.member_number 
                   FROM kassenpruefer_assignments ka
                   JOIN members m ON ka.member_id = m.id
                   WHERE ka.valid_until IS NULL
                   ORDER BY ka.role_type DESC");
$current_kassenpruefer = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get kassenprüfer history
$stmt = $db->query("SELECT ka.*, m.first_name, m.last_name, m.member_number 
                   FROM kassenpruefer_assignments ka
                   JOIN members m ON ka.member_id = m.id
                   WHERE ka.valid_until IS NOT NULL
                   ORDER BY ka.valid_from DESC
                   LIMIT 20");
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active members for selection
$stmt = $db->query("SELECT id, first_name, last_name, member_number 
                   FROM members 
                   WHERE member_type = 'active' AND active = 1 
                   ORDER BY last_name, first_name");
$active_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-check"></i> <?php echo $page_title; ?></h1>
    <p>Verwalten Sie die Kassenprüfer-Zuweisungen (Leiter und Assistent)</p>
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

<div class="card">
    <div class="card-header">
        <h2>Aktuelle Kassenprüfer</h2>
    </div>
    <div class="card-body">
        <?php if (empty($current_kassenpruefer)): ?>
            <p class="text-muted">Keine aktiven Kassenprüfer zugewiesen.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rolle</th>
                            <th>Mitglied</th>
                            <th>Gültig seit</th>
                            <th>Notizen</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($current_kassenpruefer as $kp): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $kp['role_type'] === 'leader' ? '👑 Leiter' : '🆕 Assistent'; ?></strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($kp['first_name'] . ' ' . $kp['last_name']); ?>
                                    <small class="text-muted">(<?php echo htmlspecialchars($kp['member_number']); ?>)</small>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($kp['valid_from'])); ?></td>
                                <td><?php echo htmlspecialchars($kp['notes'] ?? ''); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="endAssignment(<?php echo $kp['id']; ?>, '<?php echo $kp['role_type']; ?>')">
                                        <i class="fas fa-stop-circle"></i> Beenden
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Neue Zuweisung</h2>
    </div>
    <div class="card-body">
        <form method="POST" class="form">
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label for="role_type">Rolle *</label>
                <select name="role_type" id="role_type" class="form-control" required>
                    <option value="">Bitte wählen...</option>
                    <option value="leader">👑 Leiter (erfahrener Prüfer)</option>
                    <option value="assistant">🆕 Assistent (neuer Prüfer)</option>
                </select>
                <small class="form-text">
                    Der Leiter kann Prüfperioden finalisieren. Der Assistent wird nach einem Jahr zum Leiter.
                </small>
            </div>
            
            <div class="form-group">
                <label for="member_id">Mitglied *</label>
                <select name="member_id" id="member_id" class="form-control" required>
                    <option value="">Bitte wählen...</option>
                    <?php foreach ($active_members as $member): ?>
                        <option value="<?php echo $member['id']; ?>">
                            <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                            (<?php echo htmlspecialchars($member['member_number']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">Nur aktive Mitglieder der Einsatzeinheit können ausgewählt werden.</small>
            </div>
            
            <div class="form-group">
                <label for="valid_from">Gültig ab *</label>
                <input type="date" name="valid_from" id="valid_from" class="form-control" 
                       value="<?php echo date('Y-m-d'); ?>" required>
                <small class="form-text">Die vorherige Zuweisung dieser Rolle wird automatisch beendet.</small>
            </div>
            
            <div class="form-group">
                <label for="notes">Notizen</label>
                <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Zuweisung erstellen
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Vergangene Zuweisungen</h2>
    </div>
    <div class="card-body">
        <?php if (empty($history)): ?>
            <p class="text-muted">Keine vergangenen Zuweisungen.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rolle</th>
                            <th>Mitglied</th>
                            <th>Zeitraum</th>
                            <th>Notizen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $kp): ?>
                            <tr>
                                <td><?php echo $kp['role_type'] === 'leader' ? '👑 Leiter' : '🆕 Assistent'; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($kp['first_name'] . ' ' . $kp['last_name']); ?>
                                    <small class="text-muted">(<?php echo htmlspecialchars($kp['member_number']); ?>)</small>
                                </td>
                                <td>
                                    <?php echo date('d.m.Y', strtotime($kp['valid_from'])); ?>
                                    bis
                                    <?php echo date('d.m.Y', strtotime($kp['valid_until'])); ?>
                                </td>
                                <td><?php echo htmlspecialchars($kp['notes'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- End assignment modal/form -->
<div id="endModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Zuweisung beenden</h3>
        <form method="POST" id="endForm">
            <input type="hidden" name="action" value="end">
            <input type="hidden" name="id" id="end_id">
            
            <div class="form-group">
                <label for="valid_until">Gültig bis *</label>
                <input type="date" name="valid_until" id="valid_until" class="form-control" 
                       value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-warning">Beenden</button>
            </div>
        </form>
    </div>
</div>

<script>
function endAssignment(id, roleType) {
    if (confirm('Möchten Sie diese Kassenprüfer-Zuweisung wirklich beenden?')) {
        document.getElementById('end_id').value = id;
        document.getElementById('endModal').style.display = 'flex';
    }
}

function closeModal() {
    document.getElementById('endModal').style.display = 'none';
}

// Close modal on outside click
window.onclick = function(event) {
    const modal = document.getElementById('endModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

<style>
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
    max-width: 500px;
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
