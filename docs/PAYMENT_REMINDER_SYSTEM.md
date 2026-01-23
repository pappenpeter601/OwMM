# Payment Reminder System (Mahnwesen) - Concept Document

## Overview
A comprehensive member communication system for sending personalized payment reminders for outstanding fee obligations, integrated with the existing OwMM member and obligation management infrastructure.

---

## Current System Analysis

### Existing Infrastructure ✅
- **EmailService.php**: SMTP client with database-driven configuration
- **EmailTemplates.php**: HTML email templates with professional styling
- **Member Management**: Complete member database with email, IBAN, member_type
- **Obligations System**: `member_fee_obligations` table tracking payment status
- **Payment History**: `member_payments` table with payment_date tracking

### Data Available for Reminders
```sql
-- Member attributes
- first_name, last_name, salutation
- email, iban, member_number
- member_type (active/supporter)
- telephone, mobile

-- Obligation data
- fee_year, fee_amount, paid_amount, status
- due_date, outstanding amount
- Last payment date (from member_payments)
```

---

## Feature Requirements

### 1. Email Templates
**Two distinct templates:**

#### Active Members Template (Casual Tone)
```
Hallo [Vorname],

wie geht's? Wir wollten dich kurz daran erinnern, dass der Mitgliedsbeitrag 
für [Jahr] noch offen ist.

Deine Beitragsinformationen:
- Mitgliedsnummer: [number]
- Beitragsjahr: [year]
- Betrag: [amount] €
- Bereits gezahlt: [paid] €
- Noch offen: [outstanding] €
- Letzte Zahlung: [last_payment_date]

Bitte überweise den offenen Betrag auf unser Konto:
IBAN: [OwMM_IBAN]
Verwendungszweck: Beitrag [year] - [member_number]

Oder nutze PayPal: [paypal_link]

Bei Fragen melde dich gern!

Viele Grüße
Dein OwMM Team
```

#### Supporter Template (Formal Tone)
```
Sehr geehrte/r [Anrede] [Nachname],

wir möchten Sie höflich daran erinnern, dass der Förderbeitrag für das 
Jahr [Jahr] noch aussteht.

Ihre Beitragsinformationen:
- Mitgliedsnummer: [number]
- Beitragsjahr: [year]
- Förderbetrag: [amount] €
- Bereits eingegangen: [paid] €
- Ausstehend: [outstanding] €
- Letzte Zahlung: [last_payment_date]

Bitte überweisen Sie den ausstehenden Betrag auf folgendes Konto:
IBAN: [OwMM_IBAN]
Verwendungszweck: Förderbeitrag [year] - [member_number]

Alternativ können Sie auch über PayPal bezahlen: [paypal_link]

Für Rückfragen stehen wir Ihnen gerne zur Verfügung.

Mit freundlichen Grüßen
OwMM Feuerwehr
```

### 2. User Interface

#### Main Page: `payment_reminders.php`
```
┌─────────────────────────────────────────────┐
│ Zahlungserinnerungen                        │
│ [Jahr: 2026 ▼] [Status: Alle ▼] [Suche...] │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ Erinnerungen versenden                       │
│                                              │
│ Jahr auswählen: [2026 ▼]                    │
│ Empfänger: ○ Nur offene  ● Auch Teilzahlung│
│                                              │
│ CC-Empfänger: [admin@owmm.de____]           │
│                                              │
│ [📧 Vorschau & Versenden]                   │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ Mitglieder mit offenen Forderungen (47)     │
│                                              │
│ ☑ Alle auswählen | ☐ Nur mit E-Mail        │
│                                              │
│ ☑ Max Mustermann  | Active   | 120.00 € ✉️  │
│ ☑ Anna Schmidt     | Supporter| 60.00 € ✉️  │
│ ☑ Klaus Meyer      | Active   | 120.00 € ⚠️ │
│   └─ Keine E-Mail-Adresse hinterlegt!       │
│ ☑ Lisa Wagner      | Supporter| 60.00 € ✉️  │
│                                              │
│ Ausgewählt: 47 Mitglieder                   │
│ Versendbar: 44 (3 ohne E-Mail)              │
└─────────────────────────────────────────────┘
```

