<?php
/**
 * Helper Functions
 */

/**
 * Sanitize input data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Ensure user is authenticated and optionally has a required role
function check_auth($required_role = null) {
    if (!is_logged_in()) {
        $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }

    if ($required_role !== null && !has_role($required_role)) {
        $_SESSION['error'] = 'Zugriff verweigert.';
        redirect('dashboard.php');
    }
}

/**
 * Check if user has permission for current page
 */
function check_page_permission() {
    $current_page = basename($_SERVER['PHP_SELF']);
    
    if (!has_permission($current_page)) {
        redirect('dashboard.php');
    }
}

/**
 * Check if user has specific permission
 */
function has_permission($permission_name) {
    if (!is_logged_in()) {
        return false;
    }
    
    // Admin always has all permissions
    $is_admin = $_SESSION['is_admin'] ?? 0;
    if ($is_admin) {
        return true;
    }
    
    $user_id = $_SESSION['user_id'];
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as has_perm
        FROM user_permissions up
        INNER JOIN permissions p ON up.permission_id = p.id
        WHERE up.user_id = ? AND p.name = ?
    ");
    $stmt->execute([$user_id, $permission_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['has_perm'] > 0;
}

/**
 * Check if user is admin
 */
function is_admin() {
    if (!is_logged_in()) {
        return false;
    }
    
    return (bool)($_SESSION['is_admin'] ?? 0);
}

/**
 * Deprecated: Use is_admin() or permission helpers instead - kept for backward compatibility
 * Maps legacy role names to current permission checks.
 */
function has_role($required_role) {
    if (!is_logged_in()) {
        return false;
    }

    if ($required_role === 'admin') {
        return is_admin();
    }

    if ($required_role === 'kassenpruefer') {
        // Kassenprüfer pages are guarded by these permissions
        return is_admin() || has_permission('check_periods.php') || has_permission('kassenpruefer_assignments.php');
    }
    
    return false;
}

/**
 * Check if user can edit operations (admin or pr_manager)
 */
function can_edit_operations() {
    return has_permission('operations.php');
}

/**
 * Check if user can edit events (admin or event_manager)
 */
function can_edit_events() {
    return has_permission('events.php');
}

/**
 * Check if user can edit page content (admin or board)
 */
function can_edit_page_content() {
    return has_permission('content.php') || has_permission('board.php');
}

/**
 * Redirect to another page
 */
function redirect($url) {
    header("Location: " . $url);
    exit();
}

/**
 * Format date in German format
 */
function format_date($date, $format = 'd.m.Y') {
    return date($format, strtotime($date));
}

/**
 * Format datetime in German format
 */
function format_datetime($datetime, $format = 'd.m.Y H:i') {
    return date($format, strtotime($datetime));
}

/**
 * Upload image file
 */
function upload_image($file, $subfolder = '') {
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Keine Datei hochgeladen'];
    }
    
    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, ALLOWED_IMAGE_TYPES)) {
        return ['success' => false, 'error' => 'Ungültiger Dateityp'];
    }
    
    // Validate file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'Datei zu groß (max. 5MB)'];
    }
    
    // Create upload directory if it doesn't exist
    $upload_dir = UPLOAD_PATH . $subfolder;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . '/' . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        $url = $subfolder ? $subfolder . '/' . $filename : $filename;
        return ['success' => true, 'url' => $url, 'path' => $filepath];
    }
    
    return ['success' => false, 'error' => 'Upload fehlgeschlagen'];
}

/**
 * Delete image file
 */
function delete_image($url) {
    if (empty($url)) {
        return false;
    }
    
    $filepath = UPLOAD_PATH . $url;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    
    return false;
}

/**
 * Get operations with pagination
 */
