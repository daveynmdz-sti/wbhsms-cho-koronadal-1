<?php
/**
 * Test the get_patient_vitals endpoint
 */

// Simulate the AJAX call
$test_patient_id = 'P000007'; // David Animo Diaz

$url = "http://localhost/wbhsms-cho-koronadal-1/pages/clinical-encounter-management/new_consultation_standalone.php?action=get_patient_vitals&patient_id=" . urlencode($test_patient_id);

echo "<h2>🧪 Testing get_patient_vitals Endpoint</h2>";

echo "<p><strong>Test URL:</strong><br>";
echo "<code>$url</code></p>";

echo "<h3>🔍 Making Request...</h3>";

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]
]);

$response = file_get_contents($url, false, $context);

echo "<h3>📋 Raw Response:</h3>";
echo "<pre style='background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
echo htmlspecialchars($response);
echo "</pre>";

echo "<h3>🔍 Response Analysis:</h3>";

if (json_decode($response) !== null) {
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724;'>";
    echo "<strong>✅ Valid JSON Response</strong><br>";
    
    $data = json_decode($response, true);
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>❌ Invalid JSON Response</strong><br>";
    echo "This means there's likely a PHP error or HTML output before the JSON.";
    echo "</div>";
}

// Also test the direct database query
echo "<h3>🗄️ Direct Database Test:</h3>";

try {
    require_once '../../config/db.php';
    
    $stmt = $conn->prepare("
        SELECT 
            v.vitals_id,
            v.systolic_bp,
            v.diastolic_bp,
            v.heart_rate,
            v.respiratory_rate,
            v.temperature,
            v.weight,
            v.height,
            v.bmi,
            v.remarks,
            v.recorded_at,
            e.first_name as recorded_by_name,
            e.last_name as recorded_by_lastname
        FROM vitals v
        LEFT JOIN employees e ON v.recorded_by = e.employee_id
        WHERE v.patient_id = ? AND DATE(v.recorded_at) = CURDATE()
        ORDER BY v.recorded_at DESC
        LIMIT 1
    ");
    
    $stmt->bind_param('s', $test_patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vitals = $result->fetch_assoc();
    
    if ($vitals) {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724;'>";
        echo "<strong>✅ Vitals Found in Database</strong><br>";
        echo "<pre>";
        print_r($vitals);
        echo "</pre>";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; color: #856404;'>";
        echo "<strong>⚠️ No Vitals Found for Patient ID: $test_patient_id on " . date('Y-m-d') . "</strong><br>";
        echo "This is expected if no vitals were recorded today for this patient.";
        echo "</div>";
        
        // Show all vitals for this patient
        $stmt = $conn->prepare("
            SELECT vitals_id, recorded_at, systolic_bp, diastolic_bp 
            FROM vitals 
            WHERE patient_id = ? 
            ORDER BY recorded_at DESC 
            LIMIT 5
        ");
        $stmt->bind_param('s', $test_patient_id);
        $stmt->execute();
        $all_vitals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if ($all_vitals) {
            echo "<h4>Recent vitals for this patient:</h4>";
            echo "<pre>";
            print_r($all_vitals);
            echo "</pre>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>❌ Database Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2, h3 { color: #2c5530; }
code { background: #f4f4f4; padding: 2px 4px; border-radius: 3px; }
</style>