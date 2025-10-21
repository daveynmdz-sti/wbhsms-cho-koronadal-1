<?php
// Production Lab API Diagnostic Tool
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Production Lab API Diagnostic</h1>";

// Check session
echo "<h2>1. Session Information</h2>";
echo "<p><strong>Patient ID:</strong> " . ($_SESSION['patient_id'] ?? 'NOT SET') . "</p>";
echo "<p><strong>Session Data:</strong></p>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

// Check database connection
echo "<h2>2. Database Connection</h2>";
if ($conn) {
    echo "<p>✅ Database connection successful</p>";
    echo "<p><strong>Connection Info:</strong> " . $conn->get_server_info() . "</p>";
} else {
    echo "<p>❌ Database connection failed</p>";
    exit();
}

// If we have a patient_id, let's test everything
if (isset($_SESSION['patient_id'])) {
    $patient_session_id = $_SESSION['patient_id'];
    echo "<p><strong>Testing with Patient Session ID:</strong> " . $patient_session_id . " (" . (is_numeric($patient_session_id) ? "numeric" : "username") . ")</p>";
    
    // Test 1: Check if patient exists
    echo "<h2>3. Patient Verification</h2>";
    try {
        if (is_numeric($patient_session_id)) {
            // New format: patient_id is numeric
            $stmt = $conn->prepare("SELECT patient_id, first_name, last_name, username FROM patients WHERE patient_id = ?");
            $stmt->bind_param("i", $patient_session_id);
        } else {
            // Old format: patient_id contains username
            $stmt = $conn->prepare("SELECT patient_id, first_name, last_name, username FROM patients WHERE username = ?");
            $stmt->bind_param("s", $patient_session_id);
        }
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        
        if ($patient) {
            echo "<p>✅ Patient found in database</p>";
            echo "<ul>";
            echo "<li><strong>ID:</strong> " . $patient['patient_id'] . "</li>";
            echo "<li><strong>Name:</strong> " . $patient['first_name'] . " " . $patient['last_name'] . "</li>";
            echo "<li><strong>Username:</strong> " . $patient['username'] . "</li>";
            echo "</ul>";
        } else {
            echo "<p>❌ Patient NOT found in database with username: " . $patient_username . "</p>";
            echo "<p><strong>This is likely the problem!</strong></p>";
        }
        $stmt->close();
        
        // Get the numeric patient_id for further tests
        $patient_id = $patient ? $patient['patient_id'] : null;
    } catch (Exception $e) {
        echo "<p>❌ Error checking patient: " . $e->getMessage() . "</p>";
    }
    
    // Test 2: Check lab_orders table structure
    echo "<h2>4. Database Schema Check</h2>";
    try {
        $result = $conn->query("SHOW COLUMNS FROM lab_orders");
        echo "<h3>lab_orders table columns:</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p>❌ Error checking lab_orders schema: " . $e->getMessage() . "</p>";
    }
    
    // Test 3: Check lab_order_items table structure
    try {
        $result = $conn->query("SHOW COLUMNS FROM lab_order_items");
        echo "<h3>lab_order_items table columns:</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p>❌ Error checking lab_order_items schema: " . $e->getMessage() . "</p>";
    }
    
    // Test 4: Query all lab orders to see what exists
    echo "<h2>5. All Lab Orders in System</h2>";
    try {
        $result = $conn->query("
            SELECT 
                lo.lab_order_id, 
                lo.patient_id, 
                lo.order_date, 
                lo.status,
                p.first_name, 
                p.last_name, 
                p.username
            FROM lab_orders lo 
            LEFT JOIN patients p ON lo.patient_id = p.patient_id
            ORDER BY lo.order_date DESC 
            LIMIT 10
        ");
        
        if ($result && $result->num_rows > 0) {
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>Lab Order ID</th><th>Patient ID</th><th>Patient Name</th><th>Username</th><th>Order Date</th><th>Status</th><th>Match Current Patient?</th></tr>";
            
            while ($row = $result->fetch_assoc()) {
                $isMatch = ($row['patient_id'] == $patient_id) ? '✅ YES' : '❌ NO';
                echo "<tr>";
                echo "<td>" . $row['lab_order_id'] . "</td>";
                echo "<td>" . $row['patient_id'] . "</td>";
                echo "<td>" . ($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td>" . $row['username'] . "</td>";
                echo "<td>" . $row['order_date'] . "</td>";
                echo "<td>" . $row['status'] . "</td>";
                echo "<td><strong>" . $isMatch . "</strong></td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ No lab orders found in the system!</p>";
        }
    } catch (Exception $e) {
        echo "<p>❌ Error querying lab orders: " . $e->getMessage() . "</p>";
    }
    
    // Test 5: Test the exact query from lab_test.php
    echo "<h2>6. Test Lab Orders Query for Current Patient</h2>";
    try {
        $stmt = $conn->prepare("
            SELECT lo.*,
                   e.first_name as doctor_first_name, e.last_name as doctor_last_name,
                   CASE 
                       WHEN lo.appointment_id IS NOT NULL THEN a.scheduled_date
                       ELSE NULL
                   END as scheduled_date,
                   CASE 
                       WHEN lo.appointment_id IS NOT NULL THEN a.scheduled_time
                       ELSE NULL
                   END as scheduled_time,
                   -- Source information for standalone support
                   CASE 
                       WHEN lo.appointment_id IS NOT NULL THEN 'appointment'
                       WHEN lo.consultation_id IS NOT NULL THEN 'consultation'
                       ELSE 'standalone'
                   END as order_source,
                   -- Get test types from lab_order_items
                   GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types,
                   COUNT(loi.item_id) as test_count
            FROM lab_orders lo
            LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
            LEFT JOIN appointments a ON lo.appointment_id = a.appointment_id
            LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
            WHERE lo.patient_id = ? AND lo.status IN ('pending', 'in_progress', 'cancelled')
            GROUP BY lo.lab_order_id
            ORDER BY lo.order_date DESC
            LIMIT 10
        ");
        
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $lab_orders = $result->fetch_all(MYSQLI_ASSOC);
        
        echo "<p><strong>Query executed successfully!</strong></p>";
        echo "<p><strong>Number of lab orders found:</strong> " . count($lab_orders) . "</p>";
        
        if (count($lab_orders) > 0) {
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>Lab Order ID</th><th>Order Date</th><th>Status</th><th>Test Types</th><th>Test Count</th><th>Doctor</th></tr>";
            
            foreach ($lab_orders as $order) {
                echo "<tr>";
                echo "<td>" . $order['lab_order_id'] . "</td>";
                echo "<td>" . $order['order_date'] . "</td>";
                echo "<td>" . $order['status'] . "</td>";
                echo "<td>" . ($order['test_types'] ?: 'No tests') . "</td>";
                echo "<td>" . ($order['test_count'] ?: '0') . "</td>";
                echo "<td>" . ($order['doctor_first_name'] . ' ' . $order['doctor_last_name']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ No pending/in-progress/cancelled lab orders found for patient ID: " . $patient_id . "</p>";
        }
        $stmt->close();
    } catch (Exception $e) {
        echo "<p>❌ Error in lab orders query: " . $e->getMessage() . "</p>";
    }
    
    // Test 6: Test completed lab results query
    echo "<h2>7. Test Lab Results Query for Current Patient</h2>";
    try {
        $stmt = $conn->prepare("
            SELECT lo.*,
                   e.first_name as doctor_first_name, e.last_name as doctor_last_name,
                   CASE 
                       WHEN lo.appointment_id IS NOT NULL THEN a.scheduled_date
                       ELSE NULL
                   END as scheduled_date,
                   CASE 
                       WHEN lo.appointment_id IS NOT NULL THEN a.scheduled_time
                       ELSE NULL
                   END as scheduled_time,
                   -- Source information for standalone support
                   CASE 
                       WHEN lo.appointment_id IS NOT NULL THEN 'appointment'
                       WHEN lo.consultation_id IS NOT NULL THEN 'consultation'
                       ELSE 'standalone'
                   END as order_source,
                   -- Get test types and result info from lab_order_items
                   GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types,
                   COUNT(loi.item_id) as test_count,
                   MAX(loi.result_date) as latest_result_date,
                   COUNT(CASE WHEN loi.result_file IS NOT NULL THEN 1 END) as files_count
            FROM lab_orders lo
            LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
            LEFT JOIN appointments a ON lo.appointment_id = a.appointment_id
            LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
            WHERE lo.patient_id = ? AND lo.status = 'completed'
            GROUP BY lo.lab_order_id
            ORDER BY MAX(loi.result_date) DESC, lo.order_date DESC
            LIMIT 10
        ");
        
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $lab_results = $result->fetch_all(MYSQLI_ASSOC);
        
        echo "<p><strong>Query executed successfully!</strong></p>";
        echo "<p><strong>Number of lab results found:</strong> " . count($lab_results) . "</p>";
        
        if (count($lab_results) > 0) {
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>Lab Order ID</th><th>Order Date</th><th>Status</th><th>Test Types</th><th>Test Count</th><th>Latest Result Date</th><th>Files Count</th></tr>";
            
            foreach ($lab_results as $result_row) {
                echo "<tr>";
                echo "<td>" . $result_row['lab_order_id'] . "</td>";
                echo "<td>" . $result_row['order_date'] . "</td>";
                echo "<td>" . $result_row['status'] . "</td>";
                echo "<td>" . ($result_row['test_types'] ?: 'No tests') . "</td>";
                echo "<td>" . ($result_row['test_count'] ?: '0') . "</td>";
                echo "<td>" . ($result_row['latest_result_date'] ?: 'No date') . "</td>";
                echo "<td>" . ($result_row['files_count'] ?: '0') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ No completed lab results found for patient ID: " . $patient_id . "</p>";
        }
        $stmt->close();
    } catch (Exception $e) {
        echo "<p>❌ Error in lab results query: " . $e->getMessage() . "</p>";
    }
    
    // Test 7: Test API endpoints
    echo "<h2>8. Test API Endpoints</h2>";
    if (count($lab_orders) > 0) {
        $test_order_id = $lab_orders[0]['lab_order_id'];
        echo "<p><a href='get_lab_order_details.php?id=" . $test_order_id . "' target='_blank'>🔗 Test Lab Order Details API (ID: " . $test_order_id . ")</a></p>";
    }
    
    if (count($lab_results) > 0) {
        $test_result_id = $lab_results[0]['lab_order_id'];
        echo "<p><a href='get_lab_result_details.php?id=" . $test_result_id . "' target='_blank'>🔗 Test Lab Result Details API (ID: " . $test_result_id . ")</a></p>";
    }

} else {
    echo "<p>❌ No patient_id in session - cannot test further</p>";
}

echo "<hr>";
echo "<p><a href='lab_test.php'>← Back to Lab Test Interface</a></p>";
?>