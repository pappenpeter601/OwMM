# Accounting Check System (Kassenprüfung)

## Overview

This system implements a complete accounting check workflow for the fire department's financial management system. It allows designated auditors (Kassenprüfer) to review and verify all financial transactions with a formal approval process.

## Key Features

### 1. Role Management
- **Accountant Role**: Person responsible for accounting (can edit transactions)
- **Kassenprüfer Role**: Auditors who check the accounting (2 persons)
  - **Leader (Leiter)**: Experienced auditor, can finalize check periods
  - **Assistant (Assistent)**: New auditor, rotates to Leader after ~1 year

### 2. Kassenprüfer Assignment
- Always 2 active auditors (Leader + Assistant)
- Only **active members** (Einsatzeinheit) can be assigned
- Complete historical record of all assignments
- Annual rotation: Assistant → Leader, new person → Assistant

### 3. Check Periods
- Organize checks in batches (e.g., annual reviews)
- Define date range for transactions to be checked
- Assign specific Leader and Assistant for each period
- Track progress: total, checked, under investigation, unchecked

### 4. Transaction Checking
- Both auditors can review transactions independently or together
- For each transaction:
  - **Approve**: Mark as checked (optional remarks)
  - **Investigate**: Mark for review with required remarks
- Real-time status tracking with visual indicators
- Complete audit trail (who checked what and when)

### 5. Finalization & Locking
- **Only the Leader** can finalize a check period
- Requirements for finalization:
  - All transactions must be checked (no unchecked transactions)
  - Transactions can be "approved" or "under investigation"
- Once finalized:
  - All transactions in that period are **locked forever**
  - No edits to transactions possible (category, amount, dates, etc.)
  - No new documents can be uploaded
  - Provides audit-proof record

## Database Schema

### New Tables

#### kassenpruefer_assignments
Tracks auditor assignments with validity periods.
```sql
- member_id: Reference to active member
- role_type: 'leader' or 'assistant'
- valid_from: Start date of assignment
- valid_until: End date (NULL = currently active)
```

#### check_periods
Defines batches of transactions to be checked.
```sql
- period_name: e.g., "Jahresprüfung 2025"
- business_year: Fiscal year
- date_from / date_to: Transaction date range
- status: 'in_progress' or 'finalized'
- leader_id / assistant_id: Assigned auditors
- finalized_at / finalized_by: When and who finalized
```

#### transaction_checks
Individual check records for each transaction.
```sql
- transaction_id: Transaction being checked
- check_period_id: Which check period
- checked_by_member_id: Who performed the check
- check_date: When checked
- check_result: 'approved' or 'under_investigation'
- remarks: Comments from auditor
```

### Modified Tables

#### users
- Added role: `'accountant'` for the accounting person

#### transactions
- `check_status`: enum('unchecked','checked','under_investigation')
- `checked_in_period_id`: Links to finalized period (locks the transaction)

## Workflow

### Initial Setup (Admin)

1. **Assign Kassenprüfer** (`kassenpruefer_assignments.php`)
   - Select an active member as Leader
   - Select another active member as Assistant
   - Set validity start date
   - System automatically ends previous assignments

2. **Create Check Period** (`check_periods.php`)
   - Define period name (e.g., "Jahresprüfung 2025")
   - Set business year and date range
   - System automatically uses current Leader/Assistant

### Checking Process (Kassenprüfer)

3. **Review Transactions** (`transaction_checking.php`)
   - Access period from "Prüfperioden" menu
   - View all transactions in date range
   - Dashboard shows: Total, Checked, Under Investigation, Unchecked
   - For each transaction:
     - Click "OK" to approve (optional remarks)
     - Click "Prüfen" to mark for investigation (remarks required)
   - Both auditors can work independently or together online

4. **Monitor Progress**
   - Real-time statistics
   - Visual status indicators:
     - ✓ Geprüft (Approved)
     - ⚠️ In Prüfung (Under Investigation)
     - ⏳ Ungeprüft (Not yet checked)
   - See who checked each transaction and when

### Finalization (Leader Only)

5. **Finalize Period**
   - When all transactions are checked
   - Click "Finalisieren" button
   - Confirmation required
   - System locks all transactions in that period
   - Cannot be undone!

### Post-Finalization

6. **Locked Transactions**
   - Show 🔒 Gesperrt badge in Kontoführung
   - Red background highlight
   - All edit fields disabled
   - Document upload disabled
   - Permanent audit record

## User Interface

### Navigation (Admin Menu)
- **Prüfperioden**: Access for admin and kassenprüfer
  - Create new periods (admin only)
  - View all periods
  - Access checking interface
  - Finalize periods (leader only)