function get_operations($limit = null, $offset = 0, $published_only = true) {
    $db = getDBConnection();
    
    $sql = "SELECT o.*, u.username as creator 
            FROM operations o 
            LEFT JOIN users u ON o.created_by = u.id 
            WHERE 1=1";
    
    if ($published_only) {
        $sql .= " AND o.published = 1";
    }
    
    $sql .= " ORDER BY o.operation_date DESC";
    
    if ($limit !== null) {
        $sql .= " LIMIT :limit OFFSET :offset";
    }
    
    $stmt = $db->prepare($sql);
    
    if ($limit !== null) {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get operation images
 */
function get_operation_images($operation_id) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM operation_images WHERE operation_id = :operation_id ORDER BY sort_order");
    $stmt->execute([':operation_id' => $operation_id]);
    return $stmt->fetchAll();
}

/**
 * Get events
 */
function get_events($status = null, $limit = null, $published_only = true) {
    $db = getDBConnection();
    
    $sql = "SELECT e.*, u.username as creator 
            FROM events e 
            LEFT JOIN users u ON e.created_by = u.id 
            WHERE 1=1";
    
    if ($published_only) {
        $sql .= " AND e.published = 1";
    }
    
    if ($status) {
        $sql .= " AND e.status = :status";
    }
    
    $sql .= " ORDER BY e.event_date " . ($status === 'past' ? 'DESC' : 'ASC');
    
    if ($limit !== null) {
        $sql .= " LIMIT :limit";
    }
    
    $stmt = $db->prepare($sql);
    
    if ($status) {
        $stmt->bindValue(':status', $status);
    }
    
    if ($limit !== null) {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get event images
 */
function get_event_images($event_id) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM event_images WHERE event_id = :event_id ORDER BY sort_order");
    $stmt->execute([':event_id' => $event_id]);
    return $stmt->fetchAll();
}

/**
 * Get board members
 */
function get_board_members($active_only = true) {
    $db = getDBConnection();
    
    $sql = "SELECT id, first_name, last_name, board_position as position, 
                   board_image_url as image_url, email, telephone, mobile, active
            FROM members 
            WHERE member_type = 'active' AND is_board_member = 1";
    
    if ($active_only) {
        $sql .= " AND active = 1";
    }
    
    $sql .= " ORDER BY board_sort_order ASC, last_name ASC, first_name ASC";
    
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

/**
 * Get page content by section key
 */
function get_page_content($section_key) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM page_content WHERE section_key = :key");
    $stmt->execute([':key' => $section_key]);
    return $stmt->fetch();
}

/**
 * Get social media links
 */
function get_social_media() {
    $db = getDBConnection();
    $stmt = $db->query("SELECT * FROM social_media WHERE active = 1 ORDER BY sort_order");
    return $stmt->fetchAll();
}

/**
 * Send email notification
 */
function send_email($to, $subject, $message) {
    $headers = "From: " . ADMIN_EMAIL . "\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}
/**
 * Check if user can manage cash/financial transactions (admin or kassenpruefer)
 */
function can_edit_cash() {
    return has_permission('kontofuehrung.php');
}

/**
 * Check if user can check transactions (admin or kassenpruefer)
 */
function can_check_transactions() {
    return has_permission('check_periods.php') || has_permission('kassenpruefer_assignments.php');
}

/**
 * Check if a transaction is locked (finalized in a check period)
 */
function is_transaction_locked($transaction_id) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT checked_in_period_id FROM transactions WHERE id = :id");
    $stmt->execute(['id' => $transaction_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result && $result['checked_in_period_id'] !== null;
}

/**
 * Get all transactions with optional filtering
 */
function get_transactions($category = null, $start_date = null, $end_date = null, $limit = null, $offset = 0) {
    $db = getDBConnection();
    
    $sql = "SELECT * FROM transactions WHERE 1=1";
    $params = [];
    
    if ($category) {
        $sql .= " AND category = :category";
        $params['category'] = $category;
    }
    
    if ($start_date) {
        $sql .= " AND booking_date >= :start_date";
        $params['start_date'] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND booking_date <= :end_date";
        $params['end_date'] = $end_date;
    }
    
    $sql .= " ORDER BY booking_date DESC";
    
    if ($limit) {
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = (int)$limit;
        $params['offset'] = (int)$offset;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get transaction by ID with documents
 */
function get_transaction($id) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $transaction = $stmt->fetch();
    
    if ($transaction) {
        $stmt = $db->prepare("SELECT * FROM transaction_documents WHERE transaction_id = :id ORDER BY uploaded_at DESC");
        $stmt->execute([':id' => $id]);
        $transaction['documents'] = $stmt->fetchAll();
    }
    
    return $transaction;
}

/**
 * Get all transaction categories
 */
function get_transaction_categories() {
    $db = getDBConnection();
    $stmt = $db->query("SELECT DISTINCT category FROM transactions WHERE category IS NOT NULL ORDER BY category");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Upload CSV transactions
 */
function upload_csv_transactions($file) {
    if ($file['size'] == 0 || !in_array($file['type'], ['text/csv', 'application/vnd.ms-excel', 'text/plain'])) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    $db = getDBConnection();
    $rows = [];
    $errors = [];
    $inserted = 0;
    $skipped = 0;
    $skipped_dates = [];
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;
    
    if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
        // Skip header row - use semicolon delimiter for German bank CSV
        fgetcsv($handle, 0, ';');
        
        // First pass: collect all dates from CSV and parse all transactions
        $transactions_to_import = [];
        $csv_dates = [];
        $line_number = 1;
        
        while (($data = fgetcsv($handle, 0, ';')) !== FALSE) {
            $line_number++;
            
            // German bank CSV format:
            // 0: Auftragskonto, 1: Buchungstag, 2: Valutadatum, 3: Buchungstext, 
            // 4: Verwendungszweck, 5: Beguenstigter/Zahlungspflichtiger, 6: Kontonummer/IBAN, 
            // 7: BIC, 8: Betrag, 9: Waehrung, 10: Info, 11: Kategorie
            
            if (count($data) < 9) {
                $errors[] = "Zeile $line_number: Ungültige Anzahl von Spalten (" . count($data) . ")";
                continue;
            }
            
            try {
                // Parse date (DD.MM.YY format to YYYY-MM-DD)
                $booking_date_str = trim($data[1]);
                if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2,4})$/', $booking_date_str, $matches)) {
                    $day = $matches[1];
                    $month = $matches[2];
                    $year = $matches[3];
                    // Convert 2-digit year to 4-digit
                    if (strlen($year) == 2) {
                        $year = '20' . $year;
                    }
                    $booking_date = "$year-$month-$day";
                } else {
                    $errors[] = "Zeile $line_number: Ungültiges Datumsformat '$booking_date_str'";
                    continue;
                }
                
                // Parse amount (replace comma with dot, remove spaces)
                $amount_str = str_replace(',', '.', str_replace(' ', '', trim($data[8])));
                $amount = floatval($amount_str);
                
                // Convert encoding from ISO-8859-1/Windows-1252 to UTF-8 for German characters
                // Truncate to fit database column limits: booking_text(200), payer(100), iban(34)
                $booking_text = mb_substr(mb_convert_encoding(trim($data[3]), 'UTF-8', 'ISO-8859-1'), 0, 200);
                $purpose = mb_convert_encoding(trim($data[4]), 'UTF-8', 'ISO-8859-1'); // TEXT field, no limit
                $payer = mb_substr(mb_convert_encoding(trim($data[5]), 'UTF-8', 'ISO-8859-1'), 0, 100);
                $iban = mb_substr(mb_convert_encoding(trim($data[6]), 'UTF-8', 'ISO-8859-1'), 0, 34);
                
                // Store transaction for potential import
                $transactions_to_import[] = [
                    'booking_date' => $booking_date,
                    'booking_text' => $booking_text,
                    'purpose' => $purpose,
                    'payer' => $payer,
                    'iban' => $iban,
                    'amount' => $amount,
                    'line_number' => $line_number
                ];
                
                // Collect unique dates
                $csv_dates[$booking_date] = true;
                
            } catch (Exception $e) {
                $errors[] = "Zeile $line_number: " . $e->getMessage();
            }
        }
        fclose($handle);
        
        // Check which dates already exist in database
        $existing_dates = [];
        if (!empty($csv_dates)) {
            $date_list = array_keys($csv_dates);
            $placeholders = str_repeat('?,', count($date_list) - 1) . '?';
            $stmt = $db->prepare("SELECT DISTINCT booking_date FROM transactions WHERE booking_date IN ($placeholders)");
            $stmt->execute($date_list);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_dates[$row['booking_date']] = true;
            }
        }
        
        // Now import only transactions for dates that don't exist yet
        $db->beginTransaction();
        try {
            $insert_stmt = $db->prepare("INSERT INTO transactions 
                                         (booking_date, booking_text, purpose, payer, iban, amount, created_by) 
                                         VALUES (:booking_date, :booking_text, :purpose, :payer, :iban, :amount, :created_by)");
            
            foreach ($transactions_to_import as $trans) {
                // Skip if this date already exists in database
                if (isset($existing_dates[$trans['booking_date']])) {
                    $skipped++;
                    if (!isset($skipped_dates[$trans['booking_date']])) {
                        $skipped_dates[$trans['booking_date']] = 0;
                    }
                    $skipped_dates[$trans['booking_date']]++;
                    continue;
                }
                
                try {
                    $insert_stmt->execute([
                        ':booking_date' => $trans['booking_date'],
                        ':booking_text' => $trans['booking_text'],
                        ':purpose' => $trans['purpose'],
                        ':payer' => $trans['payer'],
                        ':iban' => $trans['iban'],
                        ':amount' => $trans['amount'],
                        ':created_by' => $user_id
                    ]);
                    $inserted++;
                } catch (Exception $e) {
                    $errors[] = "Zeile {$trans['line_number']}: " . $e->getMessage();
                }
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    $message = "Erfolgreich $inserted Transaktionen importiert";
    if ($skipped > 0) {
        $dates_info = array();
        foreach ($skipped_dates as $date => $count) {
            $dates_info[] = date('d.m.Y', strtotime($date)) . " ($count)";
        }
        $message .= ", $skipped Transaktionen übersprungen (bereits importierte Tage: " . implode(', ', $dates_info) . ")";
    }
    if (count($errors) > 0) {
        $message .= ". Fehler: " . implode(', ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $message .= " (und " . (count($errors) - 5) . " weitere)";
        }
    }
    
    return [
        'success' => true,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'errors' => $errors,
        'message' => $message
    ];
}

/**
 * Delete transaction document
 */
function delete_transaction_document($id) {
    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT file_path FROM transaction_documents WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        $file_path = ROOT_PATH . '/uploads/documents/' . basename($doc['file_path']);
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        $stmt = $db->prepare("DELETE FROM transaction_documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return true;
    }
    
    return false;
}

/**
 * Upload transaction document (PDF)
 */
function upload_transaction_document($file, $transaction_id) {
    // Accept both common and less common MIME types for PDF
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'application/x-pdf'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Also check by file extension if MIME type is not standard
    if (!in_array($file['type'], $allowed_types) && !in_array($file_ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
        return ['success' => false, 'error' => 'Ungültiger Dateityp. Nur PDF, JPG, PNG erlaubt.'];
    }
    
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Datei zu groß. Maximum 5MB.'];
    }
    
    $upload_dir = ROOT_PATH . '/uploads/documents/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return ['success' => false, 'error' => 'Fehler beim Erstellen des Upload-Verzeichnisses'];
        }
    }
    
    // Ensure directory is writable
    if (!is_writable($upload_dir)) {
        chmod($upload_dir, 0755);
        if (!is_writable($upload_dir)) {
            return ['success' => false, 'error' => 'Upload-Verzeichnis nicht beschreibbar'];
        }
    }
    
    $file_name = 'doc_' . $transaction_id . '_' . time() . '.' . $file_ext;
    $file_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // Set proper permissions
        chmod($file_path, 0644);
        
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("INSERT INTO transaction_documents (transaction_id, file_name, file_path, file_size, uploaded_by) 
                                  VALUES (:transaction_id, :file_name, :file_path, :file_size, :uploaded_by)");
            $stmt->execute([
                ':transaction_id' => $transaction_id,
                ':file_name' => $file['name'],
                ':file_path' => 'documents/' . $file_name,
                ':file_size' => $file['size'],
                ':uploaded_by' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1
            ]);
            
            return ['success' => true, 'file_path' => 'documents/' . $file_name];
        } catch (Exception $e) {
            // Delete the uploaded file if DB insert fails
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            return ['success' => false, 'error' => 'Datenbankfehler: ' . $e->getMessage()];
        }
    }
    
    return ['success' => false, 'error' => 'Fehler beim Hochladen der Datei. Bitte versuchen Sie es erneut.'];
}

/**
 * Get all members with optional filtering
 */
function get_members($member_type = null, $active_only = true) {
    $db = getDBConnection();
    
    $sql = "SELECT * FROM members WHERE 1=1";
    $params = [];
    
    if ($member_type) {
        $sql .= " AND member_type = :member_type";
        $params['member_type'] = $member_type;
    }
    
    if ($active_only) {
        $sql .= " AND active = 1";
    }
    
    $sql .= " ORDER BY last_name, first_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get member by ID with payment history
 */
function get_member($id) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM members WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $member = $stmt->fetch();
    
    if ($member) {
        // Get obligations with payment history
        $stmt = $db->prepare("SELECT * FROM member_fee_obligations WHERE member_id = :id ORDER BY fee_year DESC");
        $stmt->execute([':id' => $id]);
        $member['obligations'] = $stmt->fetchAll();
    }
    
    return $member;
}

/**
 * Get current membership fee for a member type
 */
function get_current_membership_fee($member_type, $date = null) {
    $db = getDBConnection();
    
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $sql = "SELECT * FROM membership_fees 
            WHERE member_type = :member_type 
            AND valid_from <= :date 
            AND (valid_until IS NULL OR valid_until >= :date2)
            ORDER BY valid_from DESC 
            LIMIT 1";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':member_type' => $member_type,
        ':date' => $date,
        ':date2' => $date
    ]);
    
    return $stmt->fetch();
}

