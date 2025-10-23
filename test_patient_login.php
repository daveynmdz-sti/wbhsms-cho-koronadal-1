<?php
// Auto-login as patient 7 and test the API
error_reporting(E_ALL);
ini_set('display_errors', 1);

$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';

// Login as patient 7
$_SESSION['patient_id'] = 7;
$_SESSION['patient_logged_in'] = true;
$_SESSION['login_time'] = time();

echo "<h2>✅ Logged in as Patient ID 7</h2>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Patient ID: " . $_SESSION['patient_id'] . "</p>";

// Test the API directly
echo "<h3>Testing Patient Referral API...</h3>";

// Simulate the API call
$referral_id = 19;
$_GET['referral_id'] = $referral_id;

echo "Testing referral ID: $referral_id<br>";

try {
    ob_start();
    include $root_path . '/api/patient_referral_details.php';
    $output = ob_get_clean();
    
    echo "<h4>API Response:</h4>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    
    $data = json_decode($output, true);
    if ($data === null) {
        echo "<p style='color: red;'>❌ Invalid JSON response</p>";
    } else {
        echo "<p style='color: green;'>✅ Valid JSON response</p>";
        if (isset($data['success']) && $data['success']) {
            echo "<p style='color: green;'>✅ API call successful!</p>";
        } else {
            echo "<p style='color: red;'>❌ API error: " . ($data['error'] ?? 'Unknown error') . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='pages/patient/referrals/referrals.php'>🔗 Go to Patient Referrals Page</a></p>";
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
</style>