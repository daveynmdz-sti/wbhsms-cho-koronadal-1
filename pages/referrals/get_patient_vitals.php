<?php
// get_patient_vitals.php
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/referral_permissions.php';

header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Check if role is authorized
$authorized_roles = ['doctor', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized role']);
    exit();
}

$employee_id = $_SESSION['employee_id'];
$role = $_SESSION['role'];

$patient_id = $_GET['patient_id'] ?? '';
if (empty($patient_id) || !is_numeric($patient_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid patient ID']);
    exit();
}

try {
    // Check if employee can view this patient based on jurisdiction
    if (!canEmployeeViewPatient($conn, $employee_id, $patient_id, $role)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to view vitals for this patient']);
        exit();
    }

    // Get latest vitals from various possible tables
    $vitals = null;
    
    // Try to get from vitals table first
    $stmt = $conn->prepare("
        SELECT height, weight, bp, cardiac_rate, temperature, resp_rate, 
               DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') as date
        FROM vitals 
        WHERE patient_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vitals = $result->fetch_assoc();
    $stmt->close();
    
    // If no vitals found, try appointments table
    if (!$vitals) {
        $stmt = $conn->prepare("
            SELECT height, weight, bp, cardiac_rate, temperature, resp_rate,
                   DATE_FORMAT(date, '%M %d, %Y') as date
            FROM appointments 
            WHERE patient_id = ? 
            ORDER BY date DESC 
            LIMIT 1
        ");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $vitals = $result->fetch_assoc();
        $stmt->close();
    }
    
    if ($vitals) {
        // Audit log the vitals lookup
        auditReferralAction($conn, $employee_id, 'patient_vitals_lookup', "Viewed vitals for patient ID: $patient_id");
        
        echo json_encode([
            'success' => true, 
            'vitals' => $vitals
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'No vitals data found'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
