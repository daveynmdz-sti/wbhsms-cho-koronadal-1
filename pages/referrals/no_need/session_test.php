<?php
// session_test.php - Check if session is working
session_start();

// Set test session if not exists
if (!isset($_SESSION['employee_id'])) {
    $_SESSION['employee_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['first_name'] = 'Test';
    $_SESSION['last_name'] = 'Admin';
}

echo "<h1>Session Test</h1>";
echo "<p>Employee ID: " . ($_SESSION['employee_id'] ?? 'NOT SET') . "</p>";
echo "<p>Role: " . ($_SESSION['role'] ?? 'NOT SET') . "</p>";
echo "<p>Session ID: " . session_id() . "</p>";

echo "<h2>Test Direct API Call</h2>";
echo "<form method='POST' action='pages/referrals/reinstate_referral.php'>";
echo "<input type='hidden' name='referral_id' value='3'>";
echo "<button type='submit'>Test API with Referral ID 3</button>";
echo "</form>";
?>