<?php
/**
 * Helper Functions
 */

/**
 * Get organization setting (from organization table)
 */
function get_org_setting($key, $default = '') {
    static $org_cache = null;
    
    // Try to get from organization table first
    if ($org_cache === null) {
        try {
            $db = getDBConnection();
            $stmt = $db->query("SELECT name, website, email FROM organization WHERE id = 1");
            $org_cache = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $org_cache = [];
        }
    }
    
    // Map database fields to our keys
    $mapping = [
        'site_name' => 'name',
        'site_url' => 'website',
        'admin_email' => 'email'
    ];
    
    // Handle computed values
    if ($key === 'upload_url' && !empty($org_cache['website'])) {
        return rtrim($org_cache['website'], '/') . '/uploads/';
    }
    
    $db_key = $mapping[$key] ?? $key;
    
    // Return from organization table if available
    if (!empty($org_cache[$db_key])) {
        return $org_cache[$db_key];
    }
    
    return $default;
}

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
 * Encode bytes as URL-safe base64 without padding.
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decode URL-safe base64 with optional missing padding.
 */
function base64url_decode($data) {
    $padding = 4 - (strlen($data) % 4);
    if ($padding < 4) {
        $data .= str_repeat('=', $padding);
    }

    return base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * Validate and normalize a personal name.
 * Rules: starts uppercase, no further uppercase letters, and basic anti-gibberish heuristics.
 */
function validate_person_name($value, $fieldLabel = 'Name') {
    $name = trim((string)$value);
    $name = preg_replace('/\s+/u', ' ', $name);

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 30) {
        throw new Exception($fieldLabel . ' muss zwischen 2 und 30 Zeichen lang sein.');
    }

    if (!preg_match('/^[\p{L}][\p{L}\-\'\s]*$/u', $name)) {
        throw new Exception($fieldLabel . ' enthält ungültige Zeichen.');
    }

    if (!preg_match('/^\p{Lu}/u', $name)) {
        throw new Exception($fieldLabel . ' muss mit einem Großbuchstaben beginnen.');
    }

    if (preg_match('/\p{Lu}/u', mb_substr($name, 1))) {
        throw new Exception($fieldLabel . ' darf innerhalb des Namens keine Großbuchstaben enthalten.');
    }

    $lettersOnly = mb_strtolower((string)preg_replace('/[^\p{L}]/u', '', $name));
    if ($lettersOnly === '') {
        throw new Exception($fieldLabel . ' ist ungültig.');
    }

    // Heuristic to reduce random bot strings without hard-blocking common names.
    if (!preg_match('/[aeiouyäöüàáâãåæèéêëìíîïòóôõøœùúûüýÿ]/u', $lettersOnly)) {
        throw new Exception($fieldLabel . ' wirkt nicht wie ein gültiger Name.');
    }

    if (preg_match('/(.)\1\1/u', $lettersOnly)) {
        throw new Exception($fieldLabel . ' wirkt nicht wie ein gültiger Name.');
    }

    if (preg_match('/[bcdfghjklmnpqrstvwxyzß]{6,}/u', $lettersOnly)) {
        throw new Exception($fieldLabel . ' wirkt nicht wie ein gültiger Name.');
    }

    return $name;
}

/**
 * Create a signed registration verification token that carries registration data.
 */
function create_registration_verification_token($email, $firstName, $lastName, $ttlSeconds = 86400) {
    $payload = [
        'email' => (string)$email,
        'first_name' => (string)$firstName,
        'last_name' => (string)$lastName,
        'iat' => time(),
        'exp' => time() + (int)$ttlSeconds,
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        throw new Exception('Verifizierungsdaten konnten nicht erstellt werden.');
    }

    $payloadB64 = base64url_encode($payloadJson);
    $signature = hash_hmac('sha256', $payloadB64, ENCRYPTION_KEY, true);
    $signatureB64 = base64url_encode($signature);

    return $payloadB64 . '.' . $signatureB64;
}

/**
 * Validate and decode a signed registration verification token.
 */
