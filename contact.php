<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Kontakt - ' . get_org_setting('site_name');

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            'email' => $email,
            'phone' => $phone,
            'subject' => $subject,
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR']
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

$contact_info = get_page_content('contact_info');

include 'includes/header.php';
?>

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
                    
                    <button type="submit" class="btn btn-primary btn-lg">Nachricht senden</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
