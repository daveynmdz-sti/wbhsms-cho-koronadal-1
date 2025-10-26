<?php
// get_patient_facilities.php - AJAX endpoint for getting patient's barangay and district facilities
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Include database connection and session
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/referral_permissions.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Check if role is authorized
$authorized_roles = ['doctor', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized role']);
    exit;
}

$employee_id = $_SESSION['employee_id'];
$role = $_SESSION['role'];

// Check if patient_id is provided
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if (!$patient_id) {
    echo json_encode(['error' => 'Patient ID is required']);
    exit;
}

try {
    // Check if employee can view this patient based on jurisdiction
    if (!canEmployeeViewPatient($conn, $employee_id, $patient_id, $role)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view facilities for this patient']);
        exit;
    }

    // Get patient's barangay and district information using proper joins
    $stmt = $conn->prepare("
        SELECT p.barangay_id, b.barangay_name, b.district_id, d.district_name 
        FROM patients p 
        JOIN barangay b ON p.barangay_id = b.barangay_id 
        JOIN districts d ON b.district_id = d.district_id
        WHERE p.patient_id = ? AND p.status = 'active'
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient_data = $result->fetch_assoc();
    
    if (!$patient_data) {
        echo json_encode(['error' => 'Patient not found or inactive']);
        exit;
    }
    
    $patient_barangay_id = $patient_data['barangay_id'];
    $patient_barangay_name = $patient_data['barangay_name'];
    $patient_district_id = $patient_data['district_id'];
    $patient_district_name = $patient_data['district_name'];
    
    // Find barangay health center for this patient's barangay
    $stmt = $conn->prepare("
        SELECT facility_id, name, type 
        FROM facilities 
        WHERE type = 'Barangay Health Center' 
        AND barangay_id = ? 
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('i', $patient_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $barangay_facility = $result->fetch_assoc();
    
    // Find district health office using the district_id from barangay
    // Special case: Main District (district_id = 1) uses City Health Office as district office
    if ($patient_district_id == 1) {
        // For Main District, City Health Office serves as both city and district office
        $stmt = $conn->prepare("
            SELECT facility_id, name, type, district 
            FROM facilities 
            WHERE type = 'City Health Office' 
            AND district_id = ? 
            AND status = 'active'
            LIMIT 1
        ");
    } else {
        // For Concepcion and GPS Districts, use their respective District Health Offices
        $stmt = $conn->prepare("
            SELECT facility_id, name, type, district 
            FROM facilities 
            WHERE type = 'District Health Office' 
            AND district_id = ? 
            AND status = 'active'
            LIMIT 1
        ");
    }
    $stmt->bind_param('i', $patient_district_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $district_office = $result->fetch_assoc();
    
    // Get city health office (main facility)
    $stmt = $conn->prepare("
        SELECT facility_id, name, type 
        FROM facilities 
        WHERE type = 'City Health Office' 
        AND is_main = 1 
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $city_office = $result->fetch_assoc();
    
    // Audit log the patient facility lookup
    auditReferralAction($conn, $employee_id, 'patient_facilities_lookup', "Viewed facilities for patient ID: $patient_id");
    
    echo json_encode([
        'success' => true,
        'patient' => [
            'barangay_id' => $patient_barangay_id,
            'barangay_name' => $patient_barangay_name,
            'district_id' => $patient_district_id,
            'district_name' => $patient_district_name
        ],
        'facilities' => [
            'barangay_center' => $barangay_facility,
            'district_office' => $district_office,
            'city_office' => $city_office
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>