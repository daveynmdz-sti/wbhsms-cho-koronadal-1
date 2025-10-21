<?php
// Patient Lab Test Simulator
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

echo "<h1>🧪 Patient Lab Test Simulator</h1>";

// Get all patients who have lab orders
$result = $conn->query("
    SELECT DISTINCT p.patient_id, p.first_name, p.last_name, p.username,
           COUNT(lo.lab_order_id) as lab_orders_count
    FROM patients p
    INNER JOIN lab_orders lo ON p.patient_id = lo.patient_id
    GROUP BY p.patient_id, p.first_name, p.last_name, p.username
    ORDER BY lab_orders_count DESC
");

if ($result && $result->num_rows > 0) {
    echo "<h2>Patients with Lab Orders:</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
    echo "<tr><th>Patient ID</th><th>Name</th><th>Username</th><th>Lab Orders</th><th>Action</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['patient_id'] . "</td>";
        echo "<td>" . $row['first_name'] . " " . $row['last_name'] . "</td>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['lab_orders_count'] . "</td>";
        echo "<td><a href='?simulate_patient=" . $row['patient_id'] . "' style='background: #007bff; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px;'>Test as this patient</a></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>❌ No patients with lab orders found!</p>";
}

// Handle patient simulation
if (isset($_GET['simulate_patient'])) {
    $simulate_patient_id = $_GET['simulate_patient'];
    
    // Set session to this patient
    $_SESSION['patient_id'] = $simulate_patient_id;
    
    echo "<div style='background: #e8f5e8; padding: 1rem; border: 1px solid #4caf50; border-radius: 8px; margin: 1rem 0;'>";
    echo "<h3 style='color: #2e7d32; margin: 0 0 0.5rem 0;'>✅ Simulation Active</h3>";
    echo "<p style='margin: 0;'><strong>Now simulating patient ID:</strong> " . $simulate_patient_id . "</p>";
    echo "<p style='margin: 0.5rem 0 0 0;'><a href='lab_test.php' style='background: #4caf50; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px;'>🧪 Test Lab Interface</a></p>";
    echo "</div>";
    
    // Show details about this patient's lab orders
    $stmt = $conn->prepare("
        SELECT lo.lab_order_id, lo.order_date, lo.status,
               GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types
        FROM lab_orders lo
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.patient_id = ?
        GROUP BY lo.lab_order_id
        ORDER BY lo.order_date DESC
    ");
    
    $stmt->bind_param("s", $simulate_patient_id);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (count($orders) > 0) {
        echo "<h3>Lab Orders for This Patient:</h3>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Lab Order ID</th><th>Order Date</th><th>Status</th><th>Test Types</th></tr>";
        
        foreach ($orders as $order) {
            echo "<tr>";
            echo "<td>" . $order['lab_order_id'] . "</td>";
            echo "<td>" . $order['order_date'] . "</td>";
            echo "<td>" . $order['status'] . "</td>";
            echo "<td>" . ($order['test_types'] ?: 'No tests') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    $stmt->close();
}

// Show current session info
echo "<h2>Current Session:</h2>";
echo "<p><strong>Current Patient ID:</strong> " . ($_SESSION['patient_id'] ?? 'NOT SET') . "</p>";

if (isset($_SESSION['patient_id'])) {
    echo "<p><a href='lab_test.php' style='background: #007bff; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 6px; font-weight: 600;'>🔬 Go to Lab Tests Page</a></p>";
}

echo "<hr>";
echo "<p><a href='?'>🔄 Refresh</a> | <a href='quick_session_check.php'>📊 Session Check</a> | <a href='production_diagnostic.php'>🔍 Full Diagnostic</a></p>";
?>