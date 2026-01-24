<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check permissions
if (!is_logged_in() || !has_permission('payment_reminders.php')) {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();

// Get selected obligation IDs from query parameter
$obligationIds = $_GET['ids'] ?? '';
if (empty($obligationIds)) {
    die('Keine Mitglieder ausgewählt.');
}

$ids = explode(',', $obligationIds);
$ids = array_filter($ids, 'is_numeric');

if (empty($ids)) {
    die('Ungültige Auswahl.');
}

// Get and validate contact person
$contactMemberId = $_GET['contact'] ?? '';
if (empty($contactMemberId)) {
    die('Kein Ansprechpartner ausgewählt.');
}

$contactStmt = $db->prepare("SELECT id, first_name, last_name, email, mobile, telephone FROM members WHERE id = :id AND active = 1 AND member_type = 'active'");
$contactStmt->execute(['id' => $contactMemberId]);
$contactPerson = $contactStmt->fetch(PDO::FETCH_ASSOC);

if (!$contactPerson) {
    die('Ansprechpartner nicht gefunden oder nicht gültig.');
}

$contactName = trim($contactPerson['first_name'] . ' ' . $contactPerson['last_name']);
$contactEmail = $contactPerson['email'] ?? '';
$contactMobile = $contactPerson['mobile'] ?? $contactPerson['telephone'] ?? '';

// Get organization data
$orgStmt = $db->query("SELECT name, legal_name, street, postal_code, city, phone, email, website, iban, bank_owner, paypal_link FROM organization WHERE id = 1");
$org = $orgStmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    $org = [
        'name' => 'OwMM',
        'legal_name' => 'OwMM',
        'street' => '',
        'postal_code' => '',
        'city' => '',
        'phone' => '',
        'email' => '',
        'website' => 'https://owmm.de',
        'iban' => 'DE89 3704 0044 0532 0130 00',
        'bank_owner' => 'OwMM',
        'paypal_link' => 'https://paypal.me/owmm'
    ];
}

// Get members and obligations
$placeholders = str_repeat('?,', count($ids) - 1) . '?';
$stmt = $db->prepare("
    SELECT 
        m.id, m.first_name, m.last_name, m.salutation, m.street, m.postal_code, m.city,
        m.member_number, m.member_type,
        o.id as obligation_id, o.fee_year, o.fee_amount, o.paid_amount, o.due_date,
        (SELECT MAX(mp.payment_date) FROM member_payments mp INNER JOIN member_fee_obligations mfo ON mp.obligation_id = mfo.id WHERE mfo.member_id = m.id) as last_payment_date
    FROM members m
    INNER JOIN member_fee_obligations o ON m.id = o.member_id
    WHERE o.id IN ($placeholders)
    ORDER BY m.last_name, m.first_name
");
$stmt->execute($ids);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($members)) {
    die('Keine Mitglieder gefunden.');
}

