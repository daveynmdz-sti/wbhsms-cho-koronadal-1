<?php
/**
 * API Endpoint: Get Queue Counts
 * Returns current queue counts for all triage stations
 * Used by check-in dashboard for manual station selection
 */

// Start output buffering to prevent header issues
ob_start();

// Include necessary configurations
$root_path = dirname(__DIR__);
require_once $root_path . '/config/production_security.php';
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Set JSON content type
header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    if (ob_get_level()) ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit;
}

try {
    // Get queue counts for all triage stations
    $queue_counts = [];
    
    // Check stations 1, 2, 3 (triage stations)
    for ($station_id = 1; $station_id <= 3; $station_id++) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM station_{$station_id}_queue 
            WHERE status IN ('waiting', 'in_progress') 
            AND DATE(time_in) = CURDATE()
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $queue_counts["station_{$station_id}"] = (int)$result['count'];
    }
    
    // Get additional statistics
    $total_waiting = array_sum($queue_counts);
    $optimal_station = 1;
    $min_queue = $queue_counts['station_1'];
    
    // Find station with shortest queue
    for ($station_id = 2; $station_id <= 3; $station_id++) {
        if ($queue_counts["station_{$station_id}"] < $min_queue) {
            $min_queue = $queue_counts["station_{$station_id}"];
            $optimal_station = $station_id;
        }
    }
    
    // Clean output buffer before sending JSON
    if (ob_get_level()) ob_end_clean();
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'queues' => $queue_counts,
        'statistics' => [
            'total_waiting' => $total_waiting,
            'optimal_station' => $optimal_station,
            'optimal_queue_count' => $min_queue
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Clean output buffer before sending error
    if (ob_get_level()) ob_end_clean();
    
    // Log the error
    error_log('Queue counts API error: ' . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve queue information',
        'error' => $e->getMessage()
    ]);
}
?>