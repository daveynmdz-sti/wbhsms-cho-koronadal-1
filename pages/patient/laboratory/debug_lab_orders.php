<?php
// Lab Order Debugging Tool
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

echo "<h1>Lab Order Debug Information</h1>";

// Check current patient session
echo "<h2>1. Current Patient Session</h2>";
echo "<p><strong>Patient ID in Session:</strong> " . ($_SESSION['patient_id'] ?? 'NOT SET') . "</p>";
echo "<p><strong>Session Data:</strong></p>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

// Check if patient exists and get their info
if (isset($_SESSION['patient_id'])) {
    $patient_id = $_SESSION['patient_id'];
    
    echo "<h2>2. Patient Information</h2>";
    $stmt = $conn->prepare("SELECT patient_id, first_name, last_name, username FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    
    if ($patient) {
        echo "<p><strong>Patient Found:</strong></p>";
        echo "<ul>";
        echo "<li>ID: " . $patient['patient_id'] . "</li>";
        echo "<li>Name: " . $patient['first_name'] . " " . $patient['last_name'] . "</li>";
        echo "<li>Username: " . $patient['username'] . "</li>";
        echo "</ul>";
    } else {
        echo "<p><strong>ERROR: Patient not found in database!</strong></p>";
    }
    $stmt->close();
}

// Check all lab orders in the system
echo "<h2>3. All Lab Orders in System</h2>";
$result = $conn->query("
    SELECT 
        lo.lab_order_id, 
        lo.patient_id, 
        lo.order_date, 
        lo.status,
        p.first_name, 
        p.last_name, 
        p.username,
        COUNT(loi.item_id) as test_count
    FROM lab_orders lo 
    LEFT JOIN patients p ON lo.patient_id = p.patient_id
    LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
    GROUP BY lo.lab_order_id
    ORDER BY lo.order_date DESC 
    LIMIT 20
");

if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Lab Order ID</th><th>Patient ID</th><th>Patient Name</th><th>Username</th><th>Order Date</th><th>Status</th><th>Test Count</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['lab_order_id'] . "</td>";
        echo "<td>" . $row['patient_id'] . "</td>";
        echo "<td>" . ($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['order_date'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['test_count'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No lab orders found in the system.</p>";
}

// Check lab orders for current patient specifically
if (isset($_SESSION['patient_id'])) {
    echo "<h2>4. Lab Orders for Current Patient (ID: {$patient_id})</h2>";
    
    $stmt = $conn->prepare("
        SELECT 
            lo.lab_order_id, 
            lo.order_date, 
            lo.status,
            COUNT(loi.item_id) as test_count,
            GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types
        FROM lab_orders lo 
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.patient_id = ?
        GROUP BY lo.lab_order_id
        ORDER BY lo.order_date DESC
    ");
    
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Lab Order ID</th><th>Order Date</th><th>Status</th><th>Test Count</th><th>Test Types</th></tr>";
        
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['lab_order_id'] . "</td>";
            echo "<td>" . $row['order_date'] . "</td>";
            echo "<td>" . $row['status'] . "</td>";
            echo "<td>" . $row['test_count'] . "</td>";
            echo "<td>" . ($row['test_types'] ?: 'No tests') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p><strong>No lab orders found for this patient!</strong></p>";
        echo "<p>This is why the patient lab interface shows 'No Lab Results Found'.</p>";
    }
    $stmt->close();
}

// Check lab_order_items for more details
echo "<h2>5. Lab Order Items Details</h2>";
$result = $conn->query("
    SELECT 
        loi.item_id,
        loi.lab_order_id,
        loi.test_type,
        loi.status,
        loi.result_date,
        lo.patient_id,
        p.first_name,
        p.last_name
    FROM lab_order_items loi
    LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
    LEFT JOIN patients p ON lo.patient_id = p.patient_id
    ORDER BY loi.lab_order_id DESC
    LIMIT 20
");

if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Item ID</th><th>Lab Order ID</th><th>Patient ID</th><th>Patient Name</th><th>Test Type</th><th>Status</th><th>Result Date</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['item_id'] . "</td>";
        echo "<td>" . $row['lab_order_id'] . "</td>";
        echo "<td>" . $row['patient_id'] . "</td>";
        echo "<td>" . ($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . $row['test_type'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . ($row['result_date'] ?: 'No date') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No lab order items found.</p>";
}

echo "<hr>";
echo "<p><a href='lab_test.php'>← Back to Lab Test Interface</a></p>";
?>