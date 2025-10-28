<?php
/**
 * Test script to verify queue management service functionality
 * Run this to check if the production errors are resolved
 */

// Set root path
$root_path = __DIR__;

echo "<h2>Testing Queue Management Service Dependencies</h2>\n";

// Test database connection
try {
    require_once $root_path . '/config/db.php';
    echo "✅ Database connection loaded successfully<br>\n";
    
    if (isset($pdo) && $pdo) {
        echo "✅ PDO connection is available<br>\n";
    } else {
        echo "❌ PDO connection not available<br>\n";
    }
} catch (Exception $e) {
    echo "❌ Database connection error: " . $e->getMessage() . "<br>\n";
}

// Test queue management service
try {
    require_once $root_path . '/utils/queue_management_service.php';
    echo "✅ Queue Management Service loaded successfully<br>\n";
    
    if (isset($pdo) && $pdo) {
        $queueService = new QueueManagementService($pdo);
        echo "✅ QueueManagementService instantiated successfully<br>\n";
        
        // Test some basic methods
        $employees = $queueService->getActiveEmployees();
        echo "✅ getActiveEmployees() method works - returned " . count($employees) . " employees<br>\n";
        
        $stations = $queueService->getAllStationsWithAssignments();
        echo "✅ getAllStationsWithAssignments() method works - returned " . count($stations) . " stations<br>\n";
        
        // Test the missing method that was causing the error
        $assignment = $queueService->getActiveStationByEmployee(1);
        echo "✅ getActiveStationByEmployee() method works - " . ($assignment ? "found assignment" : "no assignment found") . "<br>\n";
        
        // Test the original method
        $assignment2 = $queueService->getEmployeeStationAssignment(1);
        echo "✅ getEmployeeStationAssignment() method works - " . ($assignment2 ? "found assignment" : "no assignment found") . "<br>\n";
        
    } else {
        echo "❌ Cannot test QueueManagementService without PDO connection<br>\n";
    }
} catch (Exception $e) {
    echo "❌ Queue Management Service error: " . $e->getMessage() . "<br>\n";
}

// Test queue code formatter
try {
    require_once $root_path . '/pages/queueing/queue_code_formatter.php';
    echo "✅ Queue Code Formatter loaded successfully<br>\n";
    
    // Test the main function
    $test_result = formatQueueCodeForPatient('T001');
    echo "✅ formatQueueCodeForPatient() works - T001 → $test_result<br>\n";
} catch (Exception $e) {
    echo "❌ Queue Code Formatter error: " . $e->getMessage() . "<br>\n";
}

// Test other utility dependencies
$utility_files = [
    'automatic_status_updater.php',
    'patient_flow_validator.php',
    'qr_code_generator.php',
    'referral_permissions.php',
    'LabOrderStatusManager.php',
    'StandardEmailTemplate.php',
    'appointment_logger.php'
];

echo "<br><h3>Testing Other Utility Files</h3>\n";
foreach ($utility_files as $file) {
    try {
        require_once $root_path . '/utils/' . $file;
        echo "✅ $file loaded successfully<br>\n";
    } catch (Exception $e) {
        echo "❌ $file error: " . $e->getMessage() . "<br>\n";
    }
}

echo "<br><h3>Summary</h3>\n";
echo "If all items above show ✅, then the production errors should be resolved.<br>\n";
echo "You can now test the pages that were failing:<br>\n";
echo "- pages/patient/queueing/queue_status.php<br>\n";
echo "- pages/patient/appointment/appointments.php<br>\n";
echo "- pages/patient/appointment/submit_appointment.php<br>\n";
echo "- pages/management/records_officer/dashboard.php<br>\n";
echo "- Any other management dashboards using staff assignments<br>\n";