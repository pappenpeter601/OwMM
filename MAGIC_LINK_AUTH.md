# Magic Link Authentication System

## Overview

This system implements passwordless authentication using Magic Links for the OwMM Feuerwehr management system. Users can log in without passwords by receiving secure, time-limited links via email.

## Features

- **Passwordless Authentication**: No passwords to remember or manage
- **Admin Approval Workflow**: New registrations require admin approval
- **Email Verification**: Users must verify their email address
- **Rate Limiting**: Prevents abuse with max 3 requests per 15 minutes
- **Audit Trail**: All login attempts are logged
- **Security**: 
  - Cryptographically secure tokens (64 characters)
  - 15-minute expiration
  - Single-use links
  - IP and User-Agent tracking

## Installation

### 1. Run Database Migration

Execute the SQL migration to create required tables:

```bash
mysql -u your_username -p your_database < database/migration_magiclink_auth.sql
```

This creates:
- `email_config` - SMTP configuration storage
- `magic_links` - Token management with expiry tracking
- `registration_requests` - User registration approval workflow
- `login_attempts` - Rate limiting and audit log

It also modifies the `users` table to support magic link authentication.

### 2. Configure SMTP Settings

1. Log in to the admin panel
2. Navigate to **Admin > Email Settings** (`admin/email_settings.php`)
3. Configure your SMTP server:
   - **SMTP Host**: e.g., `smtp.ionos.de`, `smtp.gmail.com`
   - **SMTP Port**: Usually `587` for TLS
   - **Use TLS**: Enable for port 587
   - **SMTP Username**: Your email address
   - **SMTP Password**: Your email password or app-specific password
   - **From Email**: Email address for outgoing emails
   - **From Name**: Display name (e.g., "OwMM Feuerwehr")

4. Click "Test-E-Mail senden" to verify configuration

### 3. Default Configuration

The migration includes a default IONOS SMTP configuration:
```sql
INSERT INTO email_config (smtp_host, smtp_port, smtp_username, from_email, from_name, use_tls)
VALUES ('smtp.ionos.de', 587, '', 'noreply@yourdomain.com', 'OwMM Feuerwehr', 1);
```

Update this with your actual credentials via the admin UI.

## User Registration Flow

### For New Users

1. **Register**: Visit `/register.php`
   - Enter first name, last name, and email
   - Submit registration form

2. **Verify Email**: Check email inbox
   - Click verification link in email
   - Email address is confirmed

3. **Wait for Approval**: Admin reviews registration
   - User receives approval/rejection email

4. **Login**: After approval
   - Visit `/request_magiclink.php`
   - Enter email address
   - Receive magic link via email
   - Click link to auto-login (valid 15 minutes)

### For Admins

1. **Review Registrations**: Visit `admin/approve_registrations.php`
   - View all pending registration requests
   - See email verification status
   - Approve or reject registrations

2. **Approval Actions**:
   - **Approve**: Creates user account, sends approval email
   - **Reject**: Sends rejection email, user cannot register again with same email

## Magic Link Login Flow

1. **Request Link**: User visits `/request_magiclink.php`
   - Enters email address
   - System checks rate limits (max 3 per 15 min)

2. **Email Sent**: User receives email with magic link
   - Link format: `https://yourdomain.com/verify_magiclink.php?token=...`
   - Valid for 15 minutes
   - Single-use only

3. **Verify & Login**: User clicks link
   - System validates token (not expired, not used)
   - Marks token as used
   - Creates session
   - Redirects to dashboard

## File Structure

### Database Migration
- `database/migration_magiclink_auth.sql` - Database schema

### Core Infrastructure
- `includes/SMTPClient.php` - Pure PHP SMTP email client
- `includes/EmailService.php` - High-level email service wrapper
- `includes/EmailTemplates.php` - HTML email templates
- `includes/functions.php` - Helper functions (updated)

### User-Facing Pages
- `register.php` - New user registration
- `verify_registration.php` - Email verification handler
- `request_magiclink.php` - Request magic link login
- `verify_magiclink.php` - Magic link verification & auto-login

### Admin Pages
- `admin/email_settings.php` - SMTP configuration UI
- `admin/approve_registrations.php` - Registration approval dashboard
- `admin/login.php` - Updated with magic link option

## Helper Functions

Added to `includes/functions.php`:

```php
generate_magic_link($user_id, $pdo)           // Generate token
verify_magic_link($token, $pdo)               // Validate token
mark_magic_link_used($token, $pdo)            // Mark as used
check_rate_limit($email, $ip, $max, $window)  // Rate limiting
log_login_attempt($email, $success, $method)  // Audit logging
cleanup_expired_magic_links($pdo)             // Maintenance
cleanup_old_login_attempts($days, $pdo)       // Maintenance
```

