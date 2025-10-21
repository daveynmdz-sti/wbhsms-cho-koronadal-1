<?php
/**
 * Check current patient and their lab orders
 */
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Current Patient Lab Orders Status</h1>";

if (!isset($_SESSION['patient_id'])) {
    echo "<p>❌ No patient logged in</p>";
    exit;
}

$patient_id = $_SESSION['patient_id'];
$patient_username = $_SESSION['patient_username'] ?? 'Unknown';

echo "<h2>Current Patient Session</h2>";
echo "<p><strong>Patient ID:</strong> {$patient_id}</p>";
echo "<p><strong>Username:</strong> {$patient_username}</p>";

try {
    // Get patient details
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    
    if ($patient) {
        echo "<h2>Patient Details</h2>";
        echo "<p><strong>Name:</strong> {$patient['first_name']} {$patient['last_name']}</p>";
        echo "<p><strong>Username:</strong> {$patient['username']}</p>";
        echo "<p><strong>Email:</strong> {$patient['email']}</p>";
    }
    
    // Check lab orders for this patient
    $stmt = $conn->prepare("SELECT * FROM lab_orders WHERE patient_id = ? ORDER BY order_date DESC");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo "<h2>Lab Orders for This Patient</h2>";
    if (empty($orders)) {
        echo "<p>❌ <strong>No lab orders found for this patient!</strong></p>";
        echo "<p>This is why the modal fails to load order details.</p>";
        
        echo "<h3>Available Lab Orders in Database (Other Patients)</h3>";
        $stmt = $conn->prepare("SELECT lo.lab_order_id, lo.patient_id, lo.order_date, lo.status, p.username, p.first_name, p.last_name FROM lab_orders lo JOIN patients p ON lo.patient_id = p.patient_id ORDER BY lo.order_date DESC");
        $stmt->execute();
        $allOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (!empty($allOrders)) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Order ID</th><th>Patient</th><th>Username</th><th>Date</th><th>Status</th></tr>";
            foreach ($allOrders as $order) {
                echo "<tr>";
                echo "<td>{$order['lab_order_id']}</td>";
                echo "<td>{$order['first_name']} {$order['last_name']}</td>";
                echo "<td>{$order['username']}</td>";
                echo "<td>{$order['order_date']}</td>";
                echo "<td>{$order['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "<p><em>To test the lab orders functionality, you need to log in as one of these patients.</em></p>";
        }
        
    } else {
        echo "<p>✅ <strong>Found " . count($orders) . " lab order(s)</strong></p>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Order ID</th><th>Date</th><th>Status</th><th>Actions</th></tr>";
        foreach ($orders as $order) {
            echo "<tr>";
            echo "<td>{$order['lab_order_id']}</td>";
            echo "<td>{$order['order_date']}</td>";
            echo "<td>{$order['status']}</td>";
            echo "<td><a href='debug_get_lab_order_details.php?id={$order['lab_order_id']}' target='_blank'>Test Endpoint</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?>