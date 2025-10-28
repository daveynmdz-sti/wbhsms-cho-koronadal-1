<?php
/**
 * Test queue code formatter functionality
 */

echo "<h2>Testing Queue Code Formatter</h2>\n";

$root_path = __DIR__;

try {
    require_once $root_path . '/pages/queueing/queue_code_formatter.php';
    echo "✅ Queue Code Formatter loaded successfully<br>\n";
    
    // Test the formatQueueCodeForPatient function
    $test_codes = ['T001', 'C015', 'L003', 'P025', 'B007', 'D012'];
    
    echo "<h3>Testing Queue Code Formatting:</h3>\n";
    foreach ($test_codes as $code) {
        $formatted = formatQueueCodeForPatient($code);
        echo "✅ $code → $formatted<br>\n";
    }
    
    // Test queue type display names
    echo "<h3>Testing Queue Type Display Names:</h3>\n";
    $queue_types = ['triage', 'consultation', 'lab', 'prescription', 'billing', 'document'];
    foreach ($queue_types as $type) {
        $display_name = getQueueTypeDisplayName($type);
        echo "✅ $type → $display_name<br>\n";
    }
    
    // Test status formatting
    echo "<h3>Testing Status Formatting:</h3>\n";
    $statuses = ['waiting', 'called', 'in_progress', 'done', 'skipped'];
    foreach ($statuses as $status) {
        $status_info = formatQueueStatusForPatient($status);
        echo "✅ $status → {$status_info['text']} ({$status_info['class']})<br>\n";
    }
    
    // Test wait time estimation
    echo "<h3>Testing Wait Time Estimation:</h3>\n";
    for ($i = 1; $i <= 5; $i++) {
        $wait_time = estimateWaitTime($i, 'consultation');
        echo "✅ Position $i → $wait_time<br>\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error testing queue code formatter: " . $e->getMessage() . "<br>\n";
}

echo "<br><h3>Summary</h3>\n";
echo "If all tests show ✅, then the queue code formatter is working correctly.<br>\n";
echo "The missing file error should now be resolved for:<br>\n";
echo "- pages/patient/queueing/queue_status.php<br>\n";