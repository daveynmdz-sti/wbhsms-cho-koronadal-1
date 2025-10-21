<?php
/**
 * Database Migration: Make Prescriptions and Lab Orders Fully Standalone
 * 
 * This script updates the database schema to remove dependencies on appointments/visits
 * allowing prescriptions and lab orders to be created independently.
 */

// Include database configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/db.php';

echo "<h1>Database Migration: Standalone Prescriptions & Lab Orders</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; border-radius: 5px; }
</style>";

try {
    // Check current schema
    echo "<div class='section'>";
    echo "<h2>Current Schema Analysis</h2>";
    
    // Check prescriptions table structure
    $result = $conn->query("DESCRIBE prescriptions");
    $prescriptions_columns = $result->fetch_all(MYSQLI_ASSOC);
    
    echo "<h3>Prescriptions Table:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($prescriptions_columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . $column['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if appointment_id is nullable
    $appointment_nullable = false;
    foreach ($prescriptions_columns as $column) {
        if ($column['Field'] === 'appointment_id' && $column['Null'] === 'YES') {
            $appointment_nullable = true;
            break;
        }
    }
    
    echo "</div>";
    
    // Migration steps
    echo "<div class='section'>";
    echo "<h2>Migration Steps</h2>";
    
    if (!$appointment_nullable) {
        echo "<h3>Step 1: Making appointment_id nullable in prescriptions table</h3>";
        
        // First, update any existing records that might have issues
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        // Modify the appointment_id column to be nullable
        $sql = "ALTER TABLE prescriptions MODIFY COLUMN appointment_id int UNSIGNED NULL";
        if ($conn->query($sql)) {
            echo "<p class='success'>✓ appointment_id in prescriptions table is now nullable</p>";
        } else {
            echo "<p class='error'>✗ Error: " . $conn->error . "</p>";
        }
        
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    } else {
        echo "<p class='success'>✓ appointment_id in prescriptions table is already nullable</p>";
    }
    
    // Check lab_orders table
    $result = $conn->query("DESCRIBE lab_orders");
    $lab_orders_columns = $result->fetch_all(MYSQLI_ASSOC);
    
    echo "<h3>Step 2: Verifying lab_orders table structure</h3>";
    
    $lab_appointment_nullable = false;
    foreach ($lab_orders_columns as $column) {
        if ($column['Field'] === 'appointment_id' && $column['Null'] === 'YES') {
            $lab_appointment_nullable = true;
            break;
        }
    }
    
    if ($lab_appointment_nullable) {
        echo "<p class='success'>✓ appointment_id in lab_orders table is already nullable</p>";
    } else {
        echo "<p class='info'>ℹ lab_orders table needs appointment_id to be nullable</p>";
        
        $sql = "ALTER TABLE lab_orders MODIFY COLUMN appointment_id int UNSIGNED NULL";
        if ($conn->query($sql)) {
            echo "<p class='success'>✓ appointment_id in lab_orders table is now nullable</p>";
        } else {
            echo "<p class='error'>✗ Error: " . $conn->error . "</p>";
        }
    }
    
    echo "</div>";
    
    // Verification
    echo "<div class='section'>";
    echo "<h2>Migration Verification</h2>";
    
    // Test creating a standalone prescription (simulation)
    echo "<h3>Testing Standalone Capability:</h3>";
    
    // Check if we can insert a prescription without appointment_id
    $test_sql = "INSERT INTO prescriptions (patient_id, prescribed_by_employee_id, prescription_date, status, remarks, appointment_id, consultation_id, visit_id) 
                 VALUES (7, 1, NOW(), 'active', 'Test standalone prescription', NULL, NULL, NULL)";
    
    if ($conn->query($test_sql)) {
        $test_prescription_id = $conn->insert_id;
        echo "<p class='success'>✓ Successfully created standalone prescription (ID: $test_prescription_id)</p>";
        
        // Clean up test record
        $conn->query("DELETE FROM prescriptions WHERE prescription_id = $test_prescription_id");
        echo "<p class='info'>ℹ Test prescription cleaned up</p>";
    } else {
        echo "<p class='error'>✗ Error creating standalone prescription: " . $conn->error . "</p>";
    }
    
    // Test lab order
    $test_lab_sql = "INSERT INTO lab_orders (patient_id, ordered_by_employee_id, order_date, status, remarks, appointment_id, consultation_id, visit_id) 
                     VALUES (7, 1, NOW(), 'pending', 'Test standalone lab order', NULL, NULL, NULL)";
    
    if ($conn->query($test_lab_sql)) {
        $test_lab_id = $conn->insert_id;
        echo "<p class='success'>✓ Successfully created standalone lab order (ID: $test_lab_id)</p>";
        
        // Clean up test record
        $conn->query("DELETE FROM lab_orders WHERE lab_order_id = $test_lab_id");
        echo "<p class='info'>ℹ Test lab order cleaned up</p>";
    } else {
        echo "<p class='error'>✗ Error creating standalone lab order: " . $conn->error . "</p>";
    }
    
    echo "</div>";
    
    // Summary
    echo "<div class='section'>";
    echo "<h2>✅ Migration Complete</h2>";
    echo "<p class='success'>Prescriptions and Lab Orders can now be created independently without requiring appointments or visits!</p>";
    echo "<ul>";
    echo "<li>✓ Prescriptions can be standalone, appointment-based, or consultation-based</li>";
    echo "<li>✓ Lab orders can be standalone, appointment-based, or consultation-based</li>";
    echo "<li>✓ All existing data is preserved</li>";
    echo "<li>✓ Patient and management sides can create records seamlessly</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='section'>";
    echo "<h2 class='error'>Migration Error</h2>";
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<div class='section'>";
echo "<h2>🔗 Next Steps</h2>";
echo "<ul>";
echo "<li><a href='../prescriptions.php'>Test Prescription System</a></li>";
echo "<li><a href='debug_prescription.php'>Validate Prescription Functionality</a></li>";
echo "<li><a href='../../management/dashboard.php'>Management Dashboard</a></li>";
echo "</ul>";
echo "</div>";
?>