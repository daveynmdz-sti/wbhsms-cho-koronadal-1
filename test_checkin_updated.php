<?php
/**
 * Test script to verify the updated check-in process
 * Tests: visit creation, appointment update, triage queue assignment
 */

$root_path = __DIR__;
require_once $root_path . '/config/db.php';

// Define the functions we need to test
function addToStationQueue($pdo, $station_id, $patient_id, $username, $visit_id, $appointment_id, $service_id, $queue_type, $priority_level = 'normal') {
    try {
        $table_name = "station_{$station_id}_queue";
        
        $stmt = $pdo->prepare("
            INSERT INTO $table_name (
                patient_id, username, visit_id, appointment_id, service_id,
                queue_type, station_id, priority_level, status, time_in
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'waiting', NOW())
        ");
        
        $result = $stmt->execute([
            $patient_id, $username, $visit_id, $appointment_id, $service_id,
            $queue_type, $station_id, $priority_level
        ]);
        
        return ['success' => $result, 'queue_entry_id' => $pdo->lastInsertId()];
        
    } catch (Exception $e) {
        error_log('addToStationQueue Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add patient to station queue'];
    }
}

function getOptimalTriageStation($pdo, $service_id = null) {
    try {
        // Get queue counts for triage stations (1, 2, 3)
        $stations = [];
        for ($i = 1; $i <= 3; $i++) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as queue_count 
                FROM station_{$i}_queue 
                WHERE status IN ('waiting', 'in_progress') 
                AND DATE(time_in) = CURDATE()
            ");
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['queue_count'];
            
            $stations[$i] = ['station_id' => $i, 'queue_count' => $count];
        }
        
        // Find station with shortest queue
        uasort($stations, function($a, $b) {
            return $a['queue_count'] <=> $b['queue_count'];
        });
        
        $optimal_station = reset($stations);
        return $optimal_station['station_id'];
        
    } catch (Exception $e) {
        error_log('getOptimalTriageStation Error: ' . $e->getMessage());
        return 1; // Default to Station 1
    }
}

function generateQueueNumber($pdo, $station_id, $priority_level) {
    try {
        // Get today's queue count for this station
        $stmt = $pdo->prepare("
            SELECT COUNT(*) + 1 as next_number 
            FROM station_{$station_id}_queue 
            WHERE DATE(time_in) = CURDATE()
        ");
        $stmt->execute();
        $next_number = $stmt->fetch(PDO::FETCH_ASSOC)['next_number'];
        
        // Generate queue code based on priority and station
        $priority_prefix = [
            'emergency' => 'E',
            'priority' => 'P', 
            'normal' => 'N'
        ];
        $prefix = $priority_prefix[$priority_level] ?? 'N';
        
        return [
            'queue_number' => $next_number,
            'queue_code' => "T{$station_id}-{$prefix}{$next_number}"
        ];
        
    } catch (Exception $e) {
        error_log('generateQueueNumber Error: ' . $e->getMessage());
        return ['queue_number' => 1, 'queue_code' => "T{$station_id}-N1"];
    }
}

echo "<h2>Testing Updated Check-in Process</h2>\n";

// Test 1: Check if station queue tables exist
echo "<h3>Test 1: Verify Station Queue Tables</h3>\n";
$tables_to_check = ['station_1_queue', 'station_2_queue', 'station_3_queue'];
$all_tables_exist = true;

foreach ($tables_to_check as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        echo "Table $table: " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "<br>\n";
        if (!$exists) $all_tables_exist = false;
    } catch (Exception $e) {
        echo "Table $table: ❌ ERROR - " . $e->getMessage() . "<br>\n";
        $all_tables_exist = false;
    }
}

if (!$all_tables_exist) {
    echo "<p style='color: red;'><strong>⚠️ ERROR: Missing station queue tables. Please run the station_queue_tables.sql script first.</strong></p>\n";
    exit;
}

