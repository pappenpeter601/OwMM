<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Format retry wait in user-friendly time
if (!function_exists('format_retry_wait')) {
    function format_retry_wait($seconds) {
        if ($seconds < 60) {
            return $seconds . ' Sekunde' . ($seconds !== 1 ? 'n' : '');
        } elseif ($seconds < 3600) {
            $minutes = ceil($seconds / 60);
            return $minutes . ' Minute' . ($minutes !== 1 ? 'n' : '');
        } else {
            $hours = ceil($seconds / 3600);
            return $hours . ' Stunde' . ($hours !== 1 ? 'n' : '');
        }
    }
}

$page_title = 'Kontakt - ' . get_org_setting('site_name');

$success = false;
$error = '';

// Initialize form timing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['contact_form_time'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Anti-bot: honeypot must remain empty
    if (!empty($_POST['homepage'] ?? '')) {
        $error = 'Bitte bestätigen Sie, dass Sie kein Bot sind.';
    }

    // Anti-bot: minimal time on page
    if (!$error) {
        $minSeconds = 4;
        if (empty($_SESSION['contact_form_time']) || (time() - (int)$_SESSION['contact_form_time']) < $minSeconds) {
            $error = 'Bitte bestätigen Sie, dass Sie kein Bot sind.';
        }
    }

    // Anti-bot: optional Turnstile verification
    if (!$error && defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '') {
        $cfResponse = $_POST['cf-turnstile-response'] ?? '';
        if (!verify_turnstile_token($cfResponse)) {
            $error = 'Bitte bestätigen Sie, dass Sie kein Bot sind.';
        }
    }

    // Global IP rate limit: 5 messages per hour
    if (!$error) {
        $rl = rate_limit_allow('contact', 5, 60 * 60);
        if (!$rl['allowed']) {
            $wait_time = format_retry_wait($rl['retry_after']);
            $error = 'Zu viele Anfragen. Bitte warten Sie ' . $wait_time . '.';
        }
    }

    // Stop if any anti-bot or rate-limit error occurred
    if (!$error) {
        $name = sanitize_input($_POST['name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $subject = sanitize_input($_POST['subject'] ?? '');
        $message = $_POST['message'] ?? '';

        // Validate
        if (empty($name) || empty($email) || empty($subject) || empty($message)) {
            $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        } else {
            // Save to database
            $db = getDBConnection();
            $stmt = $db->prepare("INSERT INTO contact_messages (name, email, phone, subject, message, ip_address) 
                                  VALUES (:name, :email, :phone, :subject, :message, :ip)");
            
            $stmt->execute([
                'name' => $name,
                'email' => (string)$email,
                'phone' => $phone,
                'subject' => $subject,
                'message' => $message,
                'ip' => get_client_ip()
            ]);
            
            // Send email notification to admin
            $email_message = "Neue Kontaktanfrage von der Website:\n\n";
            $email_message .= "Name: $name\n";
            $email_message .= "E-Mail: $email\n";
            $email_message .= "Telefon: $phone\n";
            $email_message .= "Betreff: $subject\n\n";
            $email_message .= "Nachricht:\n$message\n";
            
            send_email(get_org_setting('admin_email'), "Neue Kontaktanfrage: $subject", $email_message);
            
            $success = true;
        }
    }
}

$contact_info = get_page_content('contact_info');

include 'includes/header.php';
?>

<style>
/* Hide honeypot field */
.hp-field {
    position: absolute !important;
    left: -5000px !important;
    width: 1px !important;
    height: 1px !important;
    overflow: hidden !important;
}
</style>

<section class="page-header-section">
    <div class="container">
        <h1>Kontakt</h1>
        <p>Nehmen Sie Kontakt mit uns auf</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="contact-grid">
            <div class="contact-info-box">
                <h2>Kontaktinformationen</h2>
                
                <div class="info-item">
                    <i class="fas fa-phone-alt"></i>
                    <div>
                        <h4>Notruf</h4>
                        <p><strong>112</strong></p>
                    </div>
                </div>
                
                <?php if (!empty($contact_info['content'])): ?>
                    <div class="info-content">
                        <?php echo nl2br(htmlspecialchars($contact_info['content'])); ?>
                    </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <h4>E-Mail</h4>
                        <p><a href="mailto:<?php echo get_org_setting('admin_email'); ?>"><?php echo get_org_setting('admin_email'); ?></a></p>
                    </div>
                </div>
                
                <div class="social-links-box">
                    <h4>Folgen Sie uns</h4>
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
            
            <div class="contact-form-box">
                <h2>Nachricht senden</h2>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        Vielen Dank für Ihre Nachricht! Wir melden uns so schnell wie möglich bei Ihnen.
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!$success): ?>
                <form method="POST" action="" class="contact-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Name *</label>
                            <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-Mail *</label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Telefon</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="subject">Betreff *</label>
                            <input type="text" id="subject" name="subject" required value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="message">Nachricht *</label>
                        <textarea id="message" name="message" rows="6" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Honeypot field: should remain empty -->
                    <div class="hp-field" aria-hidden="true">
                        <label for="homepage">Homepage</label>
                        <input type="text" id="homepage" name="homepage" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== ''): ?>
                        <div class="form-group">
                            <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars(TURNSTILE_SITE_KEY); ?>"></div>
                        </div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-primary btn-lg">Nachricht senden</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
