<?php
/**
 * Test Invoice API Access
 * Tests if the admin billing API endpoints work correctly
 */

$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Simulate admin login for testing
if (!is_employee_logged_in()) {
    // Set admin session for testing
    $_SESSION['employee_id'] = 1;
    $_SESSION['employee_role'] = 'admin';
    $_SESSION['employee_name'] = 'Test Admin';
    $_SESSION['employee_logged_in'] = true;
}

echo "<h1>Invoice API Test</h1>";
echo "<p>Employee ID: " . get_employee_session('employee_id') . "</p>";
echo "<p>Role: " . get_employee_session('role') . "</p>";
echo "<p>Logged in: " . (is_employee_logged_in() ? 'Yes' : 'No') . "</p>";

// Test get_invoice_details API
echo "<h2>Testing get_invoice_details.php</h2>";
$test_url = 'http://localhost/wbhsms-cho-koronadal-1/api/billing/management/get_invoice_details.php?billing_id=3';

// Create context with session cookies
$session_name = session_name();
$session_id = session_id();
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: {$session_name}={$session_id}\r\n"
    ]
]);

$result = file_get_contents($test_url, false, $context);
if ($result === false) {
    echo "<p style='color: red;'>Failed to fetch API data</p>";
} else {
    echo "<p style='color: green;'>API Response received:</p>";
    echo "<pre>" . htmlspecialchars($result) . "</pre>";
}

// Test print_invoice API
echo "<h2>Testing print_invoice.php</h2>";
$print_url = 'http://localhost/wbhsms-cho-koronadal-1/api/billing/management/print_invoice.php?billing_id=3&format=json';
$print_result = file_get_contents($print_url, false, $context);

if ($print_result === false) {
    echo "<p style='color: red;'>Failed to fetch print API data</p>";
} else {
    echo "<p style='color: green;'>Print API Response received:</p>";
    echo "<pre>" . htmlspecialchars(substr($print_result, 0, 1000)) . "...</pre>";
}
?>