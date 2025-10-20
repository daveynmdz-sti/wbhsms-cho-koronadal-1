<?php
// Ensure output buffering is active (but don't create unnecessary nested buffers)
if (ob_get_level() === 0) {
    ob_start();
}

// Include necessary configuration and session handling
// Use absolute path resolution for API files
$root_path = dirname(__DIR__); // API is one level down from root
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Clean any output buffer before sending JSON
if (ob_get_level()) {
    ob_clean();
}

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if user has permission to update prescriptions
$canUpdatePrescriptions = in_array($_SESSION['role_id'], [1, 2, 4, 9]); // admin, doctor, pharmacist, nurse
if (!$canUpdatePrescriptions) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    if (!isset($data['prescription_id']) || !isset($data['overall_status'])) {
        throw new Exception('Missing required fields: prescription_id and overall_status');
    }
    
    $prescription_id = intval($data['prescription_id']);
    $overall_status = $data['overall_status'];
    $remarks = isset($data['remarks']) ? $data['remarks'] : '';
    $auto_update = isset($data['auto_update']) ? $data['auto_update'] : false;
    
    // Validate status value
    $valid_statuses = ['active', 'issued', 'dispensed', 'cancelled', 'partial'];
    if (!in_array($overall_status, $valid_statuses)) {
        throw new Exception('Invalid status value');
    }
    
    // Validate prescription exists
    $checkSql = "SELECT prescription_id, status, patient_id FROM prescriptions WHERE prescription_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $prescription_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        throw new Exception('Prescription not found');
    }
    
    $prescription = $checkResult->fetch_assoc();
    
    // Check if overall_status column exists
    $checkColumnSql = "SHOW COLUMNS FROM prescriptions LIKE 'overall_status'";
    $columnResult = $conn->query($checkColumnSql);
    $hasOverallStatus = $columnResult->num_rows > 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        if ($hasOverallStatus) {
            // Update overall_status if column exists
            $updateSql = "UPDATE prescriptions SET overall_status = ?, updated_at = NOW() WHERE prescription_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $overall_status, $prescription_id);
        } else {
            // Update regular status column
            $updateSql = "UPDATE prescriptions SET status = ?, updated_at = NOW() WHERE prescription_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $overall_status, $prescription_id);
        }
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update prescription status');
        }
        
        // Log the status change if auto_update
        if ($auto_update) {
            $logSql = "INSERT INTO prescription_status_logs (prescription_id, employee_id, action, details, created_at) 
                       VALUES (?, ?, 'status_auto_update', ?, NOW())";
            $logStmt = $conn->prepare($logSql);
            $action_details = json_encode([
                'old_status' => $prescription['status'],
                'new_status' => $overall_status,
                'remarks' => $remarks,
                'auto_updated' => true
            ]);
            $logStmt->bind_param("iis", $prescription_id, $_SESSION['employee_id'], $action_details);
            $logStmt->execute(); // Don't fail if logging fails
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Prescription status updated successfully',
            'prescription_id' => $prescription_id,
            'new_status' => $overall_status,
            'auto_update' => $auto_update
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Prescription status update error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => [
            'file' => __FILE__,
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>