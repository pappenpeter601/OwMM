<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Datenschutzerklärung - ' . get_org_setting('site_name');

include 'includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <h1>Datenschutzerklärung</h1>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="legal-content">
            <h2>1. Datenschutz auf einen Blick</h2>
            
            <h3>Allgemeine Hinweise</h3>
            <p>
                Die folgenden Hinweise geben einen einfachen Überblick darüber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Website besuchen. Personenbezogene Daten sind alle Daten, mit denen Sie persönlich identifiziert werden können.
            </p>
            
            <h3>Datenerfassung auf dieser Website</h3>
            <h4>Wer ist verantwortlich für die Datenerfassung auf dieser Website?</h4>
            <p>
                Die Datenverarbeitung auf dieser Website erfolgt durch den Websitebetreiber. Dessen Kontaktdaten können Sie dem Impressum dieser Website entnehmen.
            </p>
            
            <h4>Wie erfassen wir Ihre Daten?</h4>
            <p>
                Ihre Daten werden zum einen dadurch erhoben, dass Sie uns diese mitteilen. Hierbei kann es sich z.B. um Daten handeln, die Sie in ein Kontaktformular eingeben.
            </p>
            
            <h2>2. Hosting</h2>
            <p>
                Diese Website wird bei IONOS gehostet. Anbieter ist die 1&1 IONOS SE, Elgendorfer Str. 57, 56410 Montabaur (nachfolgend IONOS). Wenn Sie diese Website besuchen, erfasst IONOS verschiedene Logfiles inklusive Ihrer IP-Adressen.
            </p>
            
            <h2>3. Allgemeine Hinweise und Pflichtinformationen</h2>
            
            <h3>Datenschutz</h3>
            <p>
                Die Betreiber dieser Seiten nehmen den Schutz Ihrer persönlichen Daten sehr ernst. Wir behandeln Ihre personenbezogenen Daten vertraulich und entsprechend der gesetzlichen Datenschutzvorschriften sowie dieser Datenschutzerklärung.
            </p>
            
            <h3>Hinweis zur verantwortlichen Stelle</h3>
            <p>
                Die verantwortliche Stelle für die Datenverarbeitung auf dieser Website ist:<br><br>
                <?php echo get_org_setting('site_name'); ?><br>
                [Ihre Adresse]<br>
                Telefon: [Ihre Telefonnummer]<br>
                E-Mail: <?php echo get_org_setting('admin_email'); ?>
            </p>
            
            <h2>4. Datenerfassung auf dieser Website</h2>
            
            <h3>Server-Log-Dateien</h3>
            <p>
                Der Provider der Seiten erhebt und speichert automatisch Informationen in so genannten Server-Log-Dateien, die Ihr Browser automatisch an uns übermittelt. Dies sind:
            </p>
            <ul>
                <li>Browsertyp und Browserversion</li>
                <li>verwendetes Betriebssystem</li>
                <li>Referrer URL</li>
                <li>Hostname des zugreifenden Rechners</li>
                <li>Uhrzeit der Serveranfrage</li>
                <li>IP-Adresse</li>
            </ul>
            
            <h3>Kontaktformular</h3>
            <p>
                Wenn Sie uns per Kontaktformular Anfragen zukommen lassen, werden Ihre Angaben aus dem Anfrageformular inklusive der von Ihnen dort angegebenen Kontaktdaten zwecks Bearbeitung der Anfrage und für den Fall von Anschlussfragen bei uns gespeichert. Diese Daten geben wir nicht ohne Ihre Einwilligung weiter.
            </p>
            
            <h2>5. Ihre Rechte</h2>
            <p>
                Sie haben das Recht:
            </p>
            <ul>
                <li>Auskunft über Ihre bei uns gespeicherten Daten zu verlangen</li>
                <li>Die Berichtigung unrichtiger Daten zu verlangen</li>
                <li>Die Löschung Ihrer Daten zu verlangen</li>
                <li>Die Einschränkung der Datenverarbeitung zu verlangen</li>
                <li>Der Datenverarbeitung zu widersprechen</li>
                <li>Ihre Daten in einem strukturierten, gängigen Format zu erhalten</li>
            </ul>
            
            <p>
                Zur Ausübung Ihrer Rechte wenden Sie sich bitte an: <?php echo get_org_setting('admin_email'); ?>
            </p>
            
            <h2>6. Änderungen dieser Datenschutzerklärung</h2>
            <p>
                Wir behalten uns vor, diese Datenschutzerklärung anzupassen, damit sie stets den aktuellen rechtlichen Anforderungen entspricht oder um Änderungen unserer Leistungen in der Datenschutzerklärung umzusetzen.
            </p>
            
            <p><em>Stand: <?php echo date('d.m.Y'); ?></em></p>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
