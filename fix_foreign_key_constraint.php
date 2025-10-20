<?php
// Fix lab_orders appointment_id foreign key constraint issue
require_once __DIR__ . '/config/db.php';

try {
    echo "<h2>Lab Orders Foreign Key Constraint Fix</h2>";
    
    // Step 1: Show current foreign key constraints
    echo "<h3>Step 1: Current Foreign Key Constraints</h3>";
    $result = $conn->query("
        SELECT 
            CONSTRAINT_NAME,
            TABLE_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'wbhsms' 
        AND TABLE_NAME = 'lab_orders' 
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    
    echo "<table border='1'>";
    echo "<tr><th>Constraint Name</th><th>Table</th><th>Column</th><th>Referenced Table</th><th>Referenced Column</th></tr>";
    $foreign_keys = [];
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['CONSTRAINT_NAME']) . "</td>";
        echo "<td>" . htmlspecialchars($row['TABLE_NAME']) . "</td>";
        echo "<td>" . htmlspecialchars($row['COLUMN_NAME']) . "</td>";
        echo "<td>" . htmlspecialchars($row['REFERENCED_TABLE_NAME']) . "</td>";
        echo "<td>" . htmlspecialchars($row['REFERENCED_COLUMN_NAME']) . "</td>";
        echo "</tr>";
        
        if ($row['COLUMN_NAME'] === 'appointment_id') {
            $foreign_keys[] = $row;
        }
    }
    echo "</table>";
    
    if (empty($foreign_keys)) {
        echo "<p>No foreign key constraints found on appointment_id column.</p>";
        
        // Try to modify the column directly
        echo "<h3>Step 2: Modifying appointment_id column</h3>";
        $conn->query("ALTER TABLE lab_orders MODIFY COLUMN appointment_id INT NULL");
        echo "<p>✅ Column modified successfully!</p>";
        
    } else {
        echo "<p>Found " . count($foreign_keys) . " foreign key constraint(s) on appointment_id.</p>";
        
        foreach ($foreign_keys as $fk) {
            $constraint_name = $fk['CONSTRAINT_NAME'];
            $referenced_table = $fk['REFERENCED_TABLE_NAME'];
            $referenced_column = $fk['REFERENCED_COLUMN_NAME'];
            
            echo "<h3>Step 2: Dropping Foreign Key Constraint '$constraint_name'</h3>";
            $dropSql = "ALTER TABLE lab_orders DROP FOREIGN KEY $constraint_name";
            if ($conn->query($dropSql)) {
                echo "<p>✅ Dropped foreign key constraint: $constraint_name</p>";
            } else {
                echo "<p>❌ Failed to drop constraint: " . $conn->error . "</p>";
                continue;
            }
            
            echo "<h3>Step 3: Modifying appointment_id column to allow NULL</h3>";
            $modifySql = "ALTER TABLE lab_orders MODIFY COLUMN appointment_id INT NULL";
            if ($conn->query($modifySql)) {
                echo "<p>✅ Modified appointment_id column to allow NULL</p>";
            } else {
                echo "<p>❌ Failed to modify column: " . $conn->error . "</p>";
                continue;
            }
            
            echo "<h3>Step 4: Recreating Foreign Key Constraint (allowing NULL)</h3>";
            $recreateSql = "ALTER TABLE lab_orders ADD CONSTRAINT $constraint_name 
                           FOREIGN KEY (appointment_id) REFERENCES $referenced_table($referenced_column)";
            if ($conn->query($recreateSql)) {
                echo "<p>✅ Recreated foreign key constraint: $constraint_name</p>";
            } else {
                echo "<p>❌ Failed to recreate constraint: " . $conn->error . "</p>";
                echo "<p><strong>Note:</strong> The column has been modified, but foreign key constraint was not recreated.</p>";
            }
        }
    }
    
    // Step 5: Verify the final structure
    echo "<h3>Step 5: Final Verification</h3>";
    $result = $conn->query("SHOW COLUMNS FROM lab_orders LIKE 'appointment_id'");
    $column_info = $result->fetch_assoc();
    
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    echo "<tr>";
    foreach ($column_info as $key => $value) {
        echo "<td><strong>$key:</strong> " . htmlspecialchars($value ?? 'NULL') . "</td>";
    }
    echo "</tr>";
    echo "</table>";
    
    if ($column_info['Null'] === 'YES') {
        echo "<p><strong>✅ SUCCESS: appointment_id now allows NULL values!</strong></p>";
    } else {
        echo "<p><strong>❌ FAILED: appointment_id still does not allow NULL values.</strong></p>";
    }
    
    // Show foreign key constraints after modification
    echo "<h3>Updated Foreign Key Constraints</h3>";
    $result = $conn->query("
        SELECT 
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'wbhsms' 
        AND TABLE_NAME = 'lab_orders' 
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    
    if ($result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>Constraint Name</th><th>Column</th><th>Referenced Table</th><th>Referenced Column</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['CONSTRAINT_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($row['COLUMN_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($row['REFERENCED_TABLE_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($row['REFERENCED_COLUMN_NAME']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No foreign key constraints found on lab_orders table.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
h3 { color: #333; margin-top: 20px; }
.success { color: green; }
.error { color: red; }
</style>

<a href="test_lab_order_creation.php">Test Lab Order Creation →</a>