# Production Authentication System Implementation

## Overview
Enhanced employee authentication system for WBHSMS with production-ready session management, security features, and proper redirect handling.

## Key Features
- ✅ **Production-Safe Redirects**: Uses absolute URLs instead of relative paths
- ✅ **Session Security**: IP address and user agent validation
- ✅ **Timeout Management**: Configurable session timeout with automatic cleanup
- ✅ **Role-Based Access**: Granular permission control
- ✅ **Output Buffer Safety**: Prevents headers already sent errors
- ✅ **Security Logging**: Audit trail for authentication events

## Core Functions (config/auth_helpers.php)

### Authentication Functions
```php
// Check if employee is logged in
is_employee_logged_in() : bool

// Get employee session data
get_employee_session($key) : mixed

// Clear employee session
clear_employee_session() : void

// Get login URL (production-safe)
get_employee_login_url() : string

// Redirect to login (with message)
redirect_to_employee_login($message = '') : void

// Require authentication with roles
require_employee_auth($allowed_roles = []) : void
```

### Security Functions
```php
// Enhanced security checks
handle_session_security() : void

// Session timeout management
check_session_timeout() : void

// Set session variables safely
set_employee_session($key, $value) : void
```

## Implementation Pattern

### Standard Page Protection
```php
<?php
// 1. Start output buffering
ob_start();

// 2. Set root path (adjust depth as needed)
$root_path = dirname(dirname(__DIR__));

// 3. Include authentication
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php';

// 4. Apply security checks
handle_session_security();
check_session_timeout();
require_employee_auth(['admin', 'doctor', 'nurse']);

// 5. Include other files
require_once $root_path . '/config/db.php';

// 6. Your page content...
?>
```

### API Endpoint Protection
```php
<?php
ob_start();
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php';

// For APIs, use JSON error responses
if (!is_employee_logged_in()) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Continue with API logic...
?>
```

## Configuration

### Environment Variables (config/env.php)
```php
// Production detection
$_ENV['APP_ENV'] = 'production'; // or 'development'

// Base URL for absolute redirects
$_ENV['WBHSMS_BASE_URL'] = 'https://yourdomain.com/wbhsms-cho-koronadal-1';
```

### Session Timeout (config/auth_helpers.php)
```php
$timeout = 3600; // 1 hour in seconds (modify as needed)
```

## Production Deployment Checklist

### 1. Environment Configuration
- [ ] Set `APP_ENV=production` in config/env.php
- [ ] Configure correct `WBHSMS_BASE_URL`
- [ ] Enable HTTPS in production
- [ ] Set proper file permissions

### 2. Security Headers
- [ ] Verify all protected pages have security headers
- [ ] Enable HTTPS-only cookies in production
- [ ] Configure proper CSP headers

### 3. Session Security
- [ ] Test IP address validation
- [ ] Verify user agent checking
- [ ] Confirm session timeout works
- [ ] Test role-based access control

### 4. Testing Scenarios
- [ ] Login/logout flow
- [ ] Session timeout handling
- [ ] Invalid role access attempts
- [ ] Network change scenarios (WiFi to mobile)
- [ ] Multiple tab handling

## Migration Guide

### Updating Existing Pages
1. Replace manual authentication checks with `require_employee_auth()`
2. Add security functions: `handle_session_security()` and `check_session_timeout()`
3. Remove relative path redirects
4. Add proper output buffer management

### Before (Old Pattern)
```php
if (!isset($_SESSION['employee_id'])) {
    header('Location: ../auth/employee_login.php');
    exit();
}
```

### After (New Pattern)
```php
require_once $root_path . '/config/auth_helpers.php';
handle_session_security();
check_session_timeout();
require_employee_auth(['admin', 'doctor']);
```

## Troubleshooting

### Common Issues
1. **Headers Already Sent**: Ensure `ob_start()` is at the very top
2. **Redirect Loops**: Check `WBHSMS_BASE_URL` configuration
3. **Session Loss**: Verify IP/user agent validation isn't too strict
4. **Permission Denied**: Check role names match exactly

### Debug Logging
Authentication events are logged to PHP error log:
```
[Security] IP address changed for employee session: 123
[Employee Session] Session timeout - redirecting to login
[Auth] Employee 123 accessed protected resource
```

## Security Benefits

### Production Environment
- **Absolute URLs**: Prevents redirect failures across different domains
- **Session Validation**: Detects session hijacking attempts
- **Timeout Management**: Prevents indefinite session persistence
- **Role Validation**: Ensures proper access control
- **Audit Trail**: Logs security events for monitoring

### Development Environment
- **Consistent Behavior**: Same authentication flow as production
- **Debug Support**: Enhanced error logging
- **Flexible Configuration**: Easy role and timeout adjustments

## Files Modified/Created

### New Files
- `config/auth_helpers.php` - Core authentication functions
- `auth_test_example.php` - Implementation example

### Updated Files
- `pages/management/admin/appointments/appointments_management.php` - Uses new auth system
- `config/session/employee_session.php` - Enhanced with absolute URL redirects

## Next Steps
1. Apply this pattern to all protected pages throughout the application
2. Test thoroughly in production environment
3. Monitor authentication logs for security events
4. Consider implementing JWT tokens for API authentication in future