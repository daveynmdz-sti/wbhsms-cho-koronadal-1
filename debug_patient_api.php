<?php
// Simple test to check patient session and API
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Patient API Debug Test</h2>";

// Start patient session
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';

echo "<h3>Session Status:</h3>";
echo "Session ID: " . session_id() . "<br>";
echo "Patient ID in session: " . (isset($_SESSION['patient_id']) ? $_SESSION['patient_id'] : 'NOT SET') . "<br>";
echo "Session data: <pre>" . print_r($_SESSION, true) . "</pre>";

if (!isset($_SESSION['patient_id'])) {
    echo "<h3 style='color: red;'>NO PATIENT SESSION FOUND!</h3>";
    echo "<p>You need to log in as a patient first.</p>";
    echo "<p><a href='../pages/patient/login.php'>Go to Patient Login</a></p>";
} else {
    echo "<h3 style='color: green;'>Patient Session Active</h3>";
    echo "<p>Testing APIs with Patient ID: " . $_SESSION['patient_id'] . "</p>";
    
    // Test API call
    if (isset($_GET['test_api'])) {
        $referral_id = isset($_GET['referral_id']) ? intval($_GET['referral_id']) : 19;
        
        echo "<h3>Testing API Call:</h3>";
        echo "Referral ID: $referral_id<br>";
        
        // Test the API
        $api_url = "http://localhost/wbhsms-cho-koronadal-1/api/patient_referral_details.php?id=$referral_id";
        echo "API URL: $api_url<br>";
        
        // Use curl to test the API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
        
        // Set session cookie
        if (session_id()) {
            curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "<h4>API Response:</h4>";
        echo "HTTP Code: $http_code<br>";
        echo "Response: <pre>$response</pre>";
    } else {
        echo "<p><a href='?test_api=1&referral_id=19'>Test API with Referral ID 19</a></p>";
    }
}

?>
<style>
body { font-family: Arial, sans-serif; margin: 20px; }
pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; }
</style>