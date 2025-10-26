<?php
// get_facility_services.php - AJAX endpoint for fetching services available at a specific facility
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
    echo json_encode(['error' => 'Unauthorized access', 'services' => []]);
    exit;
}

// Check if role is authorized
$authorized_roles = ['doctor', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized role', 'services' => []]);
    exit;
}

$employee_id = $_SESSION['employee_id'];
$role = $_SESSION['role'];

// Check if facility_id is provided
$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;

if (!$facility_id) {
    echo json_encode(['error' => 'Facility ID is required', 'services' => []]);
    exit;
}

try {
    // Validate facility access based on employee jurisdiction
    if (!canEmployeeAccessFacility($conn, $employee_id, $facility_id, $role)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to access services for this facility', 'services' => []]);
        exit;
    }

    // Get services available at the specified facility
    $stmt = $conn->prepare("
        SELECT s.service_id, s.name, s.description 
        FROM services s
        INNER JOIN facility_services fs ON s.service_id = fs.service_id
        WHERE fs.facility_id = ?
        ORDER BY s.name
    ");
    $stmt->bind_param('i', $facility_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $services = $result->fetch_all(MYSQLI_ASSOC);
    
    // Audit log the facility service lookup
    auditReferralAction($conn, $employee_id, 'facility_services_lookup', "Viewed services for facility ID: $facility_id");
    
    echo json_encode([
        'success' => true,
        'services' => $services,
        'facility_id' => $facility_id
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'services' => []
    ]);
}
?>
