<?php
// Calendar API endpoint - returns events as JSON for AJAX loading
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!is_logged_in() || !has_permission('calendar.php')) {
    http_response_code(403);
    exit(json_encode(['error' => 'Access denied']));
}

header('Content-Type: application/json');

$db = getDBConnection();

function b64d($s){$d = base64_decode($s, true); return $d!==false? $d: $s;}

// Unescape iCalendar text (RFC 5545)
function unescapeICalText($text) {
    if (!$text) return '';
    // Remove backslash escaping: \, becomes , and \\ becomes \
    $text = str_replace('\\,', ',', $text);
    $text = str_replace('\\;', ';', $text);
    $text = str_replace('\\\\', '\\', $text);
    $text = str_replace('\\n', "\n", $text);
    return $text;
}

function formatICalDate($dateStr) {
    if (!$dateStr) return '';
    
    // Check if it's UTC (ends with Z)
    $isUTC = substr($dateStr, -1) === 'Z';
    $dateStr = rtrim($dateStr, 'Z');
    $dateStr = str_replace('T', '', $dateStr);
    
    if (strlen($dateStr) === 8) {
        // Create with UTC timezone if detected, then convert to server timezone
        $tz = $isUTC ? new DateTimeZone('UTC') : null;
        $timestamp = DateTime::createFromFormat('Ymd', $dateStr, $tz);
        if ($timestamp && $isUTC) {
            $timestamp->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        if ($timestamp) return $timestamp->format('d.m.Y');
    } else if (strlen($dateStr) === 14) {
        // Create with UTC timezone if detected, then convert to server timezone
        $tz = $isUTC ? new DateTimeZone('UTC') : null;
        $timestamp = DateTime::createFromFormat('YmdHis', $dateStr, $tz);
        if ($timestamp && $isUTC) {
            $timestamp->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        if ($timestamp) return $timestamp->format('d.m.Y H:i');
    }
    return $dateStr;
}

function parseICalDate($dateStr) {
    if (!$dateStr) return 0;
    
    // Check if it's UTC (ends with Z)
    $isUTC = substr($dateStr, -1) === 'Z';
    $dateStr = rtrim($dateStr, 'Z');
    $dateStr = str_replace('T', '', $dateStr);
    
    if (strlen($dateStr) === 8) {
        $timestamp = DateTime::createFromFormat('Ymd', $dateStr);
    } else if (strlen($dateStr) === 14) {
        $timestamp = DateTime::createFromFormat('YmdHis', $dateStr);
    } else {
        return 0;
    }
    
    if (!$timestamp) return 0;
    
    if ($isUTC) {
        $timestamp->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
    
    return $timestamp->getTimestamp();
}

function dav_request($method, $url, $user, $pass, $headers = [], $body = null, $includeDepth = true) {
    $ch = curl_init($url);
    $defaultHeaders = [];
    
    if ($includeDepth && in_array($method, ['PROPFIND', 'REPORT', 'MKCOL'])) {
        $defaultHeaders[] = 'Depth: 1';
    }
    
    $headers = array_merge($defaultHeaders, $headers);
    
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_VERBOSE => false
    ];
    
    curl_setopt_array($ch, $curlOpts);
    
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    
    curl_close($ch);
    return [(int)$status, $response, $err];
}

// Load settings
$stmt = $db->query("SELECT * FROM calendar_settings ORDER BY id DESC LIMIT 1");
$cfg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cfg) {
    echo json_encode(['error' => 'Calendar not configured', 'items' => []]);
    exit;
}

$base = rtrim($cfg['base_url'], '/');
$calendar_path = ltrim($cfg['calendar_path'], '/');
$collection = $base . '/' . $calendar_path;
$user = $cfg['username'];
$pass = b64d($cfg['password']);

// Check cache
$cacheKey = 'calendar_events_' . md5($collection);
if (isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
    $cacheData = $_SESSION[$cacheKey];
    if (isset($cacheData['timestamp']) && (time() - $cacheData['timestamp']) < 300) {
        echo json_encode(['items' => $cacheData['items'], 'cached' => true]);
        exit;
    }
}

// Fetch fresh events
$propfind = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:displayname/>
    <d:resourcetype/>
    <d:getcontenttype/>
  </d:prop>
</d:propfind>
XML;

[$st, $xml, $er] = dav_request('PROPFIND', $collection, $user, $pass, [
    'Content-Type: application/xml; charset=utf-8'
], $propfind, true);

$items = [];
if (($st === 207 || $st === 200) && $xml) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadXML($xml);
    libxml_use_internal_errors(false);
    
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('d','DAV:');
    
    $responses = $xpath->query('//d:response');
    
    foreach ($responses as $resp) {
        $href_nodes = $xpath->query('./d:href', $resp);
        $href = $href_nodes->length > 0 ? $href_nodes->item(0)->textContent : '';
        
        if (!$href || !str_ends_with($href, '.ics')) continue;
        
        if (str_starts_with($href, '/')) {
            $collectionParts = parse_url($collection);
            $scheme = $collectionParts['scheme'] ?? 'https';
            $host = $collectionParts['host'] ?? 'localhost';
            $port = isset($collectionParts['port']) ? ':' . $collectionParts['port'] : '';
            $fullUrl = $scheme . '://' . $host . $port . $href;
        } else {
            $fullUrl = $href;
        }
        
        [$status, $icsContent, $err] = dav_request('GET', $fullUrl, $user, $pass, [], null, false);
        
        if ($status === 200 && $icsContent) {
            $summary = '';
            if (preg_match('/^SUMMARY:(.+)$/m', $icsContent, $m)) $summary = trim($m[1]);
            $dtstart = '';
            if (preg_match('/^DTSTART(?:;[^:]+)?:([^\r\n]+)/m', $icsContent, $m)) $dtstart = $m[1];
            $dtend = '';
            if (preg_match('/^DTEND(?:;[^:]+)?:([^\r\n]+)/m', $icsContent, $m)) $dtend = $m[1];
            $location = '';
            if (preg_match('/^LOCATION:(.+)$/m', $icsContent, $m)) $location = unescapeICalText(trim($m[1]));
            $description = '';
            if (preg_match('/^DESCRIPTION:(.+)$/m', $icsContent, $m)) $description = unescapeICalText(trim($m[1]));
            
            // Also unescape summary
            $summary = unescapeICalText($summary);
            
            $dtstart_display = formatICalDate($dtstart);
            $dtend_display = formatICalDate($dtend);
            
            if ($summary && $dtstart && $dtend) {
                $items[] = [
                    'href' => $href,
                    'summary' => $summary,
                    'location' => $location,
                    'description' => $description,
                    'dtstart' => $dtstart,
                    'dtstart_display' => $dtstart_display,
                    'dtend' => $dtend,
                    'dtend_display' => $dtend_display
                ];
            }
        }
    }
}

// Filter and sort
$now = time();
$futureLimit = $now + (60 * 24 * 60 * 60);
$filtered_items = [];

foreach ($items as $item) {
    $itemTime = parseICalDate($item['dtstart']);
    if ($itemTime >= $now - (24 * 60 * 60) && $itemTime <= $futureLimit) {
        $filtered_items[] = $item;
    }
}

usort($filtered_items, function($a, $b) {
    $timeA = parseICalDate($a['dtstart']);
    $timeB = parseICalDate($b['dtstart']);
    return $timeA - $timeB;
});

// Cache results
$_SESSION[$cacheKey] = [
    'timestamp' => time(),
    'items' => $filtered_items
];

echo json_encode(['items' => $filtered_items, 'cached' => false]);
?>
