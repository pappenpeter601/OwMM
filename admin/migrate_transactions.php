<?php
/**
 * Migration Script: Transfer TRANSACTION data from old to new structure
 * 
 * The old TRANSACTION table and new transactions table are in the SAME database.
 * This script migrates all transactions including documents from longblob.
 * 
 * HOW TO RUN:
 * 
 * OPTION 1 - Web Browser (Easiest):
 *   - Open in browser: http://localhost/OwMM/admin/migrate_transactions.php
 *   - Watch the progress and results
 * 
 * OPTION 2 - Command Line:
 *   - cd /home/bee/git/OwMM
 *   - php admin/migrate_transactions.php
 * 
 * The script will:
 * 1. Check if old TRANSACTION table exists
 * 2. Migrate all transaction data
 * 3. Extract and save DOCUMENT blobs as files
 * 4. Auto-calculate business_year from booking_date
 * 5. Show detailed results
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Detect if running in browser or CLI
$is_cli = php_sapi_name() === 'cli';
$output = [];

if (!$is_cli) {
    ob_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaktions-Migration</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .migration-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .status-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #2196F3;
        }
        .status-card.success {
            border-left-color: #4CAF50;
        }
        .status-card.warning {
            border-left-color: #FF9800;
        }
        .status-card.error {
            border-left-color: #F44336;
        }
        .status-card h2 {
            margin: 0 0 0.5rem 0;
            color: #333;
            font-size: 1.3rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-box {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0.5rem 0;
        }
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        .stat-box.success .stat-number {
            color: #4CAF50;
        }
        .stat-box.warning .stat-number {
            color: #FF9800;
        }
        .stat-box.error .stat-number {
            color: #F44336;
        }
        .category-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .category-item {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 1rem;
        }
        .category-item strong {
            color: #333;
        }
        .category-item span {
            float: right;
            background: #2196F3;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        .error-list {
            background: #ffebee;
            border: 1px solid #ef5350;
            border-radius: 4px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .error-list li {
            color: #c62828;
            margin-bottom: 0.5rem;
        }
        .success-banner {
            background: #4CAF50;
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .success-banner i {
            font-size: 2rem;
        }
        .progress-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="migration-container">
        <h1>🔄 Transaktions-Migrations-Übersicht</h1>
<?php
    ob_end_clean();
    ob_start();
}

// Get database connection
$db = getDBConnection();

if (!$is_cli) {
    echo '<div class="progress-section">' . "\n";
    echo '<h2>Migration läuft...</h2>' . "\n";
    echo '<pre style="background: #f5f5f5; padding: 1rem; border-radius: 4px; overflow-x: auto;">' . "\n";
}

echo "=== Transaction Migration Script ===\n\n";

// Step 1: Check if old table exists
try {
    $result = $db->query("SHOW TABLES LIKE 'TRANSACTION'");
    if ($result->rowCount() === 0) {
        die("Error: TRANSACTION table not found in database\n");
    }
    echo "✓ Found TRANSACTION table in database\n";
} catch (Exception $e) {
    die("Error checking table: " . $e->getMessage());
}

// Step 2: Get all transactions from old table
try {
    $stmt = $db->query("SELECT id, Buchungstag, Buchungstext, Verwendungszweck, Zahlungspflichtiger, IBAN, Betrag, DOCUMENT, CATEGORY, Comment FROM TRANSACTION ORDER BY id ASC");
    $old_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($old_transactions) . " transactions in old table\n\n";
} catch (Exception $e) {
    die("Error fetching transactions: " . $e->getMessage());
}

// Step 3: Prepare documents directory
$docs_dir = __DIR__ . '/../uploads/documents/';
if (!is_dir($docs_dir)) {
    mkdir($docs_dir, 0755, true);
    echo "✓ Created documents directory\n";
}

// Step 4: Build category mapping
$stmt = $db->query("SELECT id, name FROM transaction_categories");
$categories = [];
foreach ($stmt->fetchAll() as $cat) {
    $categories[strtolower($cat['name'])] = $cat['id'];
}

echo "=== Available Categories ===\n";
foreach ($categories as $name => $id) {
    echo "  - {$name} (ID: {$id})\n";
}
echo "\n";

// Step 5: Migrate transactions
$migrated = 0;
$skipped = 0;
$errors = [];
$docs_saved = 0;

$db->beginTransaction();

try {
    $stmt_insert = $db->prepare("
        INSERT INTO transactions (
            booking_date, 
            booking_text, 
            purpose, 
            payer, 
            iban, 
            amount, 
            category_id, 
            comment, 
            business_year,
            created_by
        ) VALUES (
            :booking_date,
            :booking_text,
            :purpose,
            :payer,
            :iban,
            :amount,
            :category_id,
            :comment,
            :business_year,
            1
        )
    ");

    foreach ($old_transactions as $trans) {
        try {
            // Extract business year from booking date (always automatic)
            $booking_date = $trans['Buchungstag'];
            $year = null;
            
            if (is_string($booking_date) && !empty($booking_date)) {
                // Handle German date format (DD.MM.YY or DD.MM.YYYY)
                if (preg_match('/(\d{2})\.(\d{2})\.(\d{2,4})/', $booking_date, $matches)) {
                    $year_str = $matches[3];
                    if (strlen($year_str) === 2) {
                        $year = (int)('20' . $year_str);
                    } else {
                        $year = (int)$year_str;
                    }
                } else {
                    // Try standard Y-m-d format
                    try {
                        $date_obj = DateTime::createFromFormat('Y-m-d', $booking_date);
                        if ($date_obj) {
                            $year = (int)$date_obj->format('Y');
                        }
                    } catch (Exception $e) {
                        // Skip if date parsing fails
                    }
                }
            }
            
            // If year extraction failed, skip this transaction
            if ($year === null) {
                throw new Exception("Could not extract year from booking date: {$booking_date}");
            }

            // Map category
            $category_id = null;
            if (!empty($trans['CATEGORY'])) {
                $cat_key = strtolower(trim($trans['CATEGORY']));
                $category_id = $categories[$cat_key] ?? null;
            }

            // Prepare amount
            $amount = (float)($trans['Betrag'] ?? 0);
            
            // Truncate payer if too long (max 100 chars)
            $payer = substr($trans['Zahlungspflichtiger'], 0, 100);

            $stmt_insert->execute([
                'booking_date' => $booking_date,
                'booking_text' => $trans['Buchungstext'],
                'purpose' => $trans['Verwendungszweck'],
                'payer' => $payer,
                'iban' => $trans['IBAN'],
                'amount' => $amount,
                'category_id' => $category_id,
                'comment' => $trans['Comment'] ?? null,
                'business_year' => $year
            ]);

            $transaction_id = $db->lastInsertId();

            // Handle DOCUMENT longblob if it exists and has data
            if (!empty($trans['DOCUMENT'])) {
                try {
                    $doc_data = $trans['DOCUMENT'];
                    $doc_ext = 'pdf'; // Default extension
                    
                    // Try to detect file type from blob header
                    $header = substr($doc_data, 0, 4);
                    if (strpos($header, '%PDF') === 0) {
                        $doc_ext = 'pdf';
                    } elseif (strpos(bin2hex($header), 'ffd8') === 0) {
                        $doc_ext = 'jpg';
                    } elseif (strpos(bin2hex($header), '89504e47') === 0) {
                        $doc_ext = 'png';
                    }
                    
                    $doc_filename = 'doc_' . $transaction_id . '_' . time() . '.' . $doc_ext;
                    $doc_path = $docs_dir . $doc_filename;
                    
                    if (file_put_contents($doc_path, $doc_data)) {
                        $file_size = filesize($doc_path);
                        $stmt_doc = $db->prepare("INSERT INTO transaction_documents (transaction_id, file_name, file_path, file_size, uploaded_by) 
                                                  VALUES (:transaction_id, :file_name, :file_path, :file_size, :uploaded_by)");
                        $stmt_doc->execute([
                            'transaction_id' => $transaction_id,
                            'file_name' => 'Document_' . $transaction_id . '.' . $doc_ext,
                            'file_path' => 'documents/' . $doc_filename,
                            'file_size' => $file_size,
                            'uploaded_by' => 1 // admin user
                        ]);
                        $docs_saved++;
                    }
                } catch (Exception $e) {
                    // Log document save error but don't fail transaction
                    $errors[] = "Transaction {$transaction_id}: Document save failed - " . $e->getMessage();
                }
            }

            $migrated++;

            if ($migrated % 50 === 0) {
                echo "  Processed {$migrated} transactions...\n";
            }

        } catch (Exception $e) {
            $skipped++;
            $errors[] = "Transaction ID {$trans['id']}: " . $e->getMessage();
        }
    }

    $db->commit();
    echo "\n✓ Transaction commit successful\n";

} catch (Exception $e) {
    $db->rollBack();
    die("Error during migration: " . $e->getMessage());
}

// Step 6: Display results
echo "\n=== Migration Results ===\n";
echo "Migrated: {$migrated} transactions\n";
echo "Documents saved: {$docs_saved}\n";
echo "Skipped: {$skipped} transactions\n";

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach (array_slice($errors, 0, 10) as $error) {
        echo "  - {$error}\n";
    }
    if (count($errors) > 10) {
        echo "  ... and " . (count($errors) - 10) . " more errors\n";
    }
}

// Step 7: Verify migration
echo "\n=== Verification ===\n";
$stmt = $db->query("SELECT COUNT(*) as count FROM transactions");
$new_count = $stmt->fetch()['count'];
echo "New transactions table now contains: {$new_count} records\n";

$stmt = $db->query("SELECT category_id, COUNT(*) as count FROM transactions GROUP BY category_id ORDER BY category_id");
$category_breakdown = [];
foreach ($stmt->fetchAll() as $row) {
    $category_breakdown[] = $row;
    if ($row['category_id'] === null) {
        echo "  - Uncategorized: {$row['count']}\n";
    } else {
        $cat_stmt = $db->prepare("SELECT name FROM transaction_categories WHERE id = ?");
        $cat_stmt->execute([$row['category_id']]);
        $cat_name = $cat_stmt->fetch()['name'];
        echo "  - {$cat_name}: {$row['count']}\n";
    }
}

$stmt = $db->query("SELECT business_year, COUNT(*) as count FROM transactions WHERE business_year IS NOT NULL GROUP BY business_year ORDER BY business_year DESC");
$year_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nTransactions by business year:\n";
foreach ($year_breakdown as $row) {
    echo "  - {$row['business_year']}: {$row['count']}\n";
}

echo "\n✓ Migration completed successfully!\n";

if (!$is_cli) {
    echo '</pre>' . "\n";
    echo '</div>' . "\n";
    
    // Display HTML results
    $success = ($skipped === 0);
    $icon = $success ? '✓' : '⚠';
    $color = $success ? 'success' : 'warning';
    
    ob_end_flush();
?>

    <div class="success-banner">
        <i class="fas fa-<?php echo $success ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <div>
            <strong>Migration <?php echo $success ? 'erfolgreich abgeschlossen!' : 'mit Warnungen abgeschlossen'; ?></strong>
            <p><?php echo $migrated; ?> Transaktionen wurden migriert.</p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-box success">
            <div class="stat-label">Migriert</div>
            <div class="stat-number"><?php echo $migrated; ?></div>
        </div>
        <div class="stat-box <?php echo ($skipped > 0) ? 'warning' : 'success'; ?>">
            <div class="stat-label">Übersprungen</div>
            <div class="stat-number"><?php echo $skipped; ?></div>
        </div>
        <div class="stat-box success">
            <div class="stat-label">Dokumente</div>
            <div class="stat-number"><?php echo $docs_saved; ?></div>
        </div>
        <div class="stat-box success">
            <div class="stat-label">Insgesamt</div>
            <div class="stat-number"><?php echo $new_count; ?></div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="status-card error">
        <h2><i class="fas fa-exclamation-triangle"></i> Fehler (<?php echo count($errors); ?>)</h2>
        <ul class="error-list">
            <?php foreach (array_slice($errors, 0, 10) as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > 10): ?>
            <li><strong>... und <?php echo count($errors) - 10; ?> weitere Fehler</strong></li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="status-card">
        <h2><i class="fas fa-list"></i> Kategorien-Übersicht</h2>
        <div class="category-list">
            <?php foreach ($category_breakdown as $cat): ?>
            <div class="category-item">
                <strong><?php 
                    if ($cat['category_id'] === null) {
                        echo 'Uncategorized';
                    } else {
                        $cat_stmt = $db->prepare("SELECT name FROM transaction_categories WHERE id = ?");
                        $cat_stmt->execute([$cat['category_id']]);
                        echo htmlspecialchars($cat_stmt->fetch()['name']);
                    }
                ?></strong>
                <span><?php echo $cat['count']; ?></span>
                <div style="clear: both;"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="status-card">
        <h2><i class="fas fa-calendar"></i> Nach Geschäftsjahr</h2>
        <div class="category-list">
            <?php foreach ($year_breakdown as $year): ?>
            <div class="category-item">
                <strong><?php echo $year['business_year']; ?></strong>
                <span><?php echo $year['count']; ?></span>
                <div style="clear: both;"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="status-card success">
        <h2><i class="fas fa-check"></i> Nächste Schritte</h2>
        <ul>
            <li><a href="kontofuehrung.php">Migrierte Daten in Kontoführung überprüfen</a></li>
            <li>Alle Kategorien korrekt zugeordnet?</li>
            <li>Geschäftsjahre korrekt berechnet?</li>
            <li>Alte TRANSACTION Tabelle als Backup behalten oder löschen</li>
        </ul>
    </div>

    </div>
</body>
</html>
<?php
}
?>

// Get database connection
$db = getDBConnection();

echo "=== Transaction Migration Script ===\n\n";

// Step 1: Check if old table exists
try {
    $result = $db->query("SHOW TABLES LIKE 'TRANSACTION'");
    if ($result->rowCount() === 0) {
        die("Error: TRANSACTION table not found in database\n");
    }
    echo "✓ Found TRANSACTION table in database\n";
} catch (Exception $e) {
    die("Error checking table: " . $e->getMessage());
}

// Step 2: Get all transactions from old table
try {
    $stmt = $db->query("SELECT id, Buchungstag, Buchungstext, Verwendungszweck, Zahlungspflichtiger, IBAN, Betrag, DOCUMENT, CATEGORY, Comment FROM TRANSACTION ORDER BY id ASC");
    $old_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($old_transactions) . " transactions in old table\n\n";
} catch (Exception $e) {
    die("Error fetching transactions: " . $e->getMessage());
}

// Step 3: Prepare documents directory
$docs_dir = __DIR__ . '/../uploads/documents/';
if (!is_dir($docs_dir)) {
    mkdir($docs_dir, 0755, true);
    echo "✓ Created documents directory\n";
}

// Step 4: Build category mapping
$stmt = $db->query("SELECT id, name FROM transaction_categories");
$categories = [];
foreach ($stmt->fetchAll() as $cat) {
    $categories[strtolower($cat['name'])] = $cat['id'];
}

echo "=== Available Categories ===\n";
foreach ($categories as $name => $id) {
    echo "  - {$name} (ID: {$id})\n";
}
echo "\n";

// Step 5: Migrate transactions
$migrated = 0;
$skipped = 0;
$errors = [];
$docs_saved = 0;

$db->beginTransaction();

try {
    $stmt_insert = $db->prepare("
        INSERT INTO transactions (
            booking_date, 
            booking_text, 
            purpose, 
            payer, 
            iban, 
            amount, 
            category_id, 
            comment, 
            business_year,
            created_by
        ) VALUES (
            :booking_date,
            :booking_text,
            :purpose,
            :payer,
            :iban,
            :amount,
            :category_id,
            :comment,
            :business_year,
            1
        )
    ");

    foreach ($old_transactions as $trans) {
        try {
            // Extract business year from booking date (always automatic)
            $booking_date = $trans['Buchungstag'];
            $year = null;
            
            if (is_string($booking_date) && !empty($booking_date)) {
                // Handle German date format (DD.MM.YY or DD.MM.YYYY)
                if (preg_match('/(\d{2})\.(\d{2})\.(\d{2,4})/', $booking_date, $matches)) {
                    $year_str = $matches[3];
                    if (strlen($year_str) === 2) {
                        $year = (int)('20' . $year_str);
                    } else {
                        $year = (int)$year_str;
                    }
                } else {
                    // Try standard Y-m-d format
                    try {
                        $date_obj = DateTime::createFromFormat('Y-m-d', $booking_date);
                        if ($date_obj) {
                            $year = (int)$date_obj->format('Y');
                        }
                    } catch (Exception $e) {
                        // Skip if date parsing fails
                    }
                }
            }
            
            // If year extraction failed, skip this transaction
            if ($year === null) {
                throw new Exception("Could not extract year from booking date: {$booking_date}");
            }

            // Map category
            $category_id = null;
            if (!empty($trans['CATEGORY'])) {
                $cat_key = strtolower(trim($trans['CATEGORY']));
                $category_id = $categories[$cat_key] ?? null;
            }

            // Prepare amount
            $amount = (float)($trans['Betrag'] ?? 0);
            
            // Truncate payer if too long (max 100 chars)
            $payer = substr($trans['Zahlungspflichtiger'], 0, 100);

            $stmt_insert->execute([
                'booking_date' => $booking_date,
                'booking_text' => $trans['Buchungstext'],
                'purpose' => $trans['Verwendungszweck'],
                'payer' => $payer,
                'iban' => $trans['IBAN'],
                'amount' => $amount,
                'category_id' => $category_id,
                'comment' => $trans['Comment'] ?? null,
                'business_year' => $year
            ]);

            $transaction_id = $db->lastInsertId();

            // Handle DOCUMENT longblob if it exists and has data
            if (!empty($trans['DOCUMENT'])) {
                try {
                    $doc_data = $trans['DOCUMENT'];
                    $doc_ext = 'pdf'; // Default extension
                    
                    // Try to detect file type from blob header
                    $header = substr($doc_data, 0, 4);
                    if (strpos($header, '%PDF') === 0) {
                        $doc_ext = 'pdf';
                    } elseif (strpos(bin2hex($header), 'ffd8') === 0) {
                        $doc_ext = 'jpg';
                    } elseif (strpos(bin2hex($header), '89504e47') === 0) {
                        $doc_ext = 'png';
                    }
                    
                    $doc_filename = 'doc_' . $transaction_id . '_' . time() . '.' . $doc_ext;
                    $doc_path = $docs_dir . $doc_filename;
                    
                    if (file_put_contents($doc_path, $doc_data)) {
                        $file_size = filesize($doc_path);
                        $stmt_doc = $db->prepare("INSERT INTO transaction_documents (transaction_id, file_name, file_path, file_size, uploaded_by) 
                                                  VALUES (:transaction_id, :file_name, :file_path, :file_size, :uploaded_by)");
                        $stmt_doc->execute([
                            'transaction_id' => $transaction_id,
                            'file_name' => 'Document_' . $transaction_id . '.' . $doc_ext,
                            'file_path' => 'documents/' . $doc_filename,
                            'file_size' => $file_size,
                            'uploaded_by' => 1 // admin user
                        ]);
                        $docs_saved++;
                    }
                } catch (Exception $e) {
                    // Log document save error but don't fail transaction
                    $errors[] = "Transaction {$transaction_id}: Document save failed - " . $e->getMessage();
                }
            }

            $migrated++;

            if ($migrated % 50 === 0) {
                echo "  Processed {$migrated} transactions...\n";
            }

        } catch (Exception $e) {
            $skipped++;
            $errors[] = "Transaction ID {$trans['id']}: " . $e->getMessage();
        }
    }

    $db->commit();
    echo "\n✓ Transaction commit successful\n";

} catch (Exception $e) {
    $db->rollBack();
    die("Error during migration: " . $e->getMessage());
}

// Step 6: Display results
echo "\n=== Migration Results ===\n";
echo "Migrated: {$migrated} transactions\n";
echo "Documents saved: {$docs_saved}\n";
echo "Skipped: {$skipped} transactions\n";

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach (array_slice($errors, 0, 10) as $error) {
        echo "  - {$error}\n";
    }
    if (count($errors) > 10) {
        echo "  ... and " . (count($errors) - 10) . " more errors\n";
    }
}

// Step 7: Verify migration
echo "\n=== Verification ===\n";
$stmt = $db->query("SELECT COUNT(*) as count FROM transactions");
$new_count = $stmt->fetch()['count'];
echo "New transactions table now contains: {$new_count} records\n";

$stmt = $db->query("SELECT category_id, COUNT(*) as count FROM transactions GROUP BY category_id ORDER BY category_id");
echo "\nTransactions by category:\n";
foreach ($stmt->fetchAll() as $row) {
    if ($row['category_id'] === null) {
        echo "  - Uncategorized: {$row['count']}\n";
    } else {
        $cat_stmt = $db->prepare("SELECT name FROM transaction_categories WHERE id = ?");
        $cat_stmt->execute([$row['category_id']]);
        $cat_name = $cat_stmt->fetch()['name'];
        echo "  - {$cat_name}: {$row['count']}\n";
    }
}

$stmt = $db->query("SELECT business_year, COUNT(*) as count FROM transactions WHERE business_year IS NOT NULL GROUP BY business_year ORDER BY business_year DESC");
echo "\nTransactions by business year:\n";
foreach ($stmt->fetchAll() as $row) {
    echo "  - {$row['business_year']}: {$row['count']}\n";
}


