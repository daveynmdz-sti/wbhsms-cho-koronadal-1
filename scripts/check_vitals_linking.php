<?php
/**
 * Simple test to check vitals-consultation requirements
 */
require_once __DIR__ . '/../config/db.php';

echo "=== Checking Vitals-Consultation Linking Requirements ===\n\n";

// Check consultations table structure
echo "1. Checking consultations table...\n";
$result = mysqli_query($conn, "DESCRIBE consultations");
$consultation_columns = [];
while ($row = mysqli_fetch_assoc($result)) {
    $consultation_columns[] = $row['Field'];
}

$required_consultation_columns = ['vitals_id', 'consulted_by', 'chief_complaint'];
$missing_consultation = array_diff($required_consultation_columns, $consultation_columns);

if (empty($missing_consultation)) {
    echo "   ✅ Consultations table has required columns\n";
} else {
    echo "   ❌ Missing columns in consultations: " . implode(', ', $missing_consultation) . "\n";
}

// Check vitals table structure  
echo "\n2. Checking vitals table...\n";
$result = mysqli_query($conn, "DESCRIBE vitals");
$vitals_columns = [];
while ($row = mysqli_fetch_assoc($result)) {
    $vitals_columns[] = $row['Field'];
}

if (in_array('consultation_id', $vitals_columns)) {
    echo "   ✅ Vitals table has consultation_id column for linking\n";
} else {
    echo "   ❌ Vitals table missing consultation_id column\n";
}

// Test basic queries
echo "\n3. Testing basic queries...\n";

// Test vitals query
$vitals_test = mysqli_query($conn, "SELECT vitals_id, patient_id FROM vitals LIMIT 1");
if ($vitals_test && mysqli_num_rows($vitals_test) > 0) {
    echo "   ✅ Vitals table accessible\n";
    $sample_vitals = mysqli_fetch_assoc($vitals_test);
    echo "   Sample vitals ID: {$sample_vitals['vitals_id']} for patient: {$sample_vitals['patient_id']}\n";
} else {
    echo "   ⚠️  No vitals records found\n";
}

// Test consultations query (using existing columns only)
$consultation_test = mysqli_query($conn, "SELECT consultation_id, patient_id FROM consultations LIMIT 1");
if ($consultation_test && mysqli_num_rows($consultation_test) > 0) {
    echo "   ✅ Consultations table accessible\n";
    $sample_consultation = mysqli_fetch_assoc($consultation_test);
    echo "   Sample consultation ID: {$sample_consultation['consultation_id']} for patient: {$sample_consultation['patient_id']}\n";
} else {
    echo "   ⚠️  No consultation records found\n";
}

echo "\n4. Why linking is important:\n";
echo "   • Vitals provide clinical context for consultations\n";
echo "   • Doctors need vital signs when making diagnoses\n";
echo "   • Audit trail: which vitals were used for which consultation\n";
echo "   • Prevents duplicate vitals entry for same consultation\n";
echo "   • Enables comprehensive clinical reporting\n";

echo "\n5. Linking workflow:\n";
echo "   Scenario A: Nurse enters vitals first\n";
echo "   → Doctor creates consultation → automatically links to today's vitals\n";
echo "   \n";
echo "   Scenario B: Doctor creates consultation first\n"; 
echo "   → Nurse adds vitals → automatically links to today's consultation\n";
echo "   \n";
echo "   Scenario C: Both created same day\n";
echo "   → System maintains bidirectional links (vitals ↔ consultation)\n";

echo "\n=== CONCLUSION ===\n";
if (empty($missing_consultation) && in_array('consultation_id', $vitals_columns)) {
    echo "✅ Database is ready for vitals-consultation linking\n";
} else {
    echo "❌ Database needs updates before linking can work properly\n";
    echo "📋 Run: essential_consultation_updates.sql\n";
}
?>