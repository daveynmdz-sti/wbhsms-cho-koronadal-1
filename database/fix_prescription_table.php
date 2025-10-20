<?php
// Fix prescription table to allow standalone prescriptions
require_once '../config/db.php';

try {
    echo "Checking prescription table structure...\n";
    
    // Check if table exists
    $result = $conn->query("SHOW TABLES LIKE 'prescriptions'");
    if ($result->num_rows === 0) {
        echo "❌ Prescriptions table does not exist!\n";
        exit(1);
    }
    
    // Check current table structure
    $result = $conn->query("DESCRIBE prescriptions");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = $row;
    }
    
    echo "Current table structure:\n";
    foreach ($columns as $column => $info) {
        echo "- {$column}: {$info['Type']} ({$info['Null']}) {$info['Key']}\n";
    }
    
    // Check if appointment_id column exists and is nullable
    if (isset($columns['appointment_id'])) {
        if ($columns['appointment_id']['Null'] === 'NO') {
            echo "\n🔧 Making appointment_id nullable...\n";
            $conn->query("ALTER TABLE prescriptions MODIFY COLUMN appointment_id INT NULL");
            echo "✅ appointment_id is now nullable\n";
        } else {
            echo "✅ appointment_id is already nullable\n";
        }
    } else {
        echo "ℹ️ appointment_id column does not exist (this is fine)\n";
    }
    
    // Check if overall_status column exists
    if (!isset($columns['overall_status'])) {
        echo "\n🔧 Adding overall_status column...\n";
        $conn->query("ALTER TABLE prescriptions ADD COLUMN overall_status ENUM('active', 'issued', 'dispensed', 'cancelled') DEFAULT 'active' AFTER status");
        echo "✅ overall_status column added\n";
    } else {
        echo "✅ overall_status column exists\n";
    }
    
    // Test insert to verify it works
    echo "\n🧪 Testing standalone prescription creation...\n";
    
    // Get a test patient
    $patient_result = $conn->query("SELECT patient_id FROM patients LIMIT 1");
    if ($patient_result->num_rows === 0) {
        echo "⚠️ No patients found to test with\n";
    } else {
        $patient = $patient_result->fetch_assoc();
        $patient_id = $patient['patient_id'];
        
        // Try to insert a test prescription
        $test_sql = "INSERT INTO prescriptions (
            patient_id, 
            prescribed_by_employee_id, 
            prescription_date, 
            status, 
            overall_status,
            remarks,
            created_at
        ) VALUES (?, 1, NOW(), 'active', 'active', 'Test prescription - will be deleted', NOW())";
        
        $stmt = $conn->prepare($test_sql);
        $stmt->bind_param("i", $patient_id);
        
        if ($stmt->execute()) {
            $test_prescription_id = $conn->insert_id;
            echo "✅ Test prescription created successfully (ID: {$test_prescription_id})\n";
            
            // Clean up test data
            $conn->query("DELETE FROM prescriptions WHERE prescription_id = {$test_prescription_id}");
            echo "✅ Test data cleaned up\n";
        } else {
            echo "❌ Failed to create test prescription: " . $conn->error . "\n";
        }
    }
    
    echo "\n✅ Prescription table structure check complete!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>