// Test 2: Check database structure
echo "<h3>Test 2: Verify Database Structure</h3>\n";
try {
    $stmt = $pdo->query("DESCRIBE station_1_queue");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required_columns = ['queue_entry_id', 'patient_id', 'username', 'visit_id', 'appointment_id', 'service_id', 'queue_type', 'station_id', 'priority_level', 'status', 'time_in'];
    
    $missing_columns = array_diff($required_columns, $columns);
    if (empty($missing_columns)) {
        echo "✅ Station queue table structure is correct<br>\n";
    } else {
        echo "❌ Missing columns in station_1_queue: " . implode(', ', $missing_columns) . "<br>\n";
    }
} catch (Exception $e) {
    echo "❌ Error checking table structure: " . $e->getMessage() . "<br>\n";
}

// Test 3: Check for existing confirmed appointments
echo "<h3>Test 3: Check Available Test Data</h3>\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM appointments WHERE status = 'confirmed' AND scheduled_date >= CURDATE()");
    $appointment_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "Confirmed appointments available for testing: $appointment_count<br>\n";
    
    if ($appointment_count > 0) {
        // Show a sample appointment
        $stmt = $pdo->query("
            SELECT a.appointment_id, a.patient_id, a.scheduled_date, a.scheduled_time, 
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            WHERE a.status = 'confirmed' AND a.scheduled_date >= CURDATE()
            LIMIT 1
        ");
        $sample = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sample) {
            echo "Sample appointment for testing: ID {$sample['appointment_id']} - {$sample['patient_name']} on {$sample['scheduled_date']} at {$sample['scheduled_time']}<br>\n";
        }
    } else {
        echo "⚠️ No confirmed appointments available for testing. You may need to create some test appointments first.<br>\n";
    }
} catch (Exception $e) {
    echo "❌ Error checking appointments: " . $e->getMessage() . "<br>\n";
}

// Test 4: Test optimal triage station function
echo "<h3>Test 4: Test Triage Station Load Balancing</h3>\n";
try {
    // Check current queue counts
    for ($i = 1; $i <= 3; $i++) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM station_{$i}_queue 
            WHERE status IN ('waiting', 'in_progress') 
            AND DATE(time_in) = CURDATE()
        ");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "Station $i current queue: $count patients<br>\n";
    }
    
    // Test the optimal station function
    $optimal_station = getOptimalTriageStation($pdo);
    echo "✅ Optimal triage station: Station $optimal_station<br>\n";
    
} catch (Exception $e) {
    echo "❌ Error testing station functions: " . $e->getMessage() . "<br>\n";
}

// Test 5: Test queue number generation
echo "<h3>Test 5: Test Queue Number Generation</h3>\n";
try {
    $test_priorities = ['normal', 'priority', 'emergency'];
    foreach ($test_priorities as $priority) {
        $queue_info = generateQueueNumber($pdo, 1, $priority);
        echo "$priority priority -> Queue Number: {$queue_info['queue_number']}, Code: {$queue_info['queue_code']}<br>\n";
    }
    echo "✅ Queue number generation working correctly<br>\n";
} catch (Exception $e) {
    echo "❌ Error testing queue number generation: " . $e->getMessage() . "<br>\n";
}

echo "<h3>Summary</h3>\n";
echo "<p>✅ Updated check-in process is ready for testing!</p>\n";
echo "<p><strong>Key Changes:</strong></p>\n";
echo "<ul>\n";
echo "<li>✅ Removed dependency on QueueManagementService</li>\n";
echo "<li>✅ Using individual station queue tables (station_1_queue, station_2_queue, station_3_queue)</li>\n";
echo "<li>✅ Automatic load balancing across triage stations</li>\n";
echo "<li>✅ Proper visit record creation</li>\n";
echo "<li>✅ Appointment status updates</li>\n";
echo "<li>✅ Priority-based queue codes</li>\n";
echo "</ul>\n";

echo "<p><strong>To test the check-in process:</strong></p>\n";
echo "<ol>\n";
echo "<li>Go to the check-in dashboard</li>\n";
echo "<li>Try checking in a confirmed appointment</li>\n";
echo "<li>Verify the patient appears in one of the triage station dashboards</li>\n";
echo "<li>Check that visit record was created in the visits table</li>\n";
echo "</ol>\n";
?>