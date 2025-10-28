<?php
/**
 * Queue Code Formatter Helper
 * Provides utilities for formatting queue codes for patient display
 */

/**
 * Format queue code for patient-friendly display
 * Converts technical queue codes (T001, C015, etc.) to patient-friendly format (HHM-###)
 * 
 * @param string $queue_code The technical queue code from database (e.g., "T001", "C015")
 * @return string Formatted patient-friendly queue code (e.g., "10A-001", "02P-015")
 */
function formatQueueCodeForPatient($queue_code) {
    if (empty($queue_code)) {
        return '';
    }
    
    // Extract prefix and number from queue code (e.g., "T001" -> "T" and "001")
    if (preg_match('/^([A-Z])(\d+)$/', $queue_code, $matches)) {
        $prefix = $matches[1];
        $number = $matches[2];
        
        // Get current hour for time-based prefix
        $current_hour = date('H');
        $time_suffix = $current_hour < 12 ? 'A' : 'P';
        $hour_display = date('h'); // 12-hour format without leading zero
        
        // Create patient-friendly format: [Hour][A/P]M-[Number]
        return $hour_display . $time_suffix . 'M-' . $number;
    }
    
    // Fallback: if queue code doesn't match expected pattern, return as-is
    return $queue_code;
}

/**
 * Get queue type display name for patients
 * 
 * @param string $queue_type The technical queue type
 * @return string Patient-friendly queue type name
 */
function getQueueTypeDisplayName($queue_type) {
    $type_names = [
        'triage' => 'Triage',
        'consultation' => 'Consultation',
        'lab' => 'Laboratory',
        'prescription' => 'Pharmacy',
        'billing' => 'Billing',
        'document' => 'Documents'
    ];
    
    return $type_names[$queue_type] ?? ucfirst($queue_type);
}

/**
 * Format queue status for patient display
 * 
 * @param string $status The technical status from database
 * @return array Status information with display text and CSS class
 */
function formatQueueStatusForPatient($status) {
    $status_info = [
        'waiting' => [
            'text' => 'Waiting',
            'class' => 'status-waiting',
            'description' => 'Please wait for your number to be called'
        ],
        'called' => [
            'text' => 'Called',
            'class' => 'status-called',
            'description' => 'Your number has been called - please proceed to the station'
        ],
        'in_progress' => [
            'text' => 'In Progress',
            'class' => 'status-in-progress',
            'description' => 'Currently being served'
        ],
        'done' => [
            'text' => 'Completed',
            'class' => 'status-completed',
            'description' => 'Service completed'
        ],
        'skipped' => [
            'text' => 'Skipped',
            'class' => 'status-skipped',
            'description' => 'Please return to the queue counter'
        ],
        'cancelled' => [
            'text' => 'Cancelled',
            'class' => 'status-cancelled',
            'description' => 'Queue entry cancelled'
        ],
        'no_show' => [
            'text' => 'No Show',
            'class' => 'status-no-show',
            'description' => 'Please return to the queue counter'
        ]
    ];
    
    return $status_info[$status] ?? [
        'text' => ucfirst($status),
        'class' => 'status-unknown',
        'description' => 'Status unknown'
    ];
}

/**
 * Calculate estimated wait time based on queue position
 * 
 * @param int $position Position in queue (1 = next)
 * @param string $queue_type Type of queue for time estimation
 * @return string Estimated wait time display
 */
function estimateWaitTime($position, $queue_type = 'consultation') {
    if ($position <= 0) {
        return 'Your turn';
    }
    
    // Average service times per queue type (in minutes)
    $service_times = [
        'triage' => 5,
        'consultation' => 15,
        'lab' => 10,
        'prescription' => 8,
        'billing' => 7,
        'document' => 10
    ];
    
    $avg_time = $service_times[$queue_type] ?? 10;
    $estimated_minutes = $position * $avg_time;
    
    if ($estimated_minutes < 60) {
        return $estimated_minutes . ' minutes';
    } else {
        $hours = floor($estimated_minutes / 60);
        $minutes = $estimated_minutes % 60;
        return $hours . 'h ' . $minutes . 'm';
    }
}

/**
 * Generate queue display information for patient dashboard
 * 
 * @param array $queue_data Queue entry data from database
 * @return array Formatted queue information for display
 */
function generateQueueDisplayInfo($queue_data) {
    if (!$queue_data) {
        return null;
    }
    
    $status_info = formatQueueStatusForPatient($queue_data['status']);
    
    return [
        'queue_code' => formatQueueCodeForPatient($queue_data['queue_code']),
        'queue_type' => getQueueTypeDisplayName($queue_data['queue_type']),
        'station_name' => $queue_data['station_name'] ?? 'Unknown Station',
        'status' => $status_info,
        'time_in' => date('h:i A', strtotime($queue_data['time_in'])),
        'priority_level' => ucfirst($queue_data['priority_level'] ?? 'normal')
    ];
}

/**
 * Check if queue code is in urgent/priority format
 * 
 * @param string $queue_code The queue code to check
 * @return bool True if priority/urgent queue
 */
function isUrgentQueueCode($queue_code) {
    // Priority codes typically start with 'U' for urgent or have special prefixes
    return preg_match('/^[U]/', $queue_code) || 
           strpos($queue_code, 'URG') !== false ||
           strpos($queue_code, 'PRI') !== false;
}

/**
 * Get queue color class based on status and priority
 * 
 * @param string $status Queue status
 * @param string $priority_level Priority level
 * @return string CSS class for styling
 */
function getQueueColorClass($status, $priority_level = 'normal') {
    if ($priority_level === 'emergency') {
        return 'queue-emergency';
    } elseif ($priority_level === 'priority') {
        return 'queue-priority';
    }
    
    switch ($status) {
        case 'waiting':
            return 'queue-waiting';
        case 'called':
            return 'queue-called';
        case 'in_progress':
            return 'queue-active';
        case 'done':
            return 'queue-completed';
        default:
            return 'queue-default';
    }
}