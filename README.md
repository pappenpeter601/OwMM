# Freiwillige Feuerwehr Website

Eine moderne, responsive Website für Ihre freiwillige Feuerwehr mit Content-Management-System und rollenbasiertem Admin-Bereich.

## Features

### Frontend
- ✅ Responsive Design (Desktop & Mobile)
- ✅ Landing Page mit Hero-Bereich
- ✅ Chronologische Einsatzübersicht mit Bildergalerie
- ✅ Veranstaltungskalender (anstehend & vergangen)
- ✅ Vorstandschaft-Seite mit Mitgliedern
- ✅ Kontaktformular
- ✅ Social Media Integration
- ✅ Impressum & Datenschutz
- ✅ Bildergalerie mit Lightbox

### Backend / Admin-Bereich
- ✅ Rollenbasiertes Zugriffssystem (4 Rollen)
- ✅ Einsätze verwalten (PR Manager)
- ✅ Veranstaltungen verwalten (Event Manager)
- ✅ Seiteninhalte bearbeiten (Vorstand)
- ✅ Bilderverwaltung
- ✅ Kontaktanfragen-Verwaltung
- ✅ Benutzer- und System-Einstellungen (Admin)

### Benutzerrollen
1. **Admin** - Vollzugriff auf alle Funktionen
2. **Board (Vorstand)** - Kann Seiteninhalte und Vorstandschaft verwalten
3. **PR Manager** - Kann Einsätze verwalten und veröffentlichen
4. **Event Manager** - Kann Veranstaltungen verwalten und veröffentlichen

## Systemanforderungen

- PHP 7.4 oder höher
- MariaDB/MySQL 5.7 oder höher
- Apache/Nginx Webserver
- mod_rewrite aktiviert (empfohlen)
- IONOS Webhosting oder vergleichbar

## Installation

### 1. Dateien hochladen

Laden Sie alle Dateien auf Ihren IONOS Webspace hoch:

```bash
/
├── admin/              # Admin-Bereich
├── assets/             # CSS, JS und andere Assets
├── config/             # Konfigurationsdateien
├── database/           # SQL-Schema
├── includes/           # PHP-Includes und Funktionen
├── uploads/            # Bildupload-Verzeichnis
├── index.php           # Startseite
├── operations.php      # Einsätze-Seite
├── events.php          # Veranstaltungen-Seite
├── board.php           # Vorstandschaft-Seite
├── contact.php         # Kontaktformular
├── impressum.php       # Impressum
└── datenschutz.php     # Datenschutz
```

### 2. Datenbank einrichten

#### Bei IONOS:

1. Melden Sie sich im IONOS Control Panel an
2. Navigieren Sie zu **Hosting → MySQL-Datenbanken**
3. Erstellen Sie eine neue Datenbank
4. Notieren Sie sich:
   - Datenbankname
   - Benutzername
   - Passwort
   - Host (meist `localhost`)

#### Datenbank-Schema importieren:

1. Öffnen Sie phpMyAdmin (im IONOS Control Panel verfügbar)
2. Wählen Sie Ihre Datenbank aus
3. Gehen Sie auf "Importieren"
4. Wählen Sie die Datei `database/schema.sql`
5. Klicken Sie auf "OK"

### 3. Konfiguration anpassen

Bearbeiten Sie die Datei `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Ihr_Datenbankname');
define('DB_USER', 'Ihr_Benutzername');
define('DB_PASS', 'Ihr_Passwort');
```

Bearbeiten Sie die Datei `config/config.php`:

```php
define('SITE_NAME', 'Freiwillige Feuerwehr [Ihr Ort]');
define('SITE_URL', 'https://ihre-domain.de');
define('ADMIN_EMAIL', 'info@ihre-domain.de');
```

### 4. Verzeichnisberechtigungen setzen

Das `uploads/` Verzeichnis muss beschreibbar sein:

```bash
chmod 755 uploads/
```

Bei IONOS können Sie dies über den Dateimanager oder FTP-Client (z.B. FileZilla) einstellen:
- Rechtsklick auf Ordner → Eigenschaften → Berechtigungen: 755

