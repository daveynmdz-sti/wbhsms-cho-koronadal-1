# Production Path Migration Guide

## Overview
This guide explains how to migrate from relative paths to production-safe absolute URLs throughout the WBHSMS application.

## Problem
Many files use relative paths like:
- `../management/admin/dashboard.php`
- `../management/$role/dashboard.php`
- `../auth/employee_login.php`

These break in production environments where the URL structure may differ.

## Solution
Use the production-safe functions from `config/auth_helpers.php`:

### Available Functions

#### 1. `get_role_dashboard_url($role)`
Returns absolute URL to role-specific dashboard
```php
// Instead of:
$dashboard_path = '../management/' . $role . '/dashboard.php';

// Use:
$dashboard_path = get_role_dashboard_url($role);
```

#### 2. `redirect_to_dashboard($role)`
Redirects to role dashboard with proper headers
```php
// Instead of:
header("Location: ../management/$role/dashboard.php");
exit();

// Use:
redirect_to_dashboard($role);
```

#### 3. `get_employee_login_url($message)`
Returns absolute URL to employee login
```php
// Instead of:
$login_url = '../auth/employee_login.php';

// Use:
$login_url = get_employee_login_url();
```

#### 4. `get_base_url()`
Returns production-safe base URL
```php
// Automatically detects:
// Development: http://localhost/wbhsms-cho-koronadal-1
// Production: https://yourdomain.com
$base_url = get_base_url();
```

## Migration Steps

### Step 1: Include auth_helpers
Add this after session includes:
```php
$root_path = dirname(dirname(__DIR__)); // Adjust path depth as needed
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php'; // Add this line
```

### Step 2: Update Redirects
Replace redirect headers:
```php
// Before:
header("Location: ../management/$role/dashboard.php");
exit();

// After:
redirect_to_dashboard($role);
```

### Step 3: Update Breadcrumbs
Replace breadcrumb links:
```php
<!-- Before -->
<a href="../management/admin/dashboard.php">Dashboard</a>

<!-- After -->
<?php $dashboard_path = get_role_dashboard_url($_SESSION['role']); ?>
<a href="<?php echo htmlspecialchars($dashboard_path); ?>">Dashboard</a>
```

### Step 4: Update Navigation Links
Replace static navigation:
```php
<!-- Before -->
<a href="../../management/cashier/dashboard.php">Back to Dashboard</a>

<!-- After -->
<?php $dashboard_path = get_role_dashboard_url('cashier'); ?>
<a href="<?php echo htmlspecialchars($dashboard_path); ?>">Back to Dashboard</a>
```

## Files Requiring Updates

Based on grep search, these files need migration:

### High Priority (Header Redirects)
- `pages/referrals/update_referrals.php`
- `pages/prescription-management/prescription_management.php`
- `pages/prescription-management/create_prescription_standalone.php`
- `pages/management/records_officer/archived_records_management.php`
- `pages/management/admin/patient-records/archived_records_management.php`
- `pages/management/admin/patient-records/patient_records_management.php`
- `pages/billing/billing_reports.php`
- `pages/billing/process_payment.php`
- `pages/clinical-encounter-management/new_consultation_standalone.php`
- `pages/clinical-encounter-management/new_consultation.php`
- `pages/clinical-encounter-management/index.php`

### Medium Priority (Breadcrumbs/Navigation)
- `pages/patient/profile/profile.php`

## Example Migration

### Before (lab_management.php)
```php
// Redirect
$role = $roleMap[$role_id] ?? 'employee';
header("Location: ../management/$role/dashboard.php");
exit();

// Breadcrumb
<a href="../management/admin/dashboard.php">Dashboard</a>
```

### After (lab_management.php)
```php
// Include helpers
require_once $root_path . '/config/auth_helpers.php';

// Redirect
$role = $roleMap[$role_id] ?? 'employee';
redirect_to_dashboard($role);

// Breadcrumb
<?php $dashboard_path = get_role_dashboard_url($_SESSION['role']); ?>
<a href="<?php echo htmlspecialchars($dashboard_path); ?>">Dashboard</a>
```

## Testing

### Development
URLs should work on `http://localhost/wbhsms-cho-koronadal-1`

### Production
URLs should work on your production domain without manual path adjustments

## Benefits

1. **Environment Agnostic**: Works in both development and production
2. **Consistent**: Same URL generation logic throughout application
3. **Maintainable**: Single place to update URL structure
4. **Secure**: Proper output escaping with `htmlspecialchars()`
5. **Robust**: Handles different deployment scenarios automatically

## Migration Checklist

For each file:
- [ ] Include `auth_helpers.php`
- [ ] Replace `header("Location: ../management/...")` with `redirect_to_dashboard()`
- [ ] Replace breadcrumb links with `get_role_dashboard_url()`
- [ ] Replace navigation links with production-safe URLs
- [ ] Test both development and production environments

## Common Patterns

### Pattern 1: Role-based Redirect After Form Processing
```php
// Before
if ($success) {
    $role = $_SESSION['role'];
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// After
if ($success) {
    redirect_to_dashboard();
}
```

### Pattern 2: Dynamic Breadcrumb
```php
<!-- Before -->
<a href="../management/<?php echo $role; ?>/dashboard.php">Dashboard</a>

<!-- After -->
<a href="<?php echo htmlspecialchars(get_role_dashboard_url($role)); ?>">Dashboard</a>
```

### Pattern 3: Back Button Links
```php
<!-- Before -->
<a href="../management/admin/dashboard.php" class="btn">Back to Dashboard</a>

<!-- After -->
<a href="<?php echo htmlspecialchars(get_role_dashboard_url('admin')); ?>" class="btn">Back to Dashboard</a>
```

This migration ensures reliable navigation across all deployment environments!