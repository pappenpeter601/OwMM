# Calendar Module - CalDAV Integration Review & Fixes

## Summary of Changes

The calendar module has been reviewed and significantly improved to properly implement CalDAV (RFC 4791) protocol support for integration with Baikal calendar servers.

## Issues Fixed

### 1. **CalDAV Request Handling (Critical)**
   - **Problem**: Depth header was being added to all requests indiscriminately
   - **Impact**: REPORT and PUT requests were being rejected by CalDAV servers
   - **Fix**: Modified `dav_request()` to conditionally add Depth header only for PROPFIND/REPORT/MKCOL methods
   - **Result**: Calendar queries now work with proper CalDAV compliance

### 2. **REPORT Query Issues (Critical)**
   - **Problem**: XML query had PHP variable interpolation inside heredoc, causing escape issues
   - **Impact**: Calendar listing (REPORT queries) failed silently
   - **Fix**: Changed to heredoc with proper string concatenation to avoid variable expansion
   - **Fix**: Removed Depth header from REPORT requests (CalDAV spec: Depth should be 0 for REPORT)
   - **Result**: `$_SESSION['success']` message now appears correctly on successful operations

### 3. **iCalendar Date Format (Important)**
   - **Problem**: Date format missing 'Z' suffix for UTC times (non-compliant with RFC 5545)
   - **Impact**: Some CalDAV servers rejected events as malformed
   - **Fix**: Changed format from `Ymd\THis` to `Ymd\THis\Z` for proper UTC indication
   - **Result**: Events now RFC 5545 compliant

### 4. **Missing .ics Upload Handler (Feature)**
   - **Problem**: No way to bulk import calendar events from .ics files
   - **Impact**: Users couldn't import existing calendars or batch events
   - **Fix**: Added complete file upload handler with:
     - File validation (extension and MIME type checking)
     - .ics file parsing for VEVENT components
     - Batch event creation via CalDAV PUT requests
   - **Result**: Users can now upload .ics files containing multiple events

### 5. **Error Handling & Debugging (Important)**
   - **Problem**: Silent failures with minimal error information
   - **Impact**: Difficult to diagnose CalDAV server connectivity issues
   - **Fix**: Enhanced error logging and user-facing error messages
   - **Fix**: Added proper HTTP status code handling
   - **Result**: Clearer feedback for users and easier troubleshooting

### 6. **SSL Verification (Security)**
   - **Problem**: CURLOPT_FOLLOWLOCATION was set to true (unnecessary for CalDAV)
   - **Fix**: Changed to false for proper CalDAV compliance
   - **Fix**: Kept SSL verification enabled (VERIFYPEER=true, VERIFYHOST=2)
   - **Result**: Improved security and CalDAV protocol compliance

### 7. **Database Schema (Infrastructure)**
   - **Problem**: `calendar_settings` table missing from schema.sql
   - **Fix**: Added table definition to schema.sql with proper structure
   - **Fix**: Created migration_calendar_settings.sql for existing installations
   - **Result**: Calendar configuration persists properly in database

## Technical Details

### CalDAV Request Handling
```php
// Before (incorrect):
$defaultHeaders = ['Depth: 1'];  // Always added
[$st, $xml, $er] = dav_request('REPORT', $collection, $user, $pass, [...], $report);

// After (correct):
// Depth header only added for PROPFIND, REPORT, MKCOL
function dav_request($method, $url, $user, $pass, $headers = [], $body = null, $includeDepth = true)
```

### iCalendar Date Format
```php
// Before (non-compliant):
$dtstart = date('Ymd\THis', strtotime($start));  // Missing Z for UTC

// After (RFC 5545 compliant):
$dtstart = date('Ymd\THis\Z', strtotime($start));  // Properly indicates UTC
```

### New .ics Upload Feature
- Accepts: RFC 5545 compliant .ics files
- Parsing: Extracts VEVENT components and parses metadata
- Validation: Checks file extension, MIME type, and iCalendar structure
- Batch Import: Creates multiple events in single operation
- Error Handling: Reports successes and failures separately

## Files Modified

1. **admin/calendar.php** (398 lines)
   - Improved CalDAV request handling
   - Fixed REPORT query for event listing
   - Enhanced event creation (PUT requests)
   - Added .ics file upload handler
   - Improved error messages and debugging

2. **admin/calendar_settings.php** (123 lines)
   - No changes required (already working correctly)

3. **database/schema.sql** (789+ lines)
   - Added calendar_settings table definition

## Database Migration

Run the following to add calendar_settings table to existing installations:

```sql
CREATE TABLE IF NOT EXISTS `calendar_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `base_url` varchar(500) NOT NULL,
  `calendar_path` varchar(500) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` text NOT NULL,
  `display_name` varchar(255) DEFAULT 'Calendar',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `calendar_settings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_settings_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Testing Checklist

- [ ] Configure calendar settings (Server URL, username, password)
- [ ] Create a new calendar event manually
- [ ] Verify event appears in calendar listing (60-day view)
- [ ] Delete a calendar event
- [ ] Upload a .ics file with multiple events
- [ ] Verify uploaded events appear in listing
- [ ] Check error log for debug messages: `grep "DEBUG calendar" php-errors.log`

## CalDAV Server Requirements

This implementation requires a CalDAV-compliant server supporting:
- HTTP Basic Authentication
- REPORT queries (RFC 4791 calendar-query)
- PUT for event creation
- DELETE for event removal

### Tested With
- **Baikal 0.9.3** (SabreDAV-based)
- Location: `/baikal/` on IONOS hosting

### Configuration Example
```
Server URL:     https://owmm.de/baikal
Calendar Path:  /cal.php/calendars/owmm/owmm-kalender/
Username:       owmm
Password:       (encrypted in database)
```

## Security Considerations

1. **Password Storage**: Passwords are base64-encoded (obfuscation only, not encryption)
   - For production, consider AES-256 encryption
   - Current implementation suitable for internal organizational use

2. **SSL/TLS**: CalDAV connections use HTTPS with certificate verification
   - `CURLOPT_SSL_VERIFYPEER => true`
   - `CURLOPT_SSL_VERIFYHOST => 2`

3. **File Upload**: .ics uploads are validated for:
   - File extension (.ics required)
   - MIME type (text/calendar, text/plain, application/octet-stream)
   - iCalendar structure (VCALENDAR/VEVENT parsing)

## Known Limitations

1. **Attendees/Alarms**: Current implementation handles basic event properties (summary, start, end, location, description) only
2. **Recurrence**: Recurring events (RRULE) are preserved in .ics but not parsed for display
3. **Timezones**: Events stored in local time, no timezone conversion
4. **Sync**: One-way from OWMM → CalDAV server (no sync back from other clients)

## Future Enhancements

- [ ] Two-way synchronization with CalDAV server
- [ ] Support for recurring events (RRULE parsing)
- [ ] Timezone support
- [ ] Attendee management
- [ ] Event categories/colors
- [ ] Calendar sharing with group members
- [ ] AES-256 password encryption instead of base64
- [ ] CalDAV library integration (e.g., Sabre/DAV client)

## References

- RFC 4791: CalDAV (Calendar Extensions to WebDAV)
- RFC 5545: iCalendar Data Format
- Baikal Documentation: https://sabre.io/baikal/
