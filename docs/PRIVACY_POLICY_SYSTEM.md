# Privacy Policy & Consent Management System

## Overview

This system implements a comprehensive, GDPR-compliant privacy policy and user consent management system for OwMM. It includes:

- **Versioned Privacy Policies**: Create and publish multiple versions of your privacy policy
- **Consent Tracking**: Audit trail of all user acceptances and rejections with IP addresses and timestamps
- **Email Consent**: Users can choose whether to receive activity updates
- **User Profiles**: Self-service portal where users can view and manage their personal data
- **Restricted Access**: Supporters see limited dashboard and are directed to their profile
- **Privacy Policy Enforcement**: Users must accept the current privacy policy before accessing the system

## Legal Compliance

This system is designed to comply with:
- **GDPR (Datenschutz-Grundverordnung)**: EU General Data Protection Regulation
- **BDSG (Bundesdatenschutzgesetz)**: German Federal Data Protection Act
- **ePrivacy Directive**: Requirements for electronic communications consent

## Database Schema

### New Tables

#### `privacy_policy_versions`
Stores versioned privacy policy documents.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| version | VARCHAR(20) | Version string (e.g., "1.0", "1.1") |
| content | LONGTEXT | Full privacy policy HTML content |
| summary | TEXT | Summary of changes in this version |
| published_at | DATETIME | When this version became active |
| requires_acceptance | TINYINT | Whether users must accept this version |
| created_at | TIMESTAMP | Creation timestamp |
| created_by | INT | User who created this version (FK: users.id) |
| updated_at | TIMESTAMP | Last update timestamp |

#### `privacy_policy_consent`
Audit trail of user consent decisions.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | User who made the decision (FK: users.id) |
| policy_version_id | INT | Policy version being accepted/rejected (FK: privacy_policy_versions.id) |
| accepted | TINYINT | 1 if accepted, 0 if rejected |
| consent_date | DATETIME | When the decision was made |
| ip_address | VARCHAR(45) | IP address from which decision was made |
| user_agent | VARCHAR(255) | Browser information |
| notes | TEXT | Any additional notes |
| created_at | TIMESTAMP | Creation timestamp |

**Unique constraint**: One consent record per user per policy version

#### `email_consent`
User preferences for email communications.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | User (FK: users.id) |
| email_activities | TINYINT | Consent to receive activity emails |
| email_updates | TINYINT | Consent for general updates |
| email_notifications | TINYINT | Consent for important notifications (always 1) |
| updated_at | DATETIME | Last update timestamp |
| updated_by_user | TINYINT | 1 if user updated, 0 if admin |

### Modified Tables

**users table** - Added columns:
- `privacy_policy_accepted_version`: VARCHAR(20) - Last accepted policy version
- `privacy_policy_accepted_at`: DATETIME - Timestamp of acceptance
- `require_privacy_policy_acceptance`: TINYINT - Flag requiring acceptance on next login

**members table** - Added column:
- `email_consent_activities`: TINYINT - Email consent preference

## File Structure

### New PHP Files

```
admin/
├── profile.php                      # User profile/self-service page
├── privacy_policy.php               # Privacy policy display & consent
├── manage_privacy_policies.php       # Admin panel for policy management
```

### Modified Files

```
admin/
├── login.php                        # Added privacy policy check
├── dashboard.php                    # Added supporter redirect
```

```
verify_magiclink.php                # Added privacy policy check
includes/functions.php              # Added helper functions
```

### Database Migrations

```
database/
├── migration_privacy_policy.sql              # Core schema
├── migration_initial_privacy_policy.sql      # Default policy
```

## User Journey

### New User Registration
1. User registers with email
2. User logs in for the first time
3. **Privacy Policy Acceptance Required**
4. User accepts or rejects privacy policy
5. If accepted → Profile page (supporters) or Dashboard (admin/staff)
6. If rejected → Account is deactivated

### Supporter User Flow
1. Login → Privacy Policy → Profile Page
2. From profile, can view:
   - Personal data
   - Obligations/fees
   - Email preferences
   - Privacy policy acceptance status
3. No access to dashboard

### Admin/Staff User Flow
1. Login → Privacy Policy → Dashboard
2. Can access all admin functions based on permissions

### Privacy Policy Updates
1. Admin creates new version in "Manage Privacy Policies"
2. Admin can save as draft or publish immediately
3. Upon publication, all users see "Accept Required" on next login
4. Users must accept new version before accessing system
5. All acceptances are logged with timestamp and IP

## Helper Functions

Added to `includes/functions.php`:

```php
// Check if user has accepted the latest privacy policy
has_accepted_privacy_policy($user_id = null)

// Get latest published privacy policy
get_latest_privacy_policy()

// Record acceptance or rejection
record_privacy_policy_decision($user_id, $policy_version_id, $accepted)

// Check if user is a supporter
is_supporter($user_id = null)
```

## Admin Features

### Manage Privacy Policies (`admin/manage_privacy_policies.php`)

**Features:**
- Create new policy versions with HTML content
- View all policy versions (draft and published)
- Publish draft policies
- View consent statistics
- See audit trail of acceptances/rejections
- List users pending acceptance

