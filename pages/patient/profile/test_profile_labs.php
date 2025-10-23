<?php
session_start();
require_once '../../../config/db.php';

echo "<h2>Patient Profile Lab Results Test</h2>\n";
echo "<pre>\n";

// Check session
echo "Session Data:\n";
echo "Patient ID: " . ($_SESSION['patient_id'] ?? 'Not set') . "\n";
echo "Patient Name: " . ($_SESSION['patient_first_name'] ?? 'Not set') . " " . ($_SESSION['patient_last_name'] ?? 'Not set') . "\n";
echo "\n";

// If no patient in session, let's find one with lab data
$patient_id = $_SESSION['patient_id'] ?? null;

if (!$patient_id) {
    echo "No patient in session, finding a patient with lab data...\n";
    $stmt = $pdo->query("
        SELECT DISTINCT lo.patient_id 
        FROM lab_orders lo 
        INNER JOIN lab_order_items loi ON lo.id = loi.lab_order_id 
        LIMIT 1
    ");
    $patient_id = $stmt->fetchColumn();
    echo "Using patient ID: $patient_id\n\n";
}

if ($patient_id) {
    // Test the exact same query from profile.php
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
        
        echo "Lab Results Query for Patient $patient_id:\n";
        echo "Found " . count($lab_results) . " results\n\n";
        
        if (count($lab_results) > 0) {
            echo "Results will be displayed in the profile page table:\n";
            foreach ($lab_results as $i => $result) {
                echo "Row " . ($i + 1) . ":\n";
                echo "  Test Date: " . ($result['result_date'] ? date('M j, Y', strtotime($result['result_date'])) : ($result['order_date'] ? date('M j, Y', strtotime($result['order_date'])) : 'Date not available')) . "\n";
                echo "  Test Name: " . ($result['test_name'] ?? 'Unknown Test') . "\n";
                echo "  Sample Type: " . ($result['sample_type'] ? ucfirst($result['sample_type']) : 'Not specified') . "\n";
                echo "  Result: " . ($result['result_value'] ? $result['result_value'] . ' ' . ($result['result_unit'] ?? '') : 'Result pending') . "\n";
                echo "  Status: " . ucfirst(str_replace('_', ' ', $result['result_status'] ?? $result['order_status'] ?? 'pending')) . "\n";
                echo "  Action: " . ($result['result_value'] ? 'View Result button available' : 'Pending - no button') . "\n";
                echo "\n";
            }
        } else {
            echo "No lab results found - the 'No Lab Results Available' message will be shown.\n";
        }
        
    } catch (Exception $e) {
        echo "Error in query: " . $e->getMessage() . "\n";
    }
} else {
    echo "No patient ID available for testing.\n";
}

echo "</pre>\n";

// Show what the HTML table would look like
if (!empty($lab_results)) {
    echo "<h3>Preview of Lab Results Table</h3>\n";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
    echo "<thead>\n";
    echo "<tr><th>Test Date</th><th>Test Name</th><th>Sample Type</th><th>Result</th><th>Status</th><th>Action</th></tr>\n";
    echo "</thead>\n";
    echo "<tbody>\n";
    
    foreach (array_slice($lab_results, 0, 4) as $result) {
        echo "<tr>\n";
        
        // Test Date
        echo "<td>";
        if (!empty($result['result_date'])) {
            echo htmlspecialchars(date('M j, Y', strtotime($result['result_date'])));
            echo "<br><small>" . htmlspecialchars(date('g:i A', strtotime($result['result_date']))) . "</small>";
        } elseif (!empty($result['order_date'])) {
            echo htmlspecialchars(date('M j, Y', strtotime($result['order_date'])));
            echo "<br><small>Ordered</small>";
        } else {
            echo "<em>Date not available</em>";
        }
        echo "</td>\n";
        
        // Test Name
        echo "<td>";
        echo "<strong>" . htmlspecialchars($result['test_name'] ?? 'Unknown Test') . "</strong>";
        if (!empty($result['test_type'])) {
            echo "<br><small>" . htmlspecialchars($result['test_type']) . "</small>";
        }
        echo "</td>\n";
        
        // Sample Type
        echo "<td>";
        if (!empty($result['sample_type'])) {
            echo "<span style='background: #f8f9fa; padding: 2px 6px; border-radius: 10px;'>";
            echo htmlspecialchars(ucfirst($result['sample_type']));
            echo "</span>";
        } else {
            echo "<em>Not specified</em>";
        }
        echo "</td>\n";
        
        // Result
        echo "<td>";
        if (!empty($result['result_value'])) {
            echo "<strong>" . htmlspecialchars($result['result_value']) . "</strong>";
            if (!empty($result['result_unit'])) {
                echo " " . htmlspecialchars($result['result_unit']);
            }
            if (!empty($result['normal_range'])) {
                echo "<br><small>Normal: " . htmlspecialchars($result['normal_range']) . "</small>";
            }
        } else {
            echo "<em style='color: orange;'>Result pending</em>";
        }
        echo "</td>\n";
        
        // Status
        echo "<td>";
        $status = $result['result_status'] ?? $result['order_status'] ?? 'pending';
        $displayStatus = ucfirst(str_replace('_', ' ', $status));
        echo "<span style='padding: 2px 8px; background: #e7f3ff; border-radius: 10px;'>";
        echo htmlspecialchars($displayStatus);
        echo "</span>";
        echo "</td>\n";
        
        // Action
        echo "<td>";
        if (!empty($result['result_value'])) {
            echo "<button style='background: green; color: white; padding: 4px 8px; border: none; border-radius: 4px;'>";
            echo "View Result";
            echo "</button>";
        } else {
            echo "<em style='color: gray;'>Pending</em>";
        }
        echo "</td>\n";
        
        echo "</tr>\n";
    }
    
    echo "</tbody>\n";
    echo "</table>\n";
}
?>