$currentDate = date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zahlungserinnerungen - Druckansicht</title>
    <style>
        /* Screen styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #000;
            background: #f0f0f0;
            padding: 20px;
        }
        
        .no-print {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .no-print h1 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .no-print .actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #dc2626;
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .letter-container {
            background: white;
            width: 210mm;
            margin: 0 auto 20px;
            padding: 10mm 18mm 10mm 18mm;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            page-break-inside: avoid;
            page-break-after: auto;
        }
        
        .letter-container:last-child {
            margin-bottom: 0;
        }
        
        /* Letterhead */
        .letterhead {
            border-bottom: 2px solid #dc2626;
            padding-bottom: 2mm;
            margin-bottom: 4mm;
        }
        
        .letterhead .org-name {
            font-size: 16pt;
            font-weight: bold;
            color: #dc2626;
            margin-bottom: 2mm;
        }
        
        .letterhead .org-details {
            font-size: 8pt;
            color: #666;
            line-height: 1.3;
        }
        
        /* Address block */
        .address-window {
            margin-bottom: 4mm;
            margin-top: 20mm;
        }
        
        .sender-line {
            font-size: 7pt;
            color: #666;
            border-bottom: 1px solid #ccc;
            padding-bottom: 1mm;
            margin-bottom: 2mm;
        }
        
        .recipient {
            font-size: 11pt;
            line-height: 1.3;
        }
        
        /* Letter content */
        .date-line {
            text-align: right;
            margin-bottom: 3mm;
            font-size: 9pt;
        }
        
        .subject {
            font-weight: bold;
            font-size: 10pt;
            margin-bottom: 3mm;
        }
        
        .salutation {
            margin-bottom: 1.5mm;
        }
        
        .content p {
            margin-bottom: 1.5mm;
            text-align: justify;
        }
        
        .info-box {
            background: #e5e5e5;
            border: 1px solid #999;
            border-radius: 4px;
            padding: 1.5mm;
            margin: 2mm 0;
        }
        
        .info-box h3 {
            font-size: 10pt;
            margin-bottom: 1mm;
            color: #dc2626;
        }
        
        .info-table {
            width: 100%;
            font-size: 9pt;
            line-height: 1.4;
        }
        
        .info-table td {
            padding: 1px 0;
        }
        
        .info-table td:first-child {
            color: #666;
            width: 45%;
        }
        
        .info-table td:last-child {
            text-align: right;
        }
        
        .info-table tr.total {
            border-top: 2px solid #ddd;
            font-weight: bold;
        }
        
        .info-table tr.total td:last-child {
            color: #dc2626;
            font-size: 12pt;
        }
        
        .payment-box {
            background: #d1d5db;
            border-left: 4px solid #3b82f6;
            padding: 2mm;
            margin: 2mm 0;
        }
        
        .payment-box h4 {
            font-size: 9pt;
            margin-bottom: 1mm;
            color: #1e40af;
        }
        
        .payment-box p {
            margin: 0;
            font-size: 8pt;
        }
        
        .registration-box {
            background: #fde68a;
            border-left: 4px solid #f59e0b;
            padding: 2mm;
            margin: 2mm 0;
        }
        
        .registration-box h4 {
            font-size: 9pt;
            margin-bottom: 1mm;
            color: #92400e;
        }
        
        .registration-box p {
            margin: 0.5mm 0;
            font-size: 8pt;
        }
        
        .closing {
            margin-top: 2mm;
        }
        
        .signature-block {
            margin-top: 3mm;
        }
        
        /* Print styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .letter-container {
                box-shadow: none;
                margin: 0;
                padding: 10mm 18mm 10mm 18mm;
                width: 210mm;
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .letter-container:last-child {
                page-break-after: auto;
            }
            
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <h1><i class="fas fa-print"></i> Zahlungserinnerungen - Druckansicht</h1>
        <p style="margin-bottom: 15px; color: #666;">
            <?= count($members) ?> Brief(e) bereit zum Drucken. 
            Verwenden Sie die Druckfunktion Ihres Browsers (Strg+P) oder klicken Sie auf "Drucken".
        </p>
        <div class="actions">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Drucken
            </button>
            <a href="payment_reminders.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Zurück
            </a>
        </div>
    </div>

    <?php foreach ($members as $member): ?>
        <?php
        $salutation = !empty($member['salutation']) ? $member['salutation'] : '';
        $firstName = $member['first_name'];
        $lastName = $member['last_name'];
        
        // Build salutation
        $salutationWord = $salutation;
        if (strcasecmp($salutation, 'Herr') === 0) {
            $salutationWord = 'Lieber';
        } elseif (strcasecmp($salutation, 'Frau') === 0) {
            $salutationWord = 'Liebe';
        }
        
        $greeting = trim($salutationWord . ' ' . $firstName);
        if (empty($greeting)) {
            $greeting = $firstName ?: $lastName ?: 'Freund';
        }
        
        $feeAmount = number_format($member['fee_amount'], 2, ',', '.');
        $paidAmount = number_format($member['paid_amount'], 2, ',', '.');
        $outstanding = number_format($member['fee_amount'] - $member['paid_amount'], 2, ',', '.');
        $lastPayment = $member['last_payment_date'] 
            ? date('d.m.Y', strtotime($member['last_payment_date']))
            : 'Vor der Systemumstellung zum 1. Januar 2024';
        $dueDate = $member['due_date'] 
            ? date('d.m.Y', strtotime($member['due_date']))
            : 'Nicht festgelegt';
        ?>
        
        <div class="letter-container">
            <!-- Letterhead -->
            <div class="letterhead">
                <div class="org-name"><?= htmlspecialchars($org['name']) ?></div>
                <div class="org-details">
                    <?php if ($org['street']): ?>
                        <?= htmlspecialchars($org['street']) ?> • 
                        <?= htmlspecialchars($org['postal_code']) ?> <?= htmlspecialchars($org['city']) ?><br>
                    <?php endif; ?>
                    <?php if ($org['phone']): ?>Tel: <?= htmlspecialchars($org['phone']) ?> • <?php endif; ?>
                    <?php if ($org['email']): ?>E-Mail: <?= htmlspecialchars($org['email']) ?> • <?php endif; ?>
                    <?= htmlspecialchars($org['website']) ?>
                </div>
            </div>
            
            <!-- Address window -->
            <div class="address-window">
                <div class="sender-line">
                    <?= htmlspecialchars($org['name']) ?>, 
                    <?= htmlspecialchars($org['street']) ?>, 
                    <?= htmlspecialchars($org['postal_code']) ?> <?= htmlspecialchars($org['city']) ?>
                </div>
                <div class="recipient">
                    <?php if ($salutation): ?><?= htmlspecialchars($salutation) ?> <?php endif; ?>
                    <?= htmlspecialchars($firstName) ?> <?= htmlspecialchars($lastName) ?><br>
                    <?php if ($member['street']): ?>
                        <?= htmlspecialchars($member['street']) ?><br>
                        <?= htmlspecialchars($member['postal_code']) ?> <?= htmlspecialchars($member['city']) ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Date and subject -->
            <div class="date-line">
                <?= $currentDate ?>
            </div>
            
            <div class="subject">
                Zahlungserinnerung: Förderbeitrag <?= htmlspecialchars($member['fee_year']) ?>
            </div>
            
            <!-- Letter content -->
            <div class="content">
                <div class="salutation">
                    Hallo <?= htmlspecialchars($greeting) ?>,
                </div>
                
                <p>
                    vielen Dank für dein Engagement! Wir möchten dich daran erinnern, dass der Förderbeitrag 
                    für das Jahr <strong><?= htmlspecialchars($member['fee_year']) ?></strong> noch offen ist.
                </p>
                
                <p>
                    Wir bedanken uns ganz herzlich für dein persönliches und finanzielles Engagement, mit dem 
                    du unsere Feuerwehr unterstützt. Ohne dich wären viele der freiwilligen Aktivitäten gar 
                    nicht möglich.
                </p>
                
                <p>
                    Wir freuen uns sehr, dass wir dich auch in diesem Jahr als förderndes Mitglied an unserer 
                    Seite haben. Bitte überweise deinen Beitrag wie gewohnt auf unser Konto.
                </p>
                
                <!-- Payment information -->
                <div class="info-box">
                    <h3>Deine Beitragsinformationen</h3>
                    <table class="info-table">
                        <tr>
                            <td>Mitgliedsnummer:</td>
                            <td><?= htmlspecialchars($member['member_number'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td>Beitragsjahr:</td>
                            <td><?= htmlspecialchars($member['fee_year']) ?></td>
                        </tr>
                        <tr>
                            <td>Förderbetrag:</td>
                            <td><?= $feeAmount ?> €</td>
                        </tr>
                        <tr>
                            <td>Bereits eingegangen:</td>
                            <td style="color: #10b981;"><?= $paidAmount ?> €</td>
                        </tr>
                        <tr class="total">
                            <td>Ausstehender Mindestbeitrag:</td>
                            <td><?= $outstanding ?> €</td>
                        </tr>
                        <tr>
                            <td>Fälligkeitsdatum:</td>
                            <td><?= $dueDate ?></td>
                        </tr>
                        <tr>
                            <td>Letzte Zahlung:</td>
                            <td style="color: #666;"><?= $lastPayment ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Payment Methods (Two Columns) -->
                <?php
                $hasPaypal = !empty($org['paypal_link']);
                if ($hasPaypal) {
                    $paypalPaymentLink = $org['paypal_link'] . '/' . number_format($member['fee_amount'], 2);
                    $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paypalPaymentLink);
                }
                ?>
                
                <div style="display: flex; gap: 3mm; margin: 3mm 0;">
                    <!-- Bank Transfer (Red) -->
                    <div class="payment-box" style="flex: <?= $hasPaypal ? '1' : '1' ?>; background: #fecaca; border-left-color: #dc2626;">
                        <h4 style="color: #991b1b;">Banküberweisung</h4>
                        <p><strong>IBAN:</strong> <?= htmlspecialchars($org['iban']) ?></p>
                        <p><strong>Kontoinhaber:</strong> <?= htmlspecialchars($org['bank_owner']) ?></p>
                    </div>
                    
                    <?php if ($hasPaypal): ?>
                    <!-- PayPal with QR Code (Blue) -->
                    <div class="payment-box" style="flex: 1; background: #bfdbfe; border-left-color: #3b82f6;">
                        <h4 style="color: #1e40af;">PayPal</h4>
                        <div style="display: flex; align-items: center; gap: 2mm;">
                            <div style="flex: 1; font-size: 7pt;">
                                <p style="word-break: break-all;"><?= htmlspecialchars($paypalPaymentLink) ?></p>
                                <p style="color: #666; margin-top: 1mm;">Scanne QR-Code</p>
                            </div>
                            <div>
                                <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="QR" style="width: 18mm; height: 18mm; border: 1px solid #3b82f6;">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Registration invitation -->
                <div class="registration-box">
                    <h4>🌐 Digitale Kommunikation</h4>
                    <div style="display: flex; gap: 2mm; align-items: flex-start;">
                        <div style="flex: 1;">
                            <p>
                                Registriere dich auf <strong>https://owmm.de/register.php</strong> für digitale Kommunikation.
                            </p>
                            <p style="font-size: 7pt; color: #666; margin-top: 1mm;">
                                Nach der Registrierung erhältst du alle Informationen per E-Mail und kannst deine Daten selbst verwalten.
                            </p>
                        </div>
                        <div>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=https://owmm.de/register.php" alt="Registration QR" style="width: 16mm; height: 16mm; border: 1px solid #f59e0b;">
                        </div>
                    </div>
                </div>
                
                <div class="closing">
                    <p>
                        Bei Fragen melde dich gern jederzeit bei uns.
                    </p>
                    
                    <?php if ($contactName || $contactEmail || $contactMobile): ?>
                    <p style="font-size: 8pt; color: #666; margin-top: 2mm;">
                        <strong>Kontakt:</strong> <?= htmlspecialchars($contactName) ?>
                        <?php if ($contactMobile): ?>| Mobil: <?= htmlspecialchars($contactMobile) ?><?php endif; ?>
                        <?php if ($contactEmail): ?>| E-Mail: <?= htmlspecialchars($contactEmail) ?><?php endif; ?>
                    </p>
                    <?php endif; ?>
                    
                    <div class="signature-block">
                        <p>
                            Viele Grüße<br>
                            <strong><?= htmlspecialchars($org['name']) ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</body>
</html>