/**
 * Generate fee obligations for all active members for a specific year
 */
function generate_fee_obligations($year, $user_id) {
    $db = getDBConnection();
    $members = get_members(null, true);
    $generated = 0;
    $skipped = 0;
    
    foreach ($members as $member) {
        // Check if obligation already exists
        $stmt = $db->prepare("SELECT id FROM member_fee_obligations WHERE member_id = :member_id AND fee_year = :year");
        $stmt->execute([':member_id' => $member['id'], ':year' => $year]);
        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }
        
        // Get fee for this member type
        $fee = get_current_membership_fee($member['member_type'], "{$year}-01-01");
        if (!$fee) {
            continue;
        }
        
        // Create obligation
        $stmt = $db->prepare("INSERT INTO member_fee_obligations 
                             (member_id, fee_year, fee_amount, generated_date, due_date, created_by)
                             VALUES (:member_id, :year, :amount, :generated_date, :due_date, :created_by)");
        $stmt->execute([
            ':member_id' => $member['id'],
            ':year' => $year,
            ':amount' => $fee['minimum_amount'],
            ':generated_date' => date('Y-m-d'),
            ':due_date' => "{$year}-12-31", // Due end of December
            ':created_by' => $user_id
        ]);
        $generated++;
    }
    
    return ['generated' => $generated, 'skipped' => $skipped];
}

