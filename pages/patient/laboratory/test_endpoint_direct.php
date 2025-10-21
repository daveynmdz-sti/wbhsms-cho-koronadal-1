<?php
/**
 * Test the get_lab_order_details.php endpoint directly
 */

echo "<h1>Testing get_lab_order_details.php Endpoint</h1>";

// Simulate patient session  
session_start();
$_SESSION['patient_id'] = 35; // Use patient ID 35 (has order ID 9)
$_SESSION['patient_username'] = 'P000035';

echo "<p><strong>Simulated Session:</strong></p>";
echo "<p>Patient ID: " . $_SESSION['patient_id'] . "</p>";
echo "<p>Patient Username: " . $_SESSION['patient_username'] . "</p>";

// Test with order ID 9 (belongs to patient 35)
echo "<h2>Testing Order ID 9 (belongs to Patient 35)</h2>";

// Simulate the AJAX request by including the file
$_GET['id'] = 9;

echo "<h3>Raw Endpoint Response:</h3>";
echo "<pre>";

// Capture the output
ob_start();
try {
    include 'get_lab_order_details.php';
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
$response = ob_get_clean();

echo htmlspecialchars($response);
echo "</pre>";

// Try to decode as JSON
echo "<h3>Parsed JSON Response:</h3>";
$jsonData = json_decode($response, true);
if ($jsonData) {
    echo "<pre>" . print_r($jsonData, true) . "</pre>";
} else {
    echo "<p>❌ Response is not valid JSON</p>";
    echo "<p>JSON Error: " . json_last_error_msg() . "</p>";
}
?>