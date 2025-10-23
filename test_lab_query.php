<?php
require_once 'config/db.php';

echo "<h2>Lab Results Query Testing</h2>\n";
echo "<pre>\n";

// Test current query
echo "Testing current query from profile.php:\n";
echo str_repeat('=', 60) . "\n";

$test_patient_id = 1; // Using patient ID 1 as test

try {
    $stmt = $pdo->prepare("
        SELECT 
            loi.id as lab_order_item_id,
            loi.lab_order_id,
            loi.patient_id,
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
            e.first_name as ordered_by_first_name,
            e.last_name as ordered_by_last_name
        FROM lab_order_items loi
        LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE loi.patient_id = ? 
        ORDER BY loi.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$test_patient_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Current query results for patient ID $test_patient_id:\n";
    echo "Found " . count($results) . " records\n\n";
    
    if (count($results) > 0) {
        foreach ($results as $i => $row) {
            echo "Record " . ($i + 1) . ":\n";
            foreach ($row as $key => $value) {
                echo "  $key: " . ($value ?? 'NULL') . "\n";
            }
            echo "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error with current query: " . $e->getMessage() . "\n";
}

echo "\n\nTesting corrected query (patient_id from lab_orders):\n";
echo str_repeat('=', 60) . "\n";

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
            e.first_name as ordered_by_first_name,
            e.last_name as ordered_by_last_name
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE lo.patient_id = ? 
        ORDER BY loi.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$test_patient_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Corrected query results for patient ID $test_patient_id:\n";
    echo "Found " . count($results) . " records\n\n";
    
    if (count($results) > 0) {
        foreach ($results as $i => $row) {
            echo "Record " . ($i + 1) . ":\n";
            foreach ($row as $key => $value) {
                echo "  $key: " . ($value ?? 'NULL') . "\n";
            }
            echo "\n";
        }
    } else {
        echo "No records found. Let's check what patient IDs exist:\n";
        
        $stmt = $pdo->query("SELECT DISTINCT patient_id FROM lab_orders ORDER BY patient_id");
        $patient_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Available patient IDs in lab_orders: " . implode(', ', $patient_ids) . "\n";
        
        if (count($patient_ids) > 0) {
            // Test with first available patient ID
            $test_patient = $patient_ids[0];
            echo "\nTesting with patient ID $test_patient:\n";
            
            $stmt = $pdo->prepare("
                SELECT 
                    loi.id as lab_order_item_id,
                    lo.patient_id,
                    loi.test_name,
                    loi.result_value,
                    loi.result_status,
                    loi.created_at
                FROM lab_order_items loi
                INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
                WHERE lo.patient_id = ? 
                ORDER BY loi.created_at DESC 
                LIMIT 3
            ");
            $stmt->execute([$test_patient]);
            $test_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($test_results as $row) {
                echo "Patient: {$row['patient_id']}, Test: {$row['test_name']}, Result: {$row['result_value']}, Status: {$row['result_status']}\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error with corrected query: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
?>