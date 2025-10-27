<?php
// reinstate_referral.php - API endpoint to reinstate cancelled/expired referrals
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Include referral permissions utility
require_once $root_path . '/utils/referral_permissions.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Please login to continue.'
    ]);
    exit();
}

// Check if role is authorized for reinstating referrals
$authorized_roles = ['doctor', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied: You do not have permission to reinstate referrals.'
    ]);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit();
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please contact administrator.'
    ]);
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'];

try {
    // Get and validate input parameters
    $referral_id = $_POST['referral_id'] ?? '';

    // Validate required fields
    if (empty($referral_id) || !is_numeric($referral_id)) {
        throw new Exception('Invalid referral ID provided.');
    }

    // For admin users, skip permission check
    if (strtolower($employee_role) === 'admin') {
        // Admin can reinstate any referral
    } else {
        // Check if employee has permission to reinstate this referral for non-admin users
        if (!canEmployeeEditReferral($conn, $employee_id, $referral_id, $employee_role)) {
            throw new Exception('You do not have permission to reinstate this referral.');
        }
    }

    // Check if referral exists and get current status
    $stmt = $conn->prepare("SELECT status FROM referrals WHERE referral_id = ?");
    $stmt->bind_param('i', $referral_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Referral not found.');
    }
    
    $referral = $result->fetch_assoc();
    $current_status = $referral['status'];
    $stmt->close();

    // Check if referral can be reinstated (only cancelled or expired referrals)
    $allowedStatuses = ['cancelled', 'expired'];
    if (!in_array(strtolower($current_status), $allowedStatuses)) {
        throw new Exception('Referral cannot be reinstated. Only cancelled or expired referrals can be reinstated. Current status: ' . $current_status);
    }

    // SIMPLE UPDATE QUERY - ONLY REFERRALS TABLE AS REQUESTED
    $stmt = $conn->prepare("UPDATE referrals SET status = 'active', updated_at = NOW() WHERE referral_id = ?");
    $stmt->bind_param('i', $referral_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update referral status: ' . $stmt->error);
    }
    
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    
    if ($affected_rows === 0) {
        throw new Exception('No rows were updated. Referral may already be active.');
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Referral has been successfully reinstated and is now active.',
        'data' => [
            'referral_id' => $referral_id,
            'previous_status' => $current_status,
            'new_status' => 'active',
            'reinstated_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'REINSTATEMENT_FAILED'
    ]);
} catch (Error $e) {
    // Handle PHP errors
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.',
        'error_code' => 'INTERNAL_ERROR'
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>