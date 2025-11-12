<?php
// get_available_time_slots.php - API to fetch available time slots for a doctor on a specific date

// Include database connection
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';

// Include session for employee authentication
require_once $root_path . '/config/session/employee_session.php';

// Ensure user is logged in
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access', 'debug' => 'Session check failed']);
    exit;
}

// Get parameters
$doctor_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

// Debug parameters
$debug_info = [
    'doctor_id' => $doctor_id,
    'date' => $date,
    'session_status' => session_status(),
    'employee_logged_in' => is_employee_logged_in()
];

// Validate inputs
if (!$doctor_id || !$date) {
    echo json_encode(['success' => false, 'error' => 'Doctor ID and date are required', 'debug' => $debug_info]);
    exit;
}

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

try {
    // First test basic database connection
    $test_stmt = $conn->prepare("SELECT COUNT(*) as count FROM doctor_schedule_slots WHERE slot_date = ?");
    $test_stmt->bind_param("s", $date);
    $test_stmt->execute();
    $test_result = $test_stmt->get_result();
    $test_count = $test_result->fetch_assoc()['count'];
    
    // Query to get available time slots for the doctor on the specified date
    // The slots are directly stored with slot_date, not calculated from day_of_week
    $stmt = $conn->prepare("
        SELECT 
            dss.slot_id,
            dss.slot_time,
            dss.slot_date,
            dss.is_booked
        FROM doctor_schedule_slots dss
        INNER JOIN doctor_schedule ds ON dss.schedule_id = ds.schedule_id
        WHERE ds.doctor_id = ? 
        AND dss.slot_date = ?
        AND ds.is_active = 1
        AND dss.is_booked = 0
        AND dss.slot_id NOT IN (
            SELECT slot_id 
            FROM doctor_schedule_slots 
            WHERE referral_id IS NOT NULL
            AND slot_date = ?
        )
        ORDER BY dss.slot_time ASC
    ");
    
    $stmt->bind_param("iss", $doctor_id, $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $slots = [];
    while ($row = $result->fetch_assoc()) {
        // Format time for display (assuming slot_time is stored as HH:MM:SS)
        $time_obj = DateTime::createFromFormat('H:i:s', $row['slot_time']);
        $formatted_time = $time_obj ? $time_obj->format('g:i A') : $row['slot_time'];
        
        $slots[] = [
            'slot_id' => $row['slot_id'],
            'start_time' => $formatted_time,
            'end_time' => '', // We don't have end_time in this table structure
            'slot_time' => $row['slot_time']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'slots' => $slots,
        'date' => $date,
        'doctor_id' => $doctor_id,
        'debug' => [
            'total_slots_found' => count($slots),
            'total_slots_on_date' => $test_count,
            'sql_query' => "SELECT dss.slot_id, dss.slot_time, dss.slot_date, dss.is_booked FROM doctor_schedule_slots dss INNER JOIN doctor_schedule ds ON dss.schedule_id = ds.schedule_id WHERE ds.doctor_id = $doctor_id AND dss.slot_date = '$date' AND ds.is_active = 1 AND dss.is_booked = 0",
            'parameters' => $debug_info
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in get_available_time_slots.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred',
        'debug_error' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine()
    ]);
}
?>