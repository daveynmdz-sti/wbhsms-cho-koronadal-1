<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Patient Referral API...\n";
echo "================================\n\n";

// Start session and simulate patient login
session_start();

// Get the first patient ID from database for testing
$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';

// Use patient ID 7 which has referrals
$_SESSION['patient_id'] = 7;
echo "Using Patient ID: 7\n";

// Get a referral for this patient
$stmt = $conn->prepare("SELECT referral_id FROM referrals WHERE patient_id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['patient_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $referral = $result->fetch_assoc();
    $referral_id = $referral['referral_id'];
    echo "Testing Referral ID: $referral_id\n\n";
    
    // Test the API
    echo "Testing API: /api/patient_referral_details.php\n";
    
    // Simulate the API call
    $_GET['id'] = $referral_id;
    
    ob_start();
    include $root_path . '/api/patient_referral_details.php';
    $output = ob_get_clean();
    
    echo "API Response:\n";
    echo $output . "\n";
    
    // Test if it's valid JSON
    $data = json_decode($output, true);
    if ($data === null) {
        echo "ERROR: Response is not valid JSON\n";
    } else {
        echo "SUCCESS: Valid JSON response received\n";
        if (isset($data['error'])) {
            echo "API Error: " . $data['error'] . "\n";
        } else {
            echo "Referral Data Keys: " . implode(', ', array_keys($data)) . "\n";
        }
    }
    
} else {
    echo "No referrals found for this patient\n";
}

$conn->close();
?>