<?php
// Test lab order creation after schema fix
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session/employee_session.php';

// Simulate logged in employee for testing
if (!isset($_SESSION['employee_id'])) {
    $_SESSION['employee_id'] = 1; // Use admin for testing
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin';
}

echo "<h2>Lab Order Creation Test</h2>";

try {
    // Test data
    $patient_id = 1; // Assuming patient ID 1 exists
    $appointment_id = null; // Test with NULL appointment
    $visit_id = null; // Test with NULL visit  
    $test_ids = [1, 2]; // Assuming lab test IDs 1 and 2 exist
    $remarks = "Test lab order creation";
    
    echo "<h3>Test Parameters:</h3>";
    echo "<ul>";
    echo "<li>Patient ID: $patient_id</li>";
    echo "<li>Appointment ID: " . ($appointment_id ? $appointment_id : 'NULL') . "</li>";
    echo "<li>Visit ID: " . ($visit_id ? $visit_id : 'NULL') . "</li>";
    echo "<li>Test IDs: " . implode(', ', $test_ids) . "</li>";
    echo "<li>Remarks: $remarks</li>";
    echo "<li>Employee ID: " . $_SESSION['employee_id'] . "</li>";
    echo "</ul>";
    
    // Start transaction
    $conn->begin_transaction();
    
    // Create lab order
    $insertOrderSql = "INSERT INTO lab_orders (patient_id, appointment_id, visit_id, ordered_by_employee_id, remarks, status) VALUES (?, ?, ?, ?, ?, 'pending')";
    $orderStmt = $conn->prepare($insertOrderSql);
    $orderStmt->bind_param("iiiis", $patient_id, $appointment_id, $visit_id, $_SESSION['employee_id'], $remarks);
    
    if (!$orderStmt->execute()) {
        throw new Exception("Failed to create lab order: " . $orderStmt->error);
    }
    
    $lab_order_id = $conn->insert_id;
    echo "<p>✅ Lab order created successfully! ID: $lab_order_id</p>";
    
    // Add lab order items
    $insertItemSql = "INSERT INTO lab_order_items (lab_order_id, lab_test_id) VALUES (?, ?)";
    $itemStmt = $conn->prepare($insertItemSql);
    
    foreach ($test_ids as $test_id) {
        $itemStmt->bind_param("ii", $lab_order_id, $test_id);
        if (!$itemStmt->execute()) {
            throw new Exception("Failed to add lab test $test_id: " . $itemStmt->error);
        }
    }
    
    echo "<p>✅ Lab order items added successfully!</p>";
    
    // Commit transaction
    $conn->commit();
    
    echo "<h3>✅ TEST PASSED: Lab order creation works!</h3>";
    
    // Show created records
    echo "<h3>Created Lab Order:</h3>";
    $result = $conn->query("SELECT * FROM lab_orders WHERE id = $lab_order_id");
    if ($order = $result->fetch_assoc()) {
        echo "<table border='1'>";
        foreach ($order as $key => $value) {
            echo "<tr><td><strong>$key</strong></td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
    }
    
    echo "<h3>Created Lab Order Items:</h3>";
    $result = $conn->query("
        SELECT loi.*, lt.test_name, lt.test_code 
        FROM lab_order_items loi 
        LEFT JOIN lab_tests lt ON loi.lab_test_id = lt.id 
        WHERE loi.lab_order_id = $lab_order_id
    ");
    echo "<table border='1'>";
    echo "<tr><th>Item ID</th><th>Test ID</th><th>Test Name</th><th>Test Code</th></tr>";
    while ($item = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($item['id']) . "</td>";
        echo "<td>" . htmlspecialchars($item['lab_test_id']) . "</td>";
        echo "<td>" . htmlspecialchars($item['test_name'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($item['test_code'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    $conn->rollback();
    echo "<h3>❌ TEST FAILED</h3>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    
    // Show available patients for debugging
    echo "<h3>Available Patients (first 5):</h3>";
    $result = $conn->query("SELECT id, first_name, last_name FROM patients LIMIT 5");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Name</th></tr>";
        while ($patient = $result->fetch_assoc()) {
            echo "<tr><td>" . $patient['id'] . "</td><td>" . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No patients found in database</p>";
    }
    
    // Show available lab tests
    echo "<h3>Available Lab Tests (first 5):</h3>";
    $result = $conn->query("SELECT id, test_name, test_code FROM lab_tests LIMIT 5");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Test Name</th><th>Test Code</th></tr>";
        while ($test = $result->fetch_assoc()) {
            echo "<tr><td>" . $test['id'] . "</td><td>" . htmlspecialchars($test['test_name']) . "</td><td>" . htmlspecialchars($test['test_code']) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No lab tests found in database</p>";
    }
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
h3 { color: #333; margin-top: 20px; }
.success { color: green; }
.error { color: red; }
</style>

<a href="pages/laboratory-management/create_lab_order.php">← Back to Create Lab Order</a>