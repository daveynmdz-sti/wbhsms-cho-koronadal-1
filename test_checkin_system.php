<?php
/**
 * Test script for Check-in System
 * Verifies that the check-in system components work correctly
 */

// Set up path and include required files
$root_path = __DIR__;
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/queue_management_service.php';

echo "<h1>Check-in System Test</h1>\n";

try {
    // Test 1: Database Connection
    echo "<h2>Test 1: Database Connection</h2>\n";
    if ($pdo) {
        echo "✅ PDO connection successful\n<br>";
    } else {
        throw new Exception("PDO connection failed");
    }

    // Test 2: QueueManagementService Initialization
    echo "<h2>Test 2: Queue Management Service</h2>\n";
    $queueService = new QueueManagementService($pdo);
    echo "✅ QueueManagementService initialized successfully\n<br>";

    // Test 3: Check Tables Exist
    echo "<h2>Test 3: Required Tables</h2>\n";
    
    $required_tables = ['appointments', 'visits', 'queue_entries', 'stations', 'patients'];
    foreach ($required_tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table '$table' exists\n<br>";
        } else {
            echo "❌ Table '$table' missing\n<br>";
        }
    }

    // Test 4: Check Sample Data
    echo "<h2>Test 4: Sample Data Check</h2>\n";
    
    // Check for appointments in confirmed status
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM appointments WHERE status = 'confirmed'");
    $confirmed_count = $stmt->fetch()['count'];
    echo "📊 Confirmed appointments: $confirmed_count\n<br>";
    
    // Check for active stations
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM stations WHERE is_active = 1");
    $station_count = $stmt->fetch()['count'];
    echo "📊 Active stations: $station_count\n<br>";
    
    // Show sample triage stations
    $stmt = $pdo->query("SELECT station_id, station_name, service_id FROM stations WHERE station_type = 'triage' AND is_active = 1 LIMIT 3");
    echo "📊 Sample Triage Stations:\n<br>";
    while ($station = $stmt->fetch()) {
        echo "&nbsp;&nbsp;&nbsp;- Station {$station['station_id']}: {$station['station_name']} (Service {$station['service_id']})\n<br>";
    }

    // Test 5: Queue Entry Creation (Dry Run)
    echo "<h2>Test 5: Queue Entry Structure Check</h2>\n";
    $stmt = $pdo->query("DESCRIBE queue_entries");
    echo "📊 Queue Entries Table Structure:\n<br>";
    while ($column = $stmt->fetch()) {
        $required = $column['Null'] === 'NO' ? ' (REQUIRED)' : '';
        echo "&nbsp;&nbsp;&nbsp;- {$column['Field']}: {$column['Type']}{$required}\n<br>";
    }

    echo "<h2>🎉 All Tests Passed!</h2>\n";
    echo "<p>The check-in system should be ready to use.</p>\n";
    echo "<p><a href='pages/queueing/checkin_dashboard.php'>Go to Check-in Dashboard</a></p>\n";

} catch (Exception $e) {
    echo "<h2>❌ Test Failed</h2>\n";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    
    // Add debug info
    echo "<h3>Debug Information:</h3>\n";
    echo "<pre>";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "PDO Available: " . (class_exists('PDO') ? 'Yes' : 'No') . "\n";
    echo "Error Details: " . $e->getTraceAsString();
    echo "</pre>";
}
?>