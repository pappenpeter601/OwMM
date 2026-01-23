# HowTo: Adding a New Page to the Admin Section

This guide walks you through all the steps required to properly add a new administrative page to the system. Following this checklist ensures you won't miss any crucial steps.

## Prerequisites

- Database access (for adding permissions)
- Admin access to the system
- Basic understanding of PHP and the codebase structure

---

## Step 1: Create the PHP Page File

1. **Location:** Create your new page in `/admin/` directory
2. **Naming:** Use lowercase with underscores (e.g., `my_feature.php`)
3. **Required structure:** Include these essential elements at the top:

```php
<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !has_permission('my_feature.php')) {
    redirect('dashboard.php');
}

$page_title = 'My Feature Title';
include 'includes/header.php';
?>

<!-- Your page content here -->

<?php include 'includes/footer.php'; ?>
```

**Important:** The permission check uses the PHP filename (e.g., `my_feature.php`) as the permission name.

---

## Step 2: Add Permission to Database

### 2.1 Insert Permission Record

Execute this SQL query to add your new permission:

```sql
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`) VALUES
('my_feature.php', 'My Feature', 'Description of what this page does', 'Category Name');
```

**Permission Categories:**
- `Basic` - General content/data management
- `Finanzen` - Financial operations
- `Kommando` - Command/board related
- `Administration` - System administration
- `Kassenprüfer` - Auditing functions

### 2.2 Grant Permission to Users

Option A - Grant to specific user:
```sql
INSERT INTO `user_permissions` (`user_id`, `permission_id`)
SELECT 1, id FROM `permissions` WHERE name = 'my_feature.php';
```

Option B - Grant to all admins automatically (admins get all permissions by default, no action needed)

---

## Step 3: Add Menu Item to Sidebar

Edit `/admin/includes/header.php` and add your navigation item in the appropriate section:

```php
<?php if (has_permission('my_feature.php')): ?>
<a href="my_feature.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'my_feature.php' ? 'active' : ''; ?>">
    <i class="fas fa-icon-name"></i> My Feature
</a>
<?php endif; ?>
```

**Icon Selection:**
- Use Font Awesome 6 icons: https://fontawesome.com/icons
- Examples: `fa-home`, `fa-file-alt`, `fa-users`, `fa-wallet`, `fa-calendar`

**Menu Structure Tips:**
- Place logically with related features
- Financial features typically go together
- Content management features go together
- Admin-only features go at the bottom

---

## Step 4: Add Dashboard Card (Optional)

If your feature should appear on the dashboard, edit `/admin/dashboard.php`:

### 4.1 Add Permission Check

Add your permission to the `$has_any_permission` check at the top:

```php
$has_any_permission = is_admin() || has_permission('kontofuehrung.php') || 
                      has_permission('my_feature.php') || // Add your permission
                      has_permission('members.php') || ...
```

### 4.2 Add Dashboard Card

In the dashboard permissions section (around line 190), add your card:

```php
<?php if (has_permission('my_feature.php')): ?>
<div class="stat-box-link" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="stat-label">
        <i class="fas fa-icon-name" style="font-size: 2rem; color: rgba(255,255,255,0.9);"></i>
    </div>
    <div class="stat-title">My Feature</div>
    <div class="stat-description">Brief description of what this does</div>
    <a href="my_feature.php" class="stretched-link"></a>
</div>
<?php endif; ?>
```

**Color Gradient Tips:**
- Use contrasting colors for visual appeal
- Keep consistency with other cards
- Ensure text remains readable

---

## Step 5: Design & Layout

### 5.1 Use Standard Layout Structure

Follow this basic page structure for consistency:

```php
<div class="page-header">
    <h1><?php echo $page_title; ?></h1>
    <div class="page-actions">
        <a href="some_action.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New
        </a>
    </div>
</div>

<div class="content-section">
    <!-- Your main content here -->
</div>
```

### 5.2 Common CSS Classes

**Buttons:**
- `.btn` - Base button class
- `.btn-primary` - Main action (red)
- `.btn-secondary` - Secondary action (blue)
- `.btn-success` - Success/confirm (green)
- `.btn-danger` - Delete/cancel (red)
- `.btn-outline` - Outlined button

**Form Elements:**
- `.form-group` - Wraps label + input
- `.form-control` - Input/select/textarea styling
- `.form-row` - Side-by-side form elements
- `.required` - Red asterisk for required fields

**Tables:**
- `.data-table` - Standard data table styling
- `.table-responsive` - Scrollable on mobile

**Messages:**
- `.alert` - Base alert class
- `.alert-success` - Success messages (green)
- `.alert-danger` - Error messages (red)
- `.alert-info` - Info messages (blue)
- `.alert-warning` - Warning messages (orange)

**Layout:**
- `.content-section` - Main content wrapper
- `.card` - Card container
- `.stats-grid` - Grid layout for statistics
- `.stat-box` - Individual stat display

### 5.3 Responsive Design

The CSS already includes responsive breakpoints. Follow these guidelines:
- Use flexible units (%, rem) instead of fixed pixels
- Stack elements vertically on mobile
- Hide non-essential elements on small screens
- Test on different screen sizes

---

## Step 6: Helper Functions (Optional)

If you need custom permission helpers, add them to `/includes/functions.php`:

```php
/**
 * Check if user can access my feature
 */
