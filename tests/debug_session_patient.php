<?php
session_start();

echo "<h2>Current Session Debug</h2>\n";
echo "<pre>\n";

echo "=== SESSION DATA ===\n";
echo "Session ID: " . session_id() . "\n";
echo "All session data:\n";
print_r($_SESSION);

echo "\n=== PATIENT SESSION CHECK ===\n";
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

if (function_exists('is_patient_logged_in')) {
    echo "Patient logged in: " . (is_patient_logged_in() ? 'YES' : 'NO') . "\n";
    
    if (is_patient_logged_in()) {
        echo "Patient ID: " . get_patient_session('patient_id') . "\n";
        echo "Patient Name: " . get_patient_session('first_name') . " " . get_patient_session('last_name') . "\n";
    }
}

echo "\n=== DATABASE TEST ===\n";
require_once $root_path . '/config/db.php';

// Get the patient ID for testing
$patient_id = $_SESSION['patient_id'] ?? null;

if (!$patient_id) {
    echo "No patient ID in session, getting first patient from database...\n";
    $stmt = $pdo->query("SELECT patient_id FROM patients LIMIT 1");
    $patient_id = $stmt->fetchColumn();
}

echo "Testing with Patient ID: $patient_id\n\n";

if ($patient_id) {
    // Test the current lab results query
    echo "=== TESTING LAB RESULTS QUERY ===\n";
    
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
        
        echo "Found " . count($lab_results) . " lab results\n";
        
        if (count($lab_results) > 0) {
            echo "✓ Query works! Results found:\n";
            foreach ($lab_results as $i => $result) {
                echo "\nResult " . ($i + 1) . ":\n";
                echo "  Test: {$result['test_name']}\n";
                echo "  Result: {$result['result_value']} {$result['result_unit']}\n";
                echo "  Status: {$result['result_status']}\n";
            }
        } else {
            echo "✗ No results found\n";
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE patient_id = ?");
            $stmt->execute([$patient_id]);
            $patient = $stmt->fetch();
            
            if ($patient) {
                echo "Patient exists: {$patient['first_name']} {$patient['last_name']}\n";
                
                // Check lab orders
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
                $stmt->execute([$patient_id]);
                $orders_count = $stmt->fetchColumn();
                echo "Lab orders for this patient: $orders_count\n";
                
                if ($orders_count == 0) {
                    echo "No lab orders found - this is why no results show\n";
                }
            } else {
                echo "Patient does not exist in database\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Query error: " . $e->getMessage() . "\n";
    }
}

echo "</pre>\n";
?>