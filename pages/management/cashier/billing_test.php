<?php
// Simplified Billing Management Test
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';

echo "<!DOCTYPE html><html><head><title>Billing Management Test</title></head><body>";
echo "<h2>Billing Management Access Test</h2>";

if (!is_employee_logged_in()) {
    echo "<p><strong>ERROR:</strong> Not logged in as employee</p>";
    echo "<p><a href='" . $root_path . "/login.php'>Login</a></p>";
} else {
    $employee_role = get_employee_session('role');
    echo "<p><strong>Current Role:</strong> " . htmlspecialchars($employee_role) . "</p>";
    
    if (!in_array($employee_role, ['cashier', 'admin'])) {
        echo "<p><strong>ACCESS DENIED:</strong> Only cashiers and administrators can access billing management.</p>";
        echo "<p>Your role '" . htmlspecialchars($employee_role) . "' does not have access.</p>";
        echo "<p><a href='../admin/dashboard.php'>Back to Admin Dashboard</a></p>";
    } else {
        echo "<p><strong>ACCESS GRANTED:</strong> You have access to billing management!</p>";
        echo "<h3>Basic Page Elements Test:</h3>";
        echo "<p>✅ Session working</p>";
        echo "<p>✅ Role check passed</p>";
        echo "<p>✅ No redirect issues</p>";
        
        // Test database connection
        try {
            require_once $root_path . '/config/db.php';
            echo "<p>✅ Database connection working</p>";
        } catch (Exception $e) {
            echo "<p>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "<hr>";
        echo "<p><a href='billing_management.php'>Try Full Billing Management Page</a></p>";
        echo "<p><a href='../admin/dashboard.php'>Back to Admin Dashboard</a></p>";
    }
}

echo "</body></html>";
?>