- **Kassenprüfer**: Admin only
  - Assign Leader and Assistant
  - View assignment history
  - End assignments

### Kontoführung Integration
- New column: **Status**
  - Shows check status for each transaction
  - Shows lock status (🔒 for finalized)
- Disabled controls for locked transactions
- Visual indicators (locked rows have red tint)

## Migration

### For Existing Databases

Run the migration SQL:
```bash
mysql -u [user] -p [database] < database/migration_accounting_check.sql
```

This will:
1. Add 'accountant' role to users table
2. Add check_status and checked_in_period_id to transactions
3. Create 3 new tables (kassenpruefer_assignments, check_periods, transaction_checks)
4. All existing transactions default to 'unchecked' status

### For New Installations

The complete schema is in `database/schema.sql` - no migration needed.

## Permissions

### Role Access Matrix

| Feature | Admin | Kassenprüfer | Accountant | Board | Others |
|---------|-------|--------------|------------|-------|--------|
| View Transactions | ✓ | ✓ | ✓ | - | - |
| Edit Transactions | ✓ | ✓ | ✓ | - | - |
| View Check Periods | ✓ | ✓ | - | - | - |
| Check Transactions | ✓ | ✓ | - | - | - |
| Finalize Periods | ✓ | Leader only | - | - | - |
| Manage Kassenprüfer | ✓ | - | - | - | - |
| Create Check Periods | ✓ | - | - | - | - |

## Best Practices

### Kassenprüfer Rotation
- Assign new Assistant each year
- After 1 year, promote Assistant to Leader
- Previous Leader's assignment ends
- Maintains continuity with experienced Leader

### Check Period Organization
- **Annual Checks**: One period per year (recommended)
- **Quarterly Checks**: More frequent reviews
- **Event-Based**: Check specific date ranges
- Clear naming: "Jahresprüfung 2025", "Q1 2026", etc.

### Transaction Checking
- Both auditors should review together initially
- Can work independently once familiar
- Use "Under Investigation" for unclear transactions
- Add detailed remarks for audit trail
- Resolve all investigations before finalizing

### Finalization
- Only finalize when confident all checks are complete
- Leader should consult with Assistant before finalizing
- Once locked, transactions cannot be changed
- Document any open issues in period notes before finalizing

## Security & Audit Compliance

### Audit Trail
- Complete history of who checked what and when
- Remarks preserved forever
- Kassenprüfer assignment history
- Finalization timestamp and user

### Data Integrity
- Foreign key constraints prevent orphaned records
- Transaction locks enforced at database level
- Status changes logged in transaction_checks table
- No backdoor edits possible after finalization

### Compliance
- Meets German accounting audit requirements (Kassenprüfung)
- Separation of duties (Accountant vs. Auditors)
- Two-person rule for audit oversight
- Permanent audit records

## Troubleshooting

### Cannot Assign Kassenprüfer
- **Issue**: Member not in dropdown
- **Solution**: Only active members (Einsatzeinheit) can be assigned
- Check member type and active status in members table

### Cannot Finalize Period
- **Issue**: "Es gibt noch X ungeprüfte Transaktionen"
- **Solution**: All transactions must be checked (approved or under investigation)
- Review unchecked count in period overview

### Cannot Edit Transaction
- **Issue**: "Diese Transaktion ist gesperrt"
- **Solution**: Transaction was finalized in a check period
- This is intentional - locked transactions cannot be edited
- Contact admin if urgent correction needed

### Wrong Person Assigned
- **Issue**: Need to change kassenprüfer assignment
- **Solution**: Admin can end current assignment and create new one
- Old assignments preserved in history

## Future Enhancements (Possible)

- Email notifications when checks are assigned
- PDF export of check period reports
- Dashboard statistics for admin
- Bulk checking operations
- Mobile-optimized interface for on-site checks
- Integration with digital signature

## Files Changed/Added

### New Files
- `database/migration_accounting_check.sql`
- `admin/kassenpruefer_assignments.php`
- `admin/check_periods.php`
- `admin/transaction_checking.php`

### Modified Files
- `database/schema.sql` - Added new tables and fields
- `includes/functions.php` - Added permission helpers
- `admin/kontofuehrung.php` - Added check status and locking
- `admin/includes/header.php` - Added menu items

## Support

For issues or questions:
1. Check this README
2. Review database schema comments
3. Check function comments in `includes/functions.php`
4. Test in development environment first

---

**Version**: 1.0
**Date**: January 2026
**Status**: Production Ready