/**
 * Get obligation with payment details
 */
function get_obligation($id) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT o.*, m.first_name, m.last_name, m.member_number, m.member_type
                          FROM member_fee_obligations o
                          JOIN members m ON o.member_id = m.id
                          WHERE o.id = :id");
    $stmt->execute([':id' => $id]);
    $obligation = $stmt->fetch();
    
    if ($obligation) {
        // Get payment history
        $stmt = $db->prepare("SELECT p.*, t.booking_text, u.username as created_by_name
                             FROM member_payments p
                             LEFT JOIN transactions t ON p.transaction_id = t.id
                             LEFT JOIN users u ON p.created_by = u.id
                             WHERE p.obligation_id = :id
                             ORDER BY p.payment_date DESC");
        $stmt->execute([':id' => $id]);
        $obligation['payments'] = $stmt->fetchAll();
        $obligation['outstanding'] = $obligation['fee_amount'] - $obligation['paid_amount'];
    }
    
    return $obligation;
}

/**
 * Add payment to obligation and update status
 */
function add_payment_to_obligation($obligation_id, $amount, $payment_date, $transaction_id = null, $payment_method = null, $notes = null, $user_id = null) {
    $db = getDBConnection();
    
    try {
        $db->beginTransaction();
        
        // Get obligation
        $stmt = $db->prepare("SELECT fee_amount, paid_amount FROM member_fee_obligations WHERE id = :id");
        $stmt->execute([':id' => $obligation_id]);
        $obligation = $stmt->fetch();
        
        if (!$obligation) {
            throw new Exception('Obligation not found');
        }
        
        // Check if transaction already linked to another obligation
        if ($transaction_id) {
            $stmt = $db->prepare("SELECT id FROM member_payments WHERE transaction_id = :transaction_id");
            $stmt->execute([':transaction_id' => $transaction_id]);
            if ($stmt->fetch()) {
                throw new Exception('Transaction already linked to another obligation');
            }
        }
        
        // Insert payment
        $stmt = $db->prepare("INSERT INTO member_payments 
                             (obligation_id, transaction_id, payment_date, amount, payment_method, notes, created_by)
                             VALUES (:obligation_id, :transaction_id, :payment_date, :amount, :payment_method, :notes, :created_by)");
        $stmt->execute([
            ':obligation_id' => $obligation_id,
            ':transaction_id' => $transaction_id,
            ':payment_date' => $payment_date,
            ':amount' => $amount,
            ':payment_method' => $payment_method,
            ':notes' => $notes,
            ':created_by' => $user_id
        ]);
        
        // Update obligation paid_amount and status
        $new_paid = $obligation['paid_amount'] + $amount;
        $status = 'partial';
        if ($new_paid <= 0) {
            $status = 'open';
        } elseif ($new_paid >= $obligation['fee_amount']) {
            $status = 'paid';
        }
        
        $stmt = $db->prepare("UPDATE member_fee_obligations 
                             SET paid_amount = :paid_amount, status = :status
                             WHERE id = :id");
        $stmt->execute([
            ':paid_amount' => $new_paid,
            ':status' => $status,
            ':id' => $obligation_id
        ]);
        
        $db->commit();
        return ['success' => true, 'payment_id' => $db->lastInsertId()];
        
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get all open obligations (outstanding fees)
 */
function get_open_obligations($year = null) {
    $db = getDBConnection();
    
    $sql = "SELECT o.*, m.first_name, m.last_name, m.member_number, m.member_type,
            (o.fee_amount - o.paid_amount) as outstanding
            FROM member_fee_obligations o
            JOIN members m ON o.member_id = m.id
            WHERE o.status IN ('open', 'partial')";
    $params = [];
    
    if ($year) {
        $sql .= " AND o.fee_year = :year";
        $params['year'] = $year;
    }
    
    $sql .= " ORDER BY o.due_date ASC, m.last_name, m.first_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get member payment status for a specific year (obligation-based)
 */
function get_member_payment_status($member_id, $year) {
    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT * FROM member_fee_obligations 
                          WHERE member_id = :member_id AND fee_year = :year");
    $stmt->execute([':member_id' => $member_id, ':year' => $year]);
    $obligation = $stmt->fetch();
    
    if (!$obligation) {
        return null;
    }
    
    return [
        'year' => $year,
        'obligation_id' => $obligation['id'],
        'required_amount' => $obligation['fee_amount'],
        'paid_amount' => $obligation['paid_amount'],
        'outstanding' => $obligation['fee_amount'] - $obligation['paid_amount'],
        'status' => $obligation['status'],
        'is_paid' => $obligation['status'] === 'paid',
        'due_date' => $obligation['due_date']
    ];
}

/**
 * Get all members with outstanding payments for a given year
 */
function get_members_with_outstanding_payments($year) {
    $obligations = get_open_obligations($year);
    return $obligations;

/**
 * Generate a secure magic link token for a user
 * 
 * @param int $user_id User ID
 * @param PDO $pdo Database connection
 * @return string Magic link token
 */
function generate_magic_link($user_id, $pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    // Generate cryptographically secure token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Store magic link in database
    $stmt = $pdo->prepare("
        INSERT INTO magic_links (token, user_id, expires_at, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$token, $user_id, $expires_at, $ip_address, $user_agent]);
    
    return $token;
}

/**
 * Verify a magic link token
 * 
 * @param string $token Magic link token
 * @param PDO $pdo Database connection
 * @return array|false User data if valid, false otherwise
 */
function verify_magic_link($token, $pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    // Find magic link with user data
    $stmt = $pdo->prepare("
        SELECT ml.*, u.id as user_id, u.username, u.first_name, u.last_name, u.email, u.role
        FROM magic_links ml
        JOIN users u ON ml.user_id = u.id
        WHERE ml.token = ?
    ");
    $stmt->execute([$token]);
    $magic_link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$magic_link) {
        return false;
    }
    
    // Check if already used
    if ($magic_link['used_at']) {
        return false;
    }
    
    // Check if expired
    if (strtotime($magic_link['expires_at']) < time()) {
        return false;
    }
    
    return $magic_link;
}

/**
 * Mark a magic link as used
 * 
 * @param string $token Magic link token
 * @param PDO $pdo Database connection
 * @return bool Success
 */
function mark_magic_link_used($token, $pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    $stmt = $pdo->prepare("
        UPDATE magic_links 
        SET used_at = NOW()
        WHERE token = ?
    ");
    return $stmt->execute([$token]);
}

/**
 * Check rate limiting for magic link requests
 * 
 * @param string $email Email address
 * @param string $ip_address IP address
 * @param int $max_attempts Maximum attempts allowed
 * @param int $time_window Time window in minutes
 * @param PDO $pdo Database connection
 * @return bool True if rate limit not exceeded, false otherwise
 */
function check_rate_limit($email, $ip_address, $max_attempts = 3, $time_window = 15, $pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempt_count
        FROM login_attempts 
        WHERE email = ? 
        AND ip_address = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$email, $ip_address, $time_window]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['attempt_count'] < $max_attempts;
}

/**
 * Log a login attempt
 * 
 * @param string $email Email address
 * @param bool $success Whether login was successful
 * @param string $method Authentication method ('password', 'magic_link')
 * @param PDO $pdo Database connection
 * @return bool Success
 */
function log_login_attempt($email, $success, $method = 'password', $pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (email, ip_address, user_agent, success, method, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$email, $ip_address, $user_agent, (int)$success, $method]);
}

/**
 * Clean up expired magic links
 * Call this periodically (e.g., via cron job)
 * 
 * @param PDO $pdo Database connection
 * @return int Number of deleted links
 */
function cleanup_expired_magic_links($pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    $stmt = $pdo->prepare("
        DELETE FROM magic_links 
        WHERE expires_at < NOW()
        OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Clean up old login attempts
 * Call this periodically (e.g., via cron job)
 * 
 * @param int $days_to_keep Number of days to keep records
 * @param PDO $pdo Database connection
 * @return int Number of deleted records
 */
function cleanup_old_login_attempts($days_to_keep = 30, $pdo = null) {
    if ($pdo === null) {
        $pdo = getDBConnection();
    }
    
    $stmt = $pdo->prepare("
        DELETE FROM login_attempts 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$days_to_keep]);
    return $stmt->rowCount();
}
}