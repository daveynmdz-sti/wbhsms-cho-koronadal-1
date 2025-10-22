<?php
$root_path = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
require_once $root_path . '/config/session/employee_session.php';

echo "Session Check:\n";
echo "Is logged in: " . (is_employee_logged_in() ? "YES" : "NO") . "\n";
echo "Role: " . (get_employee_session('role') ?: "NONE") . "\n";
echo "Employee ID: " . (get_employee_session('employee_id') ?: "NONE") . "\n";
echo "Session ID: " . session_id() . "\n";

// Test database connection
require_once $root_path . '/config/db.php';
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM billing LIMIT 1");
    $result = $stmt->fetch();
    echo "Database: CONNECTED (billing records: " . $result['count'] . ")\n";
} catch (Exception $e) {
    echo "Database: ERROR - " . $e->getMessage() . "\n";
}
?>