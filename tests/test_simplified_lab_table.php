<?php
require_once 'config/db.php';

echo "<h2>Simplified Lab Results Table Test</h2>\n";
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
    
    // Test the simplified query
    $stmt = $pdo->prepare("
        SELECT 
            loi.item_id as lab_order_item_id,
            loi.test_type as test_name,
            loi.result_file,
            loi.result_date,
            loi.created_at,
            lo.order_date
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        WHERE lo.patient_id = ? 
        ORDER BY COALESCE(loi.result_date, loi.created_at) DESC 
        LIMIT 4
    ");
    
    $stmt->execute([$patient_id]);
    $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($lab_results) . " lab results\n\n";
    
    if (count($lab_results) > 0) {
        echo "Lab results data:\n";
        foreach ($lab_results as $i => $result) {
            echo "Result " . ($i + 1) . ":\n";
            echo "  Test Name: {$result['test_name']}\n";
            echo "  Result File: " . ($result['result_file'] ?? 'NULL (Pending)') . "\n";
            echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n\n";
        }
    } else {
        echo "No results found. Creating simplified test data...\n\n";
        
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
                
                // Create simplified lab order items
                $test_items = [
                    ['Complete Blood Count (CBC)', 'cbc_result_20251023.pdf'],
                    ['Fasting Blood Sugar', 'fbs_result_20251023.pdf'],
                    ['Urinalysis', null], // Pending result
                    ['Total Cholesterol', null] // Pending result
                ];
                
                foreach ($test_items as $test) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lab_order_items 
                        (lab_order_id, test_type, result_file, result_date, created_at, updated_at) 
                        VALUES (?, ?, ?, NOW(), NOW(), NOW())
                    ");
                    $stmt->execute([$lab_order_id, $test[0], $test[1]]);
                    echo "✓ Created lab item: {$test[0]} - File: " . ($test[1] ?? 'Pending') . "\n";
                }
                
                $pdo->commit();
                echo "\n✓ Simplified test data created successfully!\n\n";
                
                // Re-test the query
                $stmt = $pdo->prepare("
                    SELECT 
                        loi.item_id as lab_order_item_id,
                        loi.test_type as test_name,
                        loi.result_file,
                        loi.result_date,
                        loi.created_at,
                        lo.order_date
                    FROM lab_order_items loi
                    INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                    WHERE lo.patient_id = ? 
                    ORDER BY COALESCE(loi.result_date, loi.created_at) DESC 
                    LIMIT 4
                ");
                
                $stmt->execute([$patient_id]);
                $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "Re-tested query results:\n";
                foreach ($lab_results as $i => $result) {
                    echo "Result " . ($i + 1) . ":\n";
                    echo "  Test Name: {$result['test_name']}\n";
                    echo "  Result File: " . ($result['result_file'] ?? 'Pending') . "\n";
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

// Show what the simplified table will look like
if (!empty($lab_results)) {
    echo "<h3>Simplified Lab Results Table (4 Columns Only)</h3>\n";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>\n";
    echo "<thead><tr><th>Test Date</th><th>Test Name</th><th>Result</th><th>Action</th></tr></thead>\n";
    echo "<tbody>\n";
    
    foreach ($lab_results as $result) {
        echo "<tr>\n";
        
        // Test Date
        echo "<td>" . (htmlspecialchars($result['result_date'] ? date('M j, Y', strtotime($result['result_date'])) : 'Date not available')) . "</td>\n";
        
        // Test Name
        echo "<td>" . htmlspecialchars($result['test_name'] ?? 'Unknown Test') . "</td>\n";
        
        // Result (Uploaded or Pending)
        echo "<td>";
        if (!empty($result['result_file'])) {
            echo "✅ <strong style='color: green;'>Uploaded</strong>";
        } else {
            echo "⏳ <em style='color: orange;'>Pending</em>";
        }
        echo "</td>\n";
        
        // Action (View button)
        echo "<td>";
        echo "<button style='background: #007bff; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer;' onclick=\"window.location.href='../laboratory/laboratory.php'\">";
        echo "👁️ View";
        echo "</button>";
        echo "</td>\n";
        
        echo "</tr>\n";
    }
    
    echo "</tbody></table>\n";
    
    echo "<p><strong>Note:</strong> All 'View' buttons redirect to <code>pages/patient/laboratory/laboratory.php</code></p>\n";
} else {
    echo "<p style='color: orange;'>No lab results available</p>\n";
}
?>