<?php
/**
 * Create test appointment for 2025-11-10 if none exists
 */

require_once 'config/db.php';

$test_date = '2025-11-10';
$test_time = '09:00:00';
$facility_id = 1;

echo "<h2>Test Appointment Creation</h2>";

// Check if there are any appointments for this date
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM appointments WHERE DATE(scheduled_date) = ?");
$stmt->execute([$test_date]);
$count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

echo "Existing appointments for $test_date: $count<br>";

if ($count == 0) {
    echo "<h3>Creating test appointment...</h3>";
    
    // First, check if we have any patients
    $patient_stmt = $pdo->query("SELECT patient_id, username, first_name, last_name FROM patients LIMIT 1");
    $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo "❌ No patients found in database. Need to create a patient first.<br>";
        
        // Create a test patient
        $insert_patient = $pdo->prepare("
            INSERT INTO patients (username, first_name, last_name, contact_number, date_of_birth, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $result = $insert_patient->execute(['TEST001', 'Test', 'Patient', '09171234567', '1990-01-01']);
        
        if ($result) {
            $patient_id = $pdo->lastInsertId();
            echo "✅ Created test patient with ID: $patient_id<br>";
        } else {
            echo "❌ Failed to create test patient<br>";
            exit;
        }
    } else {
        $patient_id = $patient['patient_id'];
        echo "✅ Using existing patient: {$patient['first_name']} {$patient['last_name']} (ID: $patient_id)<br>";
    }
    
    // Check for services
    $service_stmt = $pdo->query("SELECT service_id, service_name FROM services LIMIT 1");
    $service = $service_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        echo "❌ No services found. Creating test service...<br>";
        $insert_service = $pdo->prepare("
            INSERT INTO services (service_name, description, created_at) 
            VALUES (?, ?, NOW())
        ");
        $result = $insert_service->execute(['General Consultation', 'General medical consultation']);
        
        if ($result) {
            $service_id = $pdo->lastInsertId();
            echo "✅ Created test service with ID: $service_id<br>";
        } else {
            echo "❌ Failed to create test service<br>";
            exit;
        }
    } else {
        $service_id = $service['service_id'];
        echo "✅ Using existing service: {$service['service_name']} (ID: $service_id)<br>";
    }
    
    // Create test appointment
    $verification_code = strtoupper(substr(md5(uniqid($patient_id . time(), true)), 0, 8));
    
    $insert_appointment = $pdo->prepare("
        INSERT INTO appointments (
            patient_id, facility_id, service_id, 
            scheduled_date, scheduled_time, status, verification_code, created_at
        ) VALUES (?, ?, ?, ?, ?, 'confirmed', ?, NOW())
    ");
    
    $result = $insert_appointment->execute([
        $patient_id, $facility_id, $service_id,
        $test_date, $test_time, $verification_code
    ]);
    
    if ($result) {
        $appointment_id = $pdo->lastInsertId();
        echo "✅ Created test appointment with ID: $appointment_id<br>";
        echo "Details: Date $test_date at $test_time, Status: confirmed<br>";
        echo "Verification code: $verification_code<br>";
        
        // Generate QR code for this appointment
        require_once 'utils/qr_code_generator.php';
        $qr_result = QRCodeGenerator::generateAndSaveQR(
            $appointment_id,
            [
                'patient_id' => $patient_id,
                'scheduled_date' => $test_date,
                'scheduled_time' => $test_time,
                'facility_id' => $facility_id,
                'service_id' => $service_id
            ],
            $pdo
        );
        
        if ($qr_result['success']) {
            echo "✅ QR code generated successfully<br>";
        } else {
            echo "⚠️ QR code generation failed: " . $qr_result['error'] . "<br>";
        }
        
    } else {
        echo "❌ Failed to create test appointment<br>";
    }
    
} else {
    echo "✅ Appointments already exist for $test_date<br>";
}

echo "<h3>Current Appointments for $test_date:</h3>";
$stmt = $pdo->prepare("
    SELECT 
        a.appointment_id,
        a.patient_id,
        a.scheduled_date,
        a.scheduled_time,
        a.status,
        p.username,
        p.first_name,
        p.last_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.patient_id
    WHERE DATE(a.scheduled_date) = ?
    ORDER BY a.scheduled_time ASC
");
$stmt->execute([$test_date]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($appointments)) {
    echo "No appointments found<br>";
} else {
    echo "<table border='1' style='border-collapse: collapse; margin: 10px;'>";
    echo "<tr><th>ID</th><th>Patient</th><th>Date</th><th>Time</th><th>Status</th></tr>";
    foreach ($appointments as $appointment) {
        $patient_name = trim(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? ''));
        if (empty($patient_name)) $patient_name = 'Patient ID: ' . $appointment['patient_id'];
        
        echo "<tr>";
        echo "<td>" . $appointment['appointment_id'] . "</td>";
        echo "<td>" . $patient_name . "</td>";
        echo "<td>" . $appointment['scheduled_date'] . "</td>";
        echo "<td>" . $appointment['scheduled_time'] . "</td>";
        echo "<td>" . $appointment['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<br><a href='/pages/queueing/checkin_dashboard.php?date=$test_date'>→ Go to Check-in Dashboard for $test_date</a>";

?>