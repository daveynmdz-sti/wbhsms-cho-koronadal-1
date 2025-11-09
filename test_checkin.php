<?php
require_once 'config/db.php';

try {
    echo "<h3>Testing Station 1 Queue Check-in Process</h3>";
    
    // Check if station_1_queue table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'station_1_queue'");
    $table_exists = $stmt->fetch() !== false;
    
    if (!$table_exists) {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24; margin: 10px 0;'>";
        echo "❌ station_1_queue table does not exist. Please run check_queue_tables.php to create it.";
        echo "</div>";
        exit;
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724; margin: 10px 0;'>";
    echo "✅ station_1_queue table exists";
    echo "</div>";
    
    // Check visits table
    $stmt = $pdo->query("SHOW TABLES LIKE 'visits'");
    $visits_exists = $stmt->fetch() !== false;
    
    if (!$visits_exists) {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24; margin: 10px 0;'>";
        echo "❌ visits table does not exist. Please create it.";
        echo "</div>";
        exit;
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724; margin: 10px 0;'>";
    echo "✅ visits table exists";
    echo "</div>";
    
    // Show confirmed appointments available for check-in
    echo "<h4>Confirmed Appointments Available for Check-in</h4>";
    $stmt = $pdo->query("
        SELECT a.appointment_id, a.patient_id, a.scheduled_date, a.scheduled_time,
               p.first_name, p.last_name, p.contact_number,
               s.name as service_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN services s ON a.service_id = s.service_id
        WHERE a.status = 'confirmed'
        ORDER BY a.scheduled_date, a.scheduled_time
        LIMIT 10
    ");
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($appointments)) {
        echo "<p style='color: #856404; background: #fff3cd; padding: 10px; border-radius: 5px;'>No confirmed appointments found. Create some test appointments first.</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='padding: 8px;'>ID</th>";
        echo "<th style='padding: 8px;'>Patient</th>";
        echo "<th style='padding: 8px;'>Date/Time</th>";
        echo "<th style='padding: 8px;'>Service</th>";
        echo "<th style='padding: 8px;'>Action</th>";
        echo "</tr>";
        
        foreach ($appointments as $apt) {
            echo "<tr>";
            echo "<td style='padding: 8px;'>{$apt['appointment_id']}</td>";
            echo "<td style='padding: 8px;'>{$apt['first_name']} {$apt['last_name']}</td>";
            echo "<td style='padding: 8px;'>{$apt['scheduled_date']} {$apt['scheduled_time']}</td>";
            echo "<td style='padding: 8px;'>" . ($apt['service_name'] ?? 'General') . "</td>";
            echo "<td style='padding: 8px;'>";
            echo "<button onclick=\"testCheckIn({$apt['appointment_id']})\" style='background: #28a745; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;'>Test Check-in</button>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Show current queue status
    echo "<h4>Current Station 1 Queue Status</h4>";
    $stmt = $pdo->query("
        SELECT q.*, p.first_name, p.last_name, a.scheduled_time
        FROM station_1_queue q
        JOIN patients p ON q.patient_id = p.patient_id
        LEFT JOIN appointments a ON q.appointment_id = a.appointment_id
        WHERE q.status IN ('waiting', 'in_progress')
        ORDER BY q.time_in
    ");
    $queue_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($queue_entries)) {
        echo "<p style='color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 5px;'>Station 1 queue is currently empty.</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='padding: 8px;'>Queue ID</th>";
        echo "<th style='padding: 8px;'>Patient</th>";
        echo "<th style='padding: 8px;'>Priority</th>";
        echo "<th style='padding: 8px;'>Status</th>";
        echo "<th style='padding: 8px;'>Time In</th>";
        echo "</tr>";
        
        foreach ($queue_entries as $entry) {
            echo "<tr>";
            echo "<td style='padding: 8px;'>{$entry['id']}</td>";
            echo "<td style='padding: 8px;'>{$entry['first_name']} {$entry['last_name']}</td>";
            echo "<td style='padding: 8px;'>{$entry['priority_level']}</td>";
            echo "<td style='padding: 8px;'>{$entry['status']}</td>";
            echo "<td style='padding: 8px;'>{$entry['time_in']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<script>
    function testCheckIn(appointmentId) {
        if (confirm('Test check-in for appointment ID ' + appointmentId + '?')) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=test_checkin&appointment_id=' + appointmentId
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
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "❌ Error: " . $e->getMessage();
    echo "</div>";
}

// Handle test check-in
if (isset($_POST['action']) && $_POST['action'] === 'test_checkin') {
    try {
        $appointment_id = intval($_POST['appointment_id']);
        
        $pdo->beginTransaction();
        
        // Get appointment details
        $stmt = $pdo->prepare("
            SELECT a.*, p.first_name, p.last_name 
            FROM appointments a 
            JOIN patients p ON a.patient_id = p.patient_id 
            WHERE a.appointment_id = ? AND a.status = 'confirmed'
        ");
        $stmt->execute([$appointment_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            throw new Exception("Appointment not found or not confirmed");
        }
        
        // Update appointment status
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'checked_in', updated_at = NOW() WHERE appointment_id = ?");
        $stmt->execute([$appointment_id]);
        
        // Create visit record
        $stmt = $pdo->prepare("
            INSERT INTO visits (appointment_id, patient_id, time_in, attendance_status, created_at, updated_at) 
            VALUES (?, ?, NOW(), 'on_time', NOW(), NOW())
        ");
        $stmt->execute([$appointment_id, $appointment['patient_id']]);
        $visit_id = $pdo->lastInsertId();
        
        // Add to station_1_queue
        $username = $appointment['first_name'] . ' ' . $appointment['last_name'];
        $stmt = $pdo->prepare("
            INSERT INTO station_1_queue (
                patient_id, username, visit_id, appointment_id, service_id,
                queue_type, station_id, priority_level, status, time_in
            ) VALUES (?, ?, ?, ?, ?, 'triage', 1, 'normal', 'waiting', NOW())
        ");
        $service_id = $appointment['service_id'] ?? 1;
        $stmt->execute([$appointment['patient_id'], $username, $visit_id, $appointment_id, $service_id]);
        $queue_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        echo "✅ Success! Patient {$appointment['first_name']} {$appointment['last_name']} checked in successfully. Visit ID: {$visit_id}, Queue Entry ID: {$queue_id}";
        
    } catch (Exception $e) {
        $pdo->rollback();
        echo "❌ Error: " . $e->getMessage();
    }
    exit;
}
?>