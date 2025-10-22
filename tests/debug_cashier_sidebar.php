<?php
// Simple debug page to test cashier sidebar functionality
require_once 'config/session/employee_session.php';

// Simulate cashier session for testing
$_SESSION['employee_id'] = 6;
$_SESSION['role'] = 'cashier';
$_SESSION['employee_first_name'] = 'Carlos';
$_SESSION['employee_last_name'] = 'Garcia';
$_SESSION['employee_number'] = 'EMP00006';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<title>Cashier Sidebar Debug</title>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "</head><body>";

echo "<h1>🧪 Cashier Sidebar Debug Test</h1>";

echo "<h2>Session Information:</h2>";
echo "<ul>";
echo "<li><strong>Employee ID:</strong> " . ($_SESSION['employee_id'] ?? 'Not set') . "</li>";
echo "<li><strong>Role:</strong> " . ($_SESSION['role'] ?? 'Not set') . "</li>";
echo "<li><strong>Name:</strong> " . ($_SESSION['employee_first_name'] ?? '') . " " . ($_SESSION['employee_last_name'] ?? '') . "</li>";
echo "<li><strong>Employee Number:</strong> " . ($_SESSION['employee_number'] ?? 'Not set') . "</li>";
echo "</ul>";

echo "<h2>Sidebar Test:</h2>";
echo "<div style='border: 2px solid #ccc; padding: 20px; margin: 20px 0;'>";

// Set active page for sidebar
$activePage = 'dashboard';

try {
    // Include the sidebar
    include 'includes/sidebar_cashier.php';
    echo "<p style='color: green;'>✅ Sidebar loaded successfully!</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Sidebar failed to load: " . $e->getMessage() . "</p>";
}

echo "</div>";

echo "<h2>CSS and JS Check:</h2>";
echo "<p>Check browser console for any JavaScript errors.</p>";
echo "<p>Check network tab for failed CSS/JS requests.</p>";

echo "<h2>Testing Actions:</h2>";
echo "<ol>";
echo "<li>Check if sidebar appears on page</li>";
echo "<li>Try clicking the mobile toggle button</li>";
echo "<li>Test sidebar navigation links</li>";
echo "<li>Check browser console for errors</li>";
echo "</ol>";

echo "</body></html>";
?>