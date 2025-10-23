<?php
// Test the updated vitals display
try {
    $pdo = new PDO('mysql:host=localhost;dbname=wbhsms_database', 'root', '');
    
    echo "Testing Vitals Display Updates:\n";
    echo str_repeat('=', 50) . "\n";
    
    // Get a patient who has vitals data
    $stmt = $pdo->query("SELECT DISTINCT patient_id FROM vitals LIMIT 5");
    $patients_with_vitals = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Patients with vitals data: " . implode(', ', $patients_with_vitals) . "\n\n";
    
    // Test with first patient
    if (!empty($patients_with_vitals)) {
        $test_patient_id = $patients_with_vitals[0];
        echo "Testing with patient ID: $test_patient_id\n";
        
        // Fetch latest vitals (using updated query)
        $stmt = $pdo->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $stmt->execute([$test_patient_id]);
        $latest_vitals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest_vitals) {
            echo "Latest vitals found:\n";
            echo "- Recorded at: " . $latest_vitals['recorded_at'] . "\n";
            echo "- Height: " . ($latest_vitals['height'] ?? '-') . " cm\n";
            echo "- Weight: " . ($latest_vitals['weight'] ?? '-') . " kg\n";
            echo "- Blood Pressure: " . ($latest_vitals['systolic_bp'] ?? '-') . "/" . ($latest_vitals['diastolic_bp'] ?? '-') . " mmHg\n";
            echo "- Heart Rate: " . ($latest_vitals['heart_rate'] ?? '-') . " bpm\n";
            echo "- Temperature: " . ($latest_vitals['temperature'] ?? '-') . " °C\n";
            echo "- Respiratory Rate: " . ($latest_vitals['respiratory_rate'] ?? '-') . " bpm\n";
            echo "- BMI: " . ($latest_vitals['bmi'] ?? '-') . "\n";
            
            // Test alerts logic
            echo "\nVital Signs Alerts:\n";
            $alerts = [];
            $systolic_bp = intval($latest_vitals['systolic_bp'] ?? 0);
            $diastolic_bp = intval($latest_vitals['diastolic_bp'] ?? 0);
            $temp = floatval($latest_vitals['temperature'] ?? 0);
            $hr = intval($latest_vitals['heart_rate'] ?? 0);

            if ($temp > 38.0) {
                $alerts[] = 'HIGH TEMPERATURE';
            } elseif ($temp < 35.0 && $temp > 0) {
                $alerts[] = 'LOW TEMPERATURE';
            }

            if ($hr > 100) {
                $alerts[] = 'HIGH HEART RATE';
            } elseif ($hr < 60 && $hr > 0) {
                $alerts[] = 'LOW HEART RATE';
            }

            if ($systolic_bp >= 140 || $diastolic_bp >= 90) {
                $alerts[] = 'HIGH BLOOD PRESSURE';
            } elseif ($systolic_bp < 90 || $diastolic_bp < 60) {
                $alerts[] = 'LOW BLOOD PRESSURE';
            }
            
            if (!empty($alerts)) {
                echo "- " . implode("\n- ", $alerts) . "\n";
            } else {
                echo "- No alerts (vitals within normal range)\n";
            }
            
        } else {
            echo "No vitals found for this patient.\n";
        }
    } else {
        echo "No patients with vitals data found.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>