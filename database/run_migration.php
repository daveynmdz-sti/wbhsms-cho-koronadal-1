<?php
// Script to fix the lab_orders table appointment_id constraint
$root_path = dirname(__DIR__);
include $root_path . '/config/db.php';

echo "<h2>Database Migration: Fix Lab Orders Appointment ID</h2>";

try {
    // Read the SQL file
    $sqlFile = __DIR__ . '/fix_lab_orders_appointment_null.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new Exception("Could not read SQL file");
    }
    
    // Remove USE statement and comments for execution
    $statements = explode(';', $sql);
    $executed = 0;
    $errors = 0;
    
    echo "<h3>Executing SQL Statements:</h3>";
    echo "<div style='font-family: monospace; background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Skip empty statements, comments, and USE statements
        if (empty($statement) || 
            strpos($statement, '--') === 0 || 
            strpos($statement, '/*') === 0 ||
            stripos($statement, 'USE ') === 0) {
            continue;
        }
        
        try {
            echo "Executing: " . substr($statement, 0, 100) . "...<br>";
            $result = $conn->query($statement);
            
            if ($result === false) {
                echo "<span style='color: red;'>ERROR: " . $conn->error . "</span><br>";
                $errors++;
            } else {
                echo "<span style='color: green;'>SUCCESS</span><br>";
                $executed++;
                
                // If it's a SELECT statement, show results
                if (stripos($statement, 'SELECT') === 0 && $result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        foreach ($row as $key => $value) {
                            echo "&nbsp;&nbsp;&nbsp;&nbsp;$key: $value<br>";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo "<span style='color: red;'>ERROR: " . $e->getMessage() . "</span><br>";
            $errors++;
        }
        
        echo "<br>";
    }
    
    echo "</div>";
    
    echo "<h3>Migration Summary:</h3>";
    echo "<p>✓ Statements executed successfully: <strong>$executed</strong></p>";
    echo "<p>✗ Errors encountered: <strong>$errors</strong></p>";
    
    if ($errors === 0) {
        echo "<p style='color: green; font-weight: bold;'>🎉 Migration completed successfully!</p>";
        echo "<p>The lab_orders table now allows NULL appointment_id values for direct lab orders.</p>";
    } else {
        echo "<p style='color: orange; font-weight: bold;'>⚠️ Migration completed with some errors.</p>";
        echo "<p>Some statements may have failed due to existing constraints or data. Check the errors above.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Migration Failed:</strong> " . $e->getMessage() . "</p>";
}

echo "<br><p><a href='../pages/laboratory-management/debug_lab_orders.php'>Test Lab Order Creation</a></p>";
echo "<p><a href='../pages/laboratory-management/lab_management.php'>Go to Lab Management</a></p>";
?>