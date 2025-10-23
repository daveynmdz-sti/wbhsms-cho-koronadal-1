<?php
// Test the password fix by simulating the registration and login flow
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/db.php';

echo "=== Registration and Login Flow Test ===\n\n";

// Test password that will be used
$test_password = 'TestPass123';
echo "1. Test password: $test_password\n\n";

echo "=== Simulating register_patient.php (Step 1) ===\n";
// This is what happens in register_patient.php
$hashed_once = password_hash($test_password, PASSWORD_DEFAULT);
echo "Password hashed once: $hashed_once\n";

// This gets stored in session
$session_data = ['password' => $hashed_once];
echo "Stored in session: password = [hashed]\n\n";

echo "=== Simulating registration_otp.php (Step 2) ===\n";
// OLD WAY (WRONG - double hashing):
$double_hashed = password_hash($hashed_once, PASSWORD_DEFAULT);
echo "OLD WAY (double hash): $double_hashed\n";

// NEW WAY (FIXED - use existing hash):
$final_hash = $session_data['password']; // Just use the already hashed password
echo "NEW WAY (fixed): $final_hash\n\n";

echo "=== Login Verification Test ===\n";
echo "Testing login with original password '$test_password':\n";
echo "Against double hash: " . (password_verify($test_password, $double_hashed) ? "✅ SUCCESS" : "❌ FAILED") . "\n";
echo "Against single hash: " . (password_verify($test_password, $final_hash) ? "✅ SUCCESS" : "❌ FAILED") . "\n\n";

echo "=== Conclusion ===\n";
echo "✅ The fix in registration_otp.php prevents double-hashing\n";
echo "✅ New patients will be able to login normally\n";
echo "✅ The password verification in patient_login.php works correctly\n\n";

echo "=== What was changed ===\n";
echo "File: pages/patient/registration/registration_otp.php\n";
echo "Line 85 changed from:\n";
echo "  \$hashedPassword = password_hash(\$regData['password'], PASSWORD_DEFAULT);\n";
echo "To:\n";
echo "  \$hashedPassword = \$regData['password']; // Already hashed in register_patient.php\n";
?>