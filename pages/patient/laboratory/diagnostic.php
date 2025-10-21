<?php
// Quick diagnostic for patient laboratory system
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/db.php';

echo "<h2>Patient Laboratory System Diagnostic</h2>";

try {
    // Test database connection
    echo "<h3>1. Database Connection Test</h3>";
    if (isset($pdo)) {
        echo "✅ PDO connection available<br>";
        
        // Test basic query
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        if ($result['test'] == 1) {
            echo "✅ Database query working<br>";
        }
    } else {
        echo "❌ PDO connection not available<br>";
    }
    
    // Check table structure
    echo "<h3>2. Table Structure Check</h3>";
    
    $tables = ['patients', 'lab_orders', 'lab_order_items'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "✅ Table '$table' exists<br>";
                
                // Show some basic info
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $stmt->fetch()['count'];
                echo "&nbsp;&nbsp;&nbsp; → Records: $count<br>";
            } else {
                echo "❌ Table '$table' missing<br>";
            }
        } catch (Exception $e) {
            echo "❌ Error checking table '$table': " . $e->getMessage() . "<br>";
        }
    }
    
    // Check for lab order items with results
    echo "<h3>3. Lab Results Data Check</h3>";
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as total_items,
                   SUM(CASE WHEN result_file IS NOT NULL THEN 1 ELSE 0 END) as items_with_results
            FROM lab_order_items 
            WHERE EXISTS (SELECT 1 FROM lab_orders lo WHERE lo.lab_order_id = lab_order_items.lab_order_id)
        ");
        $data = $stmt->fetch();
        
        echo "Total lab order items: " . $data['total_items'] . "<br>";
        echo "Items with result files: " . $data['items_with_results'] . "<br>";
        
        if ($data['items_with_results'] > 0) {
            echo "✅ Test data available for viewing<br>";
            
            // Show sample data
            $stmt = $pdo->query("
                SELECT loi.item_id, loi.test_type, lo.patient_id, p.first_name, p.last_name
                FROM lab_order_items loi
                INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                INNER JOIN patients p ON lo.patient_id = p.patient_id
                WHERE loi.result_file IS NOT NULL
                LIMIT 3
            ");
            
            echo "<h4>Sample Lab Results Available:</h4>";
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Item ID</th><th>Test Type</th><th>Patient</th><th>Test URL</th></tr>";
            
            while ($row = $stmt->fetch()) {
                $test_url = "get_lab_result.php?item_id=" . $row['item_id'] . "&action=view";
                echo "<tr>";
                echo "<td>" . $row['item_id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['test_type']) . "</td>";
                echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
                echo "<td><a href='$test_url' target='_blank'>Test View</a></td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "⚠️ No lab results with files found - system ready but no test data<br>";
        }
    } catch (Exception $e) {
        echo "❌ Error checking lab results: " . $e->getMessage() . "<br>";
    }
    
    // Check patient session simulation
    echo "<h3>4. Session Check</h3>";
    session_start();
    if (isset($_SESSION['patient_id'])) {
        echo "✅ Patient session active: Patient ID " . $_SESSION['patient_id'] . "<br>";
    } else {
        echo "⚠️ No patient session - testing requires patient login<br>";
        echo "Note: The view button requires patient authentication<br>";
    }
    
    // Check file endpoints
    echo "<h3>5. File Endpoint Check</h3>";
    $endpoints = [
        'laboratory.php' => 'Main Interface',
        'get_lab_orders.php' => 'Lab Orders API',
        'get_lab_result.php' => 'Result Viewer',
        'download_lab_result.php' => 'Download Handler',
        'print_lab_result.php' => 'Print Interface'
    ];
    
    foreach ($endpoints as $file => $description) {
        if (file_exists($file)) {
            echo "✅ $description ($file)<br>";
        } else {
            echo "❌ Missing: $description ($file)<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Diagnostic error: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>Next Steps:</h3>";
echo "1. Ensure you're logged in as a patient<br>";
echo "2. Navigate to laboratory.php<br>";
echo "3. If no lab orders appear, create test data through the management system<br>";
echo "4. Check browser console for JavaScript errors<br>";
?>

<script>
// Test JavaScript functionality
console.log("Patient Laboratory Diagnostic Script Loaded");

// Test basic AJAX functionality
function testAjax() {
    console.log("Testing AJAX call to get_lab_orders.php");
    fetch('get_lab_orders.php?action=list')
        .then(response => response.text())
        .then(data => {
            console.log("AJAX response:", data);
        })
        .catch(error => {
            console.error("AJAX error:", error);
        });
}

// Auto-test after 2 seconds
setTimeout(testAjax, 2000);
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; }
th, td { text-align: left; }
h3 { color: #0066cc; }
</style>