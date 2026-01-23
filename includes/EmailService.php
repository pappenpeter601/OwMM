<?php
/**
 * Email Service for OwMM Feuerwehr System
 * Handles email sending for magic link authentication, registration approvals
 */

require_once __DIR__ . '/SMTPClient.php';
require_once __DIR__ . '/EmailTemplates.php';
require_once __DIR__ . '/../config/database.php';

class EmailService {
    private $smtpClient;
    private $config;
    private $pdo;
    
    public function __construct() {
        // Get database connection
        $this->pdo = getDBConnection();
        
        // Load email configuration from database
        $this->loadEmailConfig();
        
        // Initialize SMTP client
        if ($this->config['host']) {
            $this->smtpClient = new SMTPClient(
                $this->config['host'],
                $this->config['port'],
                $this->config['username'],
                $this->config['password'],
                $this->config['use_tls']
            );
        }
    }
    
    /**
     * Load email configuration from database
     */
    private function loadEmailConfig() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM email_config WHERE id = 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config) {
                // Decrypt password if encrypted (you might want to implement proper encryption)
                $password = $config['smtp_password'];
                
                $this->config = [
                    'host' => $config['smtp_host'],
                    'port' => (int)$config['smtp_port'],
                    'username' => $config['smtp_username'],
                    'password' => $password,
                    'from_email' => $config['from_email'],
                    'from_name' => $config['from_name'],
                    'use_tls' => (bool)$config['use_tls']
                ];
            } else {
                throw new Exception("Email configuration not found in database");
            }
        } catch (Exception $e) {
            error_log("Failed to load email config: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Send magic link email to user
     * 
     * @param string $email User email address
     * @param string $firstName User first name
     * @param string $token Magic link token
     * @return array Success/error response
     */
    public function sendMagicLink($email, $firstName, $token) {
        try {
            if (!$this->smtpClient) {
                throw new Exception("SMTP client not configured");
            }
            
            $subject = "Ihr Anmelde-Link für das OwMM Feuerwehr-System";
            $htmlBody = EmailTemplates::generateMagicLinkEmail($firstName, $token);
            
            $result = $this->smtpClient->send(
                $this->config['from_email'],
                $this->config['from_name'],
                $email,
                $subject,
                $htmlBody,
                null, // No reply-to
                null, // No CC
                true  // isHtml
            );
            
            return ['success' => true, 'message' => 'Magic link email erfolgreich gesendet'];
        } catch (Exception $e) {
            error_log("Magic link email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send registration confirmation email to user
     * 
     * @param string $email User email address
     * @param string $firstName User first name
     * @param string $verificationToken Email verification token
     * @return array Success/error response
     */
    public function sendRegistrationConfirmation($email, $firstName, $verificationToken) {
        try {
            if (!$this->smtpClient) {
                throw new Exception("SMTP client not configured");
            }
            
            $subject = "Registrierung bestätigen - OwMM Feuerwehr-System";
            $htmlBody = EmailTemplates::generateRegistrationEmail($firstName, $verificationToken);
            
            $result = $this->smtpClient->send(
                $this->config['from_email'],
                $this->config['from_name'],
                $email,
                $subject,
                $htmlBody,
                null,
                null,
                true
            );
            
            return ['success' => true, 'message' => 'Bestätigungs-Email erfolgreich gesendet'];
        } catch (Exception $e) {
            error_log("Registration confirmation email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send approval notification email to user
     * 
     * @param string $email User email address
     * @param string $firstName User first name
     * @param bool $approved Whether registration was approved or rejected
     * @return array Success/error response
     */
    public function sendApprovalNotification($email, $firstName, $approved = true) {
        try {
            if (!$this->smtpClient) {
                throw new Exception("SMTP client not configured");
            }
            
            $subject = $approved 
                ? "Ihr Zugang wurde genehmigt - OwMM Feuerwehr-System"
                : "Registrierungsantrag abgelehnt - OwMM Feuerwehr-System";
            
            $htmlBody = EmailTemplates::generateApprovalEmail($firstName, $approved);
            
            $result = $this->smtpClient->send(
                $this->config['from_email'],
                $this->config['from_name'],
                $email,
                $subject,
                $htmlBody,
                null,
                null,
                true
            );
            
            return ['success' => true, 'message' => 'Genehmigungs-Email erfolgreich gesendet'];
        } catch (Exception $e) {
            error_log("Approval notification email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to admin about new registration request
     * 
     * @param string $email User email address
     * @param string $firstName User first name
     * @param string $lastName User last name
     * @return array Success/error response
     */
    public function sendAdminRegistrationNotification($email, $firstName, $lastName) {
        try {
            if (!$this->smtpClient) {
                throw new Exception("SMTP client not configured");
            }
            
            // Send to configured from_email (assuming this is admin email)
            $subject = "Neue Registrierungsanfrage - OwMM Feuerwehr-System";
            $htmlBody = EmailTemplates::generateAdminNotificationEmail($email, $firstName, $lastName);
            
            $result = $this->smtpClient->send(
                $this->config['from_email'],
                $this->config['from_name'],
                $this->config['from_email'], // Send to self (admin)
                $subject,
                $htmlBody,
                null,
                null,
                true
            );
            
            return ['success' => true, 'message' => 'Admin-Benachrichtigung erfolgreich gesendet'];
        } catch (Exception $e) {
            error_log("Admin notification email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test email configuration by sending test email
     * 
     * @param string $testEmail Email address to send test to
     * @return array Success/error response
     */
    public function sendTestEmail($testEmail) {
        try {
            if (!$this->smtpClient) {
                throw new Exception("SMTP client not configured");
            }
            
            $subject = "Test-Email - OwMM Feuerwehr-System";
            $htmlBody = EmailTemplates::generateTestEmail();
            
            $result = $this->smtpClient->send(
                $this->config['from_email'],
                $this->config['from_name'],
                $testEmail,
                $subject,
                $htmlBody,
                null,
                null,
                true
            );
            
            return ['success' => true, 'message' => 'Test-Email erfolgreich gesendet'];
        } catch (Exception $e) {
            error_log("Test email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send payment reminder email to member
     * 
     * @param array $member Member data with all attributes
     * @param array $obligation Obligation data including last_payment_date
     * @param string $ccEmail CC email address (required)
     * @param int $userId User ID who triggered the send
     * @param string $reminderType Type of reminder (first, second, final, custom)
     * @param array $contactPerson Contact person data (active member) with name/email/mobile
     * @return array Success/error response with reminder_id
     */
    public function sendPaymentReminder($member, $obligation, $ccEmail, $userId = null, $reminderType = 'first', $contactPerson = []) {
        try {
            if (!$this->smtpClient) {
                throw new Exception("SMTP client not configured");
            }

            if (empty($ccEmail)) {
                throw new Exception("CC-Empfänger ist erforderlich");
            }

            if (empty($contactPerson)) {
                throw new Exception("Kontaktperson ist erforderlich");
            }
            
            // Validate member has email
            if (empty($member['email'])) {
                throw new Exception("Member has no email address");
            }
            
            // Get payment configuration from organization table (includes bank owner)
            $stmt = $this->pdo->query("SELECT name, legal_name, iban, paypal_link, bank_owner FROM organization WHERE id = 1");
            $paymentConfig = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
            // Fallback to email_config if organization not configured for payment details
            if (empty($paymentConfig['iban']) && empty($paymentConfig['paypal_link'])) {
                $stmt = $this->pdo->query("SELECT owmm_iban, paypal_link FROM email_config WHERE id = 1");
                $fallback = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $paymentConfig['iban'] = $paymentConfig['iban'] ?? $fallback['owmm_iban'] ?? 'DE89 3704 0044 0532 0130 00';
                $paymentConfig['paypal_link'] = $paymentConfig['paypal_link'] ?? $fallback['paypal_link'] ?? 'https://paypal.me/owmm';
            }
            
            $iban = $paymentConfig['iban'] ?? 'DE89 3704 0044 0532 0130 00';
            $paypalLink = $paymentConfig['paypal_link'] ?? 'https://paypal.me/owmm';
            $bankOwner = $paymentConfig['bank_owner'] ?? $paymentConfig['legal_name'] ?? $paymentConfig['name'] ?? 'OwMM';
            $orgName = $paymentConfig['name'] ?? $paymentConfig['legal_name'] ?? 'OwMM';
            
            // Generate PayPal payment link with amount and QR code
            $paypalPaymentLink = $paypalLink . '/' . number_format($obligation['fee_amount'], 2);
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paypalPaymentLink);

            // Prepare contact person details
            $contactName = trim(($contactPerson['first_name'] ?? '') . ' ' . ($contactPerson['last_name'] ?? ''));
            if (empty($contactName)) {
                $contactName = $contactPerson['name'] ?? 'Ansprechpartner';
            }
            $contactEmail = $contactPerson['email'] ?? '';
            $contactMobile = $contactPerson['mobile'] ?? ($contactPerson['telephone'] ?? '');
            
            // Select template based on member type
            if ($member['member_type'] === 'active') {
                $htmlBody = EmailTemplates::generatePaymentReminderActive(
                    $member,
                    $obligation,
                    $iban,
                    $bankOwner,
                    $paypalPaymentLink,
                    $qrCodeUrl,
                    [
                        'name' => $contactName,
                        'email' => $contactEmail,
                        'mobile' => $contactMobile
                    ]
                );
                $templateUsed = 'payment_reminder_active';
                $subject = 'Erinnerung: Mitgliedsbeitrag ' . $obligation['fee_year'];
            } else {
                $htmlBody = EmailTemplates::generatePaymentReminderSupporter(
                    $member,
                    $obligation,
                    $iban,
                    $bankOwner,
                    $paypalPaymentLink,
                    $qrCodeUrl,
                    $orgName,
                    [
                        'name' => $contactName,
                        'email' => $contactEmail,
                        'mobile' => $contactMobile
                    ]
                );
                $templateUsed = 'payment_reminder_supporter';
                $subject = 'Zahlungserinnerung: Förderbeitrag ' . $obligation['fee_year'];
            }
            
            // Send email
            $result = $this->smtpClient->send(
                $this->config['from_email'],
                $this->config['from_name'],
                $member['email'],
                $subject,
                $htmlBody,
                null, // No reply-to
                $ccEmail, // CC if provided
                true  // isHtml
            );
            
            // Log the reminder in database
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_reminders 
                (member_id, obligation_id, reminder_type, sent_to_email, cc_email, 
                 template_used, email_subject, sent_by, success, error_message)
                VALUES (:member_id, :obligation_id, :reminder_type, :sent_to_email, :cc_email,
                        :template_used, :email_subject, :sent_by, :success, :error_message)
            ");
            
            $stmt->execute([
                'member_id' => $member['id'],
                'obligation_id' => $obligation['id'],
                'reminder_type' => $reminderType,
                'sent_to_email' => $member['email'],
                'cc_email' => $ccEmail,
                'template_used' => $templateUsed,
                'email_subject' => $subject,
                'sent_by' => $userId,
                'success' => 1,
                'error_message' => null
            ]);
            
            $reminderId = $this->pdo->lastInsertId();
            
            return [
                'success' => true, 
                'message' => 'Zahlungserinnerung erfolgreich versendet',
                'reminder_id' => $reminderId,
                'recipient' => $member['email']
            ];
            
        } catch (Exception $e) {
            // Log failed attempt
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO payment_reminders 
                    (member_id, obligation_id, reminder_type, sent_to_email, cc_email, 
                     template_used, email_subject, sent_by, success, error_message)
                    VALUES (:member_id, :obligation_id, :reminder_type, :sent_to_email, :cc_email,
                            :template_used, :email_subject, :sent_by, :success, :error_message)
                ");
                
                $stmt->execute([
                    'member_id' => $member['id'] ?? null,
                    'obligation_id' => $obligation['id'] ?? null,
                    'reminder_type' => $reminderType,
                    'sent_to_email' => $member['email'] ?? null,
                    'cc_email' => $ccEmail,
                    'template_used' => $member['member_type'] === 'active' ? 'payment_reminder_active' : 'payment_reminder_supporter',
                    'email_subject' => 'Zahlungserinnerung ' . ($obligation['fee_year'] ?? ''),
                    'sent_by' => $userId,
                    'success' => 0,
                    'error_message' => $e->getMessage()
                ]);
            } catch (Exception $logError) {
                error_log("Failed to log payment reminder error: " . $logError->getMessage());
            }
            
            error_log("Payment reminder email error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send payment reminders to multiple members (bulk send)
     * 
     * @param array $membersWithObligations Array of [member, obligation] pairs
     * @param string $ccEmail Optional CC email address
     * @param int $userId User ID who triggered the send
     * @param string $reminderType Type of reminder
     * @return array Results with success/failure counts and details
     */
    public function sendBulkPaymentReminders($membersWithObligations, $ccEmail, $userId = null, $reminderType = 'first', $contactPerson = []) {
        if (empty($ccEmail)) {
            throw new Exception("CC-Empfänger ist erforderlich");
        }

        if (empty($contactPerson)) {
            throw new Exception("Kontaktperson ist erforderlich");
        }

        $results = [
            'total' => count($membersWithObligations),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => []
        ];
        
        foreach ($membersWithObligations as $data) {
            $member = $data['member'];
            $obligation = $data['obligation'];
            
            // Skip members without email
            if (empty($member['email'])) {
                $results['skipped']++;
                $results['details'][] = [
                    'member_id' => $member['id'],
                    'member_name' => $member['first_name'] . ' ' . $member['last_name'],
                    'status' => 'skipped',
                    'reason' => 'Keine E-Mail-Adresse'
                ];
                continue;
            }
            
            // Send reminder
            $result = $this->sendPaymentReminder($member, $obligation, $ccEmail, $userId, $reminderType, $contactPerson);
            
            if ($result['success']) {
                $results['success']++;
                $results['details'][] = [
                    'member_id' => $member['id'],
                    'member_name' => $member['first_name'] . ' ' . $member['last_name'],
                    'status' => 'success',
                    'email' => $member['email'],
                    'reminder_id' => $result['reminder_id']
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'member_id' => $member['id'],
                    'member_name' => $member['first_name'] . ' ' . $member['last_name'],
                    'status' => 'failed',
                    'error' => $result['error']
                ];
            }
            
            // Small delay to prevent overwhelming SMTP server
            usleep(100000); // 0.1 second delay between emails
        }
        
        return $results;
    }
}
?>
