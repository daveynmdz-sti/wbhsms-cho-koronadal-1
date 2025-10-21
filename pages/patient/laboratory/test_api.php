<?php
// Simple API Test for Patient Lab System
// This file helps verify that the lab APIs are working correctly

$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

// Set a test patient ID for testing (you can change this to an actual patient ID in your database)
$_SESSION['patient_id'] = 1; // Test with patient ID 1

echo "<h1>Patient Lab API Test</h1>";

// Test 1: Check if we can fetch lab orders for the patient
echo "<h2>Test 1: Lab Orders</h2>";
$stmt = $conn->prepare("
    SELECT 
        lo.lab_order_id,
        lo.order_date,
        lo.status,
        GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types
    FROM lab_orders lo
    LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
    WHERE lo.patient_id = ?
    GROUP BY lo.lab_order_id
    ORDER BY lo.order_date DESC
    LIMIT 5
");

if ($stmt) {
    $stmt->bind_param("i", $_SESSION['patient_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Lab Order ID</th><th>Order Date</th><th>Status</th><th>Test Types</th><th>API Test</th></tr>";
        
        while ($order = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $order['lab_order_id'] . "</td>";
            echo "<td>" . $order['order_date'] . "</td>";
            echo "<td>" . $order['status'] . "</td>";
            echo "<td>" . ($order['test_types'] ?: 'No tests') . "</td>";
            echo "<td><a href='get_lab_order_details.php?id=" . $order['lab_order_id'] . "' target='_blank'>Test API</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No lab orders found for patient ID: " . $_SESSION['patient_id'] . "</p>";
    }
    $stmt->close();
} else {
    echo "<p>Error: Could not prepare statement - " . $conn->error . "</p>";
}

// Test 2: Check if we can fetch completed lab results
echo "<h2>Test 2: Lab Results (Completed Orders)</h2>";
$stmt = $conn->prepare("
    SELECT 
        lo.lab_order_id,
        lo.order_date,
        lo.status,
        MAX(loi.result_date) as latest_result_date,
        GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types
    FROM lab_orders lo
    LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
    WHERE lo.patient_id = ? AND lo.status = 'completed'
    GROUP BY lo.lab_order_id
    ORDER BY MAX(loi.result_date) DESC
    LIMIT 5
");

if ($stmt) {
    $stmt->bind_param("i", $_SESSION['patient_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Lab Order ID</th><th>Order Date</th><th>Result Date</th><th>Test Types</th><th>API Test</th></tr>";
        
        while ($result_row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $result_row['lab_order_id'] . "</td>";
            echo "<td>" . $result_row['order_date'] . "</td>";
            echo "<td>" . ($result_row['latest_result_date'] ?: 'No result date') . "</td>";
            echo "<td>" . ($result_row['test_types'] ?: 'No tests') . "</td>";
            echo "<td><a href='get_lab_result_details.php?id=" . $result_row['lab_order_id'] . "' target='_blank'>Test API</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No completed lab results found for patient ID: " . $_SESSION['patient_id'] . "</p>";
    }
    $stmt->close();
} else {
    echo "<p>Error: Could not prepare statement - " . $conn->error . "</p>";
}

// Test 3: Database schema validation
echo "<h2>Test 3: Database Schema Validation</h2>";

// Check lab_orders table
$result = $conn->query("DESCRIBE lab_orders");
if ($result) {
    echo "<h3>lab_orders table structure:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
    }
    echo "</table>";
}

// Check lab_order_items table
$result = $conn->query("DESCRIBE lab_order_items");
if ($result) {
    echo "<h3>lab_order_items table structure:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
    }
    echo "</table>";
}

echo "<h2>Session Information</h2>";
echo "<p>Patient ID: " . ($_SESSION['patient_id'] ?? 'Not set') . "</p>";
echo "<p>Session Status: " . (isset($_SESSION['patient_id']) ? 'Active' : 'Not logged in') . "</p>";

echo "<hr>";
echo "<p><a href='lab_test.php'>← Back to Lab Test Interface</a></p>";
?>