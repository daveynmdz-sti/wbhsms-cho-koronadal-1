<?php
/**
 * Quick Vitals-Consultation Link Verification
 * Run this to verify that consultations are properly linked to vitals
 */

require_once '../../config/db.php';

echo "<h2>🔗 Vitals-Consultation Linking Verification</h2>";

try {
    // Query consultations with vitals links
    $stmt = $conn->prepare("
        SELECT 
            c.consultation_id,
            c.patient_id,
            p.first_name,
            p.last_name,
            c.vitals_id,
            c.chief_complaint,
            c.consultation_date,
            v.systolic_bp,
            v.diastolic_bp,
            v.heart_rate,
            v.temperature,
            v.recorded_at as vitals_recorded_at
        FROM consultations c
        LEFT JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
        WHERE DATE(c.consultation_date) = CURDATE()
        ORDER BY c.consultation_date DESC
        LIMIT 10
    ");
    
    $stmt->execute();
    $consultations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($consultations)) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
        echo "<strong>ℹ️ No consultations found for today.</strong><br>";
        echo "Create a consultation to test the vitals linking functionality.";
        echo "</div>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>Consultation ID</th><th>Patient</th><th>Vitals ID</th><th>Vitals Link Status</th>";
        echo "<th>Chief Complaint</th><th>Consultation Time</th><th>Vitals Time</th>";
        echo "</tr>";
        
        foreach ($consultations as $consultation) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($consultation['consultation_id']) . "</td>";
            echo "<td>" . htmlspecialchars($consultation['patient_id'] . " - " . $consultation['first_name'] . " " . $consultation['last_name']) . "</td>";
            echo "<td>" . ($consultation['vitals_id'] ? htmlspecialchars($consultation['vitals_id']) : '<em>NULL</em>') . "</td>";
            
            if ($consultation['vitals_id']) {
                echo "<td style='color: #28a745; font-weight: bold;'>✅ LINKED</td>";
            } else {
                echo "<td style='color: #dc3545; font-weight: bold;'>❌ NO LINK</td>";
            }
            
            echo "<td>" . htmlspecialchars(substr($consultation['chief_complaint'], 0, 50)) . "...</td>";
            echo "<td>" . date('g:i A', strtotime($consultation['consultation_date'])) . "</td>";
            echo "<td>" . ($consultation['vitals_recorded_at'] ? date('g:i A', strtotime($consultation['vitals_recorded_at'])) : '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        $linked_count = count(array_filter($consultations, function($c) { return $c['vitals_id']; }));
        $total_count = count($consultations);
        
        echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
        echo "<strong>📊 Summary:</strong><br>";
        echo "Today's consultations: $total_count<br>";
        echo "With vitals linked: $linked_count<br>";
        echo "Without vitals: " . ($total_count - $linked_count);
        echo "</div>";
    }
    
    // Show today's vitals records
    echo "<h3>🩺 Today's Vitals Records</h3>";
    
    $stmt = $conn->prepare("
        SELECT 
            v.vitals_id,
            v.patient_id,
            p.first_name,
            p.last_name,
            v.systolic_bp,
            v.diastolic_bp,
            v.heart_rate,
            v.temperature,
            v.recorded_at,
            e.first_name as recorded_by_name,
            e.last_name as recorded_by_lastname
        FROM vitals v
        LEFT JOIN patients p ON v.patient_id = p.patient_id
        LEFT JOIN employees e ON v.recorded_by = e.employee_id
        WHERE DATE(v.recorded_at) = CURDATE()
        ORDER BY v.recorded_at DESC
        LIMIT 10
    ");
    
    $stmt->execute();
    $vitals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($vitals)) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
        echo "<strong>ℹ️ No vitals recorded today.</strong><br>";
        echo "Record some vitals to test the linking functionality.";
        echo "</div>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 15px 0;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>Vitals ID</th><th>Patient</th><th>BP</th><th>HR</th><th>Temp</th>";
        echo "<th>Recorded By</th><th>Time</th>";
        echo "</tr>";
        
        foreach ($vitals as $vital) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($vital['vitals_id']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($vital['patient_id'] . " - " . $vital['first_name'] . " " . $vital['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($vital['systolic_bp'] . "/" . $vital['diastolic_bp']) . "</td>";
            echo "<td>" . htmlspecialchars($vital['heart_rate']) . "</td>";
            echo "<td>" . htmlspecialchars($vital['temperature']) . "°C</td>";
            echo "<td>" . htmlspecialchars($vital['recorded_by_name'] . " " . $vital['recorded_by_lastname']) . "</td>";
            echo "<td>" . date('g:i A', strtotime($vital['recorded_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 15px 0; color: #721c24;'>";
    echo "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "<hr>";
echo "<p><strong>💡 How to Test:</strong></p>";
echo "<ol>";
echo "<li>Go to: <a href='../clinical-encounter-management/new_consultation_standalone.php'>New Consultation</a></li>";
echo "<li>Search and select a patient (e.g., David Animo Diaz)</li>";
echo "<li>Record vitals and save</li>";
echo "<li>Create a consultation with chief complaint</li>";
echo "<li>Come back here to verify the link was created</li>";
echo "</ol>";

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2, h3 { color: #2c5530; }
table { width: 100%; }
table th, table td { padding: 8px; text-align: left; }
table th { background: #f0f0f0; font-weight: bold; }
</style>