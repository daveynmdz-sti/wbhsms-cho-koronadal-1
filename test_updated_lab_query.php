<?php
require_once 'config/db.php';

echo "<h2>Updated Lab Query Test with Correct Field Names</h2>\n";
echo "<pre>\n";

try {
    // First, let's verify the actual structure
    echo "=== VERIFYING TABLE STRUCTURES ===\n";
    
    echo "lab_orders table structure:\n";
    $stmt = $pdo->query('DESCRIBE lab_orders');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$row['Field']} ({$row['Type']})\n";
    }
    
    echo "\nlab_order_items table structure:\n";
    $stmt = $pdo->query('DESCRIBE lab_order_items');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$row['Field']} ({$row['Type']})\n";
    }
    
    echo "\n=== TESTING UPDATED QUERY ===\n";
    
    // Get a test patient ID
    $stmt = $pdo->query("SELECT patient_id FROM patients LIMIT 1");
    $patient_id = $stmt->fetchColumn();
    
    if (!$patient_id) {
        echo "No patients found in database\n";
        exit;
    }
    
    echo "Testing with patient ID: $patient_id\n\n";
    
    // Test the updated query
    $stmt = $pdo->prepare("
        SELECT 
            loi.item_id as lab_order_item_id,
            loi.lab_order_id,
            lo.patient_id,
            loi.test_type as test_name,
            loi.test_type,
            'blood' as sample_type,
            '' as normal_range,
            '' as result_value,
            '' as result_unit,
            loi.status as result_status,
            loi.result_date,
            loi.remarks,
            loi.created_at,
            loi.updated_at,
            lo.order_date,
            lo.status as order_status,
            lo.overall_status,
            CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) as ordered_by_doctor
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE lo.patient_id = ? 
        ORDER BY COALESCE(loi.result_date, loi.updated_at, loi.created_at) DESC 
        LIMIT 4
    ");
    
    $stmt->execute([$patient_id]);
    $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query executed successfully!\n";
    echo "Found " . count($lab_results) . " lab results\n\n";
    
    if (count($lab_results) > 0) {
        echo "Lab results:\n";
        foreach ($lab_results as $i => $result) {
            echo "Result " . ($i + 1) . ":\n";
            foreach ($result as $key => $value) {
                echo "  $key: " . ($value ?? 'NULL') . "\n";
            }
            echo "\n";
        }
    } else {
        echo "No results found. Let's create some test data...\n\n";
        
        // Check if there are any lab orders for this patient
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
        $order_count = $stmt->fetchColumn();
        echo "Existing lab orders for patient $patient_id: $order_count\n";
        
        if ($order_count == 0) {
            echo "Creating test lab data...\n";
            
            try {
                $pdo->beginTransaction();
                
                // Create lab order
                $stmt = $pdo->prepare("
                    INSERT INTO lab_orders 
                    (patient_id, order_date, status, overall_status, ordered_by_employee_id, created_at, updated_at) 
                    VALUES (?, NOW(), 'completed', 'completed', 1, NOW(), NOW())
                ");
                $stmt->execute([$patient_id]);
                $lab_order_id = $pdo->lastInsertId();
                echo "✓ Created lab order ID: $lab_order_id\n";
                
                // Create lab order items
                $test_items = [
                    'Complete Blood Count (CBC)',
                    'Fasting Blood Sugar',
                    'Urinalysis',
                    'Total Cholesterol',
                    'Creatinine'
                ];
                
                foreach ($test_items as $test) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lab_order_items 
                        (lab_order_id, test_type, status, result_date, created_at, updated_at) 
                        VALUES (?, ?, 'completed', NOW(), NOW(), NOW())
                    ");
                    $stmt->execute([$lab_order_id, $test]);
                    echo "✓ Created lab item: $test\n";
                }
                
                $pdo->commit();
                echo "\n✓ Test data created successfully!\n\n";
                
                // Re-test the query
                echo "Re-testing query with new data:\n";
                $stmt = $pdo->prepare("
                    SELECT 
                        loi.item_id as lab_order_item_id,
                        loi.lab_order_id,
                        lo.patient_id,
                        loi.test_type as test_name,
                        loi.test_type,
                        'blood' as sample_type,
                        '' as normal_range,
                        '' as result_value,
                        '' as result_unit,
                        loi.status as result_status,
                        loi.result_date,
                        loi.remarks,
                        loi.created_at,
                        loi.updated_at,
                        lo.order_date,
                        lo.status as order_status,
                        lo.overall_status,
                        CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) as ordered_by_doctor
                    FROM lab_order_items loi
                    INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                    LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
                    WHERE lo.patient_id = ? 
                    ORDER BY COALESCE(loi.result_date, loi.updated_at, loi.created_at) DESC 
                    LIMIT 4
                ");
                
                $stmt->execute([$patient_id]);
                $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "Found " . count($lab_results) . " results after creating test data\n\n";
                
                foreach ($lab_results as $i => $result) {
                    echo "Result " . ($i + 1) . ":\n";
                    echo "  Test: {$result['test_name']}\n";
                    echo "  Status: {$result['result_status']}\n";
                    echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n\n";
                }
                
            } catch (Exception $e) {
                $pdo->rollback();
                echo "✗ Error creating test data: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n=== TESTING JOIN RELATIONSHIP ===\n";
    
    // Test the JOIN relationship specifically
    $stmt = $pdo->query("
        SELECT COUNT(*) as total_items,
               COUNT(DISTINCT lo.patient_id) as patients_with_items
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
    ");
    $join_test = $stmt->fetch();
    
    echo "JOIN test results:\n";
    echo "  Total lab items with valid orders: {$join_test['total_items']}\n";
    echo "  Patients with lab items: {$join_test['patients_with_items']}\n";
    
    if ($join_test['total_items'] > 0) {
        echo "✓ JOIN relationship is working correctly\n";
    } else {
        echo "✗ No data found - JOIN relationship may have issues\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "</pre>\n";

echo "<h3>Expected Profile Display</h3>\n";
if (!empty($lab_results)) {
    echo "<p style='color: green;'>✓ Lab results will now display in the patient profile</p>\n";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>\n";
    echo "<thead><tr><th>Test Date</th><th>Test Name</th><th>Sample Type</th><th>Result</th><th>Status</th><th>Action</th></tr></thead>\n";
    echo "<tbody>\n";
    
    foreach ($lab_results as $result) {
        echo "<tr>\n";
        echo "<td>" . (htmlspecialchars($result['result_date'] ? date('M j, Y', strtotime($result['result_date'])) : 'Date not available')) . "</td>\n";
        echo "<td>" . htmlspecialchars($result['test_name'] ?? 'Unknown Test') . "</td>\n";
        echo "<td>" . htmlspecialchars($result['sample_type'] ?? 'Not specified') . "</td>\n";
        echo "<td>" . htmlspecialchars($result['result_value'] ?: 'Result pending') . "</td>\n";
        echo "<td>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $result['result_status'] ?? 'pending'))) . "</td>\n";
        echo "<td>";
        if (!empty($result['result_value'])) {
            echo "<button>View Result</button>";
        } else {
            echo "Pending";
        }
        echo "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</tbody></table>\n";
} else {
    echo "<p style='color: orange;'>No lab results available - empty state will be shown</p>\n";
}
?>