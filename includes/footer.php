    </main>
    
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-section">
                    <h3><?php echo SITE_NAME; ?></h3>
                    <p>Rund um die Uhr für Ihre Sicherheit im Einsatz.</p>
                    <div class="emergency-number">
                        <i class="fas fa-phone-alt"></i>
                        <span>Notruf: 112</span>
                    </div>
                </div>
                
                <div class="footer-section">
                    <h4>Navigation</h4>
                    <ul>
                        <li><a href="index.php">Start</a></li>
                        <li><a href="operations.php">Einsätze</a></li>
                        <li><a href="events.php">Veranstaltungen</a></li>
                        <li><a href="board.php">Vorstandschaft</a></li>
                        <li><a href="contact.php">Kontakt</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4>Rechtliches</h4>
                    <ul>
                        <li><a href="impressum.php">Impressum</a></li>
                        <li><a href="datenschutz.php">Datenschutz</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4>Social Media</h4>
                    <div class="social-links">
                        <?php
                        $social_media = get_social_media();
                        foreach ($social_media as $social):
                        ?>
                            <a href="<?php echo htmlspecialchars($social['url']); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($social['platform']); ?>">
                                <i class="<?php echo htmlspecialchars($social['icon_class']); ?>"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. Alle Rechte vorbehalten.</p>
                <p><a href="admin/login.php">Admin-Bereich</a></p>
            </div>
        </div>
    </footer>
    
    <script src="assets/js/main.js"></script>
</body>
</html>