function can_access_my_feature() {
    return has_permission('my_feature.php');
}
```

This allows you to use `can_access_my_feature()` instead of `has_permission('my_feature.php')` throughout the code.

---

## Step 7: Testing Checklist

Before considering your page complete, test the following:

### 7.1 Permission Testing
- [ ] Non-logged-in users are redirected to login
- [ ] Users without permission are redirected to dashboard
- [ ] Users with permission can access the page
- [ ] Admin users can always access the page

### 7.2 Navigation Testing
- [ ] Menu item appears for users with permission
- [ ] Menu item is hidden for users without permission
- [ ] Active menu item is highlighted when on the page
- [ ] Dashboard card appears/works (if applicable)

### 7.3 Functionality Testing
- [ ] All forms submit correctly
- [ ] Data validation works properly
- [ ] Error messages display correctly
- [ ] Success messages display correctly
- [ ] Database operations work as expected

### 7.4 UI/UX Testing
- [ ] Page follows design consistency
- [ ] All buttons and links work
- [ ] Responsive design works on mobile
- [ ] Icons display correctly
- [ ] Text is readable and properly formatted

---

## Common Pitfalls to Avoid

1. **Forgetting the permission check** - Always add `has_permission()` check at the top of your PHP file
2. **Wrong permission name** - Use the exact PHP filename (e.g., `my_feature.php`)
3. **Missing database entry** - Don't forget to add the permission to the database
4. **Sidebar not showing** - Ensure the permission check in `header.php` matches the database entry
5. **Dashboard confusion** - Update the `$has_any_permission` check if adding a dashboard card
6. **Inconsistent styling** - Use existing CSS classes rather than creating custom styles
7. **Missing includes** - Always include `header.php` and `footer.php`
8. **Hardcoded values** - Use configuration constants from `config.php` when possible

---

## Quick Reference: File Locations

| Purpose | File Path |
|---------|-----------|
| New admin page | `/admin/my_feature.php` |
| Database schema | `/database/schema.sql` |
| Permission migration | `/database/migration_*.sql` |
| Sidebar menu | `/admin/includes/header.php` |
| Dashboard | `/admin/dashboard.php` |
| Helper functions | `/includes/functions.php` |
| Admin CSS | `/assets/css/admin.css` |

---

## Example: Complete Implementation

Here's a minimal but complete example for adding a "Reports" page:

### 1. Create `/admin/reports.php`
```php
<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!is_logged_in() || !has_permission('reports.php')) {
    redirect('dashboard.php');
}

$page_title = 'Berichte';
include 'includes/header.php';
?>

<div class="page-header">
    <h1>Berichte</h1>
</div>

<div class="content-section">
    <p>Report content goes here...</p>
</div>

<?php include 'includes/footer.php'; ?>
```

### 2. Add Database Permission
```sql
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`) VALUES
('reports.php', 'Berichte', 'Berichte und Statistiken anzeigen', 'Administration');
```

### 3. Add to Sidebar (in `/admin/includes/header.php`)
```php
<?php if (has_permission('reports.php')): ?>
<a href="reports.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
    <i class="fas fa-chart-bar"></i> Berichte
</a>
<?php endif; ?>
```

### 4. Add to Dashboard (in `/admin/dashboard.php`)
```php
// In permission check (line ~15)
$has_any_permission = is_admin() || has_permission('kontofuehrung.php') || 
                      has_permission('reports.php') || ... 

// In permissions section (line ~190)
<?php if (has_permission('reports.php')): ?>
<div class="stat-box-link" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
    <div class="stat-label">
        <i class="fas fa-chart-bar" style="font-size: 2rem; color: rgba(255,255,255,0.9);"></i>
    </div>
    <div class="stat-title">Berichte</div>
    <div class="stat-description">Statistiken und Auswertungen</div>
    <a href="reports.php" class="stretched-link"></a>
</div>
<?php endif; ?>
```

---

## Summary

**Essential Steps (Don't Skip!):**
1. ✅ Create PHP file in `/admin/`
2. ✅ Add permission check in the file
3. ✅ Insert permission record in database
4. ✅ Add menu item to sidebar
5. ✅ Update dashboard permission check (if adding dashboard card)
6. ✅ Test all permissions thoroughly

**Optional Steps:**
- Add dashboard card for quick access
- Create helper functions for cleaner code
- Add custom validation or business logic

Following this guide ensures your new admin page is properly integrated, secure, and consistent with the rest of the system.
