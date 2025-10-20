<?php
// Simple validation script for lab order creation
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

echo "<h2>Lab Order System Validation</h2>";

// Check session
if (!isset($_SESSION['employee_id'])) {
    echo "<p style='color: red;'>❌ No employee session found. Please log in first.</p>";
    echo "<p><a href='../management/auth/employee_login.php'>Login as Employee</a></p>";
    exit();
}

echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
echo "<h3>✅ Session Status: OK</h3>";
echo "<p><strong>Employee ID:</strong> {$_SESSION['employee_id']}</p>";
echo "<p><strong>Role:</strong> {$_SESSION['role']} (ID: {$_SESSION['role_id']})</p>";
echo "</div>";

// Check role authorization
$authorizedRoles = [1, 2, 3, 9];
$canCreateOrders = in_array($_SESSION['role_id'], $authorizedRoles);

echo "<div style='background: " . ($canCreateOrders ? '#e8f5e8' : '#f8d7da') . "; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
echo "<h3>" . ($canCreateOrders ? '✅' : '❌') . " Authorization Status</h3>";
if ($canCreateOrders) {
    echo "<p>✅ You have permission to create lab orders (Role ID {$_SESSION['role_id']} is authorized)</p>";
} else {
    echo "<p>❌ You don't have permission to create lab orders</p>";
    echo "<p>Authorized roles: " . implode(', ', $authorizedRoles) . "</p>";
    echo "<p>Your role ID: {$_SESSION['role_id']}</p>";
}
echo "</div>";

// Check database schema
echo "<h3>Database Schema Validation</h3>";

$tables = ['lab_orders', 'lab_order_items', 'patients', 'barangay', 'employees'];
foreach ($tables as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    $exists = $check->num_rows > 0;
    echo "<p>" . ($exists ? '✅' : '❌') . " Table: <strong>$table</strong></p>";
}

// Check lab_orders structure
$columns = $conn->query("DESCRIBE lab_orders");
echo "<h4>lab_orders Table Structure:</h4>";
echo "<ul>";
while ($col = $columns->fetch_assoc()) {
    $nullable = $col['Null'] === 'YES' ? '(Nullable)' : '(Required)';
    echo "<li><strong>{$col['Field']}</strong>: {$col['Type']} {$nullable}</li>";
}
echo "</ul>";

// Test patient search
echo "<h3>Patient Search Test</h3>";
$patientTest = $conn->query("SELECT COUNT(*) as count FROM patients");
$patientCount = $patientTest->fetch_assoc()['count'];
echo "<p>✅ Patients in database: <strong>{$patientCount}</strong></p>";

// Test barangay lookup
$barangayTest = $conn->query("SELECT COUNT(*) as count FROM barangay WHERE status = 'active'");
$barangayCount = $barangayTest->fetch_assoc()['count'];
echo "<p>✅ Active barangays: <strong>{$barangayCount}</strong></p>";

// Test lab order creation (dry run)
echo "<h3>Lab Order Creation Test</h3>";

if ($canCreateOrders) {
    try {
        // Test if we can prepare the insert statement
        $testSql = "INSERT INTO lab_orders (patient_id, appointment_id, visit_id, ordered_by_employee_id, remarks, status) VALUES (?, ?, ?, ?, ?, 'pending')";
        $testStmt = $conn->prepare($testSql);
        
        if ($testStmt) {
            echo "<p>✅ Lab order SQL statement prepared successfully</p>";
            
            // Test lab order items
            $testItemSql = "INSERT INTO lab_order_items (lab_order_id, test_type, status) VALUES (?, ?, 'pending')";
            $testItemStmt = $conn->prepare($testItemSql);
            
            if ($testItemStmt) {
                echo "<p>✅ Lab order items SQL statement prepared successfully</p>";
            } else {
                echo "<p>❌ Lab order items SQL preparation failed: " . $conn->error . "</p>";
            }
        } else {
            echo "<p>❌ Lab order SQL preparation failed: " . $conn->error . "</p>";
        }
    } catch (Exception $e) {
        echo "<p>❌ SQL test error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>⚠️ Skipping creation test - no authorization</p>";
}

// Current lab orders
echo "<h3>Current Lab Orders</h3>";
$currentOrders = $conn->query("SELECT COUNT(*) as count FROM lab_orders");
$orderCount = $currentOrders->fetch_assoc()['count'];
echo "<p>📊 Total lab orders in system: <strong>{$orderCount}</strong></p>";

if ($orderCount > 0) {
    $recentQuery = "SELECT lo.lab_order_id, lo.patient_id, lo.appointment_id, lo.order_date,
                           p.first_name, p.last_name, p.username
                    FROM lab_orders lo
                    LEFT JOIN patients p ON lo.patient_id = p.patient_id
                    ORDER BY lo.order_date DESC LIMIT 3";
    
    $recentResult = $conn->query($recentQuery);
    
    echo "<h4>Recent Lab Orders:</h4>";
    echo "<ul>";
    while ($order = $recentResult->fetch_assoc()) {
        $appointmentText = $order['appointment_id'] ? "Appointment #{$order['appointment_id']}" : "Direct Order (No Appointment)";
        echo "<li><strong>Order #{$order['lab_order_id']}</strong> - {$order['first_name']} {$order['last_name']} ({$order['username']}) - {$appointmentText} - " . date('M d, Y H:i', strtotime($order['order_date'])) . "</li>";
    }
    echo "</ul>";
}

echo "<div style='margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;'>";
echo "<h3>Next Steps</h3>";

if (!$canCreateOrders) {
    echo "<p>❌ <strong>You need to log in with an authorized role (Admin, Doctor, Nurse, or Lab Tech) to create lab orders.</strong></p>";
} else {
    echo "<p>✅ <strong>System appears ready for lab order creation!</strong></p>";
    
    echo "<h4>Testing Links:</h4>";
    echo "<p>";
    echo "<a href='complete_lab_fix.php' style='color: #007bff; margin-right: 20px;'>🔧 Run Complete Fix & Test</a>";
    echo "<a href='../pages/laboratory-management/create_lab_order.php' style='color: #28a745; margin-right: 20px;'>➕ Create Lab Order</a>";
    echo "<a href='../pages/laboratory-management/lab_management.php' style='color: #17a2b8;'>📊 View Lab Dashboard</a>";
    echo "</p>";
}
echo "</div>";
?>