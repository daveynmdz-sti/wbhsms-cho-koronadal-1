<?php
/**
 * Test QR verification logic for existing appointment
 */

require_once 'config/db.php';
require_once 'utils/qr_code_generator.php';

$test_appointment_id = 73;
$scanned_qr_token = "12500B74";

echo "<h2>QR Verification Test for Appointment $test_appointment_id</h2>";

// Get appointment data
$stmt = $pdo->prepare("
    SELECT appointment_id, patient_id, verification_code, 
           scheduled_date, scheduled_time
    FROM appointments 
    WHERE appointment_id = ?
");
$stmt->execute([$test_appointment_id]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appointment) {
    echo "❌ Appointment not found!<br>";
    exit;
}

echo "<h3>Current Appointment Data:</h3>";
echo "Appointment ID: " . $appointment['appointment_id'] . "<br>";
echo "Stored verification_code: '" . $appointment['verification_code'] . "'<br>";
echo "Scheduled date: " . $appointment['scheduled_date'] . "<br>";

echo "<h3>QR Code Analysis:</h3>";
echo "Scanned QR token: '$scanned_qr_token'<br>";

// Generate what the QR code SHOULD be for today
$expected_qr_today = QRCodeGenerator::generateVerificationCode($test_appointment_id);
echo "Expected QR code (today): '$expected_qr_today'<br>";

// Generate what the QR code SHOULD be for the appointment date
$appointment_date = $appointment['scheduled_date'];
$original_date = date('Y-m-d'); // Store current date
// Temporarily change date for QR generation
$qr_for_appointment_date = strtoupper(substr(md5($test_appointment_id . $appointment_date . 'WBHSMS_SECRET'), 0, 8));
echo "Expected QR code (appointment date $appointment_date): '$qr_for_appointment_date'<br>";

echo "<h3>Verification Results:</h3>";

// Test 1: Direct match with stored verification code
if ($appointment['verification_code'] === $scanned_qr_token) {
    echo "✅ Scanned token matches stored verification code<br>";
} else {
    echo "❌ Scanned token does NOT match stored verification code<br>";
}

// Test 2: Match with expected QR for today
if ($expected_qr_today === $scanned_qr_token) {
    echo "✅ Scanned token matches expected QR code for today<br>";
} else {
    echo "❌ Scanned token does NOT match expected QR code for today<br>";
}

// Test 3: Match with expected QR for appointment date
if ($qr_for_appointment_date === $scanned_qr_token) {
    echo "✅ Scanned token matches expected QR code for appointment date<br>";
} else {
    echo "❌ Scanned token does NOT match expected QR code for appointment date<br>";
}

echo "<h3>Fix Required:</h3>";
if ($scanned_qr_token === $qr_for_appointment_date) {
    echo "The QR code was generated using the appointment date. We need to update the stored verification_code.<br>";
    
    // Update the appointment with the correct verification code
    $update_stmt = $pdo->prepare("UPDATE appointments SET verification_code = ? WHERE appointment_id = ?");
    $result = $update_stmt->execute([$scanned_qr_token, $test_appointment_id]);
    
    if ($result) {
        echo "✅ Updated appointment verification code to match QR code<br>";
    } else {
        echo "❌ Failed to update appointment verification code<br>";
    }
} else {
    echo "Need to investigate further - QR code pattern doesn't match expected algorithms.<br>";
}

?>