<?php
// Start session to get patient info
session_start();
require_once '../../../config/db.php';

// Get patient ID from session or use test patient
$patient_id = $_SESSION['patient_id'] ?? 1;

echo "<h2>Lab Results Debug for Patient ID: $patient_id</h2>\n";
echo "<pre>\n";

// Test the corrected query
try {
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
            CONCAT(e.first_name, ' ', e.last_name) as ordered_by_doctor
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE lo.patient_id = ? 
        ORDER BY COALESCE(loi.result_date, loi.updated_at, loi.created_at) DESC 
        LIMIT 4
    ");
    $stmt->execute([$patient_id]);
    $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query Results:\n";
    echo "Found " . count($lab_results) . " lab results for patient ID $patient_id\n\n";
    
    if (count($lab_results) > 0) {
        foreach ($lab_results as $i => $result) {
            echo "Lab Result " . ($i + 1) . ":\n";
            echo "  ID: " . $result['lab_order_item_id'] . "\n";
            echo "  Test Name: " . ($result['test_name'] ?? 'N/A') . "\n";
            echo "  Test Type: " . ($result['test_type'] ?? 'N/A') . "\n";
            echo "  Sample Type: " . ($result['sample_type'] ?? 'N/A') . "\n";
            echo "  Result Value: " . ($result['result_value'] ?? 'Pending') . "\n";
            echo "  Result Unit: " . ($result['result_unit'] ?? 'N/A') . "\n";
            echo "  Result Status: " . ($result['result_status'] ?? 'N/A') . "\n";
            echo "  Result Date: " . ($result['result_date'] ?? 'N/A') . "\n";
            echo "  Order Date: " . ($result['order_date'] ?? 'N/A') . "\n";
            echo "  Ordered By: " . ($result['ordered_by_doctor'] ?? 'N/A') . "\n";
            echo "  Normal Range: " . ($result['normal_range'] ?? 'N/A') . "\n";
            echo "\n";
        }
    } else {
        echo "No lab results found for patient ID $patient_id\n\n";
        
        // Check if there are any lab orders for this patient
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
        $order_count = $stmt->fetchColumn();
        echo "Lab orders for this patient: $order_count\n";
        
        // Check total lab order items
        $stmt = $pdo->query("SELECT COUNT(*) FROM lab_order_items");
        $total_items = $stmt->fetchColumn();
        echo "Total lab order items in database: $total_items\n";
        
        // List all patient IDs with lab orders
        $stmt = $pdo->query("
            SELECT DISTINCT lo.patient_id, COUNT(loi.id) as item_count 
            FROM lab_orders lo 
            LEFT JOIN lab_order_items loi ON lo.id = loi.lab_order_id 
            GROUP BY lo.patient_id 
            ORDER BY lo.patient_id
        ");
        $patients_with_labs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nPatients with lab data:\n";
        foreach ($patients_with_labs as $patient) {
            echo "  Patient ID {$patient['patient_id']}: {$patient['item_count']} lab items\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "</pre>\n";

// Also create some test data if none exists
if (empty($lab_results)) {
    echo "<h3>Creating Test Lab Data</h3>\n";
    echo "<pre>\n";
    
    try {
        // Check if we can create test data
        $pdo->beginTransaction();
        
        // Insert a test lab order if none exists for this patient
        $stmt = $pdo->prepare("
            INSERT INTO lab_orders (patient_id, order_date, status, overall_status, ordered_by_employee_id) 
            VALUES (?, NOW(), 'completed', 'completed', 1)
            ON DUPLICATE KEY UPDATE id=id
        ");
        $stmt->execute([$patient_id]);
        $lab_order_id = $pdo->lastInsertId();
        
        if ($lab_order_id) {
            // Insert test lab order items
            $test_items = [
                [
                    'test_name' => 'Complete Blood Count (CBC)',
                    'test_type' => 'Hematology',
                    'sample_type' => 'Blood',
                    'result_value' => '12.5',
                    'result_unit' => 'g/dL',
                    'normal_range' => '12.0-15.5 g/dL',
                    'result_status' => 'normal'
                ],
                [
                    'test_name' => 'Blood Glucose',
                    'test_type' => 'Chemistry',
                    'sample_type' => 'Blood',
                    'result_value' => '95',
                    'result_unit' => 'mg/dL',
                    'normal_range' => '70-100 mg/dL',
                    'result_status' => 'normal'
                ],
                [
                    'test_name' => 'Urinalysis',
                    'test_type' => 'Microscopy',
                    'sample_type' => 'Urine',
                    'result_value' => 'Normal',
                    'result_unit' => '',
                    'normal_range' => 'Normal',
                    'result_status' => 'normal'
                ]
            ];
            
            foreach ($test_items as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO lab_order_items 
                    (lab_order_id, test_name, test_type, sample_type, result_value, result_unit, normal_range, result_status, result_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $lab_order_id,
                    $item['test_name'],
                    $item['test_type'],
                    $item['sample_type'],
                    $item['result_value'],
                    $item['result_unit'],
                    $item['normal_range'],
                    $item['result_status']
                ]);
            }
            
            $pdo->commit();
            echo "Created test lab data for patient ID $patient_id\n";
            echo "Lab Order ID: $lab_order_id\n";
            echo "Created " . count($test_items) . " test lab items\n";
            
        } else {
            $pdo->rollback();
            echo "Could not create lab order\n";
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        echo "Error creating test data: " . $e->getMessage() . "\n";
    }
    
    echo "</pre>\n";
}
?>