<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/EmailService.php';
require_once '../includes/EmailTemplates.php';

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

// Get organization settings
$orgStmt = $db->query("SELECT iban, bank_owner, paypal_link, name FROM organization WHERE id = 1");
$org = $orgStmt->fetch(PDO::FETCH_ASSOC);

$iban = $org['iban'] ?? 'DE73258516600000169862';
$bankOwner = $org['bank_owner'] ?? 'Feuerwehr Meinern';
$paypalLink = $org['paypal_link'] ?? 'https://paypal.me/owmm';
$orgName = $org['name'] ?? 'OwMM - Ortswehr Meinern-Mittelstendorf';

// Sample data for active member
$activeMember = [
    'first_name' => 'Max',
    'last_name' => 'Müller',
    'salutation' => 'Herr',
    'member_number' => '12345',
    'email' => 'max@example.com',
    'member_type' => 'active'
];

$activeObligation = [
    'fee_year' => 2025,
    'fee_amount' => 50.00,
    'paid_amount' => 0.00,
    'last_payment_date' => null,
    'due_date' => '2025-12-31'
];

// Sample data for supporter
$supporterMember = [
    'first_name' => 'Jörn',
    'last_name' => 'Schmidt',
    'salutation' => 'Herr',
    'member_number' => '67890',
    'email' => 'joern@example.com',
    'member_type' => 'supporter'
];

$supporterObligation = [
    'fee_year' => 2025,
    'fee_amount' => 30.00,
    'paid_amount' => 0.00,
    'last_payment_date' => '2024-06-17',
    'due_date' => '2025-12-31'
];

// Sample contact person
$contactPerson = [
    'name' => 'Peter Scharringhausen',
    'email' => 'peter@example.com',
    'mobile' => '015208987931'
];

// Generate PayPal QR code
$paypalQRCode = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paypalLink . '/50');

$previewType = $_GET['type'] ?? 'supporter';
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preview - Payment Reminder</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .tab-button {
            padding: 10px 20px;
            border: none;
            background-color: #ddd;
            cursor: pointer;
            border-radius: 4px;
            font-size: 16px;
            font-weight: bold;
        }
        .tab-button.active {
            background-color: #dc2626;
            color: white;
        }
        .preview-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .info-box {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        iframe {
            width: 100%;
            height: 1000px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Email Template Preview</h1>
        
        <div class="tabs">
            <button class="tab-button <?= $previewType === 'supporter' ? 'active' : '' ?>" 
                    onclick="window.location.href='?type=supporter'">
                Förderer / Supporter
            </button>
            <button class="tab-button <?= $previewType === 'active' ? 'active' : '' ?>" 
                    onclick="window.location.href='?type=active'">
                Einsatzeinheit / Active
            </button>
        </div>

        <div class="info-box">
            <strong>ℹ️ Hinweis:</strong> Dies ist eine Vorschau der E-Mail-Vorlage. Scrollen Sie nach unten, um den kompletten E-Mail-Inhalt zu sehen.
        </div>

        <div class="preview-container">
            <?php
            if ($previewType === 'active') {
                // Generate active member email
                $paypalQRCode = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paypalLink . '/50');
                $emailHTML = EmailTemplates::generatePaymentReminderActive(
                    $activeMember,
                    $activeObligation,
                    $iban,
                    $bankOwner,
                    $paypalLink . '/50',
                    $paypalQRCode,
                    $contactPerson
                );
            } else {
                // Generate supporter member email
                $paypalQRCode = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paypalLink . '/30');
                $emailHTML = EmailTemplates::generatePaymentReminderSupporter(
                    $supporterMember,
                    $supporterObligation,
                    $iban,
                    $bankOwner,
                    $paypalLink . '/30',
                    $paypalQRCode,
                    $orgName,
                    $contactPerson
                );
            }

            // Display in iframe
            echo '<iframe srcdoc="' . htmlspecialchars($emailHTML, ENT_QUOTES, 'UTF-8') . '"></iframe>';
            ?>
        </div>
    </div>
</body>
</html>
