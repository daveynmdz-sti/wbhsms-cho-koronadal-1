<?php
// get_patient_appointments.php - API to fetch appointments for a specific patient

// Set headers for JSON response
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Include necessary files
$root_path = dirname(__DIR__);
require_once $root_path . '/config/env.php';
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is authenticated
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required. Please log in.'
    ]);
    exit();
}

// Check if patient_id is provided
if (!isset($_GET['patient_id']) || empty($_GET['patient_id'])) {
    error_log("ERROR: No patient_id provided in request");
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Patient ID is required.'
    ]);
    exit();
}

$patient_id = intval($_GET['patient_id']);

if ($patient_id <= 0) {
    error_log("ERROR: Invalid patient_id provided: " . $_GET['patient_id']);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Patient ID provided.'
    ]);
    exit();
}

// Debug output
error_log("DEBUG: Patient ID received: " . $_GET['patient_id'] . " (raw)");
error_log("DEBUG: Patient ID converted: " . $patient_id . " (int)");
error_log("DEBUG: About to execute query");

try {
    // First, verify the patient exists
    $patient_check = $conn->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE patient_id = ?");
    $patient_check->bind_param("i", $patient_id);
    $patient_check->execute();
    $patient_result = $patient_check->get_result();
    $patient_data = $patient_result->fetch_assoc();
    $patient_check->close();
    
    if (!$patient_data) {
        error_log("ERROR: Patient ID $patient_id not found in database");
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Patient not found.'
        ]);
        exit();
    }
    
    error_log("DEBUG: Patient found - ID: {$patient_data['patient_id']}, Name: {$patient_data['first_name']} {$patient_data['last_name']}");
    
    // Query to get all appointments for the patient from all facilities within CHO
    $sql = "
        SELECT 
            a.appointment_id,
            a.facility_id,
            a.scheduled_date,
            a.scheduled_time,
            a.status,
            f.name as facility_name,
            f.type as facility_type,
            f.district as facility_district,
            s.name as service_name,
            p.patient_id,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.contact_number,
            p.date_of_birth,
            p.sex,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
            b.barangay_name,
            fb.barangay_name as facility_barangay,
            a.referral_id,
            CASE 
                WHEN a.qr_code_path IS NOT NULL THEN 'available'
                ELSE NULL 
            END as qr_code_status,
            a.cancellation_reason,
            a.created_at,
            a.updated_at
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN facilities f ON a.facility_id = f.facility_id
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN barangay fb ON f.barangay_id = fb.barangay_id
        WHERE a.patient_id = ?
        ORDER BY a.scheduled_date DESC, a.scheduled_time DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    
    $stmt->close();
    
    // Debug output
    error_log("DEBUG: Found " . count($appointments) . " appointments");
    if (count($appointments) > 0) {
        error_log("DEBUG: First appointment: " . print_r($appointments[0], true));
    }
    error_log("DEBUG: About to return JSON response");
    
    // Test JSON encoding before sending
    $json_response = [
        'success' => true,
        'appointments' => $appointments,
        'count' => count($appointments),
        'patient_id' => $patient_id
    ];
    
    $json_string = json_encode($json_response);
    if ($json_string === false) {
        error_log("ERROR: JSON encoding failed: " . json_last_error_msg());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'JSON encoding error: ' . json_last_error_msg()
        ]);
        exit();
    }
    
    error_log("DEBUG: JSON encoded successfully, length: " . strlen($json_string));
    
    // Return response
    echo $json_string;
    
} catch (Exception $e) {
    error_log("Error fetching patient appointments: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching appointments: ' . $e->getMessage()
    ]);
}
?>