<?php
// Patient Deactivation API
// Security: Admin password verification required
// Function: Changes patient status from active to inactive

// Set root path and include required files
$root_path = realpath(dirname(__DIR__));
require_once $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR . 'employee_session.php';
require_once $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php';

// Set JSON response headers
header('Content-Type: application/json');

// Verify employee session and admin role
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access. Please log in.']);
    exit;
}

$employee_role = get_employee_session('role');

if (strtolower($employee_role) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Admin privileges required.']);
    exit;
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. POST required.']);
    exit;
}

try {
    // Get and validate input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input.']);
        exit;
    }
    
    $patient_id = $input['patient_id'] ?? null;
    $admin_password = $input['admin_password'] ?? null;
    $reason = $input['reason'] ?? '';
    
    // Validate required fields
    if (!$patient_id || !$admin_password) {
        http_response_code(400);
        echo json_encode(['error' => 'Patient ID and admin password are required.']);
        exit;
    }
    
    // Validate patient ID is numeric
    if (!is_numeric($patient_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid patient ID format.']);
        exit;
    }
    
    // Get current admin user details for password verification
    $admin_id = get_employee_session('employee_id');
    $stmt = $pdo->prepare("
        SELECT e.password 
        FROM employees e 
        INNER JOIN roles r ON e.role_id = r.role_id 
        WHERE e.employee_id = ? AND r.role_name = 'admin' AND e.status = 'active'
    ");
    $stmt->execute([$admin_id]);
    $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin_user) {
        http_response_code(401);
        echo json_encode(['error' => 'Admin user not found or inactive.']);
        exit;
    }
    
    // Verify admin password
    if (!password_verify($admin_password, $admin_user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid admin password.']);
        exit;
    }
    
    // Check if patient exists and is currently active
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, status FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        http_response_code(404);
        echo json_encode(['error' => 'Patient not found.']);
        exit;
    }
    
    if ($patient['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['error' => 'Patient is already inactive or archived.']);
        exit;
    }
    
    // Begin transaction for atomic operation
    $pdo->beginTransaction();
    
    try {
        // Update patient status to inactive
        $stmt = $pdo->prepare("UPDATE patients SET status = 'inactive', updated_at = NOW() WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
        
        // Log the deactivation activity
        $log_message = "Patient account deactivated (ID: $patient_id - " . $patient['first_name'] . ' ' . $patient['last_name'] . ")";
        if (!empty($reason)) {
            $log_message .= ". Reason: " . $reason;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_logs 
            (admin_id, action_type, description, created_at) 
            VALUES (?, 'deactivate', ?, NOW())
        ");
        $stmt->execute([$admin_id, $log_message]);
        
        // Commit transaction
        $pdo->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Patient account successfully deactivated.',
            'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
            'deactivated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        error_log("Patient deactivation error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to deactivate patient account.']);
    }
    
} catch (Exception $e) {
    error_log("Patient deactivation API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error occurred.']);
}
?>