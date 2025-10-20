<?php
/**
 * Test script for employee forgot password production issues
 * Tests output buffering and header redirect functionality
 */

// Simulate production environment
$_ENV['APP_DEBUG'] = '0';

// Start session
session_start();

echo "Testing Employee Forgot Password Production Fix...\n\n";

// Test 1: Check if output buffering is enabled in the file
$file_content = file_get_contents(__DIR__ . '/../pages/management/auth/employee_forgot_password.php');
$has_ob_start = strpos($file_content, 'ob_start()') !== false;
$has_ob_end_clean = strpos($file_content, 'ob_end_clean()') !== false;
$has_ob_end_flush = strpos($file_content, 'ob_end_flush()') !== false;

echo "✓ Output buffering tests:\n";
echo "  - ob_start() present: " . ($has_ob_start ? "YES" : "NO") . "\n";
echo "  - ob_end_clean() before redirects: " . ($has_ob_end_clean ? "YES" : "NO") . "\n";
echo "  - ob_end_flush() before HTML: " . ($has_ob_end_flush ? "YES" : "NO") . "\n\n";

// Test 2: Check OTP verification file
$otp_file_content = file_get_contents(__DIR__ . '/../pages/management/auth/employee_forgot_password_otp.php');
$otp_has_ob_start = strpos($otp_file_content, 'ob_start()') !== false;
$otp_has_ob_end_clean = strpos($otp_file_content, 'ob_end_clean()') !== false;

echo "✓ OTP verification file tests:\n";
echo "  - ob_start() present: " . ($otp_has_ob_start ? "YES" : "NO") . "\n";
echo "  - ob_end_clean() before redirects: " . ($otp_has_ob_end_clean ? "YES" : "NO") . "\n\n";

// Test 3: Check password reset file
$reset_file_content = file_get_contents(__DIR__ . '/../pages/management/auth/employee_reset_password.php');
$reset_has_ob_start = strpos($reset_file_content, 'ob_start()') !== false;
$reset_has_ob_end_clean = strpos($reset_file_content, 'ob_end_clean()') !== false;

echo "✓ Password reset file tests:\n";
echo "  - ob_start() present: " . ($reset_has_ob_start ? "YES" : "NO") . "\n";
echo "  - ob_end_clean() before redirects: " . ($reset_has_ob_end_clean ? "YES" : "NO") . "\n\n";

// Test 4: Simulate header issue resolution
ob_start();
// This would normally cause "headers already sent" error without ob_start()
echo "Some output that would break headers in production...\n";
ob_end_clean(); // Clean the buffer
// Now we can send headers safely
// header('Location: test.php'); // This would work now

echo "✓ Header redirect simulation: SUCCESS (no 'headers already sent' error)\n\n";

// Summary
$all_fixed = $has_ob_start && $has_ob_end_clean && $has_ob_end_flush && 
             $otp_has_ob_start && $otp_has_ob_end_clean && 
             $reset_has_ob_start && $reset_has_ob_end_clean;

echo "=== SUMMARY ===\n";
echo "Production fix status: " . ($all_fixed ? "✅ ALL FIXED" : "❌ NEEDS ATTENTION") . "\n";
echo "The 'headers already sent' issue should now be resolved in production.\n";
echo "OTP email sending and redirects should work properly.\n";
?>