<?php
/**
 * Test Lab Order Details Endpoint
 * This script tests the get_lab_order_details.php endpoint directly
 */

echo "<h1>Testing Lab Order Details Endpoint</h1>";

// Test session simulation (like a logged-in patient)
session_start();

// Check if we have test session data
if (!isset($_SESSION['patient_id'])) {
    echo "<h2>❌ No Patient Session Found</h2>";
    echo "<p>You need to be logged in as a patient to test this endpoint.</p>";
    echo "<p>Current session:</p>";
    echo "<pre>" . print_r($_SESSION, true) . "</pre>";
    
    echo "<h3>🔧 Test Session Setup</h3>";
    echo "<p>Setting up test session...</p>";
    
    // Set up a test session (you can modify these values)
    $_SESSION['patient_id'] = 1; // Numeric patient ID  
    $_SESSION['patient_username'] = 'P000001'; // Patient username
    
    echo "<p>✅ Test session created:</p>";
    echo "<ul>";
    echo "<li>Patient ID: " . $_SESSION['patient_id'] . "</li>";
    echo "<li>Patient Username: " . $_SESSION['patient_username'] . "</li>";
    echo "</ul>";
}

echo "<h2>📋 Session Information</h2>";
echo "<p><strong>Patient ID:</strong> " . ($_SESSION['patient_id'] ?? 'NOT SET') . "</p>";
echo "<p><strong>Patient Username:</strong> " . ($_SESSION['patient_username'] ?? 'NOT SET') . "</p>";

// Include database connection to check for lab orders
$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'] ?? null;

if ($patient_id && is_numeric($patient_id)) {
    echo "<h2>🔍 Available Lab Orders</h2>";
    
    try {
        $stmt = $conn->prepare("SELECT lab_order_id, order_date, status FROM lab_orders WHERE patient_id = ? ORDER BY order_date DESC LIMIT 5");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($orders)) {
            echo "<p>❌ No lab orders found for patient ID: {$patient_id}</p>";
            
            // Check if patient exists
            $patientStmt = $conn->prepare("SELECT patient_id, first_name, last_name, username FROM patients WHERE patient_id = ?");
            $patientStmt->bind_param("i", $patient_id);
            $patientStmt->execute();
            $patient = $patientStmt->get_result()->fetch_assoc();
            
            if ($patient) {
                echo "<p>✅ Patient exists: {$patient['first_name']} {$patient['last_name']} ({$patient['username']})</p>";
            } else {
                echo "<p>❌ Patient with ID {$patient_id} does not exist in database</p>";
            }
        } else {
            echo "<p>✅ Found " . count($orders) . " lab orders:</p>";
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Order ID</th><th>Date</th><th>Status</th><th>Test Endpoint</th></tr>";
            
            foreach ($orders as $order) {
                $testUrl = "get_lab_order_details.php?id=" . $order['lab_order_id'];
                echo "<tr>";
                echo "<td>{$order['lab_order_id']}</td>";
                echo "<td>{$order['order_date']}</td>";
                echo "<td>{$order['status']}</td>";
                echo "<td><a href='{$testUrl}' target='_blank'>Test Endpoint</a></td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (Exception $e) {
        echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>❌ Invalid patient session data</p>";
}

echo "<h2>🧪 Manual Endpoint Test</h2>";
echo "<p>You can test specific order IDs by accessing:</p>";
echo "<p><code>get_lab_order_details.php?id=ORDER_ID</code></p>";

echo "<h3>📝 Debugging Steps</h3>";
echo "<ol>";
echo "<li>Check if patient session is set correctly</li>";
echo "<li>Verify patient exists in database</li>";
echo "<li>Check if lab orders exist for this patient</li>";
echo "<li>Test the endpoint URL directly</li>";
echo "<li>Check browser console for JavaScript errors</li>";
echo "</ol>";
?>