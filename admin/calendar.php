<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!is_logged_in() || !has_permission('calendar.php')) {
    redirect('dashboard.php');
}

$db = getDBConnection();
$page_title = 'Kalender';

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

// Format iCalendar date to readable format
function formatICalDate($dateStr) {
    if (!$dateStr) return '';
    
    // Check if it's UTC (ends with Z)
    $isUTC = substr($dateStr, -1) === 'Z';
    
    // Remove Z if present (UTC indicator)
    $dateStr = rtrim($dateStr, 'Z');
    
    // Remove T separator if present (YYYYMMDDTHHmmss)
    $dateStr = str_replace('T', '', $dateStr);
    
    // Parse format: 20260124170200 (YYYYMMDDHHmmss) or 20260124 (YYYYMMDD)
    if (strlen($dateStr) === 8) {
        // Date only (YYYYMMDD) - create with UTC timezone if detected
        $tz = $isUTC ? new DateTimeZone('UTC') : null;
        $timestamp = DateTime::createFromFormat('Ymd', $dateStr, $tz);
        if ($timestamp && $isUTC) {
            $timestamp->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        if ($timestamp) return $timestamp->format('d.m.Y');
    } else if (strlen($dateStr) === 14) {
        // DateTime (YYYYMMDDHHmmss) - create with UTC timezone if detected
        $tz = $isUTC ? new DateTimeZone('UTC') : null;
        $timestamp = DateTime::createFromFormat('YmdHis', $dateStr, $tz);
        if ($timestamp && $isUTC) {
            $timestamp->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        if ($timestamp) return $timestamp->format('d.m.Y H:i');
    }
    
    return $dateStr;
}

// Parse iCalendar date to Unix timestamp for sorting
function parseICalDate($dateStr) {
    if (!$dateStr) return 0;
    
    // Detect if UTC (Z suffix)
    $isUTC = substr($dateStr, -1) === 'Z';
    
    // Remove Z if present (UTC indicator)
    $dateStr = rtrim($dateStr, 'Z');
    
    // Remove T separator if present
    $dateStr = str_replace('T', '', $dateStr);
    
    // Create with UTC timezone if detected, then convert to server timezone
    $tz = $isUTC ? new DateTimeZone('UTC') : null;
    
    // Parse format: 20260124170200 (YYYYMMDDHHmmss) or 20260124 (YYYYMMDD)
    if (strlen($dateStr) === 8) {
        $timestamp = DateTime::createFromFormat('Ymd', $dateStr, $tz);
    } else if (strlen($dateStr) === 14) {
        $timestamp = DateTime::createFromFormat('YmdHis', $dateStr, $tz);
    } else {
        return 0;
    }
    
    // Convert UTC to server timezone
    if ($timestamp && $isUTC) {
        $timestamp->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
    
    return $timestamp ? $timestamp->getTimestamp() : 0;
}

// Load settings
$stmt = $db->query("SELECT * FROM calendar_settings ORDER BY id DESC LIMIT 1");
$cfg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cfg) {
    $_SESSION['error'] = 'Bitte Kalender-Einstellungen zuerst konfigurieren.';
    header('Location: calendar_settings.php');
    exit;
}

$base = rtrim($cfg['base_url'], '/');
$calendar_path = $cfg['calendar_path'];
// If calendar_path starts with /, remove it since we're adding it back
$calendar_path = ltrim($calendar_path, '/');
$collection = $base . '/' . $calendar_path;
$user = $cfg['username'];
$pass = b64d($cfg['password']);

// Verify collection URL is properly formatted
if (!$collection) {
    $_SESSION['error'] = 'Kalender-Pfad ist nicht konfiguriert.';
    header('Location: calendar_settings.php');
    exit;
}

// Debug: Log the constructed collection URL
error_log("DEBUG calendar.php: base_url = " . $cfg['base_url']);
error_log("DEBUG calendar.php: calendar_path = " . $cfg['calendar_path']);
error_log("DEBUG calendar.php: collection = " . $collection);

// Helpers for CalDAV - Proper HTTP request handling
function dav_request($method, $url, $user, $pass, $headers = [], $body = null, $includeDepth = true) {
    $ch = curl_init($url);
    $defaultHeaders = [];
    
    // Only add Depth for PROPFIND, REPORT, and certain other methods
    if ($includeDepth && in_array($method, ['PROPFIND', 'REPORT', 'MKCOL'])) {
        $defaultHeaders[] = 'Depth: 1';
    }
    
    $headers = array_merge($defaultHeaders, $headers);
    
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,  // Use Digest auth (required by Baikal)
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,  // Don't auto-follow for CalDAV
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
    $httpCode = (int)$status;
    
    error_log("DEBUG calendar.php: $method $url -> HTTP $httpCode");
    if ($err) {
        error_log("DEBUG calendar.php: curl_error: $err");
    }
    
    curl_close($ch);
    return [$httpCode, $response, $err];
}

function ical_uid() { return bin2hex(random_bytes(8)) . '@owmm.de'; }

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (empty($_FILES['ics_file']) || $_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Bitte wählen Sie eine .ics Datei aus.';
    } else {
        $file = $_FILES['ics_file'];
        
        // Validate file type
        if (!in_array($file['type'], ['text/calendar', 'text/plain', 'application/octet-stream'])) {
            $error = 'Bitte laden Sie eine .ics Datei hoch (text/calendar).';
        } elseif (!str_ends_with(strtolower($file['name']), '.ics')) {
            $error = 'Dateiname muss mit .ics enden.';
        } else {
            $content = file_get_contents($file['tmp_name']);
            
            // Parse iCalendar file for VEVENT components
            $events = [];
            if (preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $content, $matches)) {
                foreach ($matches[1] as $eventBody) {
                    $event = [];
                    
                    // Extract fields
                    if (preg_match('/^SUMMARY:(.+)$/m', "BEGIN:VEVENT$eventBody", $m)) {
                        $event['summary'] = trim($m[1]);
                    }
                    if (preg_match('/^DTSTART(?:;[^:]+)?:(.+)$/m', "BEGIN:VEVENT$eventBody", $m)) {
                        $event['dtstart'] = trim($m[1]);
                    }
                    if (preg_match('/^DTEND(?:;[^:]+)?:(.+)$/m', "BEGIN:VEVENT$eventBody", $m)) {
                        $event['dtend'] = trim($m[1]);
                    }
                    if (preg_match('/^UID:(.+)$/m', "BEGIN:VEVENT$eventBody", $m)) {
                        $event['uid'] = trim($m[1]);
                    } else {
                        $event['uid'] = ical_uid();
                    }
                    if (preg_match('/^LOCATION:(.+)$/m', "BEGIN:VEVENT$eventBody", $m)) {
                        $event['location'] = unescapeICalText(trim($m[1]));
                    }
                    if (preg_match('/^DESCRIPTION:(.+)$/m', "BEGIN:VEVENT$eventBody", $m)) {
                        $event['description'] = unescapeICalText(trim($m[1]));
                    }
                    
                    if (!empty($event['summary']) && !empty($event['dtstart']) && !empty($event['dtend'])) {
                        $events[] = $event;
                    }
                }
            }
            
            if (empty($events)) {
                $error = 'Keine gültigen Ereignisse in der .ics Datei gefunden.';
            } else {
                $created = 0;
                $failed = 0;
                
                foreach ($events as $evt) {
                    $uid = $evt['uid'];
                    $dtstart = $evt['dtstart'];
                    $dtend = $evt['dtend'];
                    $summary = $evt['summary'];
                    $location = $evt['location'] ?? '';
                    $description = $evt['description'] ?? '';
                    
                    // Build iCalendar
                    $ical = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//OWMM//Calendar//DE\nBEGIN:VEVENT\nUID:$uid\nDTSTAMP:".gmdate('Ymd\THis\Z')."\nDTSTART:$dtstart\nDTEND:$dtend\nSUMMARY:".str_replace("\n"," ",$summary)."\n".
                            ($location? "LOCATION:".str_replace("\n"," ",$location)."\n":"").
                            ($description? "DESCRIPTION:".str_replace(["\n","\r"],['\\n',''], $description)."\n":"").
                            "END:VEVENT\nEND:VCALENDAR\n";
                    
                    $objectUrl = rtrim($collection, '/') . '/' . $uid . '.ics';
                    error_log("DEBUG calendar.php: Uploading event $uid to $objectUrl");
                    
                    [$status, $resp, $err] = dav_request('PUT', $objectUrl, $user, $pass, [
                        'Content-Type: text/calendar; charset=utf-8'
                    ], $ical, false);
                    
                    if ($status >= 200 && $status < 300) {
                        $created++;
                    } else {
                        error_log("DEBUG calendar.php: Upload failed for $uid: HTTP $status - $resp");
                        $failed++;
                    }
                }
                
                if ($failed === 0) {
                    $_SESSION['success'] = "$created Ereignisse erfolgreich importiert.";
                    header('Location: calendar.php');
                    exit;
                } else {
                    $error = "$created erfolgreich, $failed fehlgeschlagen.";
                }
            }
        }
    }
}

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $summary = trim($_POST['summary'] ?? '');
    $start = trim($_POST['start'] ?? '');
    $end = trim($_POST['end'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($summary && $start && $end) {
        $uid = ical_uid();
        $dtstart = date('Ymd\THis\Z', strtotime($start));
        $dtend = date('Ymd\THis\Z', strtotime($end));
        
        // Build iCalendar with proper formatting
        $ical = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//OWMM//Calendar//DE\nBEGIN:VEVENT\nUID:$uid\nDTSTAMP:".gmdate('Ymd\THis\Z')."\nDTSTART:$dtstart\nDTEND:$dtend\nSUMMARY:".str_replace("\n"," ",$summary)."\n".
                ($location? "LOCATION:".str_replace("\n"," ",$location)."\n":"").
                ($description? "DESCRIPTION:".str_replace(["\n","\r"],['\\n',''], $description)."\n":"").
                "END:VEVENT\nEND:VCALENDAR\n";
        
        $objectUrl = rtrim($collection, '/') . '/' . $uid . '.ics';
        error_log("DEBUG calendar.php: Creating event at URL: " . $objectUrl);
        
        [$status, $resp, $err] = dav_request('PUT', $objectUrl, $user, $pass, [
            'Content-Type: text/calendar; charset=utf-8'
        ], $ical, false);
        
        if ($status >= 200 && $status < 300) {
            $_SESSION['success'] = 'Termin angelegt.';
            header('Location: calendar.php');
            exit;
        } else {
            error_log("DEBUG calendar.php: Create failed: HTTP $status - " . substr($resp ?? '', 0, 200));
            $error = 'Fehler beim Anlegen (HTTP '.$status.'): '.htmlspecialchars(substr($err ?: $resp ?: 'Unknown error', 0, 100));
        }
    } else {
        $error = 'Bitte mindestens Titel, Start und Ende ausfüllen.';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $href = $_POST['href'] ?? '';
    if ($href) {
        [$status, $resp, $err] = dav_request('DELETE', $href, $user, $pass, [], null, false);
        if ($status >= 200 && $status < 300) {
            $_SESSION['success'] = 'Termin gelöscht.';
            header('Location: calendar.php');
            exit;
        } else {
            error_log("DEBUG calendar.php: Delete failed for $href: HTTP $status - $resp");
            $error = 'Löschen fehlgeschlagen (HTTP '.$status.'): '.htmlspecialchars(substr($err ?: $resp ?: 'Unknown error', 0, 100));
        }
    }
}

// Events will be loaded asynchronously via calendar_api.php
// Set a flag to indicate async loading
$loadEventsAsync = true;
$items = [];

include 'includes/header.php';
?>
<div class="page-header">
    <h1><?php echo $page_title; ?></h1>
    <div class="page-actions">
        <a href="calendar_settings.php" class="btn btn-secondary"><i class="fas fa-cog"></i> Einstellungen</a>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<div class="content-section">
    <h3>Termin anlegen</h3>
    <form method="POST" style="margin-bottom:20px;">
        <input type="hidden" name="action" value="create">
        <div class="form-row" style="display:flex; gap:10px; flex-wrap:wrap;">
            <input class="form-control" style="flex:1; min-width:200px;" name="summary" placeholder="Titel" required>
            <input class="form-control" style="flex:1; min-width:200px;" name="start" type="datetime-local" required>
            <input class="form-control" style="flex:1; min-width:200px;" name="end" type="datetime-local" required>
        </div>
        <div class="form-row" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
            <input class="form-control" style="flex:1; min-width:200px;" name="location" placeholder="Ort (optional)">
            <input class="form-control" style="flex:1; min-width:200px;" name="description" placeholder="Beschreibung (optional)">
        </div>
        <div style="margin-top:10px;"><button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Anlegen</button></div>
    </form>

    <h3>Ereignisse aus .ics Datei importieren</h3>
    <form method="POST" enctype="multipart/form-data" style="margin-bottom:20px;">
        <input type="hidden" name="action" value="upload">
        <div class="form-row" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input class="form-control" style="flex:1; min-width:200px;" type="file" name="ics_file" accept=".ics" required>
            <button class="btn btn-info" type="submit"><i class="fas fa-upload"></i> Importieren</button>
        </div>
        <small style="display:block; margin-top:5px;">Laden Sie eine .ics Datei hoch um mehrere Ereignisse auf einmal zu importieren.</small>
    </form>

    <h3>Kommende Termine (60 Tage)</h3>
    
    <div id="events-container">
        <div class="alert alert-info" style="text-align:center;">
            <i class="fas fa-spinner fa-spin"></i> Termine werden geladen...
        </div>
    </div>
</div>

<script>
// Load events asynchronously
document.addEventListener('DOMContentLoaded', function() {
    loadEvents();
});

function loadEvents() {
    fetch('calendar_api.php')
        .then(response => response.json())
        .then(data => {
            renderEvents(data.items);
        })
        .catch(error => {
            console.error('Error loading events:', error);
            document.getElementById('events-container').innerHTML = 
                '<div class="alert alert-danger">Fehler beim Laden der Termine.</div>';
        });
}

function renderEvents(items) {
    const container = document.getElementById('events-container');
    
    if (items.length === 0) {
        container.innerHTML = '<div class="alert alert-info">Keine Termine gefunden.</div>';
        return;
    }
    
    let html = `<div class="data-table">
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:12px; border-bottom:2px solid #ddd;">Titel</th>
                    <th style="text-align:left; padding:12px; border-bottom:2px solid #ddd;">Beginn</th>
                    <th style="text-align:left; padding:12px; border-bottom:2px solid #ddd;">Ende</th>
                    <th style="text-align:left; padding:12px; border-bottom:2px solid #ddd;">Ort</th>
                    <th style="text-align:left; padding:12px; border-bottom:2px solid #ddd;">Beschreibung</th>
                    <th style="text-align:center; padding:12px; border-bottom:2px solid #ddd;">Aktionen</th>
                </tr>
            </thead>
            <tbody>`;
    
    items.forEach(item => {
        const description = item.description ? 
            (item.description.substring(0, 100) + (item.description.length > 100 ? '...' : '')) : 
            '<em style="color:#999;">—</em>';
        const location = item.location || '<em style="color:#999;">—</em>';
        
        html += `<tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:12px; font-weight:bold; max-width:200px; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(item.summary)}</td>
            <td style="padding:12px; white-space:nowrap;">${escapeHtml(item.dtstart_display)}</td>
            <td style="padding:12px; white-space:nowrap;">${escapeHtml(item.dtend_display)}</td>
            <td style="padding:12px; max-width:150px; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(location)}</td>
            <td style="padding:12px; max-width:250px; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(description)}</td>
            <td style="padding:12px; text-align:center;">
                <form method="POST" onsubmit="return confirm('Sind Sie sicher?');" style="display:inline-block;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="href" value="${escapeHtml(item.href)}">
                    <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-trash"></i> Löschen</button>
                </form>
            </td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}
</script>

<?php include 'includes/footer.php'; ?>
