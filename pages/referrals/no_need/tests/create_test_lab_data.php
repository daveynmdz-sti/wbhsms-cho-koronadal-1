<?php
require_once 'config/db.php';

echo "<h2>Lab Data Creation and Verification</h2>\n";
echo "<pre>\n";

try {
    // First, let's check if we have any patients
    $stmt = $pdo->query("SELECT patient_id, first_name, last_name FROM patients ORDER BY patient_id LIMIT 5");
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== AVAILABLE PATIENTS ===\n";
    if (count($patients) > 0) {
        foreach ($patients as $patient) {
            echo "Patient ID: {$patient['patient_id']} - {$patient['first_name']} {$patient['last_name']}\n";
        }
        
        // Use first patient for testing
        $test_patient_id = $patients[0]['patient_id'];
        echo "\nUsing Patient ID $test_patient_id for testing...\n\n";
        
        // Check if this patient has any lab orders
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
        $stmt->execute([$test_patient_id]);
        $existing_orders = $stmt->fetchColumn();
        
        echo "=== EXISTING LAB DATA FOR PATIENT $test_patient_id ===\n";
        echo "Existing lab orders: $existing_orders\n";
        
        if ($existing_orders == 0) {
            echo "No existing lab data. Creating test data...\n\n";
            
            // Create test lab data
            $pdo->beginTransaction();
            
            try {
                // Create lab order
                $stmt = $pdo->prepare("
                    INSERT INTO lab_orders 
                    (patient_id, order_date, status, overall_status, ordered_by_employee_id, created_at) 
                    VALUES (?, NOW(), 'completed', 'completed', 1, NOW())
                ");
                $stmt->execute([$test_patient_id]);
                $lab_order_id = $pdo->lastInsertId();
                
                echo "Created lab order ID: $lab_order_id\n";
                
                // Create lab order items
                $test_lab_items = [
                    [
                        'test_name' => 'Complete Blood Count (CBC)',
                        'test_type' => 'Hematology',
                        'sample_type' => 'blood',
                        'result_value' => '12.5',
                        'result_unit' => 'g/dL',
                        'normal_range' => '12.0-15.5 g/dL',
                        'result_status' => 'normal'
                    ],
                    [
                        'test_name' => 'Fasting Blood Sugar',
                        'test_type' => 'Chemistry',
                        'sample_type' => 'blood',
                        'result_value' => '95',
                        'result_unit' => 'mg/dL',
                        'normal_range' => '70-100 mg/dL',
                        'result_status' => 'normal'
                    ],
                    [
                        'test_name' => 'Urinalysis',
                        'test_type' => 'Microscopy',
                        'sample_type' => 'urine',
                        'result_value' => 'Normal',
                        'result_unit' => '',
                        'normal_range' => 'Normal findings',
                        'result_status' => 'normal'
                    ],
                    [
                        'test_name' => 'Lipid Profile',
                        'test_type' => 'Chemistry',
                        'sample_type' => 'blood',
                        'result_value' => '180',
                        'result_unit' => 'mg/dL',
                        'normal_range' => '<200 mg/dL',
                        'result_status' => 'normal'
                    ]
                ];
                
                foreach ($test_lab_items as $i => $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lab_order_items 
                        (lab_order_id, test_name, test_type, sample_type, result_value, result_unit, 
                         normal_range, result_status, result_date, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
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
                    
                    $item_id = $pdo->lastInsertId();
                    echo "Created lab item ID: $item_id - {$item['test_name']}\n";
                }
                
                $pdo->commit();
                echo "\n✓ Successfully created test lab data!\n";
                
            } catch (Exception $e) {
                $pdo->rollback();
                echo "✗ Error creating test data: " . $e->getMessage() . "\n";
            }
        }
        
        // Now test the query
        echo "\n=== TESTING UPDATED QUERY ===\n";
        
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
        
        $stmt->execute([$test_patient_id]);
        $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Query results for patient $test_patient_id:\n";
        echo "Found " . count($lab_results) . " lab results\n\n";
        
        if (count($lab_results) > 0) {
            echo "Lab results that will be displayed:\n";
            foreach ($lab_results as $i => $result) {
                echo "Result " . ($i + 1) . ":\n";
                echo "  ID: {$result['lab_order_item_id']}\n";
                echo "  Test: {$result['test_name']}\n";
                echo "  Type: {$result['test_type']}\n";
                echo "  Sample: {$result['sample_type']}\n";
                echo "  Result: {$result['result_value']} {$result['result_unit']}\n";
                echo "  Range: {$result['normal_range']}\n";
                echo "  Status: {$result['result_status']}\n";
                echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n";
                echo "\n";
            }
        } else {
            echo "No results found - check query or data\n";
        }
        
    } else {
        echo "No patients found in database\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>