## Security Considerations

### Rate Limiting
- Max 3 magic link requests per email/IP per 15 minutes
- Prevents brute force and DoS attacks

### Token Security
- 64-character hex tokens (256 bits of entropy)
- Generated with `random_bytes()` (cryptographically secure)
- Single-use tokens (marked as used after login)
- 15-minute expiration
- Stored with IP address and User-Agent for tracking

### Email Security
- TLS/STARTTLS encryption for SMTP
- Password stored in database (consider encrypting)
- No sensitive data in email subjects

### Session Security
- Standard PHP sessions
- Auth method tracked in session (`$_SESSION['auth_method']`)

## Maintenance

### Cleanup Tasks

Add to cron or run periodically:

```php
// Clean up expired/used magic links (older than 7 days)
cleanup_expired_magic_links();

// Clean up old login attempts (older than 30 days)
cleanup_old_login_attempts(30);
```

Example cron job:
```bash
# Run daily at 2 AM
0 2 * * * php /path/to/owmm/cleanup_magic_links.php
```

### Monitoring

Check `login_attempts` table for:
- Failed login attempts (rate limit breaches)
- Suspicious IP addresses
- Authentication method usage statistics

```sql
-- Recent failed attempts
SELECT email, ip_address, COUNT(*) as failures
FROM login_attempts
WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY email, ip_address
HAVING failures > 5;

-- Authentication method breakdown
SELECT method, success, COUNT(*) as count
FROM login_attempts
WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY method, success;
```

## Email Templates

All emails use branded HTML templates with:
- OwMM Feuerwehr branding (red gradient header)
- Responsive design (mobile-friendly)
- Clear call-to-action buttons
- German language content

### Template Types

1. **Magic Link Email** (`generateMagicLinkEmail`)
   - Secure login link
   - 15-minute expiration notice
   - Fallback URL for button issues

2. **Registration Confirmation** (`generateRegistrationEmail`)
   - Email verification link
   - Next steps (admin approval)

3. **Approval Notification** (`generateApprovalEmail`)
   - Approved: Link to request magic link
   - Rejected: Contact admin message

4. **Admin Notification** (`generateAdminNotificationEmail`)
   - New registration details
   - Link to admin approval page

5. **Test Email** (`generateTestEmail`)
   - SMTP configuration verification
   - Success indicators

## Troubleshooting

### Email Not Sending

1. **Check SMTP Configuration**:
   - Log in to `admin/email_settings.php`
   - Verify host, port, username, password
   - Try "Test-E-Mail senden"

2. **Check Error Logs**:
   ```bash
   tail -f /path/to/owmm/logs/error.log
   ```

3. **Common Issues**:
   - Wrong port (587 for TLS, 465 for SSL)
   - TLS not enabled for port 587
   - Incorrect username/password
   - Firewall blocking outgoing SMTP

### Magic Link Not Working

1. **Check Token Expiration**:
   - Links expire after 15 minutes
   - Request a new one

2. **Check if Already Used**:
   - Each link is single-use
   - Request a new one

3. **Database Check**:
   ```sql
   SELECT * FROM magic_links 
   WHERE token = 'your_token_here';
   ```

### Rate Limit Issues

If users are blocked:

```sql
-- Check recent attempts
SELECT * FROM login_attempts 
WHERE email = 'user@example.com' 
ORDER BY created_at DESC LIMIT 10;

-- Reset rate limit (if legitimate)
DELETE FROM login_attempts 
WHERE email = 'user@example.com' 
AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE);
```

## Future Enhancements

- [ ] Add Google Sign-In as alternative
- [ ] Implement 2FA for sensitive accounts
- [ ] Add email template customization in admin UI
- [ ] Support for multiple admin email notifications
- [ ] Remember me / extended sessions
- [ ] Password recovery for legacy accounts
- [ ] OAuth 2.0 integration
- [ ] WebAuthn/FIDO2 support

## Migration from Password Auth

Existing users with passwords can still log in via `admin/login.php`. To migrate to magic link:

1. Admin updates user in database:
   ```sql
   UPDATE users 
   SET auth_method = 'both', 
       email_verified = 1 
   WHERE id = ?;
   ```

2. User can now use either:
   - Password login at `admin/login.php`
   - Magic link at `request_magiclink.php`

3. To force magic link only:
   ```sql
   UPDATE users 
   SET auth_method = 'magic_link', 
       password = NULL 
   WHERE id = ?;
   ```

## Support

For issues or questions:
- Check error logs in `/logs/`
- Review `login_attempts` table
- Verify email configuration
- Test SMTP connection

## License

Same license as the main OwMM project.
