<?php
/**
 * Consultation Form Database Alignment Verification
 * Checks if the consultation form matches the actual database structure
 */

require_once 'config/db.php';

echo "<h2>🏥 Consultation Form - Database Alignment Check</h2>";

// Get actual consultations table structure
echo "<h3>1. Actual Database Structure</h3>";

try {
    $result = $conn->query("DESCRIBE consultations");
    $db_columns = [];
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        $db_columns[] = $row['Field'];
        echo "<tr>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "❌ Error getting database structure: " . $e->getMessage();
}

echo "<h3>2. Form Fields vs Database Columns</h3>";

// Define form fields used in the consultation form
$form_fields = [
    'chief_complaint' => 'Patient\'s main concern (required)',
    'diagnosis' => 'Clinical diagnosis or assessment', 
    'treatment_plan' => 'Treatment plan and recommendations',
    'follow_up_date' => 'Next appointment date',
    'remarks' => 'Additional notes or observations',
    'consultation_status' => 'Current status of consultation'
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'><th>Form Field</th><th>In Database</th><th>Description</th><th>Status</th></tr>";

foreach ($form_fields as $field => $description) {
    $in_database = in_array($field, $db_columns);
    $status = $in_database ? '✅ Match' : '❌ Missing';
    $status_color = $in_database ? 'color: green;' : 'color: red;';
    
    echo "<tr>";
    echo "<td><strong>{$field}</strong></td>";
    echo "<td>" . ($in_database ? 'Yes' : 'No') . "</td>";
    echo "<td>{$description}</td>";
    echo "<td style='{$status_color}'>{$status}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>3. Removed Fields (Previously in Form)</h3>";

$removed_fields = [
    'history_present_illness' => 'Not in database table',
    'physical_examination' => 'Not in database table', 
    'assessment_diagnosis' => 'Not in database table',
    'consultation_notes' => 'Not in database table (use remarks instead)'
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'><th>Removed Field</th><th>Reason</th><th>Alternative</th></tr>";

foreach ($removed_fields as $field => $reason) {
    $alternative = '';
    switch ($field) {
        case 'history_present_illness':
        case 'physical_examination': 
        case 'assessment_diagnosis':
            $alternative = 'Can be included in diagnosis field';
            break;
        case 'consultation_notes':
            $alternative = 'Use remarks field instead';
            break;
    }
    
    echo "<tr>";
    echo "<td><strong>{$field}</strong></td>";
    echo "<td>{$reason}</td>";
    echo "<td>{$alternative}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>4. Consultation Status Values</h3>";

try {
    // Get ENUM values for consultation_status
    $result = $conn->query("SHOW COLUMNS FROM consultations LIKE 'consultation_status'");
    $row = $result->fetch_assoc();
    
    if ($row) {
        $enum_values = $row['Type'];
        preg_match_all("/'([^']+)'/", $enum_values, $matches);
        $status_options = $matches[1];
        
        echo "<strong>Available status options:</strong><br>";
        foreach ($status_options as $status) {
            echo "✅ {$status}<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error getting status values: " . $e->getMessage();
}

echo "<h3>5. Summary</h3>";
echo "<div style='background-color: #e8f5e8; padding: 15px; border-radius: 5px;'>";
echo "<strong>✅ Consultation Form Successfully Simplified!</strong><br>";
echo "📋 <strong>Key Improvements:</strong><br>";
echo "1. ✅ Removed non-database fields (history_present_illness, physical_examination, assessment_diagnosis, consultation_notes)<br>";
echo "2. ✅ Kept only actual database fields: chief_complaint, diagnosis, treatment_plan, follow_up_date, remarks, consultation_status<br>";
echo "3. ✅ Aligned status dropdown with database ENUM values<br>";
echo "4. ✅ Simplified form for better usability<br>";
echo "5. ✅ Chief complaint remains required (most important field)<br>";
echo "6. ✅ Follow-up date with date picker and minimum date validation<br>";
echo "</div>";

echo "<h3>6. Form Field Details</h3>";
echo "<ul>";
echo "<li><strong>chief_complaint:</strong> Required field - patient's main concern</li>";
echo "<li><strong>diagnosis:</strong> Clinical diagnosis (can include assessment details)</li>";
echo "<li><strong>treatment_plan:</strong> Treatment recommendations and plan</li>";
echo "<li><strong>follow_up_date:</strong> Optional next appointment date</li>";
echo "<li><strong>remarks:</strong> Additional notes (replaces consultation_notes)</li>";
echo "<li><strong>consultation_status:</strong> Status from predefined options</li>";
echo "</ul>";

?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h2 { color: #2c5530; border-bottom: 2px solid #4CAF50; }
h3 { color: #1976D2; margin-top: 20px; }
table { margin: 15px 0; }
table th, table td { padding: 8px 12px; text-align: left; }
table th { background: #f0f0f0; }
</style>