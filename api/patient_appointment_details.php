<?php
// Suppress all output except JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include session and database
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

try {
    // Check if patient is logged in
    if (!isset($_SESSION['patient_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access - please log in']);
        exit();
    }

    $patient_id = $_SESSION['patient_id'];
    $appointment_id = $_GET['appointment_id'] ?? '';

    if (empty($appointment_id) || !is_numeric($appointment_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
        exit();
    }
    
    // Additional debug logging for testing
    error_log("API Debug - Patient ID: " . $patient_id . ", Appointment ID: " . $appointment_id);

    // Fetch appointment details - only for the logged-in patient
    $sql = "
        SELECT a.appointment_id, a.scheduled_date, a.scheduled_time, a.status, 
               a.cancellation_reason, a.created_at, a.updated_at,
               f.name as facility_name, f.type as facility_type,
               s.name as service_name, s.description as service_description,
               p.first_name, p.last_name, p.contact_number, p.email,
               r.referral_num, r.referral_reason,
               qe.queue_number, qe.queue_type, qe.priority_level as queue_priority, 
               qe.status as queue_status, qe.time_in, qe.time_started, qe.time_completed
        FROM appointments a
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN referrals r ON a.referral_id = r.referral_id
        LEFT JOIN queue_entries qe ON a.appointment_id = qe.appointment_id
        WHERE a.appointment_id = ? AND a.patient_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $conn->error . " | Query: " . $sql);
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($appointment = $result->fetch_assoc()) {
        // Combine first and last name
        $appointment['patient_name'] = trim($appointment['first_name'] . ' ' . $appointment['last_name']);
        
        echo json_encode([
            'success' => true,
            'appointment' => $appointment
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Appointment not found or you do not have permission to view it'
        ]);
    }
    
    $stmt->close();

} catch (Exception $e) {
    error_log("Error fetching appointment details: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while fetching appointment details'
    ]);
}
?>