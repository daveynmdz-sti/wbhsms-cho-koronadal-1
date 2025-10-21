<?php
/**
 * Employee Session Path Test
 * Use this to test the employee session redirect paths
 */

echo "<h1>Employee Session Path Test</h1>";

// Include the employee session
require_once 'config/session/employee_session.php';

echo "<h2>Path Calculation Test</h2>";

// Test the path function
$root_path = getEmployeeRootPath();
echo "<p><strong>Calculated Root Path:</strong> <code>" . htmlspecialchars($root_path) . "</code></p>";

// Show what the login URL would be
$login_url = $root_path . 'pages/management/auth/employee_login.php';
echo "<p><strong>Login URL:</strong> <code>" . htmlspecialchars($login_url) . "</code></p>";

// Test if the file exists
$full_path = __DIR__ . '/' . $login_url;
$exists = file_exists($full_path);
echo "<p><strong>Login File Exists:</strong> " . ($exists ? '✅ YES' : '❌ NO') . "</p>";
if (!$exists) {
    echo "<p><strong>Looking for:</strong> <code>" . htmlspecialchars($full_path) . "</code></p>";
}

// Show current script info
echo "<h2>Script Information</h2>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Variable</th><th>Value</th></tr>";
echo "<tr><td>SCRIPT_NAME</td><td>" . ($_SERVER['SCRIPT_NAME'] ?? 'not set') . "</td></tr>";
echo "<tr><td>REQUEST_URI</td><td>" . ($_SERVER['REQUEST_URI'] ?? 'not set') . "</td></tr>";
echo "<tr><td>HTTP_HOST</td><td>" . ($_SERVER['HTTP_HOST'] ?? 'not set') . "</td></tr>";
echo "<tr><td>DOCUMENT_ROOT</td><td>" . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "</td></tr>";
echo "<tr><td>__DIR__</td><td>" . __DIR__ . "</td></tr>";
echo "</table>";

// Test session status
echo "<h2>Session Status</h2>";
echo "<p><strong>Session Status:</strong> " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Not Active') . "</p>";
echo "<p><strong>Session Name:</strong> " . session_name() . "</p>";
echo "<p><strong>Is Employee Logged In:</strong> " . (is_employee_logged_in() ? 'YES' : 'NO') . "</p>";

// Test redirect (commented out to avoid actual redirect)
echo "<h2>Redirect Test</h2>";
echo "<p>To test the actual redirect, uncomment the line below and refresh the page:</p>";
echo "<code style='background: #f0f0f0; padding: 5px;'>// require_employee_login();</code>";

echo "<hr>";
echo "<p><a href='pages/management/auth/employee_login.php'>→ Test Login Page Link</a></p>";
echo "<p><a href='pages/patient/auth/patient_login.php'>→ Compare: Patient Login Page Link</a></p>";
?>