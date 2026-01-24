#!/bin/bash
# Calendar Module Validation & Testing Script
# Usage: bash validate_calendar.sh

echo "=========================================="
echo "Calendar Module Validation Script"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PROJECT_ROOT="/home/bee/git/OwMM"

echo -e "${YELLOW}1. Checking PHP Syntax${NC}"
echo "   Validating admin/calendar.php..."
if php -l "$PROJECT_ROOT/admin/calendar.php" > /dev/null 2>&1; then
    echo -e "   ${GREEN}✓ calendar.php syntax OK${NC}"
else
    echo -e "   ${RED}✗ calendar.php has syntax errors${NC}"
    php -l "$PROJECT_ROOT/admin/calendar.php"
fi

echo "   Validating admin/calendar_settings.php..."
if php -l "$PROJECT_ROOT/admin/calendar_settings.php" > /dev/null 2>&1; then
    echo -e "   ${GREEN}✓ calendar_settings.php syntax OK${NC}"
else
    echo -e "   ${RED}✗ calendar_settings.php has syntax errors${NC}"
    php -l "$PROJECT_ROOT/admin/calendar_settings.php"
fi
echo ""

echo -e "${YELLOW}2. Checking Database Schema${NC}"
if grep -q "CREATE TABLE IF NOT EXISTS \`calendar_settings\`" "$PROJECT_ROOT/database/schema.sql"; then
    echo -e "   ${GREEN}✓ calendar_settings table in schema.sql${NC}"
else
    echo -e "   ${RED}✗ calendar_settings table NOT in schema.sql${NC}"
fi

if [ -f "$PROJECT_ROOT/database/migration_calendar_settings.sql" ]; then
    echo -e "   ${GREEN}✓ migration_calendar_settings.sql exists${NC}"
else
    echo -e "   ${RED}✗ migration_calendar_settings.sql NOT found${NC}"
fi
echo ""

echo -e "${YELLOW}3. Checking Code Quality${NC}"
echo "   Looking for CalDAV implementation improvements..."

# Check for proper Depth header handling
if grep -q "if (\$includeDepth && in_array" "$PROJECT_ROOT/admin/calendar.php"; then
    echo -e "   ${GREEN}✓ Conditional Depth header handling present${NC}"
else
    echo -e "   ${RED}✗ Depth header handling not found${NC}"
fi

# Check for .ics upload handler
if grep -q "'action' === 'upload'" "$PROJECT_ROOT/admin/calendar.php"; then
    echo -e "   ${GREEN}✓ .ics upload handler implemented${NC}"
else
    echo -e "   ${RED}✗ Upload handler not found${NC}"
fi

# Check for proper date format with Z suffix
if grep -q "Ymd\\\\THis\\\\Z" "$PROJECT_ROOT/admin/calendar.php"; then
    echo -e "   ${GREEN}✓ RFC 5545 compliant date format (with Z suffix)${NC}"
else
    echo -e "   ${RED}✗ Date format may not be RFC 5545 compliant${NC}"
fi

# Check for REPORT query fix
if grep -q "<<<'XML'" "$PROJECT_ROOT/admin/calendar.php"; then
    echo -e "   ${GREEN}✓ Proper heredoc syntax for REPORT query${NC}"
else
    echo -e "   ${RED}✗ REPORT query syntax may have issues${NC}"
fi

# Check for improved error logging
if grep -q "error_log.*\$method \$url.*HTTP" "$PROJECT_ROOT/admin/calendar.php"; then
    echo -e "   ${GREEN}✓ Enhanced error logging implemented${NC}"
else
    echo -e "   ${RED}✗ Error logging may be incomplete${NC}"
fi
echo ""

echo -e "${YELLOW}4. Checking Documentation${NC}"
if [ -f "$PROJECT_ROOT/docs/CALENDAR_CALDAV_FIXES.md" ]; then
    echo -e "   ${GREEN}✓ CALENDAR_CALDAV_FIXES.md documentation exists${NC}"
else
    echo -e "   ${RED}✗ Documentation not found${NC}"
fi

if [ -f "$PROJECT_ROOT/CALENDAR_FIXES_SUMMARY.txt" ]; then
    echo -e "   ${GREEN}✓ CALENDAR_FIXES_SUMMARY.txt exists${NC}"
else
    echo -e "   ${RED}✗ Summary file not found${NC}"
fi
echo ""

echo -e "${YELLOW}5. File Statistics${NC}"
CALENDAR_LINES=$(wc -l < "$PROJECT_ROOT/admin/calendar.php")
echo "   admin/calendar.php: $CALENDAR_LINES lines"

SCHEMA_LINES=$(wc -l < "$PROJECT_ROOT/database/schema.sql")
echo "   database/schema.sql: $SCHEMA_LINES lines"
echo ""

echo -e "${YELLOW}6. Testing Calendar Configuration${NC}"
if [ -f "$PROJECT_ROOT/admin/calendar_settings.php" ]; then
    # Extract database connection test
    echo "   Checking calendar_settings.php database access..."
    if php -r "
        require_once '$PROJECT_ROOT/config/config.php';
        require_once '$PROJECT_ROOT/config/database.php';
        try {
            \$db = getDBConnection();
            \$stmt = \$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"calendar_settings\"');
            \$result = \$stmt->fetch(PDO::FETCH_NUM);
            if (\$result[0] > 0) {
                echo 'calendar_settings table exists in database';
            } else {
                echo 'calendar_settings table NOT in database - run migration';
            }
        } catch (Exception \$e) {
            echo 'Cannot connect to database: ' . \$e->getMessage();
        }
    " 2>/dev/null; then
        echo ""
    fi
fi
echo ""

echo -e "${YELLOW}=========================================="
echo "Validation Complete"
echo "=========================================="
echo ""
echo "Next Steps:"
echo "1. Deploy files to production"
echo "2. Run database migration: mysql owmm_db < migration_calendar_settings.sql"
echo "3. Configure calendar settings in admin panel"
echo "4. Test calendar operations"
echo ""
echo "Documentation: docs/CALENDAR_CALDAV_FIXES.md"
echo "Quick Summary: CALENDAR_FIXES_SUMMARY.txt"
