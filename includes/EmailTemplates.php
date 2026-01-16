<?php
/**
 * Email Templates for OwMM Feuerwehr System
 * Professional HTML email templates for authentication and registration
 */

class EmailTemplates {
    
    /**
     * Get the base URL for magic link verification
     */
    private static function getBaseUrl() {
        // You may want to configure this in the database or config file
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
    
    /**
     * Generate HTML email base structure
     */
    public static function getHtmlBase($title, $content) {
        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .content { padding: 10px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #dc2626, #ef4444); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: normal;">OwMM Feuerwehr</h1>
                            <p style="margin: 10px 0 0 0; color: #f4f4f4; font-size: 16px;">Verwaltungssystem</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding: 30px;">
                            ' . $content . '
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f8f8; padding: 25px; text-align: center; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0 0 10px 0; font-size: 14px; color: #555;">
                                <strong>OwMM Feuerwehr Verwaltungssystem</strong>
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #888;">
                                Diese E-Mail wurde automatisch generiert. Bitte nicht antworten.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Generate magic link email
     */
    public static function generateMagicLinkEmail($firstName, $token) {
        $baseUrl = self::getBaseUrl();
        $magicLink = $baseUrl . '/verify_magiclink.php?token=' . urlencode($token);
        
        $content = '
            <h2 style="color: #dc2626; margin-top: 0;">Hallo ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '!</h2>
            
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                Sie haben einen Anmelde-Link angefordert. Klicken Sie auf den Button unten, um sich anzumelden:
            </p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($magicLink, ENT_QUOTES, 'UTF-8') . '" 
                   style="display: inline-block; padding: 15px 40px; background-color: #dc2626; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                    Jetzt anmelden
                </a>
            </div>
            
            <p style="font-size: 14px; color: #666; line-height: 1.6;">
                <strong>Wichtig:</strong> Dieser Link ist nur 15 Minuten gültig und kann nur einmal verwendet werden.
            </p>
            
            <p style="font-size: 14px; color: #666; line-height: 1.6;">
                Falls Sie keine Anmeldung angefordert haben, können Sie diese E-Mail ignorieren.
            </p>
            
            <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 25px 0;">
            
            <p style="font-size: 12px; color: #999;">
                Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:<br>
                <a href="' . htmlspecialchars($magicLink, ENT_QUOTES, 'UTF-8') . '" style="color: #dc2626; word-break: break-all;">
                    ' . htmlspecialchars($magicLink, ENT_QUOTES, 'UTF-8') . '
                </a>
            </p>
        ';
        
        return self::getHtmlBase('Anmelde-Link', $content);
    }
    
    /**
     * Generate registration confirmation email
     */
    public static function generateRegistrationEmail($firstName, $verificationToken) {
        $baseUrl = self::getBaseUrl();
        $verifyLink = $baseUrl . '/verify_registration.php?token=' . urlencode($verificationToken);
        
        $content = '
            <h2 style="color: #dc2626; margin-top: 0;">Willkommen ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '!</h2>
            
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                Vielen Dank für Ihre Registrierung im OwMM Feuerwehr Verwaltungssystem.
            </p>
            
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                Bitte bestätigen Sie Ihre E-Mail-Adresse, indem Sie auf den Button unten klicken:
            </p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '" 
                   style="display: inline-block; padding: 15px 40px; background-color: #dc2626; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                    E-Mail bestätigen
                </a>
            </div>
            
            <p style="font-size: 14px; color: #666; line-height: 1.6;">
                Nach der Bestätigung wird Ihre Registrierung von einem Administrator geprüft. Sie erhalten eine weitere E-Mail, sobald Ihr Zugang freigeschaltet wurde.
            </p>
            
            <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 25px 0;">
            
            <p style="font-size: 12px; color: #999;">
                Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:<br>
                <a href="' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '" style="color: #dc2626; word-break: break-all;">
                    ' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '
                </a>
            </p>
        ';
        
        return self::getHtmlBase('Registrierung bestätigen', $content);
    }
    
    /**
     * Generate approval notification email
     */
    public static function generateApprovalEmail($firstName, $approved = true) {
        $baseUrl = self::getBaseUrl();
        $loginLink = $baseUrl . '/request_magiclink.php';
        
        if ($approved) {
            $content = '
                <h2 style="color: #10b981; margin-top: 0;">Gute Nachrichten, ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '!</h2>
                
                <p style="font-size: 16px; color: #333; line-height: 1.6;">
                    Ihr Zugang zum OwMM Feuerwehr Verwaltungssystem wurde genehmigt.
                </p>
                
                <p style="font-size: 16px; color: #333; line-height: 1.6;">
                    Sie können sich jetzt mit einem Magic Link anmelden:
                </p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . htmlspecialchars($loginLink, ENT_QUOTES, 'UTF-8') . '" 
                       style="display: inline-block; padding: 15px 40px; background-color: #10b981; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                        Magic Link anfordern
                    </a>
                </div>
                
                <p style="font-size: 14px; color: #666; line-height: 1.6;">
                    Geben Sie einfach Ihre E-Mail-Adresse ein, und Sie erhalten einen sicheren Anmelde-Link per E-Mail.
                </p>
            ';
        } else {
            $content = '
                <h2 style="color: #dc2626; margin-top: 0;">Hallo ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',</h2>
                
                <p style="font-size: 16px; color: #333; line-height: 1.6;">
                    Leider wurde Ihr Registrierungsantrag für das OwMM Feuerwehr Verwaltungssystem abgelehnt.
                </p>
                
                <p style="font-size: 14px; color: #666; line-height: 1.6;">
                    Bei Fragen wenden Sie sich bitte an einen Administrator.
                </p>
            ';
        }
        
        return self::getHtmlBase($approved ? 'Zugang genehmigt' : 'Registrierung abgelehnt', $content);
    }
    
    /**
     * Generate admin notification email for new registration
     */
    public static function generateAdminNotificationEmail($email, $firstName, $lastName) {
        $baseUrl = self::getBaseUrl();
        $approvalLink = $baseUrl . '/admin/approve_registrations.php';
        
        $content = '
            <h2 style="color: #dc2626; margin-top: 0;">Neue Registrierungsanfrage</h2>
            
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                Ein neuer Benutzer hat sich registriert und wartet auf Genehmigung:
            </p>
            
            <div style="background-color: #f8f8f8; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <p style="margin: 5px 0; font-size: 14px; color: #333;">
                    <strong>Name:</strong> ' . htmlspecialchars($firstName . ' ' . $lastName, ENT_QUOTES, 'UTF-8') . '
                </p>
                <p style="margin: 5px 0; font-size: 14px; color: #333;">
                    <strong>E-Mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($approvalLink, ENT_QUOTES, 'UTF-8') . '" 
                   style="display: inline-block; padding: 15px 40px; background-color: #dc2626; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                    Registrierungen verwalten
                </a>
            </div>
            
            <p style="font-size: 14px; color: #666; line-height: 1.6;">
                Bitte prüfen Sie die Anfrage und genehmigen oder lehnen Sie diese im Admin-Bereich ab.
            </p>
        ';
        
        return self::getHtmlBase('Neue Registrierungsanfrage', $content);
    }
    
    /**
     * Generate test email
     */
    public static function generateTestEmail() {
        $content = '
            <h2 style="color: #dc2626; margin-top: 0;">Test-Email erfolgreich!</h2>
            
            <p style="font-size: 16px; color: #333; line-height: 1.6;">
                Die E-Mail-Konfiguration funktioniert einwandfrei.
            </p>
            
            <div style="background-color: #10b98120; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0;">
                <p style="margin: 0; font-size: 14px; color: #065f46;">
                    ✓ SMTP-Verbindung erfolgreich<br>
                    ✓ E-Mail erfolgreich versendet<br>
                    ✓ HTML-Formatierung funktioniert
                </p>
            </div>
            
            <p style="font-size: 14px; color: #666; line-height: 1.6;">
                Sie können jetzt Magic Link E-Mails versenden.
            </p>
        ';
        
        return self::getHtmlBase('Test-Email', $content);
    }
}
?>