#### Preview Modal
```
┌──────────────────────────────────────────────┐
│ Vorschau: Zahlungserinnerung                 │
│                                               │
│ Empfänger: 44 Mitglieder                     │
│ - 32 Einsatzeinheit (casual)                 │
│ - 12 Förderer (formal)                       │
│                                               │
│ ⚠️ 3 Mitglieder ohne E-Mail werden übersprungen│
│                                               │
│ [Beispiel anzeigen ▼]                        │
│ ┌─────────────────────────────────────────┐ │
│ │ Max Mustermann (max@example.com)        │ │
│ │                                         │ │
│ │ Hallo Max,                              │ │
│ │                                         │ │
│ │ wie geht's? Wir wollten dich kurz...   │ │
│ │ [vollständiges HTML wird angezeigt]     │ │
│ └─────────────────────────────────────────┘ │
│                                               │
│ [Abbrechen] [✉️ Jetzt versenden (44)]       │
└──────────────────────────────────────────────┘
```

### 3. Tracking System

#### Database Table: `payment_reminders`
```sql
CREATE TABLE payment_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  obligation_id INT NOT NULL,
  reminder_type ENUM('first', 'second', 'final'),
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_to_email VARCHAR(255),
  cc_email VARCHAR(255),
  template_used VARCHAR(50),
  email_subject VARCHAR(255),
  sent_by INT, -- user_id
  success BOOLEAN DEFAULT 1,
  error_message TEXT,
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (obligation_id) REFERENCES member_fee_obligations(id),
  FOREIGN KEY (sent_by) REFERENCES users(id)
);
```

### 4. Business Logic

#### Selection Logic
```php
// Get members with open obligations
SELECT 
  m.id, m.first_name, m.last_name, m.salutation,
  m.email, m.iban, m.member_number, m.member_type,
  o.id as obligation_id, o.fee_year, o.fee_amount, 
  o.paid_amount, (o.fee_amount - o.paid_amount) as outstanding,
  o.status, o.due_date,
  MAX(mp.payment_date) as last_payment_date
FROM members m
INNER JOIN member_fee_obligations o ON m.id = o.member_id
LEFT JOIN member_payments mp ON o.id = mp.obligation_id
WHERE o.fee_year = [selected_year]
  AND o.status IN ('open', 'partial')
  AND m.active = 1
GROUP BY m.id, o.id
ORDER BY m.last_name, m.first_name
```

#### Template Selection
```php
if ($member['member_type'] === 'active') {
    $template = 'payment_reminder_active';  // Casual tone
} else {
    $template = 'payment_reminder_supporter'; // Formal tone
}
```

#### Personalization Variables
```php
$placeholders = [
    'salutation' => $member['salutation'],
    'first_name' => $member['first_name'],
    'last_name' => $member['last_name'],
    'member_number' => $member['member_number'],
    'fee_year' => $obligation['fee_year'],
    'fee_amount' => number_format($obligation['fee_amount'], 2),
    'paid_amount' => number_format($obligation['paid_amount'], 2),
    'outstanding' => number_format($outstanding, 2),
    'due_date' => date('d.m.Y', strtotime($obligation['due_date'])),
    'last_payment_date' => $last_payment_date ? date('d.m.Y', strtotime($last_payment_date)) : 'Keine Zahlung erfasst',
    'owmm_iban' => 'DE89 3704 0044 0532 0130 00', // From config
    'paypal_link' => 'https://paypal.me/owmm' // From config
];
```

---

## Implementation Plan

### Phase 1: Database & Email Templates (Est. 1-2 hours)
1. Create migration file for `payment_reminders` table
2. Add two new email templates to `EmailTemplates.php`:
   - `generatePaymentReminderActive()` - Casual tone
   - `generatePaymentReminderSupporter()` - Formal tone
3. Add configuration table for IBAN/PayPal (or add to existing email_config)

### Phase 2: Backend Service (Est. 2-3 hours)
4. Extend `EmailService.php`:
   - `sendPaymentReminder($member, $obligation, $cc_email = null)`
   - `sendBulkPaymentReminders($members, $cc_email = null)`
   - Track sent reminders in database
5. Create helper functions in `functions.php`:
   - `get_members_with_outstanding_for_year($year, $include_partial = true)`
   - `get_last_payment_date($obligation_id)`
   - `log_payment_reminder($member_id, $obligation_id, $success, $error = null)`

### Phase 3: Frontend Interface (Est. 3-4 hours)
6. Create `admin/payment_reminders.php`:
   - Year selector + status filter
   - Member list with checkboxes
   - Email status indicators (✉️ / ⚠️)
   - CC email input
   - "Preview & Send" button
7. Create preview modal with:
   - Recipient summary
   - Warning for members without email
   - Sample email display
   - Bulk send confirmation
8. Add JavaScript for:
   - Select all/none functionality
   - Preview generation
   - AJAX bulk send with progress indicator

### Phase 4: Integration & Permissions (Est. 1 hour)
9. Add to navigation menu (admin/includes/header.php)
10. Add permission to database: `payment_reminders.php`
11. Add to dashboard with appropriate icon
12. Add menu item under "Finanzen" category