### 5. Erster Login

1. Öffnen Sie `https://ihre-domain.de/admin/login.php`
2. Standard-Anmeldedaten:
   - **Benutzername:** admin
   - **Passwort:** admin123

⚠️ **WICHTIG:** Ändern Sie das Passwort sofort nach dem ersten Login!

## Passwort ändern

Um das Admin-Passwort zu ändern, führen Sie in phpMyAdmin folgenden SQL-Befehl aus:

```sql
UPDATE users 
SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' 
WHERE username = 'admin';
```

Oder verwenden Sie dieses PHP-Skript einmalig:

```php
<?php
// password_update.php - Nach Verwendung löschen!
require_once 'config/database.php';
$new_password = 'IhrNeuesPasswort';
$hashed = password_hash($new_password, PASSWORD_DEFAULT);
$db = getDBConnection();
$stmt = $db->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$stmt->execute([$hashed]);
echo "Passwort geändert!";
?>
```

## Benutzer hinzufügen

Führen Sie in phpMyAdmin aus:

```sql
INSERT INTO users (username, email, password, role, first_name, last_name) 
VALUES (
    'pr_manager',                                                    -- Benutzername
    'pr@ihre-domain.de',                                            -- E-Mail
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Passwort: admin123
    'pr_manager',                                                    -- Rolle
    'Max',                                                           -- Vorname
    'Mustermann'                                                     -- Nachname
);
```

Verfügbare Rollen: `admin`, `board`, `pr_manager`, `event_manager`

## Inhalte pflegen

### Startseite bearbeiten

1. Melden Sie sich als **Admin** oder **Board** an
2. Navigieren Sie zu **Seiteninhalte**
3. Bearbeiten Sie die Sektionen:
   - Hero-Bereich (Willkommenstext)
   - Über uns
   - Kontaktinformationen

### Einsätze hinzufügen

1. Anmeldung als **PR Manager** oder **Admin**
2. Navigieren Sie zu **Einsätze**
3. Klicken Sie auf **+ Neuer Einsatz**
4. Füllen Sie das Formular aus:
   - Titel (z.B. "Brand in Wohnhaus")
   - Datum und Uhrzeit
   - Ort
   - Einsatzart (z.B. "Brand", "Technische Hilfe")
   - Beschreibung
   - Veröffentlichen (Haken setzen)
5. Nach dem Speichern: **Bilder** → Bilder hochladen

### Veranstaltungen verwalten

1. Anmeldung als **Event Manager** oder **Admin**
2. Navigieren Sie zu **Veranstaltungen**
3. Klicken Sie auf **+ Neue Veranstaltung**
4. Der Status wird automatisch gesetzt:
   - `upcoming` = Datum liegt in der Zukunft
   - `past` = Datum liegt in der Vergangenheit

## Magic Link Authentifizierung (Passwortlos)

Mit der Magic Link Authentifizierung melden sich Nutzer ohne Passwort an. Sie erhalten einen einmaligen, zeitlich begrenzten Link per E-Mail.

### Einrichtung

1. Datenbankmigration ausführen:

```bash
mysql -u <USER> -p <DB_NAME> < OwMM/database/migration_magiclink_auth.sql
```

2. SMTP konfigurieren:
    - Öffnen Sie den Admin-Bereich → E-Mail-Einstellungen: [admin/email_settings.php](admin/email_settings.php)
    - Tragen Sie `SMTP Host`, `Port`, `Benutzername`, `Passwort`, `Absender E-Mail` und `Name` ein
    - Senden Sie eine Test-E-Mail, um die Konfiguration zu prüfen

### Ablauf für Nutzer

- Registrierung: [register.php](register.php)
   - Formular ausfüllen (Vorname, Nachname, E-Mail)
   - E-Mail-Adresse via Link bestätigen: [verify_registration.php](verify_registration.php)
   - Admin prüft und genehmigt: [admin/approve_registrations.php](admin/approve_registrations.php)

