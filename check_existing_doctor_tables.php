<?php
// Check existing doctor schedule tables in the database
require_once 'config/db.php';

echo "<h1>Existing Doctor Schedule Tables Analysis</h1>\n";
echo "<p>Checking what tables you've already created in the database...</p>\n";

try {
    // Check for doctor schedule related tables
    echo "<h2>1. Doctor Schedule Related Tables</h2>\n";
    $stmt = $pdo->query("SHOW TABLES LIKE '%schedule%'");
    $schedule_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($schedule_tables)) {
        echo "<p><strong>Found schedule-related tables:</strong></p>\n";
        foreach ($schedule_tables as $table) {
            echo "<h3>📋 Table: <code>{$table}</code></h3>\n";
            
            // Show structure
            $stmt = $pdo->query("DESCRIBE {$table}");
            $table_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>\n";
            echo "<tr style='background: #f0f0f0;'><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
            foreach ($table_structure as $column) {
                echo "<tr>";
                echo "<td><strong>{$column['Field']}</strong></td>";
                echo "<td>{$column['Type']}</td>";
                echo "<td>{$column['Null']}</td>";
                echo "<td>{$column['Key']}</td>";
                echo "<td>{$column['Default']}</td>";
                echo "<td>{$column['Extra']}</td>";
                echo "</tr>\n";
            }
            echo "</table>\n";
            
            // Show sample data
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as record_count FROM {$table}");
                $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $record_count = $count_result['record_count'];
                
                echo "<p><strong>Records in table:</strong> {$record_count}</p>\n";
                
                if ($record_count > 0) {
                    echo "<h4>Sample Data (first 5 records):</h4>\n";
                    $stmt = $pdo->query("SELECT * FROM {$table} LIMIT 5");
                    $sample_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($sample_data)) {
                        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>\n";
                        echo "<tr style='background: #e8f5e8;'>";
                        foreach (array_keys($sample_data[0]) as $header) {
                            echo "<th>{$header}</th>";
                        }
                        echo "</tr>\n";
                        
                        foreach ($sample_data as $row) {
                            echo "<tr>";
                            foreach ($row as $value) {
                                $display_value = htmlspecialchars(substr($value ?? '', 0, 50));
                                echo "<td>{$display_value}</td>";
                            }
                            echo "</tr>\n";
                        }
                        echo "</table>\n";
                    }
                } else {
                    echo "<p><em>No data in this table yet.</em></p>\n";
                }
            } catch (Exception $e) {
                echo "<p style='color: orange;'>Could not retrieve sample data: {$e->getMessage()}</p>\n";
            }
            
            echo "<hr>\n";
        }
    } else {
        echo "<p style='color: red;'>❌ No schedule-related tables found</p>\n";
    }
    
    // Check for doctor-related tables
    echo "<h2>2. Doctor Related Tables</h2>\n";
    $stmt = $pdo->query("SHOW TABLES LIKE '%doctor%'");
    $doctor_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($doctor_tables)) {
        echo "<p><strong>Found doctor-related tables:</strong></p>\n";
        echo "<ul>\n";
        foreach ($doctor_tables as $table) {
            echo "<li><code>{$table}</code></li>\n";
        }
        echo "</ul>\n";
    }
    
    // Check if employees table has doctors
    echo "<h2>3. Doctors in Employees Table</h2>\n";
    
    // First check employees table structure
    $stmt = $pdo->query("DESCRIBE employees");
    $employees_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $available_columns = array_column($employees_columns, 'Field');
    
    // Find primary key
    $employee_pk = null;
    foreach ($employees_columns as $column) {
        if ($column['Key'] === 'PRI') {
            $employee_pk = $column['Field'];
            break;
        }
    }
    
    echo "<p><strong>Employee table primary key:</strong> <code>{$employee_pk}</code></p>\n";
    
    // Look for doctors
    if (in_array('role', $available_columns) || in_array('position', $available_columns)) {
        $where_conditions = [];
        if (in_array('role', $available_columns)) {
            $where_conditions[] = "role LIKE '%doctor%'";
            $where_conditions[] = "role = 'Doctor'";
        }
        if (in_array('position', $available_columns)) {
            $where_conditions[] = "position LIKE '%doctor%'";
        }
        
        if (!empty($where_conditions)) {
            $where_sql = "WHERE " . implode(' OR ', $where_conditions);
            $stmt = $pdo->query("SELECT * FROM employees {$where_sql} LIMIT 10");
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($doctors)) {
                echo "<p><strong>Found {" . count($doctors) . "} doctors:</strong></p>\n";
                echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>\n";
                echo "<tr style='background: #f0f0f0;'>";
                foreach (array_keys($doctors[0]) as $header) {
                    echo "<th>{$header}</th>";
                }
                echo "</tr>\n";
                
                foreach ($doctors as $doctor) {
                    echo "<tr>";
                    foreach ($doctor as $value) {
                        $display_value = htmlspecialchars(substr($value ?? '', 0, 30));
                        echo "<td>{$display_value}</td>";
                    }
                    echo "</tr>\n";
                }
                echo "</table>\n";
            } else {
                echo "<p>No doctors found with role/position filters.</p>\n";
            }
        }
    } else {
        echo "<p>No role or position columns found in employees table.</p>\n";
    }
    
    // Show all tables for reference
    echo "<h2>4. All Tables in Database</h2>\n";
    $stmt = $pdo->query("SHOW TABLES");
    $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<div style='columns: 3; column-gap: 20px;'>\n";
    echo "<ul>\n";
    foreach ($all_tables as $table) {
        echo "<li><code>{$table}</code></li>\n";
    }
    echo "</ul>\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>";
echo "<h2>5. Next Steps Based on Findings</h2>\n";
echo "<p>Based on what tables exist, I can help you:</p>\n";
echo "<ul>\n";
echo "<li>✅ Create auto-generation scripts for schedule slots</li>\n";
echo "<li>✅ Implement referral status tracking</li>\n";
echo "<li>✅ Build the doctor schedule management interface</li>\n";
echo "<li>✅ Create referral creation with slot selection</li>\n";
echo "</ul>\n";

?>