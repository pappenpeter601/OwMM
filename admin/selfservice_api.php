<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !has_permission('selfservice.php')) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung']);
    exit;
}

header('Content-Type: application/json');

$db = getDBConnection();

// Decode base64 obfuscated values
function decrypt_value($encoded) {
    if ($encoded === null || $encoded === '') return '';
    $decoded = base64_decode($encoded, true);
    return $decoded !== false ? $decoded : $encoded;
}

// Handle GET request to fetch single credential
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get') {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['error' => 'ID fehlt']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT id, name, description, login, website FROM credentials WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$credential) {
        echo json_encode(['error' => 'Eintrag nicht gefunden']);
        exit;
    }
    
    echo json_encode($credential);
    exit;
}

// Handle POST request to decrypt value
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? $input['action'] ?? null;
    
    if ($action === 'decrypt') {
        $encrypted = $input['value'] ?? null;
        
        if (!$encrypted) {
            echo json_encode(['error' => 'Kein Wert angegeben']);
            exit;
        }
        
        try {
            $decrypted = decrypt_value($encrypted);
            echo json_encode(['decrypted' => $decrypted]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Entschlüsselung fehlgeschlagen']);
        }
        exit;
    }
}

echo json_encode(['error' => 'Ungültige Anfrage']);
