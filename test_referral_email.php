<?php
/**
 * Test script for referral email functionality
 * This script tests the referral email system without creating actual referrals
 */

// Include dependencies
require_once '../config/db.php';
require_once '../utils/referral_email.php';

// Test data
$test_patient_info = [
    'patient_id' => 1,
    'first_name' => 'John',
    'middle_name' => 'M',
    'last_name' => 'Doe',
    'email' => 'test@example.com', // Change this to a real email for testing
    'full_name' => 'John M Doe'
];

$test_referral_num = 'REF-20241111-TEST';

$test_referral_details = [
    'referral_reason' => 'Test referral for email functionality verification',
    'facility_name' => 'Test Destination Hospital',
    'external_facility_name' => 'External Test Hospital',
    'scheduled_date' => '2024-11-15',
    'scheduled_time' => '10:30:00',
    'doctor_name' => 'Smith',
    'service_name' => 'General Consultation',
    'referring_facility' => 'CHO Koronadal - Test Unit',
    'destination_type' => 'external'
];

$test_qr_result = [
    'success' => true,
    'verification_code' => 'TEST123456',
    'qr_image_data' => null // No QR code for this test
];

echo "<h1>Referral Email Test</h1>";

// Test the email function
echo "<h2>Testing Email Function</h2>";
echo "<p>Attempting to send test referral email...</p>";

$email_result = sendReferralConfirmationEmail(
    $test_patient_info,
    $test_referral_num,
    $test_referral_details,
    $test_qr_result
);

echo "<h3>Result:</h3>";
echo "<pre>" . print_r($email_result, true) . "</pre>";

if ($email_result['success']) {
    echo "<p style='color: green;'><strong>✅ Email sent successfully!</strong></p>";
} else {
    echo "<p style='color: red;'><strong>❌ Email failed:</strong> " . htmlspecialchars($email_result['message']) . "</p>";
    if (isset($email_result['technical_error'])) {
        echo "<p style='color: orange;'><strong>Technical details:</strong> " . htmlspecialchars($email_result['technical_error']) . "</p>";
    }
}

// Check environment configuration
echo "<h2>Environment Configuration Check</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";

$smtp_settings = [
    'SMTP_HOST' => getenv('SMTP_HOST') ?: 'Not Set',
    'SMTP_USER' => getenv('SMTP_USER') ?: 'Not Set',
    'SMTP_PASS' => getenv('SMTP_PASS') ? '***configured***' : 'Not Set',
    'SMTP_PORT' => getenv('SMTP_PORT') ?: 'Not Set',
    'SMTP_FROM' => getenv('SMTP_FROM') ?: 'Not Set',
    'APP_DEBUG' => getenv('APP_DEBUG') ?: 'Not Set'
];

foreach ($smtp_settings as $setting => $value) {
    $status = ($value === 'Not Set') ? '❌' : '✅';
    echo "<tr><td>$setting</td><td>$value</td><td>$status</td></tr>";
}
echo "</table>";

echo "<h2>Instructions</h2>";
echo "<ol>";
echo "<li>Ensure your SMTP settings are configured in your environment or .env file</li>";
echo "<li>Change the test email address above to a real email you have access to</li>";
echo "<li>Run this script to test email functionality before using with real referrals</li>";
echo "<li>Check your email inbox (and spam folder) for the test referral email</li>";
echo "</ol>";

?>
<!DOCTYPE html>
<html>
<head>
    <title>Referral Email Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px; }
        th { background-color: #f2f2f2; }
        pre { background: #f8f8f8; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
    </style>
</head>
<body>
</body>
</html>