<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session like profile.php does
session_start();

// Include necessary files
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

echo "<h2>Profile.php Lab Results Debugging</h2>\n";
echo "<pre>\n";

// Check session and authentication
echo "=== SESSION CHECK ===\n";
echo "Session ID: " . session_id() . "\n";
echo "Patient logged in: " . (is_patient_logged_in() ? 'YES' : 'NO') . "\n";

if (is_patient_logged_in()) {
    $patient_id = get_patient_session('patient_id');
    $patient_name = get_patient_session('first_name') . ' ' . get_patient_session('last_name');
    echo "Patient ID: $patient_id\n";
    echo "Patient Name: $patient_name\n";
} else {
    echo "No patient session found\n";
    // For testing, let's use a test patient ID
    $stmt = $pdo->query("SELECT patient_id FROM patients LIMIT 1");
    $patient_id = $stmt->fetchColumn();
    echo "Using test patient ID: $patient_id\n";
}

echo "\n=== DATABASE CONNECTION ===\n";
try {
    $pdo->query('SELECT 1');
    echo "Database connection: OK\n";
} catch (Exception $e) {
    echo "Database connection error: " . $e->getMessage() . "\n";
}

echo "\n=== LAB RESULTS QUERY TEST ===\n";

if ($patient_id) {
    try {
        // Use the exact same query as in profile.php
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
        
        echo "Executing query for patient ID: $patient_id\n";
        $stmt->execute([$patient_id]);
        $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Query executed successfully\n";
        echo "Number of results: " . count($lab_results) . "\n\n";
        
        if (count($lab_results) > 0) {
            echo "=== FOUND LAB RESULTS ===\n";
            foreach ($lab_results as $i => $result) {
                echo "Result " . ($i + 1) . ":\n";
                foreach ($result as $key => $value) {
                    echo "  $key: " . ($value ?? 'NULL') . "\n";
                }
                echo "\n";
            }
        } else {
            echo "=== NO RESULTS FOUND ===\n";
            echo "Checking possible causes...\n\n";
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE patient_id = ?");
            $stmt->execute([$patient_id]);
            $patient_exists = $stmt->fetchColumn();
            echo "Patient $patient_id exists: " . ($patient_exists ? 'YES' : 'NO') . "\n";
            
            // Check lab_orders for this patient
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
            $stmt->execute([$patient_id]);
            $lab_orders_count = $stmt->fetchColumn();
            echo "Lab orders for patient $patient_id: $lab_orders_count\n";
            
            // Check lab_order_items linked to this patient's orders
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM lab_order_items loi 
                INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id 
                WHERE lo.patient_id = ?
            ");
            $stmt->execute([$patient_id]);
            $lab_items_count = $stmt->fetchColumn();
            echo "Lab order items for patient $patient_id: $lab_items_count\n";
            
            // Show some existing data
            echo "\n=== EXISTING DATA SAMPLE ===\n";
            $stmt = $pdo->query("
                SELECT lo.patient_id, COUNT(loi.id) as items 
                FROM lab_orders lo 
                LEFT JOIN lab_order_items loi ON lo.id = loi.lab_order_id 
                GROUP BY lo.patient_id 
                ORDER BY items DESC 
                LIMIT 5
            ");
            echo "Patients with lab data:\n";
            while ($row = $stmt->fetch()) {
                echo "  Patient {$row['patient_id']}: {$row['items']} items\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Query error: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
} else {
    echo "No patient ID available for testing\n";
}

echo "\n=== RECOMMENDATIONS ===\n";
if (empty($lab_results)) {
    echo "1. The query is correct but no data found for patient $patient_id\n";
    echo "2. Either create test data OR test with a patient who has lab results\n";
    echo "3. Check that lab_order_items.lab_order_id correctly references lab_orders.id\n";
    echo "4. Verify that lab_orders.patient_id matches the patient session\n";
} else {
    echo "Lab results found! The issue may be in the HTML rendering or CSS.\n";
}

echo "</pre>\n";

// If no results found, offer to create test data
if ($patient_id && empty($lab_results)) {
    echo "<h3>Create Test Data</h3>\n";
    echo "<form method='post'>\n";
    echo "<input type='hidden' name='patient_id' value='$patient_id'>\n";
    echo "<button type='submit' name='create_test_data'>Create Test Lab Data for Patient $patient_id</button>\n";
    echo "</form>\n";
    
    if (isset($_POST['create_test_data'])) {
        echo "<pre>\n";
        try {
            $pdo->beginTransaction();
            
            // Create lab order
            $stmt = $pdo->prepare("
                INSERT INTO lab_orders (patient_id, order_date, status, overall_status, ordered_by_employee_id) 
                VALUES (?, NOW(), 'completed', 'completed', 1)
            ");
            $stmt->execute([$patient_id]);
            $lab_order_id = $pdo->lastInsertId();
            
            // Create lab order items
            $tests = [
                ['CBC', 'Hematology', 'Blood', '12.5', 'g/dL', '12.0-15.5', 'normal'],
                ['Glucose', 'Chemistry', 'Blood', '95', 'mg/dL', '70-100', 'normal'],
                ['Creatinine', 'Chemistry', 'Blood', '1.1', 'mg/dL', '0.7-1.3', 'normal']
            ];
            
            foreach ($tests as $test) {
                $stmt = $pdo->prepare("
                    INSERT INTO lab_order_items 
                    (lab_order_id, test_name, test_type, sample_type, result_value, result_unit, normal_range, result_status, result_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$lab_order_id, $test[0], $test[1], $test[2], $test[3], $test[4], $test[5], $test[6]]);
            }
            
            $pdo->commit();
            echo "Successfully created test lab data!\n";
            echo "Created lab order ID: $lab_order_id\n";
            echo "Created " . count($tests) . " lab test results\n";
            echo "Refresh page to see the results.\n";
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo "Error creating test data: " . $e->getMessage() . "\n";
        }
        echo "</pre>\n";
    }
}
?>