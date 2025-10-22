<?php
/**
 * Test Index Page Query for New Consultations
 */

require_once '../../config/db.php';

echo "<h2>🔍 Testing Index Page Consultation Queries</h2>";

echo "<h3>1. Testing Basic Consultation Query</h3>";

try {
    // Test the basic consultation query
    $sql = "
        SELECT c.consultation_id as encounter_id, c.patient_id, c.vitals_id, c.chief_complaint, 
               c.diagnosis, c.consultation_status as status, 
               c.consultation_date, c.created_at, c.updated_at,
               p.first_name, p.last_name, p.username as patient_id_display,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age, p.sex,
               d.first_name as doctor_first_name, d.last_name as doctor_last_name,
               b.barangay_name, dist.district_name,
               'consultation' as visit_type, 'clinical_consultation' as visit_purpose,
               (SELECT COUNT(*) FROM prescriptions WHERE consultation_id = c.consultation_id) as prescription_count,
               (SELECT COUNT(*) FROM lab_orders WHERE consultation_id = c.consultation_id) as lab_test_count,
               (SELECT COUNT(*) FROM referrals WHERE consultation_id = c.consultation_id) as referral_count,
               -- Get vitals information if linked
               CONCAT(v.systolic_bp, '/', v.diastolic_bp) as blood_pressure, 
               v.heart_rate, v.temperature, v.weight, v.height
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
        LEFT JOIN employees d ON c.consulted_by = d.employee_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN districts dist ON b.district_id = dist.district_id
        WHERE 1=1
        ORDER BY c.consultation_date DESC, c.created_at DESC
        LIMIT 10
    ";
    
    $result = $conn->query($sql);
    
    if ($result) {
        $consultations = $result->fetch_all(MYSQLI_ASSOC);
        
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724;'>";
        echo "<strong>✅ Query Successful!</strong><br>";
        echo "Found " . count($consultations) . " consultations<br>";
        echo "</div>";
        
        if (!empty($consultations)) {
            echo "<h4>📋 Recent Consultations:</h4>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
            echo "<tr style='background: #f0f0f0;'>";
            echo "<th>ID</th><th>Date</th><th>Patient</th><th>Doctor</th><th>Chief Complaint</th><th>Diagnosis</th><th>Status</th><th>Vitals</th>";
            echo "</tr>";
            
            foreach ($consultations as $c) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($c['encounter_id']) . "</td>";
                echo "<td>" . date('M j, Y g:i A', strtotime($c['consultation_date'])) . "</td>";
                echo "<td>" . htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) . " (" . htmlspecialchars($c['patient_id_display']) . ")</td>";
                echo "<td>" . ($c['doctor_first_name'] ? 'Dr. ' . htmlspecialchars($c['doctor_first_name'] . ' ' . $c['doctor_last_name']) : 'Not assigned') . "</td>";
                echo "<td>" . htmlspecialchars(substr($c['chief_complaint'], 0, 30)) . (strlen($c['chief_complaint']) > 30 ? '...' : '') . "</td>";
                echo "<td>" . ($c['diagnosis'] ? htmlspecialchars(substr($c['diagnosis'], 0, 30)) : '<em>Pending</em>') . "</td>";
                echo "<td>" . htmlspecialchars($c['status']) . "</td>";
                echo "<td>" . ($c['vitals_id'] ? "ID: " . $c['vitals_id'] . ($c['blood_pressure'] ? "<br>BP: " . htmlspecialchars($c['blood_pressure']) : '') : 'No vitals') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; color: #856404;'>";
            echo "<strong>⚠️ No consultations found</strong><br>";
            echo "This might be normal if no consultations have been created yet.";
            echo "</div>";
        }
        
    } else {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
        echo "<strong>❌ Query Failed</strong><br>";
        echo "Error: " . htmlspecialchars($conn->error);
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>❌ Exception</strong><br>";
    echo "Error: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "<h3>2. Testing Today's Consultations</h3>";

try {
    $sql = "
        SELECT COUNT(*) as total,
               SUM(CASE WHEN consultation_status = 'completed' THEN 1 ELSE 0 END) as completed,
               SUM(CASE WHEN consultation_status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
               SUM(CASE WHEN consultation_status = 'awaiting_followup' THEN 1 ELSE 0 END) as follow_ups
        FROM consultations 
        WHERE DATE(consultation_date) = CURDATE()
    ";
    
    $result = $conn->query($sql);
    $stats = $result->fetch_assoc();
    
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; color: #1976d2;'>";
    echo "<strong>📊 Today's Statistics:</strong><br>";
    echo "Total: " . $stats['total'] . "<br>";
    echo "Completed: " . $stats['completed'] . "<br>";
    echo "Ongoing: " . $stats['ongoing'] . "<br>";
    echo "Follow-ups: " . $stats['follow_ups'];
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>❌ Stats Query Failed</strong><br>";
    echo "Error: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "<h3>3. Testing Database Schema</h3>";

try {
    // Check consultations table structure
    $result = $conn->query("DESCRIBE consultations");
    $columns = $result->fetch_all(MYSQLI_ASSOC);
    
    echo "<h4>📋 Consultations Table Structure:</h4>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
    echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if key columns exist
    $key_columns = ['consultation_id', 'patient_id', 'vitals_id', 'chief_complaint', 'diagnosis', 'consultation_status', 'consulted_by', 'attending_employee_id'];
    $existing_columns = array_column($columns, 'Field');
    
    echo "<h4>🔑 Key Columns Check:</h4>";
    foreach ($key_columns as $col) {
        $exists = in_array($col, $existing_columns);
        $status = $exists ? '✅' : '❌';
        $color = $exists ? '#155724' : '#721c24';
        echo "<div style='color: $color;'>$status $col</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>❌ Schema Check Failed</strong><br>";
    echo "Error: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2, h3, h4 { color: #2c5530; }
table { width: 100%; }
table th, table td { padding: 8px; text-align: left; border: 1px solid #ddd; }
table th { background: #f0f0f0; font-weight: bold; }
</style>