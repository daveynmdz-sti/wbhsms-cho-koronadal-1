<?php
session_start();
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

echo "<h2>Debug Lab Results Variable</h2>\n";
echo "<pre>\n";

// Get patient ID from session
$patient_id = $_SESSION['patient_id'] ?? null;

if (!$patient_id) {
    echo "No patient ID in session\n";
    exit;
}

echo "Patient ID: $patient_id\n\n";

// Run the exact same query as in profile.php
$lab_results = [];
try {
    // Enhanced query with better error handling and debugging
    $stmt = $pdo->prepare("
        SELECT 
            loi.id as lab_order_item_id,
            loi.lab_order_id,
            lo.patient_id,
            loi.test_name,
            loi.test_type,
            loi.sample_type,
            loi.normal_range,
            loi.result_value,
            loi.result_unit,
            loi.result_status,
            loi.result_date,
            loi.remarks,
            loi.created_at,
            loi.updated_at,
            lo.order_date,
            lo.status as order_status,
            lo.overall_status,
            CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) as ordered_by_doctor
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE lo.patient_id = ? 
        ORDER BY COALESCE(loi.result_date, loi.updated_at, loi.created_at) DESC 
        LIMIT 4
    ");
    $stmt->execute([$patient_id]);
    $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query executed successfully\n";
    echo "Number of results: " . count($lab_results) . "\n\n";
    
    if (count($lab_results) > 0) {
        echo "Lab results array:\n";
        var_dump($lab_results);
        
        echo "\n\nTest the PHP condition:\n";
        echo "!empty(\$lab_results) = " . ((!empty($lab_results)) ? 'TRUE' : 'FALSE') . "\n";
        
        echo "\nWhat the HTML would show:\n";
        foreach (array_slice($lab_results, 0, 4) as $i => $result) {
            echo "Row " . ($i + 1) . ":\n";
            echo "  Test Name: " . ($result['test_name'] ?? 'NULL') . "\n";
            echo "  Result Value: " . ($result['result_value'] ?? 'NULL') . "\n";
            echo "  Result Status: " . ($result['result_status'] ?? 'NULL') . "\n";
            echo "  Lab Order Item ID: " . ($result['lab_order_item_id'] ?? 'NULL') . "\n";
            echo "\n";
        }
    } else {
        echo "No results found - will show empty state\n";
        
        // Check if there are any lab orders for this patient
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
        $stmt2->execute([$patient_id]);
        $order_count = $stmt2->fetchColumn();
        echo "Lab orders count: $order_count\n";
        
        // Check if there are any lab items at all
        $stmt3 = $pdo->query("SELECT COUNT(*) FROM lab_order_items");
        $total_items = $stmt3->fetchColumn();
        echo "Total lab items in database: $total_items\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== TESTING PHP CONDITIONS ===\n";
echo "empty(\$lab_results): " . (empty($lab_results) ? 'TRUE' : 'FALSE') . "\n";
echo "!empty(\$lab_results): " . (!empty($lab_results) ? 'TRUE' : 'FALSE') . "\n";
echo "count(\$lab_results): " . count($lab_results) . "\n";
echo "count(\$lab_results) > 0: " . ((count($lab_results) > 0) ? 'TRUE' : 'FALSE') . "\n";

echo "</pre>\n";

// Show what the actual HTML condition would render
echo "<h3>HTML Rendering Test</h3>\n";
echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f9f9f9;'>\n";

if (!empty($lab_results)) {
    echo "<p style='color: green;'><strong>✓ Lab results section will be displayed</strong></p>\n";
    echo "<p>Number of results: " . count($lab_results) . "</p>\n";
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>\n";
    echo "<thead><tr><th>Test</th><th>Result</th><th>Status</th><th>Action Button</th></tr></thead>\n";
    echo "<tbody>\n";
    
    foreach (array_slice($lab_results, 0, 4) as $result) {
        echo "<tr>\n";
        echo "<td>" . htmlspecialchars($result['test_name'] ?? 'Unknown') . "</td>\n";
        echo "<td>" . htmlspecialchars($result['result_value'] ?? 'Pending') . " " . htmlspecialchars($result['result_unit'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($result['result_status'] ?? 'pending') . "</td>\n";
        echo "<td>";
        if (!empty($result['result_value'])) {
            echo "<button onclick='alert(\"View Result ID: {$result['lab_order_item_id']}\")'>View Result</button>";
        } else {
            echo "Pending";
        }
        echo "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</tbody>\n";
    echo "</table>\n";
} else {
    echo "<p style='color: red;'><strong>✗ No lab results - empty state will be shown</strong></p>\n";
    echo "<div style='text-align: center; padding: 2em; color: #666;'>\n";
    echo "<h4>No Lab Results Available</h4>\n";
    echo "<p>You don't have any lab test results yet.</p>\n";
    echo "</div>\n";
}

echo "</div>\n";
?>