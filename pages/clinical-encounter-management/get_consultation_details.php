<?php
/**
 * Get Consultation Details API
 * Returns consultation details for modal display
 */

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if role is authorized for clinical encounters
$authorized_roles = ['doctor', 'nurse', 'admin', 'records_officer', 'bhw', 'dho', 'pharmacist'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_role = strtolower($_SESSION['role']);

// Get consultation ID from query parameter with validation
$consultation_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$consultation_id || $consultation_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid consultation ID provided']);
    exit();
}

try {
    // Get consultation with patient and vitals information
    $consultation_stmt = $conn->prepare("
        SELECT c.*, 
               p.first_name, p.last_name, p.username as patient_code, p.date_of_birth, p.sex, p.contact_number,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
               COALESCE(b.barangay_name, 'Not Specified') as barangay,
               CONCAT(doc.first_name, ' ', doc.last_name) as doctor_name,
               s.name as service_name,
               v.vitals_id, v.recorded_at as vitals_recorded_at, v.systolic_bp, v.diastolic_bp, 
               v.heart_rate, v.temperature, v.respiratory_rate, v.weight, v.height, v.bmi, v.remarks as vitals_remarks,
               CONCAT(v.systolic_bp, '/', v.diastolic_bp) as blood_pressure,
               CONCAT(emp_vitals.first_name, ' ', emp_vitals.last_name) as vitals_taken_by
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees doc ON (c.consulted_by = doc.employee_id OR c.attending_employee_id = doc.employee_id)
        LEFT JOIN services s ON c.service_id = s.service_id
        LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
        LEFT JOIN employees emp_vitals ON v.recorded_by = emp_vitals.employee_id
        WHERE c.consultation_id = ?
    ");
    
    if (!$consultation_stmt) {
        throw new Exception("Failed to prepare consultation query: " . $conn->error);
    }
    
    $consultation_stmt->bind_param("i", $consultation_id);
    
    if (!$consultation_stmt->execute()) {
        throw new Exception("Failed to execute consultation query: " . $consultation_stmt->error);
    }
    
    $result = $consultation_stmt->get_result();
    $consultation_data = $result->fetch_assoc();
    
    if (!$consultation_data) {
        echo json_encode(['success' => false, 'message' => 'Consultation not found']);
        exit();
    }
    
    // Role-based access control - same as index.php
    $has_access = false;
    
    switch ($employee_role) {
        case 'doctor':
        case 'nurse':
            // Doctor/Nurse: Show consultations assigned to them or where they were involved
            if ($consultation_data['consulted_by'] == $employee_id || 
                $consultation_data['attending_employee_id'] == $employee_id) {
                $has_access = true;
            }
            break;
            
        case 'bhw':
            // BHW: Limited to patients from their assigned barangay (would need employee-barangay assignment table)
            $has_access = true; // Simplified for now
            break;
            
        case 'dho':
            // DHO: Limited to patients from their assigned district (would need employee-district assignment table)
            $has_access = true; // Simplified for now
            break;
            
        case 'admin':
        case 'records_officer':
            // Admin/Records Officer: Full access
            $has_access = true;
            break;
            
        default:
            $has_access = false;
            break;
    }
    
    if (!$has_access) {
        echo json_encode(['success' => false, 'message' => 'Access denied for this consultation']);
        exit();
    }
    
    // Format the consultation data for frontend
    $formatted_consultation = [
        'consultation_id' => $consultation_data['consultation_id'],
        'patient_name' => trim($consultation_data['first_name'] . ' ' . $consultation_data['last_name']),
        'patient_id' => $consultation_data['patient_code'],
        'age' => $consultation_data['age'],
        'sex' => $consultation_data['sex'],
        'barangay' => $consultation_data['barangay'],
        'consultation_date' => $consultation_data['consultation_date'],
        'doctor_name' => $consultation_data['doctor_name'],
        'service_name' => $consultation_data['service_name'],
        'status' => $consultation_data['consultation_status'],
        'chief_complaint' => $consultation_data['chief_complaint'],
        'diagnosis' => $consultation_data['diagnosis'],
        'treatment_plan' => $consultation_data['treatment_plan'],
        'remarks' => $consultation_data['remarks'],
        'follow_up_date' => $consultation_data['follow_up_date'],
        'vitals_id' => $consultation_data['vitals_id'],
        'blood_pressure' => $consultation_data['blood_pressure'],
        'heart_rate' => $consultation_data['heart_rate'],
        'temperature' => $consultation_data['temperature'],
        'respiratory_rate' => $consultation_data['respiratory_rate'],
        'weight' => $consultation_data['weight'],
        'height' => $consultation_data['height'],
        'bmi' => $consultation_data['bmi'],
        'vitals_remarks' => $consultation_data['vitals_remarks'],
        'vitals_taken_by' => $consultation_data['vitals_taken_by'],
        'vitals_recorded_at' => $consultation_data['vitals_recorded_at']
    ];
    
    echo json_encode([
        'success' => true,
        'consultation' => $formatted_consultation
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_consultation_details.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while loading consultation details'
    ]);
}
?>