function parse_registration_verification_token($token) {
    $parts = explode('.', (string)$token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    $payloadB64 = $parts[0];
    $signatureB64 = $parts[1];
    $expectedSig = base64url_encode(hash_hmac('sha256', $payloadB64, ENCRYPTION_KEY, true));

    if (!hash_equals($expectedSig, $signatureB64)) {
        return null;
    }

    $payloadJson = base64url_decode($payloadB64);
    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    if (empty($payload['email']) || empty($payload['first_name']) || empty($payload['last_name']) || empty($payload['exp'])) {
        return null;
    }

    if ((int)$payload['exp'] < time()) {
        return null;
    }

    return $payload;
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
    $headers = "From: " . get_org_setting('admin_email') . "\r\n";
    $headers .= "Reply-To: " . get_org_setting('admin_email') . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}
/**
 * Check if user can manage cash/financial transactions (admin or kassenpruefer)
 */
function can_edit_cash() {
    return has_permission('kontofuehrung.php') || has_permission('members.php') || 
           has_permission('generate_obligations.php') || has_permission('items.php') || 
           has_permission('outstanding_obligations.php') || has_permission('payment_reminders.php') ||
           has_permission('financial_report.php');
}

/**
 * Resolve a transaction category id by its configured name.
 */
function get_category_id_by_name($categoryName) {
    if ($categoryName === null || trim((string) $categoryName) === '') {
        return null;
    }

    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT id FROM transaction_categories WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => trim((string) $categoryName)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Default category mapping for membership obligations.
 */
function get_default_obligation_category_id($memberType) {
    $mapping = [
        'active' => 'Beitrag Einsatzeinheit',
        'supporter' => 'Beitrag Förderer'
    ];

    $categoryName = $mapping[$memberType] ?? null;
    return $categoryName ? get_category_id_by_name($categoryName) : null;
}

/**
 * Ensure obligation category columns and financial report permission exist.
 */
function ensure_financial_reporting_support() {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    ensure_expense_request_support();
    ensure_item_obligation_document_support();

    try {
        $db = getDBConnection();

        $stmt = $db->query("SHOW COLUMNS FROM member_fee_obligations LIKE 'category_id'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $db->exec("ALTER TABLE member_fee_obligations ADD COLUMN category_id INT(11) DEFAULT NULL AFTER member_id");
        }

        $stmt = $db->query("SHOW COLUMNS FROM item_obligations LIKE 'category_id'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $db->exec("ALTER TABLE item_obligations ADD COLUMN category_id INT(11) DEFAULT NULL AFTER organizing_member_id");
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM permissions WHERE name = ?");
        $stmt->execute(['financial_report.php']);
        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = $db->prepare("INSERT INTO permissions (name, display_name, description, category) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                'financial_report.php',
                'Finanzbericht',
                'Finanzberichte, Kennzahlen und Auswertungen anzeigen',
                'Finanzen'
            ]);
        }

        $activeCategoryId = get_default_obligation_category_id('active');
        $supporterCategoryId = get_default_obligation_category_id('supporter');

        if ($activeCategoryId) {
            $stmt = $db->prepare("UPDATE member_fee_obligations mfo
                                  JOIN members m ON mfo.member_id = m.id
                                  SET mfo.category_id = :category_id
                                  WHERE mfo.category_id IS NULL AND m.member_type = 'active'");
            $stmt->execute([':category_id' => $activeCategoryId]);
        }

        if ($supporterCategoryId) {
            $stmt = $db->prepare("UPDATE member_fee_obligations mfo
                                  JOIN members m ON mfo.member_id = m.id
                                  SET mfo.category_id = :category_id
                                  WHERE mfo.category_id IS NULL AND m.member_type = 'supporter'");
            $stmt->execute([':category_id' => $supporterCategoryId]);
        }

        $stmt = $db->prepare("UPDATE member_fee_obligations mfo
                              JOIN members m ON mfo.member_id = m.id
                              SET mfo.category_id = NULL
                              WHERE m.member_type = 'pensioner'");
        $stmt->execute();

        $initialized = true;
        return true;
    } catch (Exception $e) {
        error_log('ensure_financial_reporting_support failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update the status of an expense request and sync the linked obligation.
 */
function update_expense_request_status($requestId, $newStatus, $notes = '', $currentUserId = null, $categoryId = null) {
    if (!ensure_financial_reporting_support()) {
        return ['success' => false, 'error' => 'Die Finanz-Auswertung konnte nicht initialisiert werden.'];
    }

    $requestId = (int) $requestId;
    $notes = trim((string) $notes);
    $currentUserId = $currentUserId ?: ($_SESSION['user_id'] ?? null);
    $categoryId = !empty($categoryId) ? (int) $categoryId : null;

    if ($requestId <= 0) {
        return ['success' => false, 'error' => 'Antrag nicht gefunden.'];
    }

    if (!in_array($newStatus, ['approved', 'rejected', 'paid'], true)) {
        return ['success' => false, 'error' => 'Ungültiger Status.'];
    }

    $db = getDBConnection();

    try {
        $stmt = $db->prepare("SELECT er.*, COALESCE(m.email, u.email, '') AS notification_email, COALESCE(m.first_name, '') AS member_first_name
                              FROM expense_requests er
                              LEFT JOIN members m ON er.member_id = m.id
                              LEFT JOIN users u ON er.submitted_by_user_id = u.id
                              WHERE er.id = :id");
        $stmt->execute([':id' => $requestId]);
        $requestRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$requestRow) {
            throw new Exception('Antrag nicht gefunden.');
        }

        if ($newStatus === 'paid' && ($requestRow['status'] ?? '') !== 'approved') {
            throw new Exception('Ein Antrag kann erst nach Genehmigung als ausgezahlt markiert werden.');
        }

        $db->beginTransaction();

        $approvedAtSql = $newStatus === 'rejected' ? 'NULL' : 'NOW()';
        $stmt = $db->prepare("UPDATE expense_requests
                              SET status = :status,
                                  accountant_notes = :accountant_notes,
                                  approved_by = :approved_by,
                                  approved_at = " . $approvedAtSql . "
                              WHERE id = :id");
        $stmt->execute([
            ':status' => $newStatus,
            ':accountant_notes' => $notes,
            ':approved_by' => $currentUserId,
            ':id' => $requestId
        ]);

        if (!empty($requestRow['linked_item_obligation_id'])) {
            $obligationId = (int) $requestRow['linked_item_obligation_id'];

            if ($newStatus === 'rejected') {
                $stmt = $db->prepare("UPDATE item_obligations SET status = 'cancelled' WHERE id = :id");
                $stmt->execute([':id' => $obligationId]);
            } elseif ($newStatus === 'approved') {
                if (!$categoryId) {
                    $stmt = $db->prepare("SELECT category_id FROM item_obligations WHERE id = :id");
                    $stmt->execute([':id' => $obligationId]);
                    $existingCategoryId = (int) $stmt->fetchColumn();
                    $categoryId = $existingCategoryId ?: null;
                }
                if (!$categoryId) {
                    throw new Exception('Bitte vor der Genehmigung eine Kategorie auswählen.');
                }
                $stmt = $db->prepare("UPDATE item_obligations SET status = 'open', category_id = :category_id WHERE id = :id");
                $stmt->execute([':id' => $obligationId, ':category_id' => $categoryId]);
            } elseif ($newStatus === 'paid') {
                $stmt = $db->prepare("SELECT total_amount, paid_amount, category_id FROM item_obligations WHERE id = :id");
                $stmt->execute([':id' => $obligationId]);
                $obligationRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$categoryId) {
                    $categoryId = !empty($obligationRow['category_id']) ? (int) $obligationRow['category_id'] : null;
                }
                if (!$categoryId) {
                    throw new Exception('Bitte vor der Auszahlung eine Kategorie auswählen.');
                }

                if ($obligationRow) {
                    $remainingAmount = max(0, (float) $obligationRow['total_amount'] - (float) $obligationRow['paid_amount']);
                    if ($remainingAmount > 0) {
                        $stmt = $db->prepare("INSERT INTO item_obligation_payments
                                              (obligation_id, transaction_id, payment_date, amount, payment_method, notes, created_by)
                                              VALUES (:obligation_id, NULL, CURDATE(), :amount, 'manual_transfer', :notes, :created_by)");
                        $stmt->execute([
                            ':obligation_id' => $obligationId,
                            ':amount' => $remainingAmount,
                            ':notes' => 'Im Bereich Offene Forderungen als ausgezahlt markiert',
                            ':created_by' => $currentUserId
                        ]);
                    }

                    $stmt = $db->prepare("UPDATE item_obligations
                                          SET paid_amount = total_amount,
                                              status = 'paid',
                                              category_id = :category_id
                                          WHERE id = :id");
                    $stmt->execute([':id' => $obligationId, ':category_id' => $categoryId]);
                }

                sync_expense_request_status_from_obligation($obligationId);
            }
        }

        $db->commit();

        $requestRow['status'] = $newStatus;
        $requestRow['accountant_notes'] = $notes;

        try {
            require_once __DIR__ . '/EmailService.php';
            if (class_exists('EmailService')) {
                $emailService = new EmailService();
                $emailService->sendExpenseRequestStatusNotification($requestRow, $newStatus);
            }
        } catch (Exception $mailException) {
            error_log('Expense request status mail failed: ' . $mailException->getMessage());
        }

        if ($newStatus === 'approved') {
            $message = 'Antrag genehmigt.';
        } elseif ($newStatus === 'paid') {
            $message = 'Antrag als ausgezahlt markiert.';
        } else {
            $message = 'Antrag abgelehnt.';
        }

        return ['success' => true, 'message' => $message, 'request' => $requestRow];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'error' => 'Fehler beim Aktualisieren des Status: ' . $e->getMessage()];
    }
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
    $errors = [];
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

    $normalizeValue = static function ($value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding && strtoupper($encoding) !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $encoding);
        }

        return trim($value);
    };

    $normalizeHeader = static function ($value) use ($normalizeValue): string {
        $value = mb_strtolower($normalizeValue($value), 'UTF-8');
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
        return preg_replace('/[^a-z0-9]+/', '', $value);
    };

    $parseAmount = static function ($value): float {
        $amountStr = trim((string) $value);
        if ($amountStr === '') {
            return 0.0;
        }

        $amountStr = str_replace(["\xC2\xA0", ' ', '€'], '', $amountStr);
        $amountStr = preg_replace('/[^0-9,\.\-]/', '', $amountStr);

        if (strpos($amountStr, ',') !== false) {
            $amountStr = str_replace('.', '', $amountStr);
            $amountStr = str_replace(',', '.', $amountStr);
        } else {
            $parts = explode('.', $amountStr);
            if (count($parts) > 2) {
                $decimal = array_pop($parts);
                $amountStr = implode('', $parts) . '.' . $decimal;
            }
        }

        return (float) $amountStr;
    };

    if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
        $header = fgetcsv($handle, 0, ';');
        if ($header === false) {
            return ['success' => false, 'error' => 'CSV-Datei konnte nicht gelesen werden'];
        }

        $headerMap = [];
        foreach ($header as $index => $columnName) {
            $headerMap[$normalizeHeader($columnName)] = $index;
        }

        $isExtendedBankExport = count($header) >= 15;
        $resolveColumn = static function (array $aliases, int $fallbackIndex) use ($headerMap): int {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $headerMap)) {
                    return (int) $headerMap[$alias];
                }
            }
            return $fallbackIndex;
        };

        $columnMap = [
            'booking_date' => $resolveColumn(['buchungstag'], 1),
            'booking_text' => $resolveColumn(['buchungstext'], 3),
            'purpose' => $resolveColumn(['verwendungszweck'], 4),
            'payer' => $resolveColumn(['beguenstigterzahlungspflichtiger', 'begunstigterzahlungspflichtiger'], $isExtendedBankExport ? 11 : 5),
            'iban' => $resolveColumn(['kontonummeriban', 'iban'], $isExtendedBankExport ? 12 : 6),
            'amount' => $resolveColumn(['betrag'], $isExtendedBankExport ? 14 : 8),
        ];

        $transactions_to_import = [];
        $line_number = 1;

        while (($data = fgetcsv($handle, 0, ';')) !== FALSE) {
            $line_number++;

            $nonEmptyCells = array_filter($data, static function ($value) {
                return trim((string) $value) !== '';
            });
            if (empty($nonEmptyCells)) {
                continue;
            }

            try {
                $booking_date_str = $normalizeValue($data[$columnMap['booking_date']] ?? '');
                if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2,4})$/', $booking_date_str, $matches)) {
                    $day = $matches[1];
                    $month = $matches[2];
                    $year = $matches[3];
                    if (strlen($year) == 2) {
                        $year = '20' . $year;
                    }
                    $booking_date = "$year-$month-$day";
                    $business_year = (int) $year;
                } else {
                    $errors[] = "Zeile $line_number: Ungültiges Datumsformat '$booking_date_str'";
                    continue;
                }

                $amountRaw = $normalizeValue($data[$columnMap['amount']] ?? '');
                $amount = $parseAmount($amountRaw);

                $booking_text = mb_substr($normalizeValue($data[$columnMap['booking_text']] ?? ''), 0, 200);
                $purpose = $normalizeValue($data[$columnMap['purpose']] ?? '');
                $payer = mb_substr($normalizeValue($data[$columnMap['payer']] ?? ''), 0, 100);
                $iban = mb_substr($normalizeValue($data[$columnMap['iban']] ?? ''), 0, 34);

                $transactions_to_import[] = [
                    'booking_date' => $booking_date,
                    'booking_text' => $booking_text,
                    'purpose' => $purpose,
                    'payer' => $payer,
                    'iban' => $iban,
                    'amount' => $amount,
                    'amount_sql' => number_format($amount, 2, '.', ''),
                    'business_year' => $business_year,
                    'line_number' => $line_number
                ];
            } catch (Exception $e) {
                $errors[] = "Zeile $line_number: " . $e->getMessage();
            }
        }
        fclose($handle);

        $db->beginTransaction();
        try {
            $insert_stmt = $db->prepare("INSERT INTO transactions 
                                         (booking_date, booking_text, purpose, payer, iban, amount, business_year, created_by) 
                                         VALUES (:booking_date, :booking_text, :purpose, :payer, :iban, :amount, :business_year, :created_by)");

            $find_exact_stmt = $db->prepare("SELECT id, business_year FROM transactions
                                            WHERE booking_date = :booking_date
                                              AND COALESCE(booking_text, '') = :booking_text
                                              AND COALESCE(purpose, '') = :purpose
                                              AND COALESCE(payer, '') = :payer
                                              AND COALESCE(iban, '') = :iban
                                              AND amount = :amount
                                            LIMIT 1");

            $find_zero_stmt = $db->prepare("SELECT id FROM transactions
                                           WHERE booking_date = :booking_date
                                             AND COALESCE(booking_text, '') = :booking_text
                                             AND COALESCE(purpose, '') = :purpose
                                             AND (COALESCE(payer, '') = '' OR COALESCE(payer, '') = :payer)
                                             AND (COALESCE(iban, '') = '' OR COALESCE(iban, '') = :iban)
                                             AND amount = 0
                                           LIMIT 1");

            $update_zero_stmt = $db->prepare("UPDATE transactions
                                              SET amount = :amount,
                                                  payer = :payer,
                                                  iban = :iban,
                                                  business_year = :business_year
                                              WHERE id = :id");

            $repair_existing_stmt = $db->prepare("UPDATE transactions
                                                  SET business_year = :business_year
                                                  WHERE id = :id");

            foreach ($transactions_to_import as $trans) {
                $lookupParams = [
                    ':booking_date' => $trans['booking_date'],
                    ':booking_text' => $trans['booking_text'],
                    ':purpose' => $trans['purpose'],
                    ':payer' => $trans['payer'],
                    ':iban' => $trans['iban'],
                    ':amount' => $trans['amount_sql']
                ];

                $find_exact_stmt->execute($lookupParams);
                $existingExact = $find_exact_stmt->fetch(PDO::FETCH_ASSOC);
                if ($existingExact) {
                    if (empty($existingExact['business_year'])) {
                        $repair_existing_stmt->execute([
                            ':business_year' => $trans['business_year'],
                            ':id' => $existingExact['id']
                        ]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                $find_zero_stmt->execute([
                    ':booking_date' => $trans['booking_date'],
                    ':booking_text' => $trans['booking_text'],
                    ':purpose' => $trans['purpose'],
                    ':payer' => $trans['payer'],
                    ':iban' => $trans['iban']
                ]);
                $existingZero = $find_zero_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingZero && abs((float) $trans['amount']) > 0.0001) {
                    $update_zero_stmt->execute([
                        ':amount' => $trans['amount_sql'],
                        ':payer' => $trans['payer'],
                        ':iban' => $trans['iban'],
                        ':business_year' => $trans['business_year'],
                        ':id' => $existingZero['id']
                    ]);
                    auto_match_expense_request_to_transaction((int) $existingZero['id']);
                    $updated++;
                    continue;
                }

                try {
                    $insert_stmt->execute([
                        ':booking_date' => $trans['booking_date'],
                        ':booking_text' => $trans['booking_text'],
                        ':purpose' => $trans['purpose'],
                        ':payer' => $trans['payer'],
                        ':iban' => $trans['iban'],
                        ':amount' => $trans['amount_sql'],
                        ':business_year' => $trans['business_year'],
                        ':created_by' => $user_id
                    ]);
                    $newTransactionId = (int) $db->lastInsertId();
                    auto_match_expense_request_to_transaction($newTransactionId);
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
    if ($updated > 0) {
        $message .= ", $updated bestehende Einträge korrigiert (z. B. 0,00 oder fehlendes Geschäftsjahr)";
    }
    if ($skipped > 0) {
        $message .= ", $skipped Duplikate übersprungen";
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
        $defaultCategoryId = get_default_obligation_category_id($member['member_type']);
        $stmt = $db->prepare("INSERT INTO member_fee_obligations 
                             (member_id, category_id, fee_year, fee_amount, generated_date, due_date, created_by)
                             VALUES (:member_id, :category_id, :year, :amount, :generated_date, :due_date, :created_by)");
        $stmt->execute([
            ':member_id' => $member['id'],
            ':category_id' => $defaultCategoryId,
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
        
        // Validate against transaction remaining amount when provided
        if ($transaction_id) {
            $stmt = $db->prepare("SELECT amount FROM transactions WHERE id = :id");
            $stmt->execute([':id' => $transaction_id]);
            $transaction = $stmt->fetch();
            if ($transaction) {
                if ($transaction['amount'] <= 0) {
                    throw new Exception('Nur Einnahmen können verknüpft werden');
                }
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS total_linked FROM member_payments WHERE transaction_id = :transaction_id");
                $stmt->execute([':transaction_id' => $transaction_id]);
                $total_linked = $stmt->fetchColumn();
                $remaining = $transaction['amount'] - $total_linked;
                if ($amount > $remaining + 0.0001) {
                    throw new Exception('Verknüpfung überschreitet den Transaktionsbetrag');
                }
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
            (o.fee_amount - o.paid_amount) as outstanding,
            tc.name AS category_name,
            tc.color AS category_color
            FROM member_fee_obligations o
            JOIN members m ON o.member_id = m.id
            LEFT JOIN transaction_categories tc ON o.category_id = tc.id
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
}

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

/**
 * Check if user has accepted the latest privacy policy
 */
function has_accepted_privacy_policy($user_id = null) {
    if (!$user_id && !is_logged_in()) {
        return false;
    }
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'];
    }
    
    try {
        $db = getDBConnection();
        
        // Get latest published privacy policy
        $stmt = $db->prepare("
            SELECT id FROM privacy_policy_versions 
            WHERE published_at IS NOT NULL 
            ORDER BY published_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $policy = $stmt->fetch();
        
        if (!$policy) {
            return true; // No policy published yet, so considered accepted
        }
        
        // Check if user has accepted this version
        $stmt = $db->prepare("
            SELECT id FROM privacy_policy_consent 
            WHERE user_id = ? AND policy_version_id = ? AND accepted = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id, $policy['id']]);
        
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get latest privacy policy version
 */
function get_latest_privacy_policy() {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            SELECT * FROM privacy_policy_versions 
            WHERE published_at IS NOT NULL 
            ORDER BY published_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Record privacy policy acceptance or rejection
 */
function record_privacy_policy_decision($user_id, $policy_version_id, $accepted) {
    try {
        $db = getDBConnection();
        
        // Remove existing consent record if any
        $stmt = $db->prepare("
            DELETE FROM privacy_policy_consent 
            WHERE user_id = ? AND policy_version_id = ?
        ");
        $stmt->execute([$user_id, $policy_version_id]);
        
        // Insert new consent record
        $stmt = $db->prepare("
            INSERT INTO privacy_policy_consent 
            (user_id, policy_version_id, accepted, consent_date, ip_address, user_agent) 
            VALUES (?, ?, ?, NOW(), ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $policy_version_id,
            $accepted ? 1 : 0,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Get policy version
        $stmt = $db->prepare("SELECT version FROM privacy_policy_versions WHERE id = ?");
        $stmt->execute([$policy_version_id]);
        $policy = $stmt->fetch();
        
        if ($policy) {
            // Update user's privacy policy acceptance status
            $stmt = $db->prepare("
                UPDATE users 
                SET privacy_policy_accepted_version = ?,
                    privacy_policy_accepted_at = NOW(),
                    require_privacy_policy_acceptance = ?,
                    active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $policy['version'],
                0, // Clear the flag
                $accepted ? 1 : 0, // Disable user if rejected
                $user_id
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if user is a supporter
 */
function is_supporter($user_id = null) {
    if (!$user_id && !is_logged_in()) {
        return false;
    }
    
    if (!$user_id) {
        $user_id = $_SESSION['user_id'];
    }
    
    try {
        $db = getDBConnection();
        
        $stmt = $db->prepare("
            SELECT m.member_type FROM users u
            LEFT JOIN members m ON u.member_id = m.id
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        
        return ($result && $result['member_type'] === 'supporter');
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ensure reimbursement request tables and permission exist.
 */
function ensure_expense_request_support() {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    try {
        $db = getDBConnection();

        $db->exec("CREATE TABLE IF NOT EXISTS expense_requests (
            id INT(11) NOT NULL AUTO_INCREMENT,
            member_id INT(11) DEFAULT NULL,
            submitted_by_user_id INT(11) DEFAULT NULL,
            full_name_snapshot VARCHAR(150) NOT NULL,
            iban VARCHAR(34) DEFAULT NULL,
            transfer_reference VARCHAR(80) NOT NULL,
            expense_context TEXT NOT NULL,
            requested_amount_total DECIMAL(10,2) NOT NULL,
            status ENUM('submitted','approved','rejected','paid') NOT NULL DEFAULT 'submitted',
            linked_item_obligation_id INT(11) DEFAULT NULL,
            accountant_notes TEXT DEFAULT NULL,
            approved_by INT(11) DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY transfer_reference (transfer_reference),
            KEY member_id (member_id),
            KEY submitted_by_user_id (submitted_by_user_id),
            KEY linked_item_obligation_id (linked_item_obligation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS expense_request_items (
            id INT(11) NOT NULL AUTO_INCREMENT,
            expense_request_id INT(11) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY expense_request_id (expense_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS expense_request_documents (
            id INT(11) NOT NULL AUTO_INCREMENT,
            expense_request_id INT(11) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT(11) DEFAULT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by INT(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY expense_request_id (expense_request_id),
            KEY uploaded_by (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmt = $db->prepare("SELECT COUNT(*) FROM permissions WHERE name = ?");
        $stmt->execute(['expense_requests.php']);
        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = $db->prepare("INSERT INTO permissions (name, display_name, description, category) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                'expense_requests.php',
                'Belege Einreichen',
                'Auslagen und Erstattungsanträge einreichen und verwalten',
                'Finanzen'
            ]);
        }

        $initialized = true;
        return true;
    } catch (Exception $e) {
        error_log('ensure_expense_request_support failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get the current user as linked member record.
 */
function ensure_item_obligation_document_support() {
    static $initialized = false;
    if ($initialized) {
        return true;
    }

    try {
        $db = getDBConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS item_obligation_documents (
            id INT(11) NOT NULL AUTO_INCREMENT,
            obligation_id INT(11) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT(11) DEFAULT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by INT(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY obligation_id (obligation_id),
            KEY uploaded_by (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $db->exec("ALTER TABLE item_obligation_documents
                       ADD CONSTRAINT item_obligation_documents_ibfk_1 FOREIGN KEY (obligation_id) REFERENCES item_obligations(id) ON DELETE CASCADE");
        } catch (Exception $ignored) {
        }

        try {
            $db->exec("ALTER TABLE item_obligation_documents
                       ADD CONSTRAINT item_obligation_documents_ibfk_2 FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL");
        } catch (Exception $ignored) {
        }

        $initialized = true;
        return true;
    } catch (Exception $e) {
        error_log('ensure_item_obligation_document_support failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Upload a document for a positive obligation.
 */
function upload_item_obligation_document($file, $obligation_id, $pdo = null) {
    ensure_item_obligation_document_support();

    $allowed_types = ['application/pdf', 'application/x-pdf', 'image/jpeg', 'image/png', 'image/webp'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file['type'], $allowed_types) && !in_array($file_ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
        return ['success' => false, 'error' => 'Ungültiger Dateityp. Nur PDF, JPG, PNG und WEBP sind erlaubt.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Datei zu groß. Maximum 5MB.'];
    }

    $upload_dir = ROOT_PATH . '/uploads/obligations/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        return ['success' => false, 'error' => 'Upload-Verzeichnis konnte nicht erstellt werden.'];
    }

    $file_name = 'obligation_' . (int) $obligation_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_ext;
    $fs_path = $upload_dir . $file_name;
    $db_path = 'obligations/' . $file_name;

    if (!move_uploaded_file($file['tmp_name'], $fs_path)) {
        return ['success' => false, 'error' => 'Fehler beim Hochladen der Datei.'];
    }

    chmod($fs_path, 0644);

    try {
        $db = $pdo ?: getDBConnection();
        $stmt = $db->prepare("INSERT INTO item_obligation_documents
                              (obligation_id, file_name, file_path, file_size, uploaded_by)
                              VALUES (:obligation_id, :file_name, :file_path, :file_size, :uploaded_by)");
        $stmt->execute([
            ':obligation_id' => $obligation_id,
            ':file_name' => $file['name'],
            ':file_path' => $db_path,
            ':file_size' => $file['size'] ?? 0,
            ':uploaded_by' => $_SESSION['user_id'] ?? null
        ]);

        return ['success' => true, 'file_path' => $db_path, 'fs_path' => $fs_path];
    } catch (Exception $e) {
        if (file_exists($fs_path)) {
            unlink($fs_path);
        }
        return ['success' => false, 'error' => 'Datenbankfehler: ' . $e->getMessage()];
    }
}

function get_current_member_record($user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
    if (!$user_id) {
        return null;
    }

    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT m.*
                              FROM users u
                              LEFT JOIN members m ON (u.member_id = m.id OR u.email = m.email)
                              WHERE u.id = ?
                              LIMIT 1");
        $stmt->execute([$user_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        return $member ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Normalize uploaded files array.
 */
function normalize_uploaded_files_array($files) {
    if (empty($files) || !isset($files['name'])) {
        return [];
    }

    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    return $normalized;
}

/**
 * Generate a readable expense reference.
 */
function generate_expense_request_reference($request_id) {
    return 'ERST-' . date('Y') . '-' . str_pad((int) $request_id, 6, '0', STR_PAD_LEFT);
}

/**
 * Upload a reimbursement receipt.
 */
function upload_expense_request_document($file, $expense_request_id, $pdo = null) {
    ensure_expense_request_support();

    $allowed_types = ['application/pdf', 'application/x-pdf', 'image/jpeg', 'image/png', 'image/webp'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file['type'], $allowed_types) && !in_array($file_ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
        return ['success' => false, 'error' => 'Ungültiger Dateityp. Nur PDF, JPG, PNG und WEBP sind erlaubt.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Datei zu groß. Maximum 5MB.'];
    }

    $upload_dir = ROOT_PATH . '/uploads/expense_requests/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        return ['success' => false, 'error' => 'Upload-Verzeichnis konnte nicht erstellt werden.'];
    }

    $file_name = 'expense_' . (int) $expense_request_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_ext;
    $fs_path = $upload_dir . $file_name;
    $db_path = 'expense_requests/' . $file_name;

    if (!move_uploaded_file($file['tmp_name'], $fs_path)) {
        return ['success' => false, 'error' => 'Fehler beim Hochladen der Datei.'];
    }

    chmod($fs_path, 0644);

    try {
        $db = $pdo ?: getDBConnection();
        $stmt = $db->prepare("INSERT INTO expense_request_documents
                              (expense_request_id, file_name, file_path, file_size, uploaded_by)
                              VALUES (:expense_request_id, :file_name, :file_path, :file_size, :uploaded_by)");
        $stmt->execute([
            ':expense_request_id' => $expense_request_id,
            ':file_name' => $file['name'],
            ':file_path' => $db_path,
            ':file_size' => $file['size'] ?? 0,
            ':uploaded_by' => $_SESSION['user_id'] ?? null
        ]);

        return ['success' => true, 'file_path' => $db_path, 'fs_path' => $fs_path];
    } catch (Exception $e) {
        if (file_exists($fs_path)) {
            unlink($fs_path);
        }
        return ['success' => false, 'error' => 'Datenbankfehler: ' . $e->getMessage()];
    }
}

/**
 * Create a reimbursement request and the linked outgoing obligation.
 */
function create_expense_request($formData, $items, $files, $user_id = null) {
    if (!ensure_expense_request_support()) {
        return ['success' => false, 'error' => 'Die Beleg-Einreichen-Funktion konnte nicht initialisiert werden.'];
    }

    $user_id = $user_id ?: ($_SESSION['user_id'] ?? null);
    $member = get_current_member_record($user_id);

    $full_name = sanitize_input($formData['full_name'] ?? (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')));
    $ibanInput = trim((string) ($formData['iban'] ?? ''));
    if ($ibanInput === '' && !empty($member['iban'])) {
        $ibanInput = (string) $member['iban'];
    }
    $iban = strtoupper(preg_replace('/\s+/', '', $ibanInput));
    $expense_context = trim((string) ($formData['expense_context'] ?? ''));
    $transferReferenceRaw = trim((string) ($formData['transfer_reference'] ?? ''));
    $transferReferenceRaw = strtr($transferReferenceRaw, [
        'Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE',
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
        'ß' => 'ss'
    ]);
    $transfer_reference = preg_replace('/\s+/', ' ', $transferReferenceRaw);
    $directAmount = (float) str_replace(',', '.', (string) ($formData['total_amount'] ?? 0));

    if ($full_name === '' || $expense_context === '') {
        return ['success' => false, 'error' => 'Bitte füllen Sie Name, Referenz, Betrag und Kontext vollständig aus.'];
    }
    if ($transfer_reference === '') {
        return ['success' => false, 'error' => 'Bitte geben Sie einen Verwendungszweck bzw. eine Referenz an.'];
    }
    if (mb_strlen($transfer_reference) > 80) {
        return ['success' => false, 'error' => 'Die Referenz darf maximal 80 Zeichen lang sein.'];
    }
    if (preg_match("/[^A-Za-z0-9\/\-\?:\(\)\.,'\+ ]/u", $transfer_reference)) {
        return ['success' => false, 'error' => "Die Referenz enthält nicht erlaubte Sonderzeichen. Erlaubt sind Buchstaben, Zahlen, Leerzeichen sowie / - ? : ( ) . , ' +"];
    }
    if ($iban !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{13,32}$/', $iban)) {
        return ['success' => false, 'error' => 'Bitte geben Sie eine gültige IBAN ein oder lassen Sie das Feld leer.'];
    }

    $validItems = [];
    $calculatedTotal = 0.0;
    foreach ($items as $item) {
        $description = trim((string) ($item['description'] ?? ''));
        $amount = (float) ($item['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        if ($description === '') {
            $description = $expense_context !== '' ? $expense_context : ('Erstattungsantrag ' . $transfer_reference);
        }
        $validItems[] = [
            'description' => mb_substr($description, 0, 255),
            'amount' => round($amount, 2)
        ];
        $calculatedTotal += round($amount, 2);
    }

    if (empty($validItems) && $directAmount > 0) {
        $validItems[] = [
            'description' => mb_substr($expense_context !== '' ? $expense_context : ('Erstattungsantrag ' . $transfer_reference), 0, 255),
            'amount' => round($directAmount, 2)
        ];
        $calculatedTotal = round($directAmount, 2);
    }

    if (empty($validItems) || $calculatedTotal <= 0) {
        return ['success' => false, 'error' => 'Bitte geben Sie einen gültigen Betrag an.'];
    }

    $normalizedFiles = array_values(array_filter(normalize_uploaded_files_array($files), function ($file) {
        return ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }));
    if (empty($normalizedFiles)) {
        return ['success' => false, 'error' => 'Bitte laden Sie mindestens einen Beleg hoch.'];
    }

    $db = getDBConnection();
    $uploadedFsPaths = [];

    try {
        $stmt = $db->prepare("SELECT id FROM expense_requests WHERE transfer_reference = :reference LIMIT 1");
        $stmt->execute([':reference' => $transfer_reference]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['success' => false, 'error' => 'Diese Referenz ist bereits vergeben. Bitte wählen Sie eine eindeutige Referenz.'];
        }

        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO expense_requests
            (member_id, submitted_by_user_id, full_name_snapshot, iban, transfer_reference, expense_context, requested_amount_total, status)
            VALUES (:member_id, :submitted_by_user_id, :full_name_snapshot, :iban, :transfer_reference, :expense_context, :requested_amount_total, 'submitted')");
        $stmt->execute([
            ':member_id' => $member['id'] ?? null,
            ':submitted_by_user_id' => $user_id,
            ':full_name_snapshot' => $full_name,
            ':iban' => $iban,
            ':transfer_reference' => $transfer_reference,
            ':expense_context' => $expense_context,
            ':requested_amount_total' => $calculatedTotal
        ]);

        $request_id = (int) $db->lastInsertId();

        $itemStmt = $db->prepare("INSERT INTO expense_request_items (expense_request_id, description, amount)
                                  VALUES (:expense_request_id, :description, :amount)");
        foreach ($validItems as $item) {
            $itemStmt->execute([
                ':expense_request_id' => $request_id,
                ':description' => $item['description'],
                ':amount' => $item['amount']
            ]);
        }

        $ibanForNotes = $iban !== '' ? $iban : 'nicht angegeben';
        $obligationNotes = "[ERSTATTUNG]\nReferenz: {$transfer_reference}\nIBAN: {$ibanForNotes}\nKontext: {$expense_context}";
        $oblStmt = $db->prepare("INSERT INTO item_obligations
            (member_id, receiver_name, receiver_email, total_amount, paid_amount, status, notes, created_by)
            VALUES (:member_id, :receiver_name, :receiver_email, :total_amount, 0.00, 'open', :notes, :created_by)");
        $oblStmt->execute([
            ':member_id' => $member['id'] ?? null,
            ':receiver_name' => $full_name,
            ':receiver_email' => $member['email'] ?? null,
            ':total_amount' => $calculatedTotal,
            ':notes' => $obligationNotes,
            ':created_by' => $user_id
        ]);

        $linkedObligationId = (int) $db->lastInsertId();
        $stmt = $db->prepare("UPDATE expense_requests SET linked_item_obligation_id = :linked_item_obligation_id WHERE id = :id");
        $stmt->execute([
            ':linked_item_obligation_id' => $linkedObligationId,
            ':id' => $request_id
        ]);

        foreach ($normalizedFiles as $file) {
            $uploadResult = upload_expense_request_document($file, $request_id, $db);
            if (!$uploadResult['success']) {
                throw new Exception($uploadResult['error']);
            }
            if (!empty($uploadResult['fs_path'])) {
                $uploadedFsPaths[] = $uploadResult['fs_path'];
            }
        }

        $db->commit();

        try {
            require_once __DIR__ . '/EmailService.php';
            if (class_exists('EmailService')) {
                $emailService = new EmailService();
                $emailService->sendAdminExpenseRequestNotification([
                    'full_name' => $full_name,
                    'iban' => $iban,
                    'reference' => $transfer_reference,
                    'context' => $expense_context,
                    'amount' => $calculatedTotal,
                    'request_id' => $request_id,
                    'items_count' => count($validItems)
                ]);
            }
        } catch (Exception $mailException) {
            error_log('Expense request mail failed: ' . $mailException->getMessage());
        }

        return [
            'success' => true,
            'request_id' => $request_id,
            'reference' => $transfer_reference,
            'linked_item_obligation_id' => $linkedObligationId
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($uploadedFsPaths as $fsPath) {
            if (is_string($fsPath) && file_exists($fsPath)) {
                unlink($fsPath);
            }
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get expense requests, optionally filtered by status or user.
 */
function get_expense_requests($status = null, $user_id = null, $limit = 100) {
    if (!ensure_expense_request_support()) {
        return [];
    }

    $db = getDBConnection();
    $sql = "SELECT er.*, 
                   COALESCE(m.first_name, '') AS member_first_name,
                   COALESCE(m.last_name, '') AS member_last_name,
                   COALESCE(m.email, u.email, '') AS notification_email,
                   COUNT(DISTINCT eri.id) AS item_count,
                   COUNT(DISTINCT erd.id) AS document_count
            FROM expense_requests er
            LEFT JOIN members m ON er.member_id = m.id
            LEFT JOIN users u ON er.submitted_by_user_id = u.id
            LEFT JOIN expense_request_items eri ON er.id = eri.expense_request_id
            LEFT JOIN expense_request_documents erd ON er.id = erd.expense_request_id
            WHERE 1=1";
    $params = [];

    if ($status) {
        $sql .= " AND er.status = :status";
        $params[':status'] = $status;
    }
    if ($user_id) {
        $sql .= " AND er.submitted_by_user_id = :user_id";
        $params[':user_id'] = $user_id;
    }

    $sql .= " GROUP BY er.id ORDER BY er.created_at DESC LIMIT " . (int) $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sync expense request status with the linked obligation payment state.
 */
function sync_expense_request_status_from_obligation($obligation_id) {
    if (!ensure_expense_request_support()) {
        return;
    }

    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT er.id, er.status AS request_status, io.status AS obligation_status
                              FROM expense_requests er
                              JOIN item_obligations io ON er.linked_item_obligation_id = io.id
                              WHERE er.linked_item_obligation_id = ?
                              LIMIT 1");
        $stmt->execute([$obligation_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $newStatus = $row['request_status'];
        if ($row['obligation_status'] === 'paid') {
            $newStatus = 'paid';
        } elseif ($row['request_status'] !== 'rejected') {
            $newStatus = 'approved';
        }

        $stmt = $db->prepare("UPDATE expense_requests SET status = :status WHERE id = :id");
        $stmt->execute([
            ':status' => $newStatus,
            ':id' => $row['id']
        ]);
    } catch (Exception $e) {
        error_log('sync_expense_request_status_from_obligation failed: ' . $e->getMessage());
    }
}

/**
 * Try to auto-match an imported outgoing transaction to a reimbursement request by reference.
 */
function auto_match_expense_request_to_transaction($transaction_id) {
    if (!ensure_expense_request_support()) {
        return false;
    }

    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction || (float) $transaction['amount'] >= 0) {
            return false;
        }

        $searchHaystack = mb_strtolower(trim(($transaction['purpose'] ?? '') . ' ' . ($transaction['booking_text'] ?? '')));
        if ($searchHaystack === '') {
            return false;
        }

        $stmt = $db->query("SELECT er.id, er.transfer_reference, er.linked_item_obligation_id, io.total_amount, io.paid_amount, io.status
                            FROM expense_requests er
                            JOIN item_obligations io ON er.linked_item_obligation_id = io.id
                            WHERE er.linked_item_obligation_id IS NOT NULL
                              AND er.status IN ('submitted','approved','paid')
                              AND io.status = 'open'");

        $match = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $reference = mb_strtolower(trim($candidate['transfer_reference'] ?? ''));
            if ($reference !== '' && mb_strpos($searchHaystack, $reference) !== false) {
                $match = $candidate;
                break;
            }
        }

        if (!$match) {
            return false;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM item_obligation_payments WHERE transaction_id = :transaction_id AND obligation_id = :obligation_id");
        $stmt->execute([
            ':transaction_id' => $transaction_id,
            ':obligation_id' => $match['linked_item_obligation_id']
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $available = abs((float) $transaction['amount']);
        $remaining = max(0, (float) $match['total_amount'] - (float) $match['paid_amount']);
        $linkAmount = min($available, $remaining);
        if ($linkAmount <= 0) {
            return false;
        }

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO item_obligation_payments
            (obligation_id, transaction_id, payment_date, amount, payment_method, notes, created_by)
            VALUES (:obligation_id, :transaction_id, :payment_date, :amount, :payment_method, :notes, :created_by)");
        $stmt->execute([
            ':obligation_id' => $match['linked_item_obligation_id'],
            ':transaction_id' => $transaction_id,
            ':payment_date' => $transaction['booking_date'],
            ':amount' => $linkAmount,
            ':payment_method' => 'bank',
            ':notes' => 'Automatisch per Referenz verknüpft',
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);

        $newPaid = (float) $match['paid_amount'] + $linkAmount;
        $newStatus = $newPaid + 0.0001 >= (float) $match['total_amount'] ? 'paid' : 'open';
        $stmt = $db->prepare("UPDATE item_obligations SET paid_amount = :paid_amount, status = :status WHERE id = :id");
        $stmt->execute([
            ':paid_amount' => $newPaid,
            ':status' => $newStatus,
            ':id' => $match['linked_item_obligation_id']
        ]);
        $db->commit();

        sync_expense_request_status_from_obligation($match['linked_item_obligation_id']);
        return true;
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('auto_match_expense_request_to_transaction failed: ' . $e->getMessage());
        return false;
    }
}