- Anmeldung per Magic Link: [request_magiclink.php](request_magiclink.php)
   - E-Mail eingeben → Link wird versendet (gültig 15 Minuten, einmalig)
   - Klick auf den Link → automatische Anmeldung: [verify_magiclink.php](verify_magiclink.php)

### Sicherheit

- Token: 64-stellig, sicher generiert (`random_bytes`), 15 Minuten gültig
- Einmalige Verwendung (wird beim Login sofort als benutzt markiert)
- Rate Limiting: max. 3 Anfragen pro 15 Minuten pro E-Mail/IP
- Audit Trail: Anmeldeversuche werden in `login_attempts` geloggt

### Admin-Menü

- Registrierungen: [admin/approve_registrations.php](admin/approve_registrations.php)
- E-Mail-Einstellungen: [admin/email_settings.php](admin/email_settings.php)
- Admin-Login Seite zeigt zusätzlich Option „Mit Magic Link anmelden“

### Hinweise

- Der `users.auth_method` steuert, ob Magic Link erlaubt ist (`magic_link` oder `both`).
- Für bestehende Konten ohne Passwort kann `password` auf NULL gesetzt werden.

### Vorstandschaft pflegen

1. Anmeldung als **Board** oder **Admin**
2. Navigieren Sie zu **Vorstandschaft**
3. Fügen Sie Mitglieder hinzu mit:
   - Foto (optional)
   - Name und Position
   - Biografie (optional)
   - Kontaktdaten (optional)
4. Sortierung über "Sortierreihenfolge"

## Social Media Integration

Bearbeiten Sie die Einträge in der Datenbank-Tabelle `social_media`:

```sql
UPDATE social_media SET url = 'https://instagram.com/ihre_feuerwehr' WHERE platform = 'Instagram';
UPDATE social_media SET url = 'https://tiktok.com/@ihre_feuerwehr' WHERE platform = 'TikTok';
UPDATE social_media SET url = 'https://facebook.com/ihre_feuerwehr' WHERE platform = 'Facebook';
```

## Impressum und Datenschutz anpassen

Bearbeiten Sie die Dateien direkt:
- `impressum.php` - Tragen Sie Ihre Vereinsdaten ein
- `datenschutz.php` - Passen Sie die Datenschutzerklärung an

**Wichtig:** Konsultieren Sie bei Bedarf einen Rechtsanwalt für die rechtskonforme Gestaltung.

## Sicherheitshinweise

### Produktivbetrieb

1. **Fehleranzeige deaktivieren** in `config/config.php`:
   ```php
   error_reporting(0);
   ini_set('display_errors', 0);
   ```

