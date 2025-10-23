<?php
/**
 * Quick Login Test Script
 * Sets up admin session for testing billing overview
 */

$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Get admin user from database
try {
    $stmt = $pdo->prepare("
        SELECT e.employee_id, e.employee_number, e.first_name, e.middle_name, e.last_name, 
               r.role_name, e.role_id
        FROM employees e
        JOIN roles r ON e.role_id = r.role_id
        WHERE r.role_name = 'admin' AND e.status = 'active'
        LIMIT 1
    ");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        // Set up admin session
        session_regenerate_id(true);
        $_SESSION['employee_id'] = $admin['employee_id'];
        $_SESSION['employee_number'] = $admin['employee_number'];
        $_SESSION['employee_last_name'] = $admin['last_name'];
        $_SESSION['employee_first_name'] = $admin['first_name'];
        $_SESSION['employee_middle_name'] = $admin['middle_name'];
        $_SESSION['employee_name'] = trim($admin['first_name'] . ' ' . ($admin['middle_name'] ? $admin['middle_name'] . ' ' : '') . $admin['last_name']);
        $_SESSION['role'] = $admin['role_name'];
        $_SESSION['role_id'] = $admin['role_id'];
        $_SESSION['login_time'] = time();
        $_SESSION['user_type'] = $admin['role_name'];
        $_SESSION['user_id'] = $admin['employee_id'];
        
        echo "<h1>Admin Login Successful</h1>";
        echo "<p>Employee ID: " . $admin['employee_id'] . "</p>";
        echo "<p>Name: " . $_SESSION['employee_name'] . "</p>";
        echo "<p>Role: " . $_SESSION['role'] . "</p>";
        echo "<p>Session ID: " . session_id() . "</p>";
        echo "<p><a href='pages/management/admin/billing/billing_overview.php'>Go to Billing Overview</a></p>";
        echo "<p><a href='test_browser_api.html'>Test API Endpoints</a></p>";
        
        // Debug session
        echo "<h3>Session Data:</h3>";
        echo "<pre>" . print_r($_SESSION, true) . "</pre>";
    } else {
        echo "<h1>No Admin User Found</h1>";
        echo "<p>Please create an admin user first.</p>";
    }
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>