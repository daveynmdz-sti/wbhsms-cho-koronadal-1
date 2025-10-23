<?php
// Debug version of patient referral details API
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>Patient Referral API Debug</h2>";

// Include patient session configuration
$root_path = dirname(__DIR__);

echo "<p>Root path: $root_path</p>";

// Check if session files exist
echo "<p>Session file exists: " . (file_exists($root_path . '/config/session/patient_session.php') ? 'YES' : 'NO') . "</p>";
echo "<p>DB file exists: " . (file_exists($root_path . '/config/db.php') ? 'YES' : 'NO') . "</p>";

require_once $root_path . '/config/session/patient_session.php';

echo "<p>Session status: " . session_status() . "</p>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Patient ID in session: " . (isset($_SESSION['patient_id']) ? $_SESSION['patient_id'] : 'NOT SET') . "</p>";
echo "<p>Session data: <pre>" . print_r($_SESSION, true) . "</pre></p>";

// If no patient session, try to log in
if (!isset($_SESSION['patient_id'])) {
    echo "<h3>No Patient Session - Creating One</h3>";
    $_SESSION['patient_id'] = 7;
    $_SESSION['patient_logged_in'] = true;
    $_SESSION['login_time'] = time();
    echo "<p>✅ Patient session created for ID: 7</p>";
}

require_once $root_path . '/config/db.php';

echo "<p>Database connection: " . (isset($conn) ? 'SUCCESS' : 'FAILED') . "</p>";
if (isset($conn) && $conn->connect_error) {
    echo "<p>DB Error: " . $conn->connect_error . "</p>";
}

// Test parameters
$referral_id = $_GET['referral_id'] ?? 19;
echo "<p>Testing referral ID: $referral_id</p>";

// Test the actual API call
echo "<hr><h3>API Test Results:</h3>";

try {
    $_GET['referral_id'] = $referral_id; // Simulate the parameter
    
    ob_start();
    include $root_path . '/api/patient_referral_details.php';
    $output = ob_get_clean();
    
    echo "<h4>Raw Output:</h4>";
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    
    $data = json_decode($output, true);
    if ($data === null) {
        echo "<p style='color: red;'>❌ Invalid JSON</p>";
    } else {
        echo "<p style='color: green;'>✅ Valid JSON</p>";
        if ($data['success'] ?? false) {
            echo "<p style='color: green;'>✅ API Success</p>";
        } else {
            echo "<p style='color: red;'>❌ API Error: " . ($data['error'] ?? 'Unknown') . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception: " . $e->getMessage() . "</p>";
} catch (Error $e) {
    echo "<p style='color: red;'>❌ Fatal Error: " . $e->getMessage() . "</p>";
}

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
</style>