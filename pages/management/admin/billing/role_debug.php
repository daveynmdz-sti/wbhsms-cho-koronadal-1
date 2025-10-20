<?php
// Quick Role Debug Test
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/session/employee_session.php';

echo "<!DOCTYPE html><html><body>";
echo "<h2>Role Debug Test</h2>";

if (is_employee_logged_in()) {
    echo "<h3>Employee Session Data:</h3>";
    echo "<p><strong>Role (using 'role'):</strong> " . htmlspecialchars(get_employee_session('role', 'not_found')) . "</p>";
    echo "<p><strong>Role Name (using 'role_name'):</strong> " . htmlspecialchars(get_employee_session('role_name', 'not_found')) . "</p>";
    echo "<p><strong>Employee ID:</strong> " . htmlspecialchars(get_employee_session('employee_id', 'not_found')) . "</p>";
    echo "<p><strong>Employee Name:</strong> " . htmlspecialchars(get_employee_session('first_name', 'Unknown')) . " " . htmlspecialchars(get_employee_session('last_name', '')) . "</p>";
    
    echo "<h3>Access Test:</h3>";
    $role = get_employee_session('role', 'none');
    $role_name = get_employee_session('role_name', 'none');
    echo "<p><strong>Admin Access (using 'role'):</strong> " . (in_array($role, ['admin']) ? 'YES' : 'NO') . "</p>";
    echo "<p><strong>Admin Access (using 'role_name'):</strong> " . (in_array($role_name, ['admin']) ? 'YES' : 'NO') . "</p>";
    echo "<p><strong>Billing Access (using 'role'):</strong> " . (in_array($role, ['cashier', 'admin']) ? 'YES' : 'NO') . "</p>";
    echo "<p><strong>Billing Access (using 'role_name'):</strong> " . (in_array($role_name, ['cashier', 'admin']) ? 'YES' : 'NO') . "</p>";
    
    echo "<hr><p><a href='../../cashier/billing_management.php'>Test Billing Management Access</a></p>";
    echo "<p><a href='../dashboard.php'>Back to Admin Dashboard</a></p>";
} else {
    echo "<p><strong>ERROR:</strong> Not logged in as employee</p>";
    echo "<p><a href='" . $root_path . "/login.php'>Login</a></p>";
}

echo "</body></html>";
?>