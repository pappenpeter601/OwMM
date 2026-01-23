# Baikal CalDAV Server Setup Guide für IONOS

Dieses Dokument beschreibt die Installation und Konfiguration eines eigenen Baikal CalDAV-Servers auf IONOS für die gemeinsame Kalendarverwaltung.

## 1. Voraussetzungen

- IONOS Webhosting mit PHP 7.4+ und MySQL/MariaDB
- SSH/Terminal-Zugriff (oder File Manager via IONOS Control Panel)
- Administrativer Zugriff auf die Baikal-Konfiguration

## 2. Baikal Installation auf IONOS

### 2.1 Baikal herunterladen und hochladen

1. **Baikal besorgen**
   ```bash
   # Lokal herunterladen:
   wget https://github.com/sabre-io/Baikal/releases/download/0.9.3/baikal-0.9.3.zip
   unzip baikal-0.9.3.zip
   ```

2. **In IONOS hochladen**
   - Per SFTP oder File Manager in `public_html/baikal/` hochladen
   - Oder Verzeichnis `baikal` anlegen und Inhalte dorthin laden
   - **Wichtig**: Die `.htaccess` Datei aus dem Repository (`baikal/.htaccess`) ebenfalls hochladen

3. **Verzeichnisrechte setzen** (IONOS FTP/SFTP)
   ```bash
   chmod -R 755 public_html/baikal/
   chmod -R 775 public_html/baikal/Specific/
   chmod -R 775 public_html/baikal/config/
   ```

### 2.2 Datenbank erstellen (IONOS)

1. Ins IONOS Kundencenter einloggen
2. Zu **Verwaltung → Datenbanken** navigieren
3. **Neue Datenbank** erstellen:
   - Name: z.B. `baikal_db`
   - Benutzer: z.B. `baikal_user`
   - Passwort: sicheres Passwort generieren
4. Notizen: Hostname (z.B. `sql.owmm.de`), Benutzer, Passwort

### 2.3 Baikal Web-Setup durchführen

1. **Browser öffnen**: `https://owmm.de/baikal/`
2. **Setup-Seite folgen**:
   - Sprache: Deutsch
   - **Database settings**:
     - Type: MySQL/MariaDB
     - Hostname: von IONOS (z.B. `sql.owmm.de`)
     - Username: `baikal_user`
     - Password: Passwort von oben
     - Database name: `baikal_db`
   - **Baikal settings**:
     - Admin login: `admin`
     - Admin password: sicheres Passwort
3. **Finish** klicken → Baikal ist installiert

## 3. Baikal-Verwaltung: Kalender und Benutzer anlegen

### 3.1 Als Admin in Baikal einloggen

1. **Baikal Admin öffnen**: `https://owmm.de/baikal/admin/`
2. Login: `admin` / dein Admin-Passwort

### 3.2 Benutzer für gemeinsamen Kalender anlegen

1. **Benutzer** → **Neuer Benutzer**
   - Username: `owmm` oder `owmm_calendar`
   - Password: sicheres Passwort (wird später in OWMM-App eingetragen)
   - Speichern

### 3.3 Kalender im Benutzer erstellen

1. **Benutzer** → `owmm` auswählen
2. **Kalender (CalDAV)** → **Neuer Kalender**
   - Name: `OWMM Kalender`
   - Description: `Gemeinsamer Kalender für Feuerwehr OWMM`
   - Speichern

### 3.4 Kalender-URL notieren

Nach dem Speichern sollte die URL angezeigt werden, z.B.:
```
https://owmm.de/baikal/cal.php/calendars/owmm/owmm-kalender/
```

**Wichtig**: Diese URL wird für die Konfiguration in der OWMM-App benötigt.

## 4. OWMM-App Konfiguration

### 4.1 Im Admin-Panel: Kalender-Einstellungen

1. **Admin → Kalender → Einstellungen**
2. Ausfüllen:
   - **Server-URL**: `https://owmm.de/baikal`
   - **Kalender-Pfad**: `/cal.php/calendars/owmm/owmm-kalender/`
   - **Benutzername**: `owmm`
   - **Passwort**: (vom Baikal-Benutzer oben)
   - **Anzeigename**: `OWMM Kalender`
