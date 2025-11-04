<?php
// get_latest_vitals.php - AJAX endpoint for getting patient's latest vitals
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
$authorized_roles = ['doctor', 'nurse', 'bhw', 'dho', 'records_officer', 'admin'];
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
        echo json_encode(['error' => 'You do not have permission to view vitals for this patient']);
        exit;
    }

    // Get latest vitals for this patient
    $stmt = $conn->prepare("
        SELECT v.*, CONCAT(e.first_name, ' ', e.last_name) as recorded_by_name,
               r.role_name as recorded_by_role
        FROM vitals v 
        LEFT JOIN employees e ON v.recorded_by = e.employee_id
        LEFT JOIN roles r ON e.role_id = r.role_id
        WHERE v.patient_id = ? 
        ORDER BY v.recorded_at DESC, v.vitals_id DESC 
        LIMIT 1
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vitals = $result->fetch_assoc();
    
    if ($vitals) {
        echo json_encode([
            'success' => true,
            'vitals' => $vitals
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'vitals' => null,
            'message' => 'No vitals found for this patient'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>