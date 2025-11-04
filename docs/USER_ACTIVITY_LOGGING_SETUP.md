# User Activity Logging Implementation - Setup Instructions

## Overview
This implementation adds comprehensive user activity logging for employee and admin sessions in the WBHSMS system. All session-related activities are now tracked in real-time.

## Database Migration Required

**IMPORTANT**: Before using the new logging features, you must run the database migration to add required columns.

### Option 1: Run via phpMyAdmin (Recommended for XAMPP)

1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Select your `wbhsms` database
3. Go to the "SQL" tab
4. Copy and paste the contents of `database/migrations/add_activity_log_columns.sql`
5. Click "Go" to execute the migration

### Option 2: Run via HTTP (Browser)

1. Access: `http://localhost/wbhsms-cho-koronadal-1/scripts/migrate_activity_logs.php`
2. The script will automatically run the migration and show results

### Option 3: Command Line (if PHP is in PATH)

```bash
cd c:\xampp\htdocs\wbhsms-cho-koronadal-1
php scripts/migrate_activity_logs.php
```

## What Gets Logged

The system now logs the following activities automatically:

### Authentication Events
- ✅ **Login Success**: Employee/admin successful login attempts
- ✅ **Login Failed**: Failed login attempts (invalid credentials)
- ✅ **Logout**: Manual logout actions
- ✅ **Session Start**: When a new session is created
- ✅ **Session End**: When session ends normally
- ✅ **Session Timeout**: When session expires due to inactivity

### Security Events
- ✅ **Password Change**: When users change their own password
- ✅ **Password Reset**: When admin resets a user's password
- ✅ **Account Lock**: When account gets locked
- ✅ **Account Unlock**: When admin unlocks an account

### Data Captured
For each event, the following information is recorded:
- **admin_id**: ID of admin performing the action (if applicable)
- **employee_id**: ID of employee affected by the action
- **user_type**: Either 'employee' or 'admin'
- **action_type**: Type of action (login, logout, password_change, etc.)
- **description**: Human-readable description of the action
- **ip_address**: Client IP address (supports proxy headers)
- **device_info**: User agent string (browser/device information)
- **created_at**: Timestamp of the action

## Files Modified

### Core Implementation
- `utils/UserActivityLogger.php` - Main logging service class
- `config/session/employee_session.php` - Updated session management with logging
- `pages/management/auth/employee_login.php` - Added login/logout logging
- `pages/management/auth/employee_logout.php` - Added logout logging
- `pages/user/user_settings.php` - Added password change logging
- `pages/management/admin/user-management/password_manager.php` - Added password reset/unlock logging

### Database
- `database/migrations/add_activity_log_columns.sql` - Migration script
- `scripts/migrate_activity_logs.php` - Automated migration runner
- `scripts/test_activity_logging.php` - Comprehensive test suite

## Usage Examples

### Using the Logger in Your Code

```php
// Include the logger
require_once $root_path . '/utils/UserActivityLogger.php';

// Log a successful login
activity_logger()->logLogin($user_id, 'employee', $username);

// Log a failed login
activity_logger()->logFailedLogin($username, 'employee');

// Log a password change
activity_logger()->logPasswordChange($user_id, 'employee');

// Log a custom activity
log_user_activity($admin_id, $employee_id, 'employee', 'update', 'Profile updated');
```

### Direct Database Insert

```sql
INSERT INTO user_activity_logs
  (admin_id, employee_id, user_type, action_type, description, ip_address, device_info, created_at)
VALUES
  (?, ?, ?, ?, ?, ?, ?, NOW());
```

## Testing the Implementation

### 1. Run the Test Suite
Visit: `http://localhost/wbhsms-cho-koronadal-1/scripts/test_activity_logging.php`

This will run 15 comprehensive tests covering:
- Database structure validation
- All logging functions
- IP address detection
- Device info capture
- Recent logs verification

### 2. Manual Testing
1. **Login Test**: Try logging in with valid credentials
2. **Failed Login Test**: Try logging in with invalid credentials
3. **Password Change Test**: Change your password in user settings
4. **Logout Test**: Log out normally
5. **Session Timeout Test**: Leave session idle for 30+ minutes

### 3. Verify in Database
Check the `user_activity_logs` table:
```sql
SELECT * FROM user_activity_logs ORDER BY created_at DESC LIMIT 10;
```

### 4. View in Application
Access the activity logs page:
`http://localhost/wbhsms-cho-koronadal-1/pages/management/admin/user-management/user_activity_logs.php`

## Security Features

### IP Address Detection
- Supports proxy headers (X-Forwarded-For, X-Real-IP, etc.)
- Validates IP addresses
- Fallbacks to REMOTE_ADDR or localhost

### Device Information
- Captures full user agent string
- Truncates if longer than 500 characters
- Handles missing user agent gracefully

### Data Integrity
- All logging operations use prepared statements
- Graceful error handling (logging failure doesn't break application)
- Comprehensive error logging for debugging

## Monitoring and Maintenance

### Regular Checks
1. Monitor log table size: `SELECT COUNT(*) FROM user_activity_logs;`
2. Check for failed logging attempts in error logs
3. Review suspicious activity patterns

### Log Retention
Consider implementing log rotation/archival for large deployments:
```sql
-- Archive logs older than 1 year
CREATE TABLE user_activity_logs_archive AS 
SELECT * FROM user_activity_logs 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Delete archived logs
DELETE FROM user_activity_logs 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

## Troubleshooting

### Common Issues

1. **Migration Fails**
   - Check database permissions
   - Ensure XAMPP MySQL is running
   - Run migration steps manually in phpMyAdmin

2. **No Logs Appearing**
   - Verify migration completed successfully
   - Check if UserActivityLogger.php is being included
   - Review error logs for exceptions

3. **Duplicate Column Errors**
   - Migration already ran successfully
   - Skip to testing phase

4. **Permission Errors**
   - Ensure web server has read access to utils/ directory
   - Check file permissions on UserActivityLogger.php

### Error Logs
Check these locations for errors:
- XAMPP: `C:\xampp\apache\logs\error.log`
- Application: Project `/logs/` directory

## Performance Considerations

### Database Indexes
The migration automatically creates performance indexes:
- `idx_user_activity_logs_user_type`
- `idx_user_activity_logs_action_type`
- `idx_user_activity_logs_created_at`
- `idx_user_activity_logs_ip_address`

### Memory Usage
- Activity logger uses singleton pattern
- Minimal memory footprint
- No persistent connections

## Compliance and Auditing

This implementation provides:
- ✅ **Complete audit trail** of user actions
- ✅ **IP address tracking** for security analysis
- ✅ **Device fingerprinting** for anomaly detection
- ✅ **Comprehensive session management** monitoring
- ✅ **Password security events** tracking
- ✅ **Admin action accountability**

Perfect for healthcare compliance requirements and security auditing.

## Support

If you encounter issues:
1. Run the test script first
2. Check error logs
3. Verify database migration completed
4. Review file permissions
5. Ensure all required files are present

The implementation is backward compatible and won't break existing functionality even if migration fails.