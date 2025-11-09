<?php
/**
 * Quick Test Data Creator
 * Creates sample appointments for testing the queue system
 */

$root_path = __DIR__;
require_once $root_path . '/config/db.php';

echo "<h2>Creating Test Data for Queue Testing</h2>";

try {
    // First, let's check the appointments table structure
    echo "<h3>Checking Appointments Table Structure</h3>";
    $stmt = $pdo->query("DESCRIBE appointments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='margin-bottom: 20px;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if we have patients
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM patients LIMIT 1");
    $patient_count = $stmt->fetch()['count'];
    
    if ($patient_count == 0) {
        echo "<p style='color: red;'>❌ No patients found. Please create some patients first.</p>";
        exit;
    }
    
    // Get first few patients
    $stmt = $pdo->query("SELECT patient_id, first_name, last_name FROM patients LIMIT 5");
    $patients = $stmt->fetchAll();
    
    echo "<h3>Creating Test Appointments</h3>";
    
    // Create appointments for today
    $today = date('Y-m-d');
    $times = ['08:00:00', '09:00:00', '10:00:00', '11:00:00', '14:00:00'];
    
    foreach ($patients as $index => $patient) {
        if ($index >= count($times)) break;
        
        $time = $times[$index];
        $scheduled_datetime = $today . ' ' . $time;
        
        // Check if appointment already exists
        $stmt = $pdo->prepare("
            SELECT appointment_id FROM appointments 
            WHERE patient_id = ? AND DATE(scheduled_date) = ? AND TIME(scheduled_time) = ?
        ");
        $stmt->execute([$patient['patient_id'], $today, $time]);
        
        if ($stmt->rowCount() > 0) {
            echo "<p>⚠️ Appointment for {$patient['first_name']} {$patient['last_name']} at $time already exists</p>";
            continue;
        }
        
        // Create new appointment with verification code
        $verification_code = strtoupper(substr(md5(uniqid($patient['patient_id'] . time(), true)), 0, 8));
        
        $stmt = $pdo->prepare("
            INSERT INTO appointments (
                patient_id, facility_id, service_id, scheduled_date, scheduled_time,
                status, verification_code, created_at, updated_at
            ) VALUES (?, 1, 1, ?, ?, 'confirmed', ?, NOW(), NOW())
        ");
        
        if ($stmt->execute([$patient['patient_id'], $today, $scheduled_datetime, $verification_code])) {
            echo "<p>✅ Created appointment for {$patient['first_name']} {$patient['last_name']} at $time (Code: $verification_code)</p>";
        } else {
            echo "<p>❌ Failed to create appointment for {$patient['first_name']} {$patient['last_name']}</p>";
        }
    }
    
    echo "<h3>Test Data Ready!</h3>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ol>";
    echo "<li><a href='pages/queueing/checkin_dashboard.php'>Go to Check-in Dashboard</a></li>";
    echo "<li>Click 'Check In with Priority' for any appointment</li>";
    echo "<li>Test the manual station selection feature</li>";
    echo "<li>Verify patient appears in selected triage station</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>