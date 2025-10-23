<?php
session_start();
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

echo "<h2>Test Lab Results API</h2>\n";
echo "<pre>\n";

// Get patient ID
$patient_id = $_SESSION['patient_id'] ?? null;
if (!$patient_id) {
    echo "No patient session found\n";
    exit;
}

echo "Testing API for Patient ID: $patient_id\n\n";

// Get a lab order item ID for testing
$stmt = $pdo->prepare("
    SELECT loi.id as lab_order_item_id, loi.test_name
    FROM lab_order_items loi
    INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
    WHERE lo.patient_id = ?
    LIMIT 1
");
$stmt->execute([$patient_id]);
$lab_item = $stmt->fetch();

if (!$lab_item) {
    echo "No lab items found for this patient\n";
    exit;
}

$test_item_id = $lab_item['lab_order_item_id'];
echo "Testing with Lab Item ID: $test_item_id ({$lab_item['test_name']})\n\n";

// Test the API endpoint
$api_url = "http://localhost/wbhsms-cho-koronadal-1/pages/patient/api/get_lab_result_details.php?lab_order_item_id=" . $test_item_id;
echo "API URL: $api_url\n\n";

// Create a context for the HTTP request to include session cookies
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? ''
    ]
]);

try {
    $response = file_get_contents($api_url, false, $context);
    $data = json_decode($response, true);
    
    echo "API Response:\n";
    echo "Success: " . ($data['success'] ? 'YES' : 'NO') . "\n";
    
    if ($data['success']) {
        echo "Result Data:\n";
        foreach ($data['result'] as $key => $value) {
            echo "  $key: " . ($value ?? 'NULL') . "\n";
        }
    } else {
        echo "Error: " . $data['message'] . "\n";
    }
    
} catch (Exception $e) {
    echo "API Test Error: " . $e->getMessage() . "\n";
}

echo "</pre>\n";

// JavaScript test
echo "<h3>JavaScript API Test</h3>\n";
echo "<div id='js-result'></div>\n";
echo "<button onclick='testAPI()'>Test API with JavaScript</button>\n";

echo "<script>
function testAPI() {
    const resultDiv = document.getElementById('js-result');
    resultDiv.innerHTML = 'Loading...';
    
    fetch('$api_url')
        .then(response => response.json())
        .then(data => {
            console.log('API Response:', data);
            if (data.success) {
                resultDiv.innerHTML = `
                    <h4>✓ API Success</h4>
                    <p><strong>Test:</strong> \${data.result.test_name}</p>
                    <p><strong>Result:</strong> \${data.result.result_value} \${data.result.result_unit || ''}</p>
                    <p><strong>Status:</strong> \${data.result.result_status}</p>
                    <p><strong>Date:</strong> \${data.result.result_date || data.result.created_at}</p>
                `;
            } else {
                resultDiv.innerHTML = `<h4>✗ API Error</h4><p>\${data.message}</p>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            resultDiv.innerHTML = `<h4>✗ JavaScript Error</h4><p>\${error.message}</p>`;
        });
}
</script>";
?>