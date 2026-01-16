<?php
/**
 * Admin Registration Approval Page
 * Admins can approve or reject pending registration requests
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/EmailService.php';

check_auth();

$pdo = getDBConnection();
$success_message = '';
$error_message = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $request_id = (int)$_POST['request_id'];
        $action = $_POST['action'];
        
        // Get request details
        $stmt = $pdo->prepare("SELECT * FROM registration_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception("Registrierungsanfrage nicht gefunden.");
        }
        
        if ($request['status'] !== 'pending') {
            throw new Exception("Diese Anfrage wurde bereits bearbeitet.");
        }
        
        if ($action === 'approve') {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Create user account
            $stmt = $pdo->prepare("
                INSERT INTO users (username, first_name, last_name, email, auth_method, email_verified, is_admin, created_at)
                VALUES (?, ?, ?, ?, 'magic_link', 1, 0, NOW())
            ");
            $username = strtolower($request['first_name'] . '.' . $request['last_name']);
            $stmt->execute([
                $username,
                $request['first_name'],
                $request['last_name'],
                $request['email']
            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // Delete registration request (user is now created, no need to keep it)
            $stmt = $pdo->prepare("DELETE FROM registration_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            
            $pdo->commit();
            
            // Send approval email
            $emailService = new EmailService();
            $emailService->sendApprovalNotification(
                $request['email'], 
                $request['first_name'], 
                true
            );
            
            $success_message = "Registrierung von {$request['first_name']} {$request['last_name']} wurde genehmigt.";
            
        } elseif ($action === 'reject') {
            // Update registration request
            $stmt = $pdo->prepare("
                UPDATE registration_requests 
                SET status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $request_id]);
            
            // Send rejection email
            $emailService = new EmailService();
            $emailService->sendApprovalNotification(
                $request['email'], 
                $request['first_name'], 
                false
            );
            
            $success_message = "Registrierung von {$request['first_name']} {$request['last_name']} wurde abgelehnt.";
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Get all pending registration requests
$stmt = $pdo->query("
    SELECT * FROM registration_requests 
    WHERE status = 'pending'
    ORDER BY created_at DESC
");
$pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent approved/rejected requests
$stmt = $pdo->query("
    SELECT r.*, u.username as approved_by_username
    FROM registration_requests r
    LEFT JOIN users u ON r.approved_by = u.id
    WHERE status IN ('approved', 'rejected')
    ORDER BY approved_at DESC
    LIMIT 10
");
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
.approval-container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 20px;
}

.page-header {
    margin-bottom: 30px;
}

.page-header h1 {
    color: #dc2626;
    margin: 0 0 10px 0;
}

.page-header p {
    color: #666;
    margin: 0;
}

.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 25px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #dc2626;
}

.section {
    background: white;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.section h2 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #dc2626;
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #999;
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 15px;
}

.request-card {
    border: 2px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s;
}

.request-card:hover {
    border-color: #dc2626;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.1);
}

.request-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.request-info h3 {
    margin: 0 0 5px 0;
    color: #333;
    font-size: 18px;
}

.request-info .email {
    color: #666;
    font-size: 14px;
}

.request-meta {
    text-align: right;
    font-size: 13px;
    color: #999;
}

.request-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.detail-item {
    font-size: 14px;
}

.detail-label {
    font-weight: bold;
    color: #555;
    margin-bottom: 3px;
}

.detail-value {
    color: #333;
}

.request-actions {
    display: flex;
    gap: 10px;
    padding-top: 15px;
    border-top: 1px solid #e5e5e5;
}

.btn {
    padding: 10px 25px;
    border: none;
    border-radius: 5px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-approve {
    background: #10b981;
    color: white;
}

.btn-approve:hover {
    background: #059669;
}

.btn-reject {
    background: #dc2626;
    color: white;
}

.btn-reject:hover {
    background: #b91c1c;
}

.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #d1fae5;
    color: #065f46;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: bold;
    color: #555;
    border-bottom: 2px solid #e5e5e5;
}

.history-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e5e5;
    color: #333;
}

.history-table tr:hover {
    background: #f8f9fa;
}

.no-pending {
    background: #d1fae5;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    color: #065f46;
}
</style>

<div class="approval-container">
    <div class="page-header">
        <h1>Registrierungsanfragen</h1>
        <p>Verwalten Sie ausstehende Registrierungen</p>
    </div>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>Ausstehende Anfragen (<?php echo count($pending_requests); ?>)</h2>
        
        <?php if (empty($pending_requests)): ?>
            <div class="no-pending">
                ✓ Keine ausstehenden Registrierungsanfragen
            </div>
        <?php else: ?>
            <?php foreach ($pending_requests as $request): ?>
                <div class="request-card">
                    <div class="request-header">
                        <div class="request-info">
                            <h3><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></h3>
                            <div class="email"><?php echo htmlspecialchars($request['email']); ?></div>
                        </div>
                        <div class="request-meta">
                            <span class="status-badge status-pending">Ausstehend</span><br>
                            <small>Erstellt: <?php echo date('d.m.Y H:i', strtotime($request['created_at'])); ?></small>
                        </div>
                    </div>
                    
                    <div class="request-details">
                        <div class="detail-item">
                            <div class="detail-label">Vorname</div>
                            <div class="detail-value"><?php echo htmlspecialchars($request['first_name']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Nachname</div>
                            <div class="detail-value"><?php echo htmlspecialchars($request['last_name']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">E-Mail</div>
                            <div class="detail-value"><?php echo htmlspecialchars($request['email']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">E-Mail verifiziert</div>
                            <div class="detail-value">
                                <?php echo $request['email_verified_at'] ? 
                                    '✓ Ja (' . date('d.m.Y H:i', strtotime($request['email_verified_at'])) . ')' : 
                                    '✗ Nein'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" class="request-actions">
                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-approve">
                            ✓ Genehmigen
                        </button>
                        <button type="submit" name="action" value="reject" class="btn btn-reject">
                            ✗ Ablehnen
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($recent_requests)): ?>
        <div class="section">
            <h2>Letzte Entscheidungen</h2>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Status</th>
                        <th>Genehmigt von</th>
                        <th>Datum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_requests as $request): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($request['email']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                    <?php echo $request['status'] === 'approved' ? 'Genehmigt' : 'Abgelehnt'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($request['approved_by_username'] ?? 'Unbekannt'); ?></td>
                            <td><?php echo date('d.m.Y H:i', strtotime($request['approved_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
