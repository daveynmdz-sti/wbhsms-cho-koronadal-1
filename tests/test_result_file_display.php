<?php
require_once 'config/db.php';

echo "<h2>Updated Lab Results Display Test</h2>\n";
echo "<pre>\n";

try {
    // Get a test patient ID
    $stmt = $pdo->query("SELECT patient_id FROM patients LIMIT 1");
    $patient_id = $stmt->fetchColumn();
    
    if (!$patient_id) {
        echo "No patients found in database\n";
        exit;
    }
    
    echo "Testing with patient ID: $patient_id\n\n";
    
    // Test the updated query with result_file
    $stmt = $pdo->prepare("
        SELECT 
            loi.item_id as lab_order_item_id,
            loi.lab_order_id,
            lo.patient_id,
            loi.test_type as test_name,
            loi.test_type,
            'blood' as sample_type,
            loi.result_file,
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
    
    echo "Found " . count($lab_results) . " lab results\n\n";
    
    if (count($lab_results) > 0) {
        echo "Lab results with result_file field:\n";
        foreach ($lab_results as $i => $result) {
            echo "Result " . ($i + 1) . ":\n";
            echo "  Test: {$result['test_name']}\n";
            echo "  Status: {$result['result_status']}\n";
            echo "  Result File: " . ($result['result_file'] ?? 'NULL') . "\n";
            echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n\n";
        }
    } else {
        echo "No results found. Creating test data with result files...\n\n";
        
        // Check if there are any lab orders for this patient
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
        $order_count = $stmt->fetchColumn();
        
        if ($order_count == 0) {
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
                
                // Create lab order items with result files
                $test_items = [
                    ['Complete Blood Count (CBC)', 'completed', 'cbc_result_20251023.pdf'],
                    ['Fasting Blood Sugar', 'completed', 'fbs_result_20251023.pdf'],
                    ['Urinalysis', 'completed', 'urinalysis_result_20251023.pdf'],
                    ['Total Cholesterol', 'pending', null]
                ];
                
                foreach ($test_items as $test) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lab_order_items 
                        (lab_order_id, test_type, status, result_file, result_date, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
                    ");
                    $stmt->execute([$lab_order_id, $test[0], $test[1], $test[2]]);
                    echo "✓ Created lab item: {$test[0]} - File: " . ($test[2] ?? 'No file') . "\n";
                }
                
                $pdo->commit();
                echo "\n✓ Test data created successfully!\n\n";
                
                // Re-test the query
                $stmt = $pdo->prepare("
                    SELECT 
                        loi.item_id as lab_order_item_id,
                        loi.lab_order_id,
                        lo.patient_id,
                        loi.test_type as test_name,
                        loi.test_type,
                        'blood' as sample_type,
                        loi.result_file,
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
                
                echo "Re-tested query results:\n";
                foreach ($lab_results as $i => $result) {
                    echo "Result " . ($i + 1) . ":\n";
                    echo "  Test: {$result['test_name']}\n";
                    echo "  Status: {$result['result_status']}\n";
                    echo "  Result File: " . ($result['result_file'] ?? 'No file') . "\n";
                    echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n\n";
                }
                
            } catch (Exception $e) {
                $pdo->rollback();
                echo "✗ Error creating test data: " . $e->getMessage() . "\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>\n";

// Show what the updated table will look like
if (!empty($lab_results)) {
    echo "<h3>Updated Lab Results Table (No Action Column)</h3>\n";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>\n";
    echo "<thead><tr><th>Test Date</th><th>Test Name</th><th>Sample Type</th><th>Result File</th><th>Status</th></tr></thead>\n";
    echo "<tbody>\n";
    
    foreach ($lab_results as $result) {
        echo "<tr>\n";
        echo "<td>" . (htmlspecialchars($result['result_date'] ? date('M j, Y', strtotime($result['result_date'])) : 'Date not available')) . "</td>\n";
        echo "<td>" . htmlspecialchars($result['test_name'] ?? 'Unknown Test') . "</td>\n";
        echo "<td>" . htmlspecialchars($result['sample_type'] ?? 'Not specified') . "</td>\n";
        echo "<td>";
        if (!empty($result['result_file'])) {
            echo "📄 <a href='#' style='color: blue;'>" . htmlspecialchars(basename($result['result_file'])) . "</a>";
        } else {
            echo "<em>Result pending</em>";
        }
        echo "</td>\n";
        echo "<td>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $result['result_status'] ?? 'pending'))) . "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</tbody></table>\n";
} else {
    echo "<p style='color: orange;'>No lab results available</p>\n";
}
?>