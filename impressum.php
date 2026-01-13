<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Impressum - ' . SITE_NAME;

include 'includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <h1>Impressum</h1>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="legal-content">
            <h2>Angaben gemäß § 5 TMG</h2>
            <p>
                <strong>Freiwillige Feuerwehr Meinern-Mittelstendorf</strong><br>
                Nottorfweg 3A<br>
                29614 Soltau<br>
            </p>
            
            <h3>Vertreten durch:</h3>
            <p>
                Marc Grünhagen<br>
                Ortsbrandmeister
            </p>
            
            <h3>Kontakt:</h3>
            <p>
                Telefon: 0174 9778157<br>
                E-Mail: kommando@owmm.de
            </p>
            
            <h3>Verantwortlich für den Inhalt nach § 55 Abs. 2 RStV:</h3>
            <p>
                Peter Scharringhausen<br>
                Wüsthof 1<br>
                29614 Soltau<br>
                Telefon: 01520 89 879 31<br>
                E-Mail: kommando@owmm.de
            </p>
            
            <h2>Haftungsausschluss</h2>
            
            <h3>Haftung für Inhalte</h3>
            <p>
                Die Inhalte unserer Seiten wurden mit größter Sorgfalt erstellt. Für die Richtigkeit, Vollständigkeit und Aktualität der Inhalte können wir jedoch keine Gewähr übernehmen. Als Diensteanbieter sind wir gemäß § 7 Abs.1 TMG für eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 TMG sind wir als Diensteanbieter jedoch nicht verpflichtet, übermittelte oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu forschen, die auf eine rechtswidrige Tätigkeit hinweisen. Verpflichtungen zur Entfernung oder Sperrung der Nutzung von Informationen nach den allgemeinen Gesetzen bleiben hiervon unberührt.
            </p>
            
            <h3>Haftung für Links</h3>
            <p>
                Unser Angebot enthält Links zu externen Webseiten Dritter, auf deren Inhalte wir keinen Einfluss haben. Deshalb können wir für diese fremden Inhalte auch keine Gewähr übernehmen. Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder Betreiber der Seiten verantwortlich.
            </p>
            
            <h3>Urheberrecht</h3>
            <p>
                Die durch die Seitenbetreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht. Die Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen des Urheberrechtes bedürfen der schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers.
            </p>
            
            <h2>Bildnachweise</h2>
            <p>
                Alle auf dieser Website verwendeten Bilder sind Eigentum von <?php echo SITE_NAME; ?> oder wurden mit entsprechender Lizenz verwendet.
            </p>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
