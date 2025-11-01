<?php
// Production-ready error handling
$root_path = dirname(__DIR__);
require_once $root_path . '/config/env.php';
require_once $root_path . '/config/production_security.php';

if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Start output buffering to catch any unexpected output
ob_start();

// Include session and database
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$employee_id = get_employee_session('employee_id');
$role = get_employee_session('role');

if (!$employee_id || !$role) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session data incomplete']);
    exit();
}

$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer'];
if (!in_array(strtolower($role), $authorized_roles)) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$appointment_id = $_GET['appointment_id'] ?? '';

if (empty($appointment_id) || !is_numeric($appointment_id)) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
    exit();
}

try {
    $sql = "
        SELECT a.appointment_id, a.scheduled_date, a.scheduled_time, a.status, a.service_id,
               a.cancellation_reason, a.created_at, a.updated_at,
               p.first_name, p.last_name, p.middle_name, p.username as patient_id,
               p.contact_number, p.date_of_birth, p.sex,
               f.name as facility_name, f.district as facility_district,
               b.barangay_name,
               s.name as service_name,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN services s ON a.service_id = s.service_id
        WHERE a.appointment_id = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();

    if (!$appointment) {
        if (ob_get_level()) { ob_clean(); }
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit();
    }

    // Format the data with safe null handling
    $appointment['patient_name'] = trim(($appointment['last_name'] ?? '') . ', ' . ($appointment['first_name'] ?? '') . ' ' . ($appointment['middle_name'] ?? ''));
    $appointment['appointment_date'] = $appointment['scheduled_date'] ? date('F j, Y', strtotime($appointment['scheduled_date'])) : 'N/A';
    $appointment['time_slot'] = $appointment['scheduled_time'] ? date('g:i A', strtotime($appointment['scheduled_time'])) : 'N/A';
    $appointment['status'] = ucfirst($appointment['status'] ?? 'unknown');
    
    // Ensure service_name is available, provide fallback
    if (empty($appointment['service_name'])) {
        $appointment['service_name'] = 'General Consultation';
    }
    
    // Format cancellation details if applicable
    if (!empty($appointment['cancellation_reason'])) {
        $appointment['cancel_reason'] = $appointment['cancellation_reason'];
        $appointment['cancelled_at'] = $appointment['updated_at'] ? date('M j, Y g:i A', strtotime($appointment['updated_at'])) : 'N/A';
    }

    // Clean output buffer and send success response
    if (ob_get_level()) { ob_clean(); }
    echo json_encode(['success' => true, 'appointment' => $appointment]);

} catch (Exception $e) {
    // Clean output buffer and send error response
    if (ob_get_level()) { ob_clean(); }
    http_response_code(500);
    
    // Log detailed error in debug mode only
    if (getenv('APP_DEBUG') === '1') {
        error_log("Database error in get_appointment_details.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
} catch (Error $e) {
    // Clean output buffer and send error response for fatal errors
    if (ob_get_level()) { ob_clean(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error occurred']);
}
?>
