<?php
/**
 * Debug Station Assignment for EMP00002
 * Quick diagnostic to check station assignment and station details
 */

require_once '../config/db.php';

echo "=== Station Assignment Debug for EMP00002 ===\n\n";

// Check employee ID for EMP00002
$stmt = $conn->prepare("SELECT employee_id, employee_number, first_name, last_name FROM employees WHERE employee_number = 'EMP00002'");
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();

if (!$employee) {
    echo "❌ Employee EMP00002 not found!\n";
    exit;
}

$employee_id = $employee['employee_id'];
echo "✅ Employee Found:\n";
echo "   ID: {$employee['employee_id']}\n";
echo "   Number: {$employee['employee_number']}\n";
echo "   Name: {$employee['first_name']} {$employee['last_name']}\n\n";

// Check station assignment
$stmt = $conn->prepare("
    SELECT sch.*, s.station_name, s.station_type, s.is_active as station_active
    FROM staff_assignments sch 
    JOIN stations s ON sch.station_id = s.station_id 
    WHERE sch.employee_id = ?
    ORDER BY sch.assigned_at DESC
");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "=== All Station Assignments ===\n";
if (empty($assignments)) {
    echo "❌ No station assignments found!\n";
} else {
    foreach ($assignments as $i => $assignment) {
        echo "Assignment #" . ($i + 1) . ":\n";
        echo "   Schedule ID: {$assignment['schedule_id']}\n";
        echo "   Station ID: {$assignment['station_id']}\n";
        echo "   Station Name: {$assignment['station_name']}\n";
        echo "   Station Type: {$assignment['station_type']}\n";
        echo "   Station Active: " . ($assignment['station_active'] ? 'Yes' : 'No') . "\n";
        echo "   Assignment Active: " . ($assignment['is_active'] ? 'Yes' : 'No') . "\n";
        echo "   Start Date: {$assignment['start_date']}\n";
        echo "   End Date: " . ($assignment['end_date'] ?: 'No end date') . "\n";
        echo "   Assigned At: {$assignment['assigned_at']}\n";
        
        // Check if this assignment is currently valid
        $start_valid = $assignment['start_date'] <= date('Y-m-d');
        $end_valid = !$assignment['end_date'] || $assignment['end_date'] >= date('Y-m-d');
        $is_current = $assignment['is_active'] && $start_valid && $end_valid;
        
        echo "   Current Valid: " . ($is_current ? '✅ YES' : '❌ NO') . "\n";
        if (!$is_current) {
            if (!$assignment['is_active']) echo "     - Reason: Assignment not active\n";
            if (!$start_valid) echo "     - Reason: Start date is in future\n";
            if (!$end_valid) echo "     - Reason: End date has passed\n";
        }
        echo "\n";
    }
}

// Check what the sidebar query would return
echo "=== Sidebar Query Test ===\n";
$stmt = $conn->prepare("
    SELECT s.station_name, s.station_type, sch.schedule_id
    FROM staff_assignments sch 
    JOIN stations s ON sch.station_id = s.station_id 
    WHERE sch.employee_id = ? 
    AND sch.is_active = 1
    AND (sch.start_date <= CURDATE() AND (sch.end_date IS NULL OR sch.end_date >= CURDATE()))
    AND s.station_type IN ('consultation', 'triage', 'checkin')
    ORDER BY sch.assigned_at DESC 
    LIMIT 1
");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$sidebar_result = $stmt->get_result()->fetch_assoc();

if ($sidebar_result) {
    echo "✅ Sidebar would show:\n";
    echo "   Station: {$sidebar_result['station_name']}\n";
    echo "   Type: {$sidebar_result['station_type']}\n";
    echo "   Queue Management: ENABLED\n";
} else {
    echo "❌ Sidebar would show: Queue Management DISABLED\n";
    echo "   Trying without station type filter...\n";
    
    $stmt = $conn->prepare("
        SELECT s.station_name, s.station_type, sch.schedule_id
        FROM staff_assignments sch 
        JOIN stations s ON sch.station_id = s.station_id 
        WHERE sch.employee_id = ? 
        AND sch.is_active = 1
        AND (sch.start_date <= CURDATE() AND (sch.end_date IS NULL OR sch.end_date >= CURDATE()))
        ORDER BY sch.assigned_at DESC 
        LIMIT 1
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $any_result = $stmt->get_result()->fetch_assoc();
    
    if ($any_result) {
        echo "   📋 Found assignment to: {$any_result['station_name']} (Type: {$any_result['station_type']})\n";
        echo "   🔍 Issue: Station type '{$any_result['station_type']}' not in allowed types\n";
    }
}

echo "\n=== All Available Stations ===\n";
$stmt = $conn->prepare("SELECT station_id, station_name, station_type, is_active FROM stations ORDER BY station_id");
$stmt->execute();
$stations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($stations as $station) {
    $status = $station['is_active'] ? '✅' : '❌';
    echo "{$status} ID {$station['station_id']}: {$station['station_name']} ({$station['station_type']})\n";
}

echo "\n=== Recommendations ===\n";
if ($sidebar_result) {
    echo "✅ Configuration looks good! EMP00002 should have queue management access.\n";
} else {
    echo "🔧 To enable queue management for EMP00002:\n";
    echo "   1. Ensure station ID 8 has station_type = 'consultation'\n";
    echo "   2. OR update the sidebar to allow the current station type\n";
    echo "   3. Verify the assignment dates are current\n";
}
?>