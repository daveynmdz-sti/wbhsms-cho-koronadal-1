<?php
/**
 * Migration script to add qr_code_path column to appointments table
 * Run this script once to add the LONGBLOB column for storing QR codes
 */

// Include database connection
$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';

try {
    // Check if qr_code_path column exists
    $check_column = $conn->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'appointments' 
        AND COLUMN_NAME = 'qr_code_path'
    ");
    
    if ($check_column->num_rows == 0) {
        // Column doesn't exist, add it
        echo "Adding qr_code_path column to appointments table...\n";
        
        $alter_query = "
            ALTER TABLE appointments 
            ADD COLUMN qr_code_path LONGBLOB NULL 
            COMMENT 'Stores QR code PNG image as binary data'
        ";
        
        if ($conn->query($alter_query)) {
            echo "✓ Successfully added qr_code_path column to appointments table\n";
        } else {
            throw new Exception("Failed to add column: " . $conn->error);
        }
    } else {
        echo "✓ qr_code_path column already exists in appointments table\n";
    }
    
    // Check if we need to add an index for better performance
    $check_index = $conn->query("
        SELECT INDEX_NAME 
        FROM INFORMATION_SCHEMA.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'appointments' 
        AND COLUMN_NAME = 'appointment_id'
        AND INDEX_NAME = 'idx_appointment_qr'
    ");
    
    if ($check_index->num_rows == 0) {
        echo "Adding index for QR code queries...\n";
        
        $index_query = "
            ALTER TABLE appointments 
            ADD INDEX idx_appointment_qr (appointment_id, patient_id)
        ";
        
        if ($conn->query($index_query)) {
            echo "✓ Successfully added index for QR code queries\n";
        } else {
            echo "! Warning: Failed to add index (may already exist): " . $conn->error . "\n";
        }
    } else {
        echo "✓ QR code query index already exists\n";
    }
    
    echo "\n=== Migration completed successfully ===\n";
    echo "The appointments table is now ready for QR code storage.\n";
    echo "QR codes will be stored as LONGBLOB binary data.\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

$conn->close();
?>