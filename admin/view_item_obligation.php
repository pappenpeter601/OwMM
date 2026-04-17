<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('outstanding_obligations.php')) {
    header('Location: login.php');
    exit;
}

ensure_financial_reporting_support();

$db = getDBConnection();
$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: outstanding_obligations.php');
    exit;
}

// Get obligation details
$stmt = $db->prepare("SELECT io.*, 
                              m.first_name as member_first_name,
                              m.last_name as member_last_name,
                              m.member_number,
                              m.id as member_id,
                              om.first_name as org_first_name,
                              om.last_name as org_last_name,
                              om.id as organizing_member_id,
                              tc.name as category_name,
                              tc.color as category_color
                      FROM item_obligations io
                      LEFT JOIN members m ON io.member_id = m.id
                      LEFT JOIN members om ON io.organizing_member_id = om.id
                      LEFT JOIN transaction_categories tc ON io.category_id = tc.id
                      WHERE io.id = :id");
$stmt->execute([':id' => $id]);
$obligation = $stmt->fetch();

if (!$obligation) {
    header('Location: outstanding_obligations.php');
    exit;
}

// Determine if receiver is a member or external
$is_member_receiver = !empty($obligation['member_id']);
if ($is_member_receiver) {
    $receiver_name = $obligation['member_first_name'] . ' ' . $obligation['member_last_name'];
    $receiver_contact = $obligation['member_number'] ? 'Mitgliedsnr: ' . $obligation['member_number'] : '';
} else {
    $receiver_name = $obligation['receiver_name'];
    $receiver_contact = '';
    if ($obligation['receiver_phone']) {
        $receiver_contact .= ($receiver_contact ? ' | ' : '') . $obligation['receiver_phone'];
    }
    if ($obligation['receiver_email']) {
        $receiver_contact .= ($receiver_contact ? ' | ' : '') . $obligation['receiver_email'];
    }
}

// Get obligation items
$stmt = $db->prepare("SELECT oi.*, i.name as item_name
                      FROM obligation_items oi
                      JOIN items i ON oi.item_id = i.id
                      WHERE oi.obligation_id = :obligation_id");
$stmt->execute([':obligation_id' => $id]);
$items = $stmt->fetchAll();

$outstanding = (float) $obligation['total_amount'] - (float) $obligation['paid_amount'];

$expense_request = null;
$expense_request_docs = [];
$obligation_docs = [];
$linked_transaction_docs = [];
try {
    ensure_item_obligation_document_support();
    $stmt = $db->prepare("SELECT * FROM item_obligation_documents WHERE obligation_id = :id ORDER BY uploaded_at DESC");
    $stmt->execute([':id' => $id]);
    $obligation_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ensure_expense_request_support();
    $stmt = $db->prepare("SELECT * FROM expense_requests WHERE linked_item_obligation_id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $expense_request = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($expense_request) {
        $stmt = $db->prepare("SELECT * FROM expense_request_documents WHERE expense_request_id = :id ORDER BY uploaded_at DESC");
        $stmt->execute([':id' => $expense_request['id']]);
        $expense_request_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare("SELECT DISTINCT td.*, t.id AS transaction_id, t.booking_date, t.amount
                          FROM item_obligation_payments iop
                          JOIN transactions t ON iop.transaction_id = t.id
                          JOIN transaction_documents td ON td.transaction_id = t.id
                          WHERE iop.obligation_id = :id1
                          UNION
                          SELECT DISTINCT td.*, t.id AS transaction_id, t.booking_date, t.amount
                          FROM item_obligation_payments iop
                          JOIN transactions t ON iop.transaction_id = t.id
                          JOIN transaction_documents td ON td.id = t.document_id
                          WHERE iop.obligation_id = :id2
                          ORDER BY uploaded_at DESC");
    $stmt->execute([':id1' => $id, ':id2' => $id]);
    $linked_transaction_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expense_request = null;
    $expense_request_docs = [];
    $obligation_docs = [];
    $linked_transaction_docs = [];
}

$status_badge_class = 'badge-danger';
$status_icon = 'fas fa-exclamation-triangle';
$status_label = 'Offen';

if ($expense_request) {
    switch ($expense_request['status']) {
        case 'paid':
            $status_badge_class = 'badge-success';
            $status_icon = 'fas fa-check-circle';
            $status_label = 'Ausgezahlt';
            break;
        case 'approved':
            $status_badge_class = 'badge-primary';
            $status_icon = 'fas fa-thumbs-up';
            $status_label = 'Genehmigt';
            break;
        case 'rejected':
            $status_badge_class = 'badge-secondary';
            $status_icon = 'fas fa-ban';
            $status_label = 'Abgelehnt';
            break;
        default:
            $status_badge_class = 'badge-warning';
            $status_icon = 'fas fa-clock';
            $status_label = 'Eingereicht';
            break;
    }
} elseif (($obligation['status'] ?? '') === 'cancelled') {
    $status_badge_class = 'badge-secondary';
    $status_icon = 'fas fa-ban';
    $status_label = 'Storniert';
} elseif ($outstanding <= 0) {
    $status_badge_class = 'badge-success';
    $status_icon = 'fas fa-check-circle';
    $status_label = 'Bezahlt';
} elseif ((float) $obligation['paid_amount'] > 0) {
    $status_badge_class = 'badge-warning';
    $status_icon = 'fas fa-clock';
    $status_label = 'Teilzahlung';
}

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="outstanding_obligations.php?tab=items" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">
            <?= $expense_request ? 'Erstattungsantrag' : 'Forderung' ?> #<?= $id ?>
        </h1>
    </div>
</div>

<!-- Obligation Header -->
<div class="card">
    <div class="card-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
            <div>
                <h3 style="margin-top: 0;">Empfänger</h3>
                <p style="font-size: 1.1rem; margin: 0;">
                    <strong><?= htmlspecialchars($receiver_name) ?></strong>
                </p>
                <?php if ($is_member_receiver): ?>
                    <p style="color: #666; margin: 0.5rem 0 0 0;">
                        <span class="badge badge-primary">Mitglied</span>
                    </p>
                    <?php if ($obligation['member_number']): ?>
                        <p style="color: #666; margin: 0.5rem 0 0 0;">
                            Mitgliedsnr: <?= htmlspecialchars($obligation['member_number']) ?>
                        </p>
                    <?php endif; ?>
                    <p style="color: #666; margin: 0.5rem 0 0 0;">
                        <a href="members.php?edit=<?= $obligation['member_id'] ?>">Mitglied ansehen →</a>
                    </p>
                <?php else: ?>
                    <p style="color: #666; margin: 0.5rem 0 0 0;">
                        <span class="badge badge-secondary">Externe Person</span>
                    </p>
                    <?php if ($receiver_contact): ?>
                        <p style="color: #666; margin: 0.5rem 0 0 0;">
                            <?= htmlspecialchars($receiver_contact) ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div>
                <?php if ($obligation['organizing_member_id']): ?>
                    <h3 style="margin-top: 0;">Organisierendes Mitglied</h3>
                    <p style="font-size: 1.1rem; margin: 0;">
                        <strong><?= htmlspecialchars($obligation['org_first_name'] . ' ' . $obligation['org_last_name']) ?></strong>
                    </p>
                    <p style="color: #666; margin: 0.5rem 0 0 0;">
                        <a href="members.php?edit=<?= $obligation['organizing_member_id'] ?>">Mitglied ansehen →</a>
                    </p>
                <?php endif; ?>
            </div>
            
            <div>
                <h3 style="margin-top: 0;">Status</h3>
                <p style="margin: 0;">
                    <span class="badge <?= htmlspecialchars($status_badge_class) ?>" style="font-size: 1rem; padding: 0.5rem 1rem;">
                        <i class="<?= htmlspecialchars($status_icon) ?>"></i> <?= htmlspecialchars($status_label) ?>
                    </span>
                </p>
                <?php if (!empty($obligation['category_name'])): ?>
                    <p style="color: #666; margin: 0.5rem 0 0 0;">
                        Kategorie: <span class="badge" style="background: <?= htmlspecialchars($obligation['category_color'] ?: '#78909c') ?>; color: #fff;"><?= htmlspecialchars($obligation['category_name']) ?></span>
                    </p>
                <?php endif; ?>
                <?php if ($obligation['due_date']): ?>
                    <p style="color: #666; margin: 0.5rem 0 0 0;">
                        Fällig: <strong><?= date('d.m.Y', strtotime($obligation['due_date'])) ?></strong>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Amount Summary -->
<div class="stats-grid" style="margin-top: 1rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #2196f3;">
            <i class="fas fa-euro-sign"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($obligation['total_amount'], 2, ',', '.') ?> €</div>
            <div class="stat-label">Gesamtbetrag</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #4caf50;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($obligation['paid_amount'], 2, ',', '.') ?> €</div>
            <div class="stat-label">Bezahlt</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: <?= $outstanding > 0 ? '#ff9800' : '#9c27b0' ?>;">
            <i class="fas fa-exclamation"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($outstanding, 2, ',', '.') ?> €</div>
            <div class="stat-label">Ausstehend</div>
        </div>
    </div>
</div>

<!-- Items Table -->
<div class="card" style="margin-top: 1rem;">
    <div class="card-header">
        <h2>Optional verknüpfte Artikel</h2>
    </div>
    <div class="card-body">
        <?php if (!empty($items)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th style="width: 100px;">Menge</th>
                        <th style="width: 120px;">Preis pro Stück</th>
                        <th style="width: 140px; text-align: right;">Summe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td><?= number_format($item['unit_price'], 2, ',', '.') ?> €</td>
                            <td style="text-align: right;"><?= number_format($item['subtotal'], 2, ',', '.') ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="margin: 0; color: #666;">Für diese Forderung wurden keine Artikel verknüpft.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($obligation_docs)): ?>
    <div class="card" style="margin-top: 1rem;">
        <div class="card-header">
            <h2>Verknüpfte Forderungsdokumente</h2>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                <?php foreach ($obligation_docs as $doc): ?>
                    <a href="../uploads/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-secondary">
                        <i class="fas fa-file"></i> <?= htmlspecialchars($doc['file_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($expense_request): ?>    <div class="card" style="margin-top: 1rem;">
        <div class="card-header">
            <h2>Erstattungsdetails</h2>
        </div>
        <div class="card-body">
            <p><strong>Referenz:</strong> <?= htmlspecialchars($expense_request['transfer_reference']) ?></p>
            <p><strong>Status:</strong>
                <?php if ($expense_request['status'] === 'paid'): ?>
                    <span class="badge badge-success">Ausgezahlt</span>
                <?php elseif ($expense_request['status'] === 'approved'): ?>
                    <span class="badge badge-primary">Genehmigt</span>
                <?php elseif ($expense_request['status'] === 'rejected'): ?>
                    <span class="badge badge-secondary">Abgelehnt</span>
                <?php else: ?>
                    <span class="badge badge-warning">Eingereicht</span>
                <?php endif; ?>
            </p>
            <p><strong>Kontext:</strong><br><?= nl2br(htmlspecialchars($expense_request['expense_context'])) ?></p>
            <?php if (!empty($expense_request['accountant_notes'])): ?>
                <p><strong>Notiz Buchhaltung:</strong><br><?= nl2br(htmlspecialchars($expense_request['accountant_notes'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($expense_request_docs)): ?>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem;">
                    <?php foreach ($expense_request_docs as $doc): ?>
                        <a href="../uploads/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-secondary">
                            <i class="fas fa-file"></i> <?= htmlspecialchars($doc['file_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($linked_transaction_docs)): ?>
    <div class="card" style="margin-top: 1rem;">
        <div class="card-header">
            <h2>Verknüpfte Buchungsbelege</h2>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                <?php foreach ($linked_transaction_docs as $doc): ?>
                    <a href="../uploads/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-secondary">
                        <i class="fas fa-file"></i> <?= htmlspecialchars($doc['file_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <p style="color: #666; margin: 0;">
                Die Belege stammen aus den bereits verknüpften historischen Buchungen.
            </p>
        </div>
    </div>
<?php endif; ?>

<!-- Notes -->
<?php if ($obligation['notes']): ?>
    <div class="card" style="margin-top: 1rem;">
        <div class="card-header">
            <h2>Notizen</h2>
        </div>
        <div class="card-body">
            <p><?= htmlspecialchars($obligation['notes']) ?></p>
        </div>
    </div>
<?php endif; ?>

<!-- Created Info -->
<div class="card" style="margin-top: 1rem;">
    <div class="card-body" style="color: #999; font-size: 0.9rem;">
        Erstellt: <?= date('d.m.Y H:i', strtotime($obligation['created_at'])) ?>
        <?php if ($obligation['created_by']): ?>
            <br>Erstellt von: Benutzer <?= htmlspecialchars($obligation['created_by']) ?>
        <?php endif; ?>
        <br>Zuletzt aktualisiert: <?= date('d.m.Y H:i', strtotime($obligation['updated_at'])) ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
