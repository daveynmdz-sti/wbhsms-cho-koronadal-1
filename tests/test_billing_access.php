<?php
// Authentication and Access Test for Billing Pages
echo "<!DOCTYPE html><html><head><title>Billing Access Test</title></head><body>";
echo "<h1>🔐 Billing Pages Access Test</h1>";

// Include employee session configuration
$root_path = __DIR__; // Current directory is the root
require_once $root_path . '/config/session/employee_session.php';

echo "<h2>Session Status Check:</h2>";

echo "<h3>Raw Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Employee Session Functions:</h3>";
echo "<ul>";
echo "<li><strong>is_employee_logged_in():</strong> " . (function_exists('is_employee_logged_in') ? (is_employee_logged_in() ? 'TRUE' : 'FALSE') : 'Function not found') . "</li>";

if (function_exists('get_employee_session')) {
    echo "<li><strong>Employee ID:</strong> " . (get_employee_session('employee_id') ?? 'Not set') . "</li>";
    echo "<li><strong>Role:</strong> " . (get_employee_session('role') ?? 'Not set') . "</li>";
    echo "<li><strong>First Name:</strong> " . (get_employee_session('first_name') ?? 'Not set') . "</li>";
    echo "<li><strong>Last Name:</strong> " . (get_employee_session('last_name') ?? 'Not set') . "</li>";
} else {
    echo "<li><strong>get_employee_session():</strong> Function not found</li>";
}
echo "</ul>";

echo "<h3>Authorization Check:</h3>";
$employee_role = get_employee_session('role') ?? null;
$allowed_roles = ['cashier', 'admin'];
$is_authorized = in_array(strtolower($employee_role), $allowed_roles);

echo "<ul>";
echo "<li><strong>Current Role:</strong> " . ($employee_role ?? 'None') . "</li>";
echo "<li><strong>Allowed Roles:</strong> " . implode(', ', $allowed_roles) . "</li>";
echo "<li><strong>Is Authorized:</strong> " . ($is_authorized ? 'YES' : 'NO') . "</li>";
echo "</ul>";

echo "<h2>Access Test Results:</h2>";

if (!function_exists('is_employee_logged_in') || !is_employee_logged_in()) {
    echo "<p style='color: red;'><strong>❌ NOT LOGGED IN</strong> - You need to login first</p>";
    echo "<p><a href='pages/management/auth/employee_login.php'>🔗 Go to Employee Login</a></p>";
} elseif (!$is_authorized) {
    echo "<p style='color: orange;'><strong>⚠️ NOT AUTHORIZED</strong> - Your role ({$employee_role}) cannot access billing pages</p>";
    echo "<p>You need Cashier or Admin role to access billing functions.</p>";
} else {
    echo "<p style='color: green;'><strong>✅ ACCESS GRANTED</strong> - You should be able to access billing pages</p>";
}

echo "<h2>Direct Billing Page Tests:</h2>";
echo "<p>Try these links (they should work if you're properly authenticated):</p>";
echo "<ul>";
echo "<li><a href='pages/billing/create_invoice.php' target='_blank'>🔗 Create Invoice</a></li>";
echo "<li><a href='pages/billing/process_payment.php' target='_blank'>🔗 Process Payment</a></li>";
echo "<li><a href='pages/billing/billing_management.php' target='_blank'>🔗 Billing Management</a></li>";
echo "<li><a href='pages/billing/billing_reports.php' target='_blank'>🔗 Billing Reports</a></li>";
echo "</ul>";

echo "<h2>Manual Login Test:</h2>";
echo "<p><strong>If you need to login as cashier:</strong></p>";
echo "<ol>";
echo "<li><a href='pages/management/auth/employee_login.php'>🔗 Go to Employee Login</a></li>";
echo "<li>Use Employee Number: <strong>EMP00006</strong> (Carlos Garcia)</li>";
echo "<li>Enter the password (check with system administrator)</li>";
echo "<li>Should redirect to cashier dashboard</li>";
echo "<li>Then use sidebar navigation to access billing pages</li>";
echo "</ol>";

echo "</body></html>";
?>