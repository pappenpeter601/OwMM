# Transaction Migration Guide

## Overview

The migration script transfers all data from the old `TRANSACTION` table to the new `transactions` table in the same database, including:

- ✅ All transaction records
- ✅ Category mapping (CATEGORY → category_id)
- ✅ Documents (LONGBLOB → files)
- ✅ Auto-calculated business_year from booking_date
- ✅ All comments and metadata

## Prerequisites

Before running the migration, ensure:

1. **Database schema updated**: The new tables (`transactions`, `transaction_categories`, `transaction_documents`) exist in your database
   ```bash
   Import /home/bee/git/OwMM/database/schema.sql into your database
   ```

2. **Both tables exist in same database**:
   - Old table: `TRANSACTION` (with columns: id, Buchungstag, Buchungstext, Verwendungszweck, Zahlungspflichtiger, IBAN, Betrag, DOCUMENT, CATEGORY, Comment)
   - New table: `transactions` (empty, ready for data)

3. **Write permissions**: Ensure the `/uploads/documents/` directory is writable

## How to Run the Migration

### OPTION 1: Web Browser (Easiest - Recommended)

**Step 1:** Open the migration script in your browser:
```
http://localhost/OwMM/admin/migrate_transactions.php
```

**Step 2:** Watch the output as the script:
- Checks for the old TRANSACTION table
- Reads all transactions
- Displays available categories
- Migrates data
- Saves documents from LONGBLOB
- Shows detailed results

**Step 3:** After completion, verify results in the browser output

---

### OPTION 2: Command Line (For Servers/Automation)

**Step 1:** Open terminal and navigate to project:
```bash
cd /home/bee/git/OwMM
```

**Step 2:** Run the PHP script:
```bash
php admin/migrate_transactions.php
```

**Step 3:** Watch the console output showing progress

---

## What the Script Does

### 1. **Validates Old Table**
- Checks that `TRANSACTION` table exists
- Counts total records

### 2. **Loads Category Mapping**
- Reads available categories from `transaction_categories`
- Maps old string categories to new category IDs

### 3. **Migrates Each Transaction**
For each record in old TRANSACTION:
- Extracts `Buchungstag` (booking date)
- Auto-calculates `business_year` from booking date (e.g., "22.10.24" → 2024)
- Maps CATEGORY string to category_id
- Copies all fields: purpose, payer, iban, amount, comment
- Generates transaction ID

### 4. **Extracts and Saves Documents**
- Reads LONGBLOB from DOCUMENT column
- Detects file type (PDF, JPG, PNG)
- Saves as file to `/uploads/documents/`
- Creates entry in `transaction_documents` table
- Links document to transaction

### 5. **Reports Results**
Shows:
- Total migrated transactions
- Number of documents saved
- Breakdown by category
- Breakdown by business year
- Any errors encountered

---

## Example Output

```
=== Transaction Migration Script ===

✓ Found TRANSACTION table in database
✓ Found 847 transactions in old table
✓ Created documents directory

=== Available Categories ===
  - fixkosten (ID: 1)
  - beitrag einsatzeinheit (ID: 2)
  - beitrag förderer (ID: 3)
  - mieteinnahme (ID: 4)
  - verpflegung (ID: 5)
  - event mit gewinnerwartung (ID: 6)
  - event ohne gewinnerwartung (ID: 7)
  - anschaffung (ID: 8)
  - instandhaltung (ID: 9)

  Processed 50 transactions...
  Processed 100 transactions...
  ...
✓ Transaction commit successful

=== Migration Results ===
Migrated: 847 transactions
Documents saved: 234
Skipped: 0 transactions

=== Verification ===
New transactions table now contains: 847 records

Transactions by category:
  - Fixkosten: 145
  - Beitrag Einsatzeinheit: 234
  - Verpflegung: 89
  - Anschaffung: 156
  - Uncategorized: 183

Transactions by business year:
  - 2024: 312
  - 2023: 389
  - 2022: 146

✓ Migration completed successfully!
```

---

## After Migration

### 1. **Verify in Admin Panel**
- Log in to `/admin/`
- Go to **Kontoführung** (Cash Management)
- Filter by different categories and years
- Check that documents are visible and downloadable

### 2. **Review Documents**
- Check `/uploads/documents/` folder
- Verify files were saved correctly
- Files are named: `doc_[transaction_id]_[timestamp].pdf|jpg|png`

### 3. **Categorize Uncategorized Transactions**
- Any transactions with blank CATEGORY in old table will have category_id = NULL
- Review "Uncategorized" transactions in Kontoführung
- Manually assign categories as needed

### 4. **Backup Old Data** (Optional but Recommended)
- Keep the old TRANSACTION table as backup
- After verification, you can delete it or archive it separately

### 5. **Test Filters**
- Filter by business year
- Filter by category
- Test date range filtering
- Verify calculations (income/expense totals)

---

## Troubleshooting

### Error: "TRANSACTION table not found"
**Solution:** Ensure the old TRANSACTION table exists in the same database
```sql
SELECT * FROM TRANSACTION LIMIT 1;
```

### Error: "Could not extract year from booking date"
**Solution:** Some booking dates may be in unexpected format. Check the data:
```sql
SELECT DISTINCT Buchungstag FROM TRANSACTION LIMIT 10;
```
The script supports: DD.MM.YY, DD.MM.YYYY, and YYYY-MM-DD formats

### Documents not saving
**Solution:** Check write permissions on uploads folder:
```bash
chmod 755 /home/bee/git/OwMM/uploads/documents/
```

### Migration seems slow
**Solution:** This is normal for 1000+ transactions. The script shows progress every 50 records.

---

## Column Mapping Reference

| Old Column | New Column | Notes |
|-----------|-----------|--------|
| id | id | Preserved |
| Buchungstag | booking_date | Kept as-is |
| Buchungstext | booking_text | Copied |
| Verwendungszweck | purpose | Copied |
| Zahlungspflichtiger | payer | Copied |
| IBAN | iban | Copied |
| Betrag | amount | Copied |
| CATEGORY (string) | category_id (FK) | Mapped to ID |
| Comment | comment | Copied |
| DOCUMENT (LONGBLOB) | transaction_documents | Extracted to file |
| (auto) | business_year | Auto-calculated from booking_date |
| (auto) | created_by | Set to admin user (1) |
| (auto) | created_at | Set to current timestamp |

---

## Safety Notes

✅ **Safe to Run Multiple Times**
- The script uses INSERT (not REPLACE), so running it twice will create duplicates
- Make sure to back up before running if concerned

✅ **Non-Destructive**
- Old TRANSACTION table is not deleted
- Only new tables are populated

✅ **Transaction Rollback**
- If any error occurs during migration, all changes are rolled back
- Database will be left in consistent state

---

## Support

For issues or questions:
1. Check the error message in the script output
2. Review database logs
3. Verify table structure matches schema.sql
4. Ensure file permissions are correct