### Phase 5: History & Reporting (Est. 1-2 hours)
13. Add "Versand-Historie" tab to payment_reminders.php
14. Show sent reminders with filters:
    - Date range
    - Member search
    - Success/failure status
15. Link reminders to member_payments page

---

## Technical Decisions

### Why Separate Templates?
- **Tone matters**: Active members are colleagues, supporters are formal donors
- **Expectations differ**: Different minimum amounts, different language
- **Flexibility**: Easy to adjust one without affecting the other

### Why Track Reminders?
- **Audit trail**: Know when and what was sent
- **Prevent spam**: Don't send multiple reminders in short time
- **Analytics**: Track response rates (payments after reminders)
- **Legal protection**: Proof of communication

### Why Preview Modal?
- **Safety**: Prevent accidental bulk sends
- **Validation**: Admin can review before sending
- **Transparency**: See exactly what members receive
- **Error prevention**: Catch missing data before sending

---

## Configuration Requirements

### Email Config Additions
```sql
ALTER TABLE email_config ADD COLUMN owmm_iban VARCHAR(34);
ALTER TABLE email_config ADD COLUMN paypal_link VARCHAR(255);
```

### Permission Entry
```sql
INSERT INTO permissions (name, display_name, description, category)
VALUES ('payment_reminders.php', 'Zahlungserinnerungen', 
        'Zahlungserinnerungen versenden', 'Finanzen');
```

---

## Success Metrics

### Functionality Checklist
- [ ] Active members receive casual tone
- [ ] Supporters receive formal tone
- [ ] All member attributes displayed correctly
- [ ] Last payment date shown accurately
- [ ] Members without email clearly marked
- [ ] CC copy sent to admin
- [ ] Reminders logged in database
- [ ] Preview shows actual email content
- [ ] Bulk send completes without timeout
- [ ] Error handling for failed sends

### User Experience Goals
- One-click year selection
- Clear visual feedback (✉️ vs ⚠️)
- No page reload during send
- Progress indicator for bulk operations
- Success/failure summary after send

---

## Future Enhancements (V2)

1. **Reminder Escalation**: First → Second → Final reminder
2. **Automatic Scheduling**: Auto-send reminders X days after due date
3. **SMS Integration**: Send SMS for members without email
4. **Payment Portal Link**: Generate unique payment links per member
5. **Response Tracking**: Mark obligation as "reminded on [date]"
6. **Template Editor**: Admin UI to edit email templates
7. **A/B Testing**: Test different subject lines/content
8. **Dunning Fees**: Automatically add late fees to obligations

---

## Security Considerations

- **Permission-based access**: Only authorized users can send
- **Rate limiting**: Prevent spam (max X emails per hour)
- **Email validation**: Verify email format before sending
- **SQL injection protection**: Use prepared statements
- **XSS prevention**: Sanitize all user input
- **GDPR compliance**: Include unsubscribe link (future)

---

## Testing Checklist

### Unit Tests
- [ ] Template rendering with all placeholders
- [ ] Email address validation
- [ ] Obligation calculation (outstanding = fee - paid)
- [ ] Last payment date lookup
- [ ] CC email handling

### Integration Tests
- [ ] SMTP connection and send
- [ ] Database logging
- [ ] Bulk send with 50+ members
- [ ] Error handling for invalid emails
- [ ] Transaction rollback on failures

### User Acceptance Tests
- [ ] Admin sends reminder to self
- [ ] Check formatting in Gmail/Outlook/Apple Mail
- [ ] Verify all German umlauts display correctly
- [ ] Test with member without email
- [ ] Verify CC copy arrives
- [ ] Check reminder appears in history

---

## Estimated Total Effort
**10-13 hours of development**

### Breakdown:
- Database & Templates: 2h
- Backend Logic: 3h
- Frontend UI: 4h
- Integration: 1h
- History/Reporting: 2h
- Testing: 1-2h

---

## Next Steps

1. **Review this concept** - Approve technical approach
2. **Start with Phase 1** - Database and templates foundation
3. **Iterative development** - Complete one phase, test, continue
4. **Deploy to staging** - Test with real data before production
5. **Train users** - Document how to use the system

---

## Questions to Answer Before Implementation

1. **IBAN/PayPal**: Where should we store OwMM bank details?
2. **Subject Line**: Fixed or customizable email subject?
3. **Reminder Types**: Just one reminder, or first/second/final?
4. **Automatic Sending**: Manual only, or schedule automatic reminders?
5. **Access Control**: Who can send reminders? (Admin only? Kassenprüfer too?)

---

*Document created: 2026-01-22*
*Status: Ready for Implementation*