3. **Speichern**

### 4.2 Test: Termin anlegen

1. **Admin → Kalender**
2. **Termin anlegen** (Beispiel):
   - Titel: `Testtermin`
   - Beginn: Heute 14:00
   - Ende: Heute 15:00
3. **Anlegen** klicken
4. Termin sollte auf der Liste erscheinen

## 5. Smartphone-Setup (für Mitglieder)

### iOS

1. **Einstellungen → Kontakte → Konten & Passwörter**
2. **Konto hinzufügen** → **Andere** → **CalDAV-Konto**
3. Ausfüllen:
   - Server: `owmm.de` (oder volle URL: `owmm.de/baikal`)
   - Benutzername: `owmm`
   - Passwort: (von Baikal)
   - Beschreibung: `OWMM Kalender`
4. **Weiter** → iOS sucht und findet den Kalender automatisch
5. **Fertig** → Kalender sollte in der Kalender-App sichtbar sein

### Android

1. **Google Kalender** App öffnen (oder alternative CalDAV-App wie **Davx5** oder **FairEmail**)
2. Wenn native Android-Integration:
   - Über **Einstellungen → Konten** einen CalDAV-Account hinzufügen
3. **Davx5** (empfohlen, kostenlos):
   - App installieren
   - **Neues Konto** → **CalDAV**
   - Server: `owmm.de/baikal`
   - Username: `owmm`
   - Password: (von Baikal)
   - **Verbindung testen** → sollte erfolgreich sein
   - **Erstellen**
4. Kalender wird dann in Google Kalender oder der Standard-Kalender-App angezeigt

## 6. SSL-Zertifikat und HTTPS

- IONOS stellt kostenlose Let's Encrypt SSL-Zertifikate zur Verfügung
- **Wichtig**: CalDAV erfordert HTTPS für sichere Verbindungen
- In IONOS Control Panel:
  - **SSL** → **Let's Encrypt kostenlos aktivieren**
  - Automatische Erneuerung aktivieren

## 7. Sicherheitsempfehlungen

1. **Admin-Passwort**: Stark und regelmäßig wechseln
2. **Benutzer-Passwort**: Für Kalender-Sharing nur bei Bedarf in der OWMM-App speichern
3. **Backups**: Regelmäßige Datensicherung der Baikal-Datenbank
4. **Zugriff einschränken**: Optional: In IONOS die `/baikal/admin/` nur für IP-Adressen erlauben

## 8. Fehlerbehebung

### Problem: "ZUGRIFF NICHT ERLAUBT" oder "403 Forbidden"
- **Lösung 1**: `.htaccess` Datei im `baikal/` Verzeichnis prüfen (siehe Repository: `baikal/.htaccess`)
- **Lösung 2**: Dateirechte prüfen: `chmod 644 baikal/.htaccess`
- **Lösung 3**: IONOS Verzeichnisschutz deaktivieren (Control Panel → Sicherheit → Verzeichnisschutz)

### Problem: "403 Forbidden" (allgemein)
- **Lösung**: Dateirechte prüfen (siehe 2.1.3)

### Problem: "Datenbank-Verbindungsfehler"
- **Lösung**: Hostname, Benutzer, Passwort und Datenbankname überprüfen

### Problem: "Kalender auf Smartphone nicht sichtbar"
- **Lösung**: Baikal-URL direkt eingeben (z.B. `owmm.de/baikal/cal.php/calendars/owmm/owmm-kalender/`)

### Problem: "Termin wird nicht synchronisiert"
- **Lösung**: Smartphone neu verbinden oder App neu starten

## 9. Weitere Ressourcen

- **Baikal Doku**: https://sabre.io/baikal/
- **IONOS Support**: https://www.ionos.de/hosting/support

---

**Hinweis**: Diese Anleitung ist spezifisch für IONOS-Hosting. Bei anderen Hosting-Providern können die Schritte leicht abweichen.
