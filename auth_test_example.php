<?php
/**
 * Example: How to properly implement authentication in any protected page
 * Copy this pattern to any page that requires employee authentication
 */

// Step 1: Clean output buffer and set path
ob_start(); // Prevent any accidental output before headers
$root_path = dirname(__DIR__); // Adjust path depth as needed

// Step 2: Include required files
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php';

// Step 3: Apply security checks (this will automatically redirect if needed)
handle_session_security();    // Check for session hijacking
check_session_timeout();      // Check for session timeout
require_employee_auth(['admin', 'doctor', 'nurse']); // Require specific roles

// Step 4: Include other required files after authentication
require_once $root_path . '/config/db.php';

// Step 5: Your page content starts here
?>
<!DOCTYPE html>
<html>
<head>
    <title>Protected Page Example</title>
</head>
<body>
    <h1>Welcome, <?= htmlspecialchars(get_employee_session('first_name') . ' ' . get_employee_session('last_name')) ?></h1>
    <p>Your role: <?= htmlspecialchars(get_employee_session('role')) ?></p>
    <p>This page is protected and will automatically redirect to login if:</p>
    <ul>
        <li>User is not logged in</li>
        <li>Session has timed out</li>
        <li>IP address or user agent has changed (security)</li>
        <li>User doesn't have required role permissions</li>
    </ul>
    
    <h2>Production Benefits:</h2>
    <ul>
        <li>✅ Uses absolute URLs for redirects (works in production)</li>
        <li>✅ Proper output buffer management</li>
        <li>✅ Enhanced security with session validation</li>
        <li>✅ Automatic timeout handling</li>
        <li>✅ Role-based access control</li>
    </ul>
</body>
</html>