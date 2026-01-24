<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin or kassenpruefer
if (!is_logged_in() || !has_permission('outstanding_obligations.php')) {
    header('Location: login.php');
    exit;
}

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
                              om.id as organizing_member_id
                      FROM item_obligations io
                      LEFT JOIN members m ON io.member_id = m.id
                      LEFT JOIN members om ON io.organizing_member_id = om.id
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

$outstanding = $obligation['total_amount'] - $obligation['paid_amount'];

include 'includes/header.php';
?>

<div class="content-header">
    <div>
        <a href="outstanding_obligations.php?tab=items" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Zurück
        </a>
        <h1 style="display: inline-block; margin-left: 1rem;">
            Artikel-Forderung #<?= $id ?>
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
                    <?php 
                    $outstanding = $obligation['total_amount'] - $obligation['paid_amount'];
                    if ($outstanding == 0): ?>
                        <span class="badge badge-success" style="font-size: 1rem; padding: 0.5rem 1rem;">
                            <i class="fas fa-check-circle"></i> Bezahlt
                        </span>
                    <?php elseif ($obligation['paid_amount'] > 0): ?>
                        <span class="badge badge-warning" style="font-size: 1rem; padding: 0.5rem 1rem;">
                            <i class="fas fa-clock"></i> Teilzahlung
                        </span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size: 1rem; padding: 0.5rem 1rem;">
                            <i class="fas fa-exclamation-triangle"></i> Offen
                        </span>
                    <?php endif; ?>
                </p>
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
        <h2>Artikel</h2>
    </div>
    <div class="card-body">
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
    </div>
</div>

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
