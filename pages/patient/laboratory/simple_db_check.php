<?php
/**
 * Simple Lab Orders Check
 */

// Fix path resolution
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/db.php';

echo "<h1>Database Lab Orders Check</h1>";

try {
    // Check if there are any lab orders at all
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM lab_orders");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    echo "<p><strong>Total Lab Orders in Database:</strong> " . $result['count'] . "</p>";
    
    if ($result['count'] > 0) {
        // Get some sample orders
        $stmt = $conn->prepare("SELECT lab_order_id, patient_id, order_date, status FROM lab_orders ORDER BY order_date DESC LIMIT 5");
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo "<h2>Sample Lab Orders:</h2>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Order ID</th><th>Patient ID</th><th>Date</th><th>Status</th></tr>";
        foreach ($orders as $order) {
            echo "<tr>";
            echo "<td>{$order['lab_order_id']}</td>";
            echo "<td>{$order['patient_id']}</td>";
            echo "<td>{$order['order_date']}</td>";
            echo "<td>{$order['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test the endpoint with the first order
        $firstOrder = $orders[0];
        echo "<h2>Test Endpoint with Order ID {$firstOrder['lab_order_id']}:</h2>";
        echo "<p><a href='get_lab_order_details.php?id={$firstOrder['lab_order_id']}' target='_blank'>";
        echo "Click here to test: get_lab_order_details.php?id={$firstOrder['lab_order_id']}</a></p>";
        
    } else {
        echo "<p>❌ No lab orders found in database. This might be why the modal is failing.</p>";
    }
    
    // Check patients table
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM patients");
    $stmt->execute(); 
    $result = $stmt->get_result()->fetch_assoc();
    echo "<p><strong>Total Patients in Database:</strong> " . $result['count'] . "</p>";
    
} catch (Exception $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
}
?>