**Access:** Admin only

### User Profile (`admin/profile.php`)

**Users can:**
- View personal data from members table
- Edit personal information
- Update contact details and address
- Update IBAN for payments
- View their obligations
- Manage email communication preferences
- View privacy policy acceptance history
- Delete their account

## Email Consent

Users can configure:
- **Email Activities**: Receive updates about OwMM activities (opt-in)
- **Important Notifications**: Always receive (cannot be disabled)
- **Updates & Newsletters**: Opt-in for general updates

## Audit & Compliance

### Logged Information

Every privacy policy consent decision records:
- Timestamp (consent_date)
- IP address of user
- Browser/User Agent
- Acceptance or rejection
- Policy version number

### GDPR Compliance

✓ **Art. 6 (Lawfulness)**: Consent-based processing with valid legal basis
✓ **Art. 7 (Consent conditions)**: Easy to give/withdraw, granular choices
✓ **Art. 13/14 (Information)**: Comprehensive privacy policy provided
✓ **Art. 15 (Access)**: Users can view their data in profile
✓ **Art. 16 (Rectification)**: Users can edit their information
✓ **Art. 17 (Deletion)**: Users can request account deletion
✓ **Art. 20 (Portability)**: Audit trail available on request
✓ **Art. 32 (Security)**: IP logging, HTTPS, encrypted passwords
✓ **Art. 33/34 (Breach notification)**: Data protected by design

## Setup Instructions

### 1. Database Migrations

Run these migrations in order:

```sql
-- 1. Core schema
SOURCE database/migration_privacy_policy.sql;

-- 2. Initial privacy policy
SOURCE database/migration_initial_privacy_policy.sql;
```

### 2. Customize Privacy Policy

Edit the default privacy policy:
- Go to Admin → Manage Privacy Policies
- Click "Edit" on Version 1.0
- Update organization information (name, address, contact)
- Customize content as needed for your jurisdiction

Recommended sections to customize:
- Section 1: Organization details
- Section 5: Specific data retention policies
- Section 11: Contact information
- Section 12: Data Protection Officer (if applicable)

### 3. Publishing New Versions

When updating your privacy policy:
1. Create a new version (e.g., 1.1, 2.0)
2. Edit the content
3. Publish immediately to require user acceptance
4. Users will see the policy on their next login

## Configuration Recommendations

### Privacy Policy Content
- Update with your organization's actual contact information
- Add your DPO (Data Protection Officer) if required
- Customize retention periods per your legal requirements
- Add specific data processing activities

### Retention Periods
Current defaults (GDPR compliant):
- Member data: 7 years after membership ends (tax law)
- Financial data: 10 years (commercial code)
- Login attempts: 90 days
- Email consent: Until changed by user

### Email Communication
Default settings:
- Activity emails: Opt-in (user must choose)
- Important notifications: Always sent (cannot opt out)
- General updates: Opt-in

## Testing

### Test Checklist

- [ ] New user registration → Privacy policy required
- [ ] User accepts → Redirects to profile (supporter) or dashboard (admin)
- [ ] User rejects → Account deactivated
- [ ] Magic link login → Privacy policy check
- [ ] Password login → Privacy policy check
- [ ] Supporter login → Redirects to profile, no dashboard access
- [ ] New policy version → Shows on next login for all users
- [ ] Admin can view consent statistics
- [ ] Audit log shows correct timestamps and IPs
- [ ] Print privacy policy works correctly
- [ ] Email consent preferences saved correctly

## Troubleshooting

### Users stuck on privacy policy page
**Issue**: User cannot proceed past privacy policy
**Solution**: 
- Check database: Verify privacy_policy_versions table has published_at
- Check user flags: Ensure require_privacy_policy_acceptance is set correctly
- Clear sessions: Session might be cached

### Email preferences not saving
**Issue**: Email consent not updating
**Solution**:
- Verify email_consent table exists and has user's record
- Check database permissions
- Verify JavaScript enabled in browser

### Audit trail missing
**Issue**: No records in privacy_policy_consent
**Solution**:
- Check privacy_policy_versions.published_at is set
- Verify ip_address not being blocked by firewall
- Check database error logs

## Security Considerations

✓ All consent decisions are immutable (logged in database)
✓ IP addresses stored for audit trail (GDPR compliant)
✓ User agent logged to detect suspicious activity
✓ Rejected users cannot bypass by clearing cookies
✓ Admin-only policy management
✓ No ability to delete consent records (permanent audit trail)

## Future Enhancements

Potential additions:
- Email notifications when new policy version published
- Scheduled reports of acceptance rates
- Bulk send of policy acceptance reminders
- Integration with external DMS/document management
- PDF export of policies with acceptance signatures
- Multi-language support for privacy policies
- Cookie consent banner for public website
- CCPA/LGPD support for international users

## Support & Questions

For questions about GDPR compliance, consult:
- https://gdpr-info.eu/ (GDPR text and guides)
- Your local Data Protection Authority
- A qualified data protection lawyer
- Your organization's Data Protection Officer (if applicable)

---

**Last Updated**: January 24, 2026
**Version**: 1.0
**Status**: Ready for production
