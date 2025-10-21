<?php
/**
 * Test Vitals-Consultation Linking Logic
 */
require_once __DIR__ . '/../config/db.php';

echo "=== Vitals-Consultation Linking Test ===\n\n";

// Test 1: Find a patient with vitals and/or consultation
echo "1. Testing vitals-consultation relationship...\n";

// Get a sample patient
$patient_sql = "SELECT patient_id, username, first_name, last_name FROM patients WHERE status = 'active' LIMIT 1";
$result = mysqli_query($conn, $patient_sql);

if ($result && $patient = mysqli_fetch_assoc($result)) {
    echo "   Using patient: {$patient['first_name']} {$patient['last_name']} ({$patient['username']})\n";
    $patient_id = $patient['patient_id'];
    
    // Check for today's vitals
    $vitals_sql = "SELECT vitals_id, systolic_bp, diastolic_bp, temperature, consultation_id, recorded_at 
                   FROM vitals 
                   WHERE patient_id = ? AND DATE(recorded_at) = CURDATE()
                   ORDER BY recorded_at DESC LIMIT 1";
    $stmt = $conn->prepare($vitals_sql);
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $vitals_result = $stmt->get_result();
    $today_vitals = $vitals_result->fetch_assoc();
    
    if ($today_vitals) {
        echo "   ✅ Found today's vitals: ID {$today_vitals['vitals_id']}\n";
        echo "      BP: {$today_vitals['systolic_bp']}/{$today_vitals['diastolic_bp']}, Temp: {$today_vitals['temperature']}°C\n";
        echo "      Linked to consultation: " . ($today_vitals['consultation_id'] ? $today_vitals['consultation_id'] : 'None') . "\n";
    } else {
        echo "   ⚠️  No vitals found for today\n";
    }
    
    // Check for today's consultations
    $consultation_sql = "SELECT consultation_id, chief_complaint, vitals_id, consultation_status, consultation_date
                         FROM consultations 
                         WHERE patient_id = ? AND DATE(consultation_date) = CURDATE()
                         ORDER BY created_at DESC LIMIT 1";
    $stmt = $conn->prepare($consultation_sql);
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $consultation_result = $stmt->get_result();
    $today_consultation = $consultation_result->fetch_assoc();
    
    if ($today_consultation) {
        echo "   ✅ Found today's consultation: ID {$today_consultation['consultation_id']}\n";
        echo "      Chief complaint: " . substr($today_consultation['chief_complaint'], 0, 50) . "...\n";
        echo "      Linked to vitals: " . ($today_consultation['vitals_id'] ? $today_consultation['vitals_id'] : 'None') . "\n";
        echo "      Status: {$today_consultation['consultation_status']}\n";
    } else {
        echo "   ⚠️  No consultation found for today\n";
    }
    
    // Check linking consistency
    if ($today_vitals && $today_consultation) {
        $vitals_links_to_consultation = ($today_vitals['consultation_id'] == $today_consultation['consultation_id']);
        $consultation_links_to_vitals = ($today_consultation['vitals_id'] == $today_vitals['vitals_id']);
        
        if ($vitals_links_to_consultation && $consultation_links_to_vitals) {
            echo "   ✅ Bidirectional linking is correct\n";
        } else {
            echo "   ❌ Linking inconsistency detected:\n";
            echo "      Vitals points to consultation: " . ($today_vitals['consultation_id'] ?? 'NULL') . "\n";
            echo "      Consultation points to vitals: " . ($today_consultation['vitals_id'] ?? 'NULL') . "\n";
        }
    }
    
    echo "\n";
    
    // Test 2: Demonstrate auto-linking logic
    echo "2. Testing auto-linking logic (simulation)...\n";
    
    if ($today_vitals && !$today_consultation) {
        echo "   📝 Scenario: Patient has vitals but no consultation\n";
        echo "   💡 When consultation is created, it should auto-link to vitals ID: {$today_vitals['vitals_id']}\n";
    } elseif ($today_consultation && !$today_vitals) {
        echo "   📝 Scenario: Patient has consultation but no vitals\n";
        echo "   💡 Consultation can exist without vitals, or vitals can be added later\n";
    } elseif ($today_vitals && $today_consultation) {
        echo "   📝 Scenario: Patient has both vitals and consultation\n";
        echo "   💡 They should be properly linked together\n";
    } else {
        echo "   📝 Scenario: Patient has no records today\n";
        echo "   💡 Either vitals or consultation can be created first\n";
    }
    
} else {
    echo "   ❌ No active patients found\n";
}

echo "\n";

// Test 3: Show the linking workflow
echo "3. Proper Vitals-Consultation Workflow:\n";
echo "   Step 1: Patient search finds existing vitals for today\n";
echo "   Step 2: If vitals exist, consultation auto-links to them\n";
echo "   Step 3: If no vitals, consultation can be created standalone\n";
echo "   Step 4: When vitals are added later, they link to existing consultation\n";
echo "   Step 5: Both records maintain bidirectional references\n";

echo "\n";

// Test 4: Query to show all linked records
echo "4. Current vitals-consultation relationships:\n";
$linked_sql = "SELECT 
                    c.consultation_id,
                    c.patient_id,
                    c.vitals_id as c_vitals_id,
                    c.chief_complaint,
                    c.consultation_status,
                    v.vitals_id as v_vitals_id,
                    v.consultation_id as v_consultation_id,
                    v.systolic_bp,
                    v.diastolic_bp,
                    p.username,
                    p.first_name,
                    p.last_name
               FROM consultations c
               LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
               LEFT JOIN patients p ON c.patient_id = p.patient_id
               WHERE DATE(c.consultation_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
               ORDER BY c.consultation_date DESC
               LIMIT 5";

$result = mysqli_query($conn, $linked_sql);
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $linking_status = "❌ Not linked";
        if ($row['c_vitals_id'] && $row['v_vitals_id'] && $row['c_vitals_id'] == $row['v_vitals_id']) {
            if ($row['v_consultation_id'] == $row['consultation_id']) {
                $linking_status = "✅ Properly linked";
            } else {
                $linking_status = "⚠️ Partially linked";
            }
        } elseif (!$row['c_vitals_id']) {
            $linking_status = "⭕ No vitals";
        }
        
        echo "   Consultation {$row['consultation_id']} | {$row['first_name']} {$row['last_name']} | {$linking_status}\n";
        if ($row['v_vitals_id']) {
            echo "      Vitals: {$row['systolic_bp']}/{$row['diastolic_bp']} mmHg\n";
        }
    }
} else {
    echo "   No recent consultations found\n";
}

echo "\n=== Test Complete ===\n";
echo "This demonstrates how vitals and consultations should be linked together.\n";
?>