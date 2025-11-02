<?php
// get_patient_referrals.php - API to fetch referrals for a specific patient

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
error_log("DEBUG: About to execute referrals query");

try {
    // Query to get all referrals for the patient
    $sql = "
        SELECT 
            r.referral_id,
            r.referral_num,
            r.referring_facility_id,
            r.referred_to_facility_id,
            r.external_facility_name,
            r.vitals_id,
            r.service_id,
            r.referral_reason,
            r.referred_by,
            r.referral_date,
            r.status,
            r.destination_type,
            r.notes,
            r.updated_at,
            rf.name as referring_facility_name,
            tf.name as referred_to_facility_name,
            s.name as service_name,
            p.patient_id as patient_id,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.contact_number,
            p.date_of_birth,
            p.sex,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
            b.barangay_name as patient_barangay,
            emp.first_name as referred_by_first_name,
            emp.last_name as referred_by_last_name,
            CONCAT(emp.first_name, ' ', emp.last_name) as referred_by_name,
            rol.role_name as referred_by_role
        FROM referrals r
        JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN facilities rf ON r.referring_facility_id = rf.facility_id
        LEFT JOIN facilities tf ON r.referred_to_facility_id = tf.facility_id
        LEFT JOIN services s ON r.service_id = s.service_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees emp ON r.referred_by = emp.employee_id
        LEFT JOIN roles rol ON emp.role_id = rol.role_id
        WHERE r.patient_id = ?
        ORDER BY r.referral_date DESC, r.updated_at DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $referrals = [];
    while ($row = $result->fetch_assoc()) {
        $referrals[] = $row;
    }
    
    $stmt->close();
    
    // Debug output
    error_log("DEBUG: Found " . count($referrals) . " referrals");
    if (count($referrals) > 0) {
        error_log("DEBUG: First referral: " . print_r($referrals[0], true));
    }
    error_log("DEBUG: About to return JSON response");
    
    // Test JSON encoding before sending
    $json_response = [
        'success' => true,
        'referrals' => $referrals,
        'count' => count($referrals),
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
    error_log("Error fetching patient referrals: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching referrals: ' . $e->getMessage()
    ]);
}
?>