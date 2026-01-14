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
}
?>
