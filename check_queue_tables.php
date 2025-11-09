<?php
require_once 'config/db.php';

try {
    echo "<h3>Checking Queue Tables and Structure</h3>";
    
    // Check what tables exist with 'queue' in the name
    $stmt = $pdo->query("SHOW TABLES LIKE '%queue%'");
    $queue_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h4>Existing Queue Tables:</h4>";
    if (empty($queue_tables)) {
        echo "<p style='color: red;'>❌ No queue tables found!</p>";
    } else {
        echo "<ul>";
        foreach ($queue_tables as $table) {
            echo "<li>✅ {$table}</li>";
        }
        echo "</ul>";
    }
    
    // Check for visits table
    $stmt = $pdo->query("SHOW TABLES LIKE 'visits'");
    $visits_exists = $stmt->fetch() !== false;
    
    echo "<h4>Visits Table:</h4>";
    if ($visits_exists) {
        echo "<p style='color: green;'>✅ visits table exists</p>";
        
        // Show structure
        $stmt = $pdo->query("DESCRIBE visits");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='margin: 10px 0; border-collapse: collapse;'>";
        echo "<tr style='background: #f8f9fa;'><th style='padding: 8px;'>Column</th><th style='padding: 8px;'>Type</th><th style='padding: 8px;'>Null</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td style='padding: 8px;'>{$col['Field']}</td>";
            echo "<td style='padding: 8px;'>{$col['Type']}</td>";
            echo "<td style='padding: 8px;'>{$col['Null']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ visits table does not exist!</p>";
    }
    
    // Check for station queue tables specifically
    echo "<h4>Station Queue Tables Check:</h4>";
    $stations_to_check = [1, 2, 3];
    $missing_tables = [];
    
    foreach ($stations_to_check as $station_id) {
        $table_name = "station_{$station_id}_queue";
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
        $exists = $stmt->fetch() !== false;
        
        if ($exists) {
            echo "<p style='color: green;'>✅ {$table_name} exists</p>";
        } else {
            echo "<p style='color: red;'>❌ {$table_name} missing</p>";
            $missing_tables[] = $table_name;
        }
    }
    
    // Show SQL to create missing tables
    if (!empty($missing_tables)) {
        echo "<h3>SQL to Create Missing Queue Tables</h3>";
        echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
        
        foreach ($missing_tables as $table_name) {
            echo "-- Create {$table_name} table\n";
            echo "CREATE TABLE {$table_name} (\n";
            echo "    id INT PRIMARY KEY AUTO_INCREMENT,\n";
            echo "    patient_id INT NOT NULL,\n";
            echo "    username VARCHAR(100),\n";
            echo "    visit_id INT,\n";
            echo "    appointment_id INT,\n";
            echo "    service_id INT,\n";
            echo "    queue_type VARCHAR(50) DEFAULT 'triage',\n";
            echo "    station_id INT DEFAULT " . substr($table_name, 8, 1) . ",\n";
            echo "    priority_level ENUM('normal', 'priority', 'emergency') DEFAULT 'normal',\n";
            echo "    status ENUM('waiting', 'in_progress', 'completed', 'skipped') DEFAULT 'waiting',\n";
            echo "    time_in DATETIME DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    time_called DATETIME NULL,\n";
            echo "    time_completed DATETIME NULL,\n";
            echo "    notes TEXT,\n";
            echo "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n";
            echo "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
            echo "    INDEX idx_patient_id (patient_id),\n";
            echo "    INDEX idx_visit_id (visit_id),\n";
            echo "    INDEX idx_appointment_id (appointment_id),\n";
            echo "    INDEX idx_status_time (status, time_in),\n";
            echo "    INDEX idx_priority_time (priority_level, time_in)\n";
            echo ");\n\n";
        }
        echo "</pre>";
        
        echo "<button onclick=\"createTables()\" style='padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 10px 0;'>";
        echo "Create Missing Queue Tables";
        echo "</button>";
        
        echo "<script>
        function createTables() {
            if (confirm('This will create the missing queue tables. Continue?')) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=create_queue_tables'
                })
                .then(response => response.text())
                .then(data => {
                    alert(data);
                    location.reload();
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
            }
        }
        </script>";
    }
    
    // Show recent check-in attempts
    echo "<h3>Recent Check-in Attempts (Error Log)</h3>";
    echo "<p><em>Check your error logs for any recent check-in errors:</em></p>";
    echo "<ul>";
    echo "<li>Windows XAMPP: <code>C:\\xampp\\apache\\logs\\error.log</code></li>";
    echo "<li>Look for: 'Priority check-in error' or 'addToStationQueue Error'</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "❌ Error: " . $e->getMessage();
    echo "</div>";
}

// Handle table creation
if (isset($_POST['action']) && $_POST['action'] === 'create_queue_tables') {
    try {
        $stations = [1, 2, 3];
        $created_count = 0;
        
        foreach ($stations as $station_id) {
            $table_name = "station_{$station_id}_queue";
            
            // Check if table exists first
            $check_stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
            if ($check_stmt->fetch() === false) {
                $sql = "CREATE TABLE {$table_name} (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    patient_id INT NOT NULL,
                    username VARCHAR(100),
                    visit_id INT,
                    appointment_id INT,
                    service_id INT,
                    queue_type VARCHAR(50) DEFAULT 'triage',
                    station_id INT DEFAULT {$station_id},
                    priority_level ENUM('normal', 'priority', 'emergency') DEFAULT 'normal',
                    status ENUM('waiting', 'in_progress', 'completed', 'skipped') DEFAULT 'waiting',
                    time_in DATETIME DEFAULT CURRENT_TIMESTAMP,
                    time_called DATETIME NULL,
                    time_completed DATETIME NULL,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_patient_id (patient_id),
                    INDEX idx_visit_id (visit_id),
                    INDEX idx_appointment_id (appointment_id),
                    INDEX idx_status_time (status, time_in),
                    INDEX idx_priority_time (priority_level, time_in)
                )";
                
                $pdo->exec($sql);
                $created_count++;
            }
        }
        
        echo "✅ Successfully created {$created_count} queue tables!";
    } catch (Exception $e) {
        echo "❌ Error creating tables: " . $e->getMessage();
    }
    exit;
}
?>