2. **HTTPS aktivieren** - Bei IONOS im Control Panel:
   - SSL-Zertifikat aktivieren (meist kostenfrei via Let's Encrypt)
   - Erzwingen über .htaccess

3. **Starke Passwörter** verwenden für:
   - Datenbank
   - Admin-Accounts
   - FTP/SFTP

4. **Regelmäßige Backups** erstellen:
   - Datenbank (phpMyAdmin → Export)
   - Dateien (FTP-Download)

5. **PHP-Version aktuell halten**
   - Im IONOS Control Panel: PHP-Version auf mind. 7.4 setzen

### .htaccess Sicherheit (empfohlen)

Erstellen Sie eine `.htaccess` im Root-Verzeichnis:

```apache
# HTTPS erzwingen
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Schutz der Config-Dateien
<FilesMatch "^(config|database)\.php$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Verzeichnis-Listing deaktivieren
Options -Indexes
```

## Technologie-Stack

- **Backend:** PHP 7.4+
- **Datenbank:** MariaDB/MySQL
- **Frontend:** HTML5, CSS3, JavaScript (Vanilla)
- **Icons:** Font Awesome 6
- **Responsive:** CSS Grid & Flexbox

## Ordnerstruktur erklärt

```
/admin/              # Geschützter Admin-Bereich
  ├── login.php      # Login-Seite
  ├── dashboard.php  # Dashboard-Übersicht
  ├── operations.php # Einsätze-Verwaltung
  ├── events.php     # Veranstaltungen-Verwaltung
  ├── content.php    # Seiteninhalte-Editor
  ├── board.php      # Vorstandschaft-Verwaltung
  └── ...

/assets/             # Statische Dateien
  ├── css/           # Stylesheets
  ├── js/            # JavaScript
  └── images/        # Logo, Icons etc.

/config/             # Konfigurationsdateien
  ├── config.php     # Allgemeine Einstellungen
  └── database.php   # Datenbank-Verbindung

/database/           # SQL-Dateien
  └── schema.sql     # Datenbank-Schema

/includes/           # PHP-Funktionen
  ├── functions.php  # Hilfsfunktionen
  ├── header.php     # Frontend-Header
  └── footer.php     # Frontend-Footer

/uploads/            # Upload-Verzeichnis (beschreibbar!)
  ├── operations/    # Einsatzbilder
  ├── events/        # Veranstaltungsbilder
  └── board/         # Vorstandsfotos
```

## Wartung & Updates

### Regelmäßige Aufgaben:

1. **Wöchentlich:** Kontaktanfragen prüfen
2. **Monatlich:** Backup erstellen
3. **Quartalsweise:** PHP-Version und Sicherheit prüfen
4. **Jährlich:** Impressum und Datenschutz aktualisieren

### Datenbank-Backup

In phpMyAdmin:
1. Datenbank auswählen
2. "Exportieren" → "Schnell" → "SQL" → "OK"
3. Datei sicher aufbewahren

### Dateien-Backup

Per FTP:
1. Gesamten Webspace-Ordner herunterladen
2. Auf lokalem PC oder Cloud speichern

## Troubleshooting

### Problem: Weiße Seite / Keine Anzeige

**Lösung:**
1. Prüfen Sie `logs/error.log`
2. Aktivieren Sie temporär in `config/config.php`:
   ```php
   ini_set('display_errors', 1);
   ```

### Problem: Bilder können nicht hochgeladen werden

**Lösung:**
1. Prüfen Sie Verzeichnisberechtigungen: `uploads/` muss 755 sein
2. Prüfen Sie PHP `upload_max_filesize` in php.ini
3. Bei IONOS: Control Panel → PHP-Einstellungen → Upload-Limit erhöhen

### Problem: Datenbank-Verbindung fehlgeschlagen

**Lösung:**
1. Prüfen Sie `config/database.php` auf Tippfehler
2. Testen Sie Zugangsdaten in phpMyAdmin
3. Bei IONOS: Host ist meist `localhost`

### Problem: Admin-Login funktioniert nicht

**Lösung:**
1. Prüfen Sie, ob Sessions funktionieren
2. Setzen Sie Passwort zurück (siehe Abschnitt "Passwort ändern")
3. Prüfen Sie, ob User in DB existiert:
   ```sql
   SELECT * FROM users WHERE username = 'admin';
   ```

## Support & Weiterentwicklung

### Geplante Erweiterungen (optional)

- Newsletter-System
- Mitgliederbereich
- Online-Spenden-Integration
- Mehrsprachigkeit
- Bildergalerien mit Tags
- Einsatz-Statistiken
- Kalender-Export (iCal)

### Anpassungen

Alle Farben und Designs können in den CSS-Dateien angepasst werden:
- `assets/css/style.css` - Frontend-Design
- `assets/css/admin.css` - Admin-Design

CSS-Variablen in `:root`:
```css
--primary-color: #d32f2f;    /* Hauptfarbe (Feuerwehr-Rot) */
--secondary-color: #1976d2;  /* Akzentfarbe (Blau) */
```

## Lizenz

Diese Software wurde speziell für freiwillige Feuerwehren entwickelt und darf frei verwendet werden.

## Autor

Entwickelt für die ehrenamtliche Arbeit der freiwilligen Feuerwehren.

---

**Viel Erfolg mit Ihrer neuen Website! 🚒**

Bei Fragen zur Installation auf IONOS Webhosting kontaktieren Sie den IONOS Support oder einen lokalen Webentwickler.
