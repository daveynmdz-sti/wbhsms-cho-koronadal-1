<?php
require_once '../../config/db.php';
require_once '../../config/session/employee_session.php';

echo "<h2>Lab Order Creation Debug</h2>";

// Check if user is logged in
echo "<h3>1. Session Check</h3>";
if (isset($_SESSION['employee_id'])) {
    echo "<p style='color: green;'>✓ Employee logged in: ID " . $_SESSION['employee_id'] . ", Role: " . ($_SESSION['role'] ?? 'unknown') . "</p>";
    
    // Check role authorization
    if (in_array($_SESSION['role'] ?? '', ['admin', 'doctor', 'nurse'])) {
        echo "<p style='color: green;'>✓ User authorized to create lab orders</p>";
    } else {
        echo "<p style='color: red;'>✗ User NOT authorized to create lab orders. Current role: " . ($_SESSION['role'] ?? 'unknown') . "</p>";
    }
} else {
    echo "<p style='color: red;'>✗ No employee session found</p>";
}

// Check database connection
echo "<h3>2. Database Connection</h3>";
if ($conn && !$conn->connect_error) {
    echo "<p style='color: green;'>✓ Database connected successfully</p>";
} else {
    echo "<p style='color: red;'>✗ Database connection failed: " . ($conn ? $conn->connect_error : 'No connection') . "</p>";
    exit();
}

// Check if required tables exist
echo "<h3>3. Database Tables Check</h3>";
$requiredTables = ['patients', 'lab_orders', 'lab_order_items', 'appointments', 'visits'];

foreach ($requiredTables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✓ Table '$table' exists</p>";
    } else {
        echo "<p style='color: red;'>✗ Table '$table' missing</p>";
    }
}

// Check lab_orders table structure
echo "<h3>4. Lab Orders Table Structure</h3>";
$result = $conn->query("DESCRIBE lab_orders");
if ($result) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>Could not get lab_orders table structure</p>";
}

// Check lab_order_items table structure
echo "<h3>5. Lab Order Items Table Structure</h3>";
$result = $conn->query("DESCRIBE lab_order_items");
if ($result) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>Could not get lab_order_items table structure</p>";
}

// Test patient search
echo "<h3>6. Patient Search Test</h3>";
try {
    $sql = "SELECT COUNT(*) as patient_count FROM patients";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<p>Total patients in database: " . $row['patient_count'] . "</p>";
        
        if ($row['patient_count'] > 0) {
            // Show sample patients
            $sampleSql = "SELECT patient_id, first_name, last_name, username FROM patients LIMIT 5";
            $sampleResult = $conn->query($sampleSql);
            if ($sampleResult) {
                echo "<p><strong>Sample patients:</strong></p>";
                echo "<ul>";
                while ($patient = $sampleResult->fetch_assoc()) {
                    echo "<li>ID: " . $patient['patient_id'] . " - " . 
                         htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) . 
                         " (" . htmlspecialchars($patient['username']) . ")</li>";
                }
                echo "</ul>";
            }
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking patients: " . $e->getMessage() . "</p>";
}

// Test lab order creation (simulation)
echo "<h3>7. Lab Order Creation Test</h3>";
if (isset($_SESSION['employee_id'])) {
    try {
        // Get first patient for testing
        $patientResult = $conn->query("SELECT patient_id FROM patients LIMIT 1");
        if ($patientResult && $row = $patientResult->fetch_assoc()) {
            $testPatientId = $row['patient_id'];
            echo "<p>Using test patient ID: $testPatientId</p>";
            
            // Test the INSERT query structure (without actually inserting)
            $testSql = "SELECT ? as patient_id, ? as appointment_id, ? as visit_id, ? as employee_id, ? as remarks, 'pending' as status";
            $testStmt = $conn->prepare($testSql);
            if ($testStmt) {
                $testAppointmentId = null;
                $testVisitId = null;
                $testRemarks = "Test remarks";
                $testStmt->bind_param("iiiis", $testPatientId, $testAppointmentId, $testVisitId, $_SESSION['employee_id'], $testRemarks);
                $testStmt->execute();
                $testResult = $testStmt->get_result();
                if ($testResult) {
                    echo "<p style='color: green;'>✓ Lab order SQL structure is valid</p>";
                } else {
                    echo "<p style='color: red;'>✗ Lab order SQL structure test failed</p>";
                }
            } else {
                echo "<p style='color: red;'>✗ Failed to prepare test statement: " . $conn->error . "</p>";
            }
        } else {
            echo "<p style='color: red;'>No patients available for testing</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error in lab order test: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>Cannot test lab order creation - no employee session</p>";
}

// Check for error logs
echo "<h3>8. Recent Error Logs</h3>";
$errorLogPath = ini_get('error_log');
if ($errorLogPath && file_exists($errorLogPath)) {
    $errorLines = array_slice(file($errorLogPath), -10);
    if (!empty($errorLines)) {
        echo "<pre style='background-color: #f4f4f4; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;'>";
        foreach ($errorLines as $line) {
            echo htmlspecialchars($line);
        }
        echo "</pre>";
    } else {
        echo "<p>No recent errors in log file</p>";
    }
} else {
    echo "<p>Error log file not found or not accessible</p>";
}

echo "<h3>9. Troubleshooting Steps</h3>";
echo "<div style='background-color: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
echo "<p><strong>To create a lab order successfully:</strong></p>";
echo "<ol>";
echo "<li>Make sure you're logged in as admin, doctor, or nurse</li>";
echo "<li>Select a patient from the search</li>";
echo "<li>Choose at least one lab test</li>";
echo "<li>Click 'Create Lab Order'</li>";
echo "</ol>";
echo "<p><strong>If still not working:</strong></p>";
echo "<ul>";
echo "<li>Check browser console for JavaScript errors</li>";
echo "<li>Check browser network tab for failed requests</li>";
echo "<li>Verify the form is submitting to the correct URL</li>";
echo "</ul>";
echo "</div>";

?>

<style>
table { border-collapse: collapse; margin: 10px 0; }
th { background-color: #f2f2f2; font-weight: bold; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
</style>