<?php
// get_available_doctors.php - API to fetch doctors who have available time slots on a specific date

// Include database connection
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';

// Include session for employee authentication
require_once $root_path . '/config/session/employee_session.php';

// Ensure user is logged in
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Get parameters
$date = isset($_GET['date']) ? $_GET['date'] : '';

// Validate inputs
if (!$date) {
    echo json_encode(['success' => false, 'error' => 'Date is required']);
    exit;
}

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

try {
    // Query to get doctors who have available time slots on the specified date
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            e.employee_id,
            e.first_name,
            e.last_name,
            COUNT(dss.slot_id) as available_slots_count
        FROM employees e
        INNER JOIN doctor_schedule ds ON e.employee_id = ds.doctor_id
        INNER JOIN doctor_schedule_slots dss ON ds.schedule_id = dss.schedule_id
        WHERE e.role_id = 2 
        AND e.status = 'active'
        AND ds.is_active = 1
        AND dss.slot_date = ?
        AND dss.is_booked = 0
        AND dss.slot_id NOT IN (
            SELECT slot_id 
            FROM doctor_schedule_slots 
            WHERE referral_id IS NOT NULL
            AND slot_date = ?
        )
        GROUP BY e.employee_id, e.first_name, e.last_name
        HAVING available_slots_count > 0
        ORDER BY e.last_name, e.first_name
    ");
    
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $doctors = [];
    while ($row = $result->fetch_assoc()) {
        $doctors[] = [
            'employee_id' => $row['employee_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'full_name' => $row['first_name'] . ' ' . $row['last_name'],
            'available_slots_count' => $row['available_slots_count']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'doctors' => $doctors,
        'date' => $date,
        'total_doctors_with_slots' => count($doctors)
    ]);

} catch (Exception $e) {
    error_log("Error in get_available_doctors.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred',
        'debug_error' => $e->getMessage()
    ]);
}
?>