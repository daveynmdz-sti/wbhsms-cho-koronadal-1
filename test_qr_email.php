<?php
/**
 * Test script to verify QR code generation and email embedding
 */

// Set up environment
$root_path = __DIR__;
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/qr_code_generator.php';

// Test QR code generation
echo "<h2>Testing QR Code Generation</h2>";

// Test data
$test_appointment_id = 12345; // Use a test ID
$test_data = [
    'patient_id' => 1,
    'scheduled_date' => '2025-11-15',
    'scheduled_time' => '10:00',
    'facility_id' => 1,
    'service_id' => 1
];

echo "Generating QR code for test appointment ID: $test_appointment_id<br>";

$qr_result = QRCodeGenerator::generateAppointmentQR($test_appointment_id, $test_data);

if ($qr_result['success']) {
    echo "✅ QR Generation successful<br>";
    echo "- QR Data size: " . strlen($qr_result['qr_image_data']) . " bytes<br>";
    echo "- Verification Code: " . $qr_result['verification_code'] . "<br>";
    
    // Display QR code as base64 image
    $base64_image = base64_encode($qr_result['qr_image_data']);
    echo "<img src='data:image/png;base64,$base64_image' alt='Generated QR Code' style='border: 1px solid #ccc; margin: 10px;'><br>";
    
    // Test saving to database (create a test appointment record if needed)
    echo "<h3>Testing Database Save</h3>";
    
    // Check if test appointment exists
    $check_stmt = $conn->prepare("SELECT appointment_id FROM appointments WHERE appointment_id = ?");
    $check_stmt->bind_param("i", $test_appointment_id);
    $check_stmt->execute();
    $exists = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if (!$exists) {
        echo "Creating test appointment record...<br>";
        // Insert minimal test appointment
        $insert_stmt = $conn->prepare("
            INSERT INTO appointments (appointment_id, patient_id, facility_id, service_id, appointment_date, appointment_time, status, verification_code, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
        ");
        $verification_code = substr(md5($test_appointment_id . date('Y-m-d')), 0, 8);
        $insert_stmt->bind_param("iiissss", 
            $test_appointment_id,
            $test_data['patient_id'],
            $test_data['facility_id'], 
            $test_data['service_id'],
            $test_data['scheduled_date'],
            $test_data['scheduled_time'],
            $verification_code
        );
        
        if ($insert_stmt->execute()) {
            echo "✅ Test appointment created<br>";
        } else {
            echo "❌ Failed to create test appointment: " . $insert_stmt->error . "<br>";
        }
        $insert_stmt->close();
    }
    
    // Now test saving QR code
    $save_result = QRCodeGenerator::saveQRToAppointment($test_appointment_id, $qr_result['qr_image_data'], $conn);
    
    if ($save_result) {
        echo "✅ QR code saved to database<br>";
        
        // Verify retrieval
        $retrieve_stmt = $conn->prepare("SELECT qr_code_path FROM appointments WHERE appointment_id = ?");
        $retrieve_stmt->bind_param("i", $test_appointment_id);
        $retrieve_stmt->execute();
        $retrieved = $retrieve_stmt->get_result()->fetch_assoc();
        $retrieve_stmt->close();
        
        if ($retrieved && $retrieved['qr_code_path']) {
            echo "✅ QR code retrieved from database, size: " . strlen($retrieved['qr_code_path']) . " bytes<br>";
            
            // Display retrieved QR code
            $retrieved_base64 = base64_encode($retrieved['qr_code_path']);
            echo "<img src='data:image/png;base64,$retrieved_base64' alt='Retrieved QR Code' style='border: 1px solid #ccc; margin: 10px;'><br>";
            
            echo "<h3>Testing Email Embedding Logic</h3>";
            
            // Test the email embedding logic
            if (method_exists('PHPMailer\PHPMailer\PHPMailer', 'addStringEmbeddedImage') || function_exists('addStringEmbeddedImage')) {
                echo "✅ addStringEmbeddedImage method available<br>";
            } else {
                echo "⚠️ addStringEmbeddedImage not available, will use temp file method<br>";
                
                // Test temp file creation
                $temp_file = tempnam(sys_get_temp_dir(), 'qr_test_') . '.png';
                if (file_put_contents($temp_file, $retrieved['qr_code_path']) !== false) {
                    echo "✅ Temporary file creation successful: $temp_file<br>";
                    echo "- File size: " . filesize($temp_file) . " bytes<br>";
                    
                    // Clean up
                    unlink($temp_file);
                    echo "✅ Temporary file cleaned up<br>";
                } else {
                    echo "❌ Failed to create temporary file<br>";
                }
            }
            
        } else {
            echo "❌ Failed to retrieve QR code from database<br>";
        }
        
    } else {
        echo "❌ Failed to save QR code to database<br>";
    }
    
} else {
    echo "❌ QR Generation failed: " . $qr_result['error'] . "<br>";
}

echo "<h3>Test Summary</h3>";
echo "This test verifies:<br>";
echo "1. QR code generation functionality<br>";
echo "2. Database storage of binary QR data<br>";
echo "3. Retrieval of QR data for email embedding<br>";
echo "4. Email attachment preparation methods<br>";
echo "<br>";
echo "If all tests pass, QR codes should be included in appointment confirmation emails.";

?>