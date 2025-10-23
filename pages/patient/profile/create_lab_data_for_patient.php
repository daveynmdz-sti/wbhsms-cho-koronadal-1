<?php
session_start();
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

echo "<h2>Create Lab Data for Current Patient</h2>\n";
echo "<pre>\n";

// Get current patient
$patient_id = $_SESSION['patient_id'] ?? null;

if (!$patient_id) {
    echo "No patient logged in. Let's use first available patient...\n";
    $stmt = $pdo->query("SELECT patient_id, first_name, last_name FROM patients LIMIT 1");
    $patient = $stmt->fetch();
    if ($patient) {
        $patient_id = $patient['patient_id'];
        echo "Using Patient ID: {$patient_id} ({$patient['first_name']} {$patient['last_name']})\n";
    } else {
        die("No patients found in database\n");
    }
} else {
    // Get patient info
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();
    echo "Current Patient: {$patient_id} ({$patient['first_name']} {$patient['last_name']})\n";
}

echo "\n=== CHECKING EXISTING LAB DATA ===\n";

// Check existing lab orders
$stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
$stmt->execute([$patient_id]);
$existing_orders = $stmt->fetchColumn();

echo "Existing lab orders: $existing_orders\n";

if ($existing_orders > 0) {
    // Show existing data
    $stmt = $pdo->prepare("
        SELECT 
            loi.test_name,
            loi.result_value,
            loi.result_status,
            loi.created_at
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
        WHERE lo.patient_id = ?
        ORDER BY loi.created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $existing_items = $stmt->fetchAll();
    
    echo "Existing lab items:\n";
    foreach ($existing_items as $item) {
        echo "  - {$item['test_name']}: {$item['result_value']} ({$item['result_status']})\n";
    }
} else {
    echo "No existing lab data found. Creating test data...\n";
    
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
        
        // Create comprehensive lab order items
        $test_items = [
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
                'test_name' => 'Total Cholesterol',
                'test_type' => 'Chemistry',
                'sample_type' => 'blood',
                'result_value' => '180',
                'result_unit' => 'mg/dL',
                'normal_range' => '<200 mg/dL',
                'result_status' => 'normal'
            ],
            [
                'test_name' => 'Creatinine',
                'test_type' => 'Chemistry',
                'sample_type' => 'blood',
                'result_value' => '1.1',
                'result_unit' => 'mg/dL',
                'normal_range' => '0.7-1.3 mg/dL',
                'result_status' => 'normal'
            ]
        ];
        
        foreach ($test_items as $i => $item) {
            $stmt = $pdo->prepare("
                INSERT INTO lab_order_items 
                (lab_order_id, test_name, test_type, sample_type, result_value, 
                 result_unit, normal_range, result_status, result_date, created_at, updated_at) 
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
            echo "✓ Created lab item ID $item_id: {$item['test_name']}\n";
        }
        
        $pdo->commit();
        echo "\n✓ Successfully created " . count($test_items) . " lab test results!\n";
        
    } catch (Exception $e) {
        $pdo->rollback();
        echo "✗ Error creating lab data: " . $e->getMessage() . "\n";
    }
}

echo "\n=== TESTING PROFILE QUERY ===\n";

// Test the exact query from profile.php
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
    
    echo "Query results: " . count($lab_results) . " records\n";
    
    if (count($lab_results) > 0) {
        echo "✓ Lab results will now be displayed in profile!\n\n";
        foreach ($lab_results as $i => $result) {
            echo "Result " . ($i + 1) . ":\n";
            echo "  Test: {$result['test_name']}\n";
            echo "  Result: {$result['result_value']} {$result['result_unit']}\n";
            echo "  Status: {$result['result_status']}\n";
            echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n\n";
        }
    } else {
        echo "✗ Still no results - check query or database\n";
    }
    
} catch (Exception $e) {
    echo "Query error: " . $e->getMessage() . "\n";
}

echo "</pre>\n";

echo "<p><strong>Now refresh the patient profile page to see the lab results!</strong></p>\n";
echo "<p><a href='profile.php' target='_blank'>Open Patient Profile</a></p>\n";
?>