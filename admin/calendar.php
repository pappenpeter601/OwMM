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

// Debug: Log the constructed collection URL
error_log("DEBUG calendar.php: base_url = " . $cfg['base_url']);
error_log("DEBUG calendar.php: calendar_path = " . $cfg['calendar_path']);
error_log("DEBUG calendar.php: collection = " . $collection);

// Helpers for CalDAV
function dav_request($method, $url, $user, $pass, $headers = [], $body = null) {
    $ch = curl_init($url);
    $defaultHeaders = [
        'Depth: 1'
    ];
    $headers = array_merge($defaultHeaders, $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, $response, $err];
}

function ical_uid() { return bin2hex(random_bytes(8)) . '@owmm.de'; }

// Handle create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $summary = trim($_POST['summary'] ?? '');
    $start = trim($_POST['start'] ?? '');
    $end = trim($_POST['end'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($summary && $start && $end) {
        $uid = ical_uid();
        $dtstart = date('Ymd\THis', strtotime($start));
        $dtend = date('Ymd\THis', strtotime($end));
        $ical = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//OWMM//Calendar//DE\nBEGIN:VEVENT\nUID:$uid\nDTSTAMP:".gmdate('Ymd\THis\Z')."\nDTSTART:$dtstart\nDTEND:$dtend\nSUMMARY:".str_replace("\n"," ",$summary)."\n".
                ($location? "LOCATION:".str_replace("\n"," ",$location)."\n":"").
                ($description? "DESCRIPTION:".str_replace(["\n","\r"],['\\n',''], $description)."\n":"").
                "END:VEVENT\nEND:VCALENDAR\n";
        $objectUrl = rtrim($collection, '/') . '/' . $uid . '.ics';
        error_log("DEBUG calendar.php: Creating event at URL: " . $objectUrl);
        [$status, $resp, $err] = dav_request('PUT', $objectUrl, $user, $pass, [
            'Content-Type: text/calendar; charset=utf-8'
        ], $ical);
        if ($status >= 200 && $status < 300) {
            $_SESSION['success'] = 'Termin angelegt.';
            header('Location: calendar.php');
            exit;
        } else {
            $error = 'Fehler beim Anlegen (HTTP '.$status.'): '.htmlspecialchars($err ?: $resp);
        }
    } else {
        $error = 'Bitte mindestens Titel, Start und Ende ausfüllen.';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $href = $_POST['href'] ?? '';
    if ($href) {
        [$status, $resp, $err] = dav_request('DELETE', $href, $user, $pass);
        if ($status >= 200 && $status < 300) {
            $_SESSION['success'] = 'Termin gelöscht.';
            header('Location: calendar.php');
            exit;
        } else {
            $error = 'Löschen fehlgeschlagen (HTTP '.$status.'): '.htmlspecialchars($err ?: $resp);
        }
    }
}

// Fetch events next 60 days via REPORT
$from = gmdate('Ymd\THis\Z');
$to = gmdate('Ymd\THis\Z', strtotime('+60 days'));
$report = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<cal:calendar-query xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag/>
    <cal:calendar-data/>
  </d:prop>
  <cal:filter>
    <cal:comp-filter name="VCALENDAR">
      <cal:comp-filter name="VEVENT">
        <cal:time-range start="$from" end="$to"/>
      </cal:comp-filter>
    </cal:comp-filter>
  </cal:filter>
</cal:calendar-query>
XML;

[$st, $xml, $er] = dav_request('REPORT', $collection, $user, $pass, [
    'Content-Type: application/xml; charset=utf-8'
], $report);

$items = [];
if ($st >= 200 && $st < 300 && $xml) {
    // Very light parsing: split by BEGIN:VEVENT
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if ($dom->loadXML($xml)) {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('d','DAV:');
        $xpath->registerNamespace('cal','urn:ietf:params:xml:ns:caldav');
        foreach ($xpath->query('//d:response') as $resp) {
            $href = $xpath->query('./d:href', $resp)->item(0)->textContent ?? '';
            $cdata = $xpath->query('.//cal:calendar-data', $resp)->item(0)->textContent ?? '';
            if ($cdata) {
                // naive extraction of SUMMARY/DTSTART/DTEND
                $summary = '';
                if (preg_match('/^SUMMARY:(.+)$/m', $cdata, $m)) $summary = trim($m[1]);
                $dtstart = '';
                if (preg_match('/^DTSTART(?:;[^:]+)?:([^\r\n]+)/m', $cdata, $m)) $dtstart = $m[1];
                $dtend = '';
                if (preg_match('/^DTEND(?:;[^:]+)?:([^\r\n]+)/m', $cdata, $m)) $dtend = $m[1];
                $items[] = [
                    'href' => $href,
                    'summary' => $summary,
                    'dtstart' => $dtstart,
                    'dtend' => $dtend
                ];
            }
        }
    }
}

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

    <h3>Kommende Termine (60 Tage)</h3>
    <?php if (empty($items)): ?>
        <div class="alert alert-info">Keine Termine gefunden oder Zugriff fehlgeschlagen.</div>
    <?php else: ?>
        <div class="data-table">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Titel</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Beginn</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Ende</th>
                        <th style="padding:8px; border-bottom:1px solid #ddd;">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($it['summary']); ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($it['dtstart']); ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($it['dtend']); ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f0f0f0; text-align:center;">
                            <form method="POST" onsubmit="return confirm('Sind Sie sicher?');" style="display:inline-block;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="href" value="<?php echo htmlspecialchars($it['href']); ?>">
                                <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-trash"></i> Löschen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
