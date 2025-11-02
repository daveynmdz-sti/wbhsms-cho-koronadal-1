<?php
// get_patient_consultations.php - API to fetch patient consultation history
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized access'
    ]);
    exit();
}

// Validate patient_id parameter
if (!isset($_GET['patient_id']) || !is_numeric($_GET['patient_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid patient ID'
    ]);
    exit();
}

$patient_id = intval($_GET['patient_id']);

// Debug output
error_log("DEBUG: Patient ID received: " . $_GET['patient_id'] . " (raw)");
error_log("DEBUG: Patient ID converted: " . $patient_id . " (int)");
error_log("DEBUG: About to execute consultations query");

try {
    // Check if patient exists first
    $patient_check = "SELECT patient_id, first_name, last_name FROM patients WHERE patient_id = ?";
    $patient_stmt = $pdo->prepare($patient_check);
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode([
            'success' => false,
            'error' => 'Patient not found'
        ]);
        exit();
    }

    error_log("DEBUG: Patient found - ID: " . $patient['patient_id'] . ", Name: " . $patient['first_name'] . " " . $patient['last_name']);

    // Query to get all consultations for the patient - using correct column names from actual DB structure
    $sql = "
        SELECT c.consultation_id as encounter_id, c.patient_id, c.vitals_id, 
               c.chief_complaint, c.diagnosis, c.treatment_plan, c.remarks, 
               c.follow_up_date, c.consultation_status as status, 
               c.consultation_date, c.created_at, c.updated_at,
               p.first_name, p.last_name, p.username as patient_id_display,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age, p.sex,
               attending.first_name as doctor_first_name, attending.last_name as doctor_last_name,
               CONCAT(attending.first_name, ' ', attending.last_name) as doctor_name,
               consulted.first_name as consulted_first_name, consulted.last_name as consulted_last_name,
               CONCAT(consulted.first_name, ' ', consulted.last_name) as consulted_doctor_name,
               b.barangay_name, f.district as district_name,
               s.name as service_name, s.service_id,
               f.name as facility_name, f.type as facility_type,
               -- Get vitals information if linked
               CASE 
                   WHEN v.systolic_bp IS NOT NULL AND v.diastolic_bp IS NOT NULL 
                   THEN CONCAT(v.systolic_bp, '/', v.diastolic_bp) 
                   ELSE NULL 
               END as blood_pressure, 
               v.heart_rate, v.temperature, v.weight, v.height, v.respiratory_rate,
               v.bmi, v.recorded_at as vitals_recorded_at
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
        LEFT JOIN employees attending ON c.attending_employee_id = attending.employee_id
        LEFT JOIN employees consulted ON c.consulted_by = consulted.employee_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN services s ON c.service_id = s.service_id
        LEFT JOIN facilities f ON attending.facility_id = f.facility_id
        WHERE c.patient_id = ?
        ORDER BY c.consultation_date DESC, c.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$patient_id]);
    $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Found " . count($consultations) . " consultations");
    
    if (!empty($consultations)) {
        error_log("DEBUG: First consultation: " . print_r($consultations[0], true));
    }

    // Process consultations to add additional information
    foreach ($consultations as &$consultation) {
        // Format consultation date
        if ($consultation['consultation_date']) {
            $consultation['formatted_date'] = date('M j, Y', strtotime($consultation['consultation_date']));
            $consultation['formatted_time'] = date('g:i A', strtotime($consultation['consultation_date']));
        }
        
        // Format status badge
        $consultation['status_badge'] = ucfirst(str_replace('_', ' ', $consultation['status']));
    }

    error_log("DEBUG: About to return JSON response");
    
    $response = [
        'success' => true,
        'consultations' => $consultations,
        'count' => count($consultations),
        'patient_id' => $patient_id
    ];

    $json_result = json_encode($response);
    if ($json_result === false) {
        error_log("DEBUG: JSON encoding failed: " . json_last_error_msg());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to encode response data'
        ]);
    } else {
        error_log("DEBUG: JSON encoded successfully, length: " . strlen($json_result));
        echo $json_result;
    }

} catch (Exception $e) {
    error_log("Error fetching patient consultations: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
?>