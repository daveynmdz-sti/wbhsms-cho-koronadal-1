<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Billing System Error Check</h1>";

// Test API endpoints
echo "<h2>API Endpoints Test</h2>";

$api_tests = [
    'search_patients' => [
        'url' => 'api/search_patients.php',
        'method' => 'GET',
        'params' => '?query=test'
    ],
    'service_catalog' => [
        'url' => 'api/get_service_catalog.php',
        'method' => 'GET',
        'params' => ''
    ]
];

foreach ($api_tests as $test_name => $test_config) {
    $full_url = "http://localhost/wbhsms-cho-koronadal-1/{$test_config['url']}{$test_config['params']}";
    
    echo "<h3>Testing: $test_name</h3>";
    echo "<p>URL: <a href='$full_url' target='_blank'>$full_url</a></p>";
    
    // Use curl to test the API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        echo "<p style='color: green;'>✓ HTTP $http_code - API responding</p>";
        
        $json_data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "<p style='color: green;'>✓ Valid JSON response</p>";
            if (isset($json_data['success'])) {
                echo "<p>Success: " . ($json_data['success'] ? 'true' : 'false') . "</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠ Non-JSON response (may be HTML error page)</p>";
            echo "<details><summary>Response preview (first 200 chars)</summary>";
            echo "<pre>" . htmlspecialchars(substr($response, 0, 200)) . "...</pre>";
            echo "</details>";
        }
    } else {
        echo "<p style='color: red;'>✗ HTTP $http_code - API not responding properly</p>";
    }
    
    echo "<hr>";
}

// Check file permissions and existence
echo "<h2>File System Check</h2>";

$critical_files = [
    'config/db.php' => 'Database configuration',
    'config/session/employee_session.php' => 'Employee session management',
    'pages/billing/create_invoice.php' => 'Create invoice interface',
    'pages/billing/process_payment.php' => 'Process payment interface',
    'pages/billing/billing_management.php' => 'Billing management dashboard',
    'pages/billing/billing_reports.php' => 'Billing reports',
    'api/search_patients.php' => 'Patient search API',
    'api/get_service_catalog.php' => 'Service catalog API',
    'api/create_invoice.php' => 'Create invoice API',
    'api/process_payment.php' => 'Process payment API'
];

foreach ($critical_files as $file => $description) {
    if (file_exists($file)) {
        if (is_readable($file)) {
            echo "<p style='color: green;'>✓ $file ($description) - Exists and readable</p>";
        } else {
            echo "<p style='color: orange;'>⚠ $file ($description) - Exists but not readable</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ $file ($description) - Missing</p>";
    }
}

echo "<h2>PHP Configuration Check</h2>";

$php_settings = [
    'display_errors' => ini_get('display_errors'),
    'error_reporting' => error_reporting(),
    'session.auto_start' => ini_get('session.auto_start'),
    'session.cookie_secure' => ini_get('session.cookie_secure'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit')
];

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
foreach ($php_settings as $setting => $value) {
    echo "<tr><td>$setting</td><td>$value</td></tr>";
}
echo "</table>";

echo "<h2>Database Connection Test</h2>";

try {
    require_once 'config/db.php';
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Test each billing table
    $tables = ['invoices', 'invoice_items', 'payments', 'patients', 'service_items', 'employees'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM $table LIMIT 1");
            echo "<p style='color: green;'>✓ Table '$table' accessible</p>";
        } catch (PDOException $e) {
            echo "<p style='color: red;'>✗ Table '$table' error: " . $e->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<h2>Session Test</h2>";

session_start();
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session status: " . session_status() . " (1=disabled, 2=active)</p>";

if (isset($_SESSION)) {
    echo "<p>Session variables count: " . count($_SESSION) . "</p>";
    if (!empty($_SESSION)) {
        echo "<details><summary>Session contents</summary><pre>";
        print_r($_SESSION);
        echo "</pre></details>";
    }
} else {
    echo "<p style='color: red;'>✗ Session not available</p>";
}
?>