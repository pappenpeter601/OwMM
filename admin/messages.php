<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
if (!is_logged_in() || !is_admin()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle message actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';
    
    if ($action === 'mark_read') {
        $stmt = $db->prepare("UPDATE contact_messages SET status = 'read' WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $success = "Nachricht als gelesen markiert";
    } elseif ($action === 'archive') {
        $stmt = $db->prepare("UPDATE contact_messages SET status = 'archived' WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $success = "Nachricht archiviert";
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM contact_messages WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $success = "Nachricht gelöscht";
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'new';

// Get messages
$sql = "SELECT * FROM contact_messages WHERE 1=1";
if ($filter !== 'all') {
    $sql .= " AND status = :status";
}
$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
if ($filter !== 'all') {
    $stmt->execute(['status' => $filter]);
} else {
    $stmt->execute();
}
$messages = $stmt->fetchAll();

// Get counts
$stmt = $db->query("SELECT status, COUNT(*) as count FROM contact_messages GROUP BY status");
$counts = [];
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = $row['count'];
}

$page_title = 'Kontaktanfragen';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Kontaktanfragen</h1>
</div>

<div class="filter-tabs">
    <a href="?filter=new" class="<?php echo $filter === 'new' ? 'active' : ''; ?>">
        Neu (<?php echo $counts['new'] ?? 0; ?>)
    </a>
    <a href="?filter=read" class="<?php echo $filter === 'read' ? 'active' : ''; ?>">
        Gelesen (<?php echo $counts['read'] ?? 0; ?>)
    </a>
    <a href="?filter=archived" class="<?php echo $filter === 'archived' ? 'active' : ''; ?>">
        Archiviert (<?php echo $counts['archived'] ?? 0; ?>)
    </a>
    <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">
        Alle
    </a>
</div>

<div class="messages-list">
    <?php if (empty($messages)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox fa-3x"></i>
            <h3>Keine Nachrichten</h3>
            <p>Es gibt keine Nachrichten in dieser Kategorie.</p>
        </div>
    <?php else: ?>
        <?php foreach ($messages as $message): ?>
            <div class="message-card <?php echo $message['status']; ?>">
                <div class="message-header">
                    <div class="message-from">
                        <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                        <span class="email"><?php echo htmlspecialchars($message['email']); ?></span>
                        <?php if ($message['phone']): ?>
                            <span class="phone"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($message['phone']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="message-meta">
                        <span class="date"><?php echo format_datetime($message['created_at']); ?></span>
                        <span class="badge badge-<?php echo $message['status'] === 'new' ? 'success' : 'warning'; ?>">
                            <?php echo ucfirst($message['status']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="message-subject">
                    <strong>Betreff:</strong> <?php echo htmlspecialchars($message['subject']); ?>
                </div>
                
                <div class="message-body">
                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                </div>
                
                <div class="message-actions">
                    <?php if ($message['status'] === 'new'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="id" value="<?php echo $message['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Als gelesen markieren</button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($message['status'] !== 'archived'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="id" value="<?php echo $message['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-info">Archivieren</button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="mailto:<?php echo htmlspecialchars($message['email']); ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-reply"></i> Antworten
                    </a>
                    
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Wirklich löschen?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $message['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.filter-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid var(--border-color);
}

.filter-tabs a {
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: var(--text-color);
    border-bottom: 3px solid transparent;
    transition: all 0.3s;
}

.filter-tabs a:hover {
    background-color: var(--light-color);
}

.filter-tabs a.active {
    border-bottom-color: var(--primary-color);
    color: var(--primary-color);
    font-weight: 600;
}

.messages-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.message-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 4px solid var(--border-color);
}

.message-card.new {
    border-left-color: var(--success-color);
    background-color: #f0f9ff;
}

.message-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 1rem;
    align-items: flex-start;
}

.message-from strong {
    font-size: 1.1rem;
    display: block;
    margin-bottom: 0.25rem;
}

.message-from .email,
.message-from .phone {
    display: block;
    color: #666;
    font-size: 0.9rem;
}

.message-meta {
    text-align: right;
}

.message-subject {
    margin-bottom: 1rem;
    padding: 0.75rem;
    background-color: var(--light-color);
    border-radius: 4px;
}

.message-body {
    margin-bottom: 1.5rem;
    line-height: 1.8;
    color: var(--text-color);
}

.message-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 8px;
    color: #999;
}
</style>

<?php include 'includes/footer.php'; ?>
