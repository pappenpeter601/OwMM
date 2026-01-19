<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!is_logged_in() || !has_permission('calendar.php')) {
    redirect('dashboard.php');
}

$db = getDBConnection();
$page_title = 'Kalender Einstellungen';

function b64e($s){return $s!==null && $s!==''? base64_encode($s): null;}
function b64d($s){$d = base64_decode($s, true); return $d!==false? $d: $s;}

// Load settings (single row)
$stmt = $db->query("SELECT * FROM calendar_settings ORDER BY id DESC LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'base_url' => '',
    'calendar_path' => '',
    'username' => '',
    'password' => '',
    'display_name' => 'OWMM Kalender'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $base_url = trim($_POST['base_url'] ?? '');
    $calendar_path = trim($_POST['calendar_path'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $display_name = trim($_POST['display_name'] ?? 'OWMM Kalender');

    if (empty($base_url) || empty($calendar_path) || empty($username)) {
        $error = 'Bitte füllen Sie mindestens Server-URL, Kalender-Pfad und Benutzername aus.';
    } else {
        if ($settings && isset($settings['id'])) {
            $sql = "UPDATE calendar_settings SET base_url=:base_url, calendar_path=:calendar_path, username=:username, ".
                   (!empty($password) ? "password=:password, " : "").
                   "display_name=:display_name, updated_by=:uid WHERE id=:id";
            $params = [
                'id' => $settings['id'],
                'base_url' => $base_url,
                'calendar_path' => $calendar_path,
                'username' => $username,
                'display_name' => $display_name,
                'uid' => $_SESSION['user_id']
            ];
            if (!empty($password)) $params['password'] = b64e($password);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = $db->prepare("INSERT INTO calendar_settings (base_url, calendar_path, username, password, display_name, updated_by) VALUES (:base_url, :calendar_path, :username, :password, :display_name, :uid)");
            $stmt->execute([
                'base_url' => $base_url,
                'calendar_path' => $calendar_path,
                'username' => $username,
                'password' => b64e($password),
                'display_name' => $display_name,
                'uid' => $_SESSION['user_id']
            ]);
        }
        $_SESSION['success'] = 'Einstellungen gespeichert.';
        header('Location: calendar_settings.php');
        exit;
    }
}

include 'includes/header.php';
?>
<div class="page-header">
    <h1><?php echo $page_title; ?></h1>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['success'])): ?>
<div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>

<div class="content-section">
    <form method="POST">
        <div class="form-group">
            <label class="required">Server-URL (Baikal)</label>
            <input type="url" class="form-control" name="base_url" required value="<?php echo htmlspecialchars($settings['base_url']); ?>" placeholder="https://owmm.de/baikal">
        </div>
        <div class="form-group">
            <label class="required">Kalender-Pfad (Collection)</label>
            <input type="text" class="form-control" name="calendar_path" required value="<?php echo htmlspecialchars($settings['calendar_path']); ?>" placeholder="/cal.php/calendars/calendar/owmm/">
            <small>Endet in der Regel mit "/". Vollständige Collection-URL = Server-URL + Kalender-Pfad</small>
        </div>
        <div class="form-group">
            <label class="required">Benutzername</label>
            <input type="text" class="form-control" name="username" required value="<?php echo htmlspecialchars($settings['username']); ?>">
        </div>
        <div class="form-group">
            <label>Passwort</label>
            <input type="password" class="form-control" name="password" placeholder="Leer lassen, um unverändert zu lassen">
        </div>
        <div class="form-group">
            <label>Anzeigename</label>
            <input type="text" class="form-control" name="display_name" value="<?php echo htmlspecialchars($settings['display_name']); ?>">
        </div>
        <div style="display:flex; gap:10px;">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn btn-secondary" href="calendar.php">Zum Kalender</a>
        </div>
    </form>

    <div style="margin-top:30px;">
        <h3>Smartphone Einrichtung</h3>
        <p>Richten Sie ein CalDAV-Konto ein mit:</p>
        <ul>
            <li>Server: <strong><span id="calServerText"><?php echo htmlspecialchars($settings['base_url']); ?></span></strong></li>
            <li>Account/Benutzer: <strong><?php echo htmlspecialchars($settings['username']); ?></strong></li>
            <li>Passwort: im Self-Service hinterlegt</li>
            <li>CalDAV-URL (optional): <strong><span id="calUrlText"><?php echo htmlspecialchars($settings['base_url'] . $settings['calendar_path']); ?></span></strong></li>
        </ul>
        <small>Hinweis: iOS/Android erkennen den Kalender meist automatisch nach Login. Bei Problemen nutzen Sie die vollständige CalDAV-URL.</small>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
