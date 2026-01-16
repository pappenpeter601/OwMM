# Flexible Permission System

## Overview
Simple and flexible permission system that allows users to have multiple permissions instead of a single hardcoded role.

## Key Features
- **Multiple Permissions**: Users can have any combination of permissions
- **Easy Management**: Visual interface in Admin → Einstellungen → Berechtigungen
- **Backward Compatible**: Old role field still exists for basic categorization
- **No Over-Engineering**: Just 2 tables and simple functions

## Database Structure

### New Tables

#### `permissions`
Defines all available permissions in the system:
- `name`: Machine name (e.g., `edit_operations`)
- `display_name`: Human readable name (e.g., "Einsätze verwalten")
- `category`: Group permissions (e.g., "Inhalte", "Finanzen")

#### `user_permissions`
Many-to-many junction table:
- Links users to their permissions
- Tracks who granted each permission and when

## Default Permissions

| Permission | Display Name | Category | Description |
|-----------|--------------|----------|-------------|
| `view_dashboard` | Dashboard ansehen | Allgemein | Access to admin dashboard |
| `edit_operations` | Einsätze verwalten | Inhalte | Manage operations |
| `edit_events` | Veranstaltungen verwalten | Inhalte | Manage events |
| `edit_content` | Seiteninhalte bearbeiten | Inhalte | Edit page content |
| `edit_board` | Kommando verwalten | Inhalte | Manage board members |
| `edit_cash` | Kontoführung | Finanzen | Financial transactions |
| `check_transactions` | Kassenprüfung | Finanzen | Transaction checking |
| `manage_settings` | Einstellungen verwalten | Administration | System settings |
| `manage_users` | Benutzer verwalten | Administration | User management |

## Usage

### In Code
Check permissions using the `has_permission()` function:

```php
if (has_permission('edit_operations')) {
    // User can edit operations
}
```

### Existing Functions (Updated)
All existing permission functions now use the new system:
- `can_edit_operations()` → checks `edit_operations` permission
- `can_edit_events()` → checks `edit_events` permission
- `can_edit_page_content()` → checks `edit_content` or `edit_board` permission
- `can_edit_cash()` → checks `edit_cash` permission
- `can_check_transactions()` → checks `check_transactions` permission

### Admin User
The `admin` role automatically has ALL permissions without needing explicit grants.

## Managing Permissions

1. Go to **Admin → Einstellungen**
2. Click **Berechtigungen** tab
3. Each user shows their permissions grouped by category
4. Click a permission button to toggle:
   - **Green with ✓**: User has this permission (click to revoke)
   - **Outlined**: User doesn't have this permission (click to grant)

## Migration

Run `database/migration_magiclink_auth.sql` which includes:
- New `permissions` table
- New `user_permissions` table
- Default permissions data
- Grant all permissions to admin user (id=1)

## Role Field
The `role` enum field still exists for:
- Basic user categorization
- Backward compatibility
- Display purposes

But actual permission checks now use the flexible permission system.
