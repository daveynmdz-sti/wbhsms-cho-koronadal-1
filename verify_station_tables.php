<!DOCTYPE html>
<html>
<head>
    <title>Station Queue Tables Verification</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .section { margin: 20px 0; }
    </style>
</head>
<body>
    <h1>Station Queue Tables Verification</h1>
    <p><strong>Date:</strong> <?= date('Y-m-d H:i:s') ?></p>

<?php
// Verify Station Queue Tables Creation
// This script checks if all 15 station queue tables were created successfully

require_once 'config/db.php';

try {
    // Get database name from config
    $database_name = 'wbhsms_database'; // Database name from config
    
    // Check if all 15 station queue tables exist
    $tables_to_check = [];
    for ($i = 1; $i <= 15; $i++) {
        $tables_to_check[] = "station_{$i}_queue";
    }
    
    $existing_tables = [];
    $missing_tables = [];
    
    foreach ($tables_to_check as $table_name) {
        $stmt = $pdo->query("
            SELECT TABLE_NAME 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = '$database_name' 
            AND TABLE_NAME = '$table_name'
        ");
        $result = $stmt->fetch();
        
        if ($result) {
            $existing_tables[] = $table_name;
            echo "<span class='success'>✓ Table '{$table_name}' exists</span><br>";
        } else {
            $missing_tables[] = $table_name;
            echo "<span class='error'>✗ Table '{$table_name}' NOT FOUND</span><br>";
        }
    }
    
    echo "<div class='section'>";
    echo "<h2>Summary</h2>";
    echo "Tables found: " . count($existing_tables) . " / " . count($tables_to_check) . "<br>";
    
    if (count($missing_tables) > 0) {
        echo "<span class='error'>Missing tables: " . implode(', ', $missing_tables) . "</span><br>";
    } else {
        echo "<span class='success'>✓ All station queue tables created successfully!</span><br>";
    }
    echo "</div>";
    
    // Check table structure for first table as sample
    if (count($existing_tables) > 0) {
        echo "<div class='section'>";
        echo "<h2>Sample Table Structure (station_1_queue)</h2>";
        $stmt = $pdo->query("DESCRIBE station_1_queue");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Column</th><th>Type</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        // Check foreign key constraints
        echo "<div class='section'>";
        echo "<h2>Foreign Key Constraints (station_1_queue)</h2>";
        $stmt = $pdo->query("
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = '$database_name'
            AND TABLE_NAME = 'station_1_queue' 
            AND CONSTRAINT_NAME != 'PRIMARY'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        
        $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($constraints) > 0) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Constraint Name</th><th>Column</th><th>References</th></tr>";
            foreach ($constraints as $constraint) {
                echo "<tr>";
                echo "<td>{$constraint['CONSTRAINT_NAME']}</td>";
                echo "<td>{$constraint['COLUMN_NAME']}</td>";
                echo "<td>{$constraint['REFERENCED_TABLE_NAME']}.{$constraint['REFERENCED_COLUMN_NAME']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<span class='warning'>⚠ No foreign key constraints found</span>";
        }
        echo "</div>";
        
        // Count records in each table
        echo "<div class='section'>";
        echo "<h2>Record Counts</h2>";
        foreach ($existing_tables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "Table '{$table}': {$count} records<br>";
        }
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>Database Error: " . $e->getMessage() . "</div>";
} catch (Exception $e) {
    echo "<div class='error'>Error: " . $e->getMessage() . "</div>";
}

echo "<div class='section'><h2>Verification Complete</h2></div>";
?>
</body>
</html>