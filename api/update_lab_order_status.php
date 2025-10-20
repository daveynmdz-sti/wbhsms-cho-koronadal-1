<?php
// Include necessary configuration and session handling
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if user has permission to update lab orders
$canUpdateLab = in_array($_SESSION['role_id'], [1, 2, 3, 9]); // admin, doctor, nurse, laboratory_tech
if (!$canUpdateLab) {
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
    if (!isset($data['lab_order_id']) || !isset($data['overall_status'])) {
        throw new Exception('Missing required fields: lab_order_id and overall_status');
    }
    
    $lab_order_id = intval($data['lab_order_id']);
    $overall_status = $data['overall_status'];
    $remarks = isset($data['remarks']) ? $data['remarks'] : '';
    $auto_update = isset($data['auto_update']) ? $data['auto_update'] : false;
    
    // Validate status value
    $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled', 'partial'];
    if (!in_array($overall_status, $valid_statuses)) {
        throw new Exception('Invalid status value');
    }
    
    // Validate lab order exists
    $checkSql = "SELECT lab_order_id, status, patient_id FROM lab_orders WHERE lab_order_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $lab_order_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        throw new Exception('Lab order not found');
    }
    
    $labOrder = $checkResult->fetch_assoc();
    
    // Check if overall_status column exists
    $checkColumnSql = "SHOW COLUMNS FROM lab_orders LIKE 'overall_status'";
    $columnResult = $conn->query($checkColumnSql);
    $hasOverallStatus = $columnResult->num_rows > 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        if ($hasOverallStatus) {
            // Update overall_status if column exists
            $updateSql = "UPDATE lab_orders SET overall_status = ?, updated_at = NOW() WHERE lab_order_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $overall_status, $lab_order_id);
        } else {
            // Update regular status column
            $updateSql = "UPDATE lab_orders SET status = ?, updated_at = NOW() WHERE lab_order_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $overall_status, $lab_order_id);
        }
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update lab order status');
        }
        
        // Log the status change if auto_update
        if ($auto_update) {
            $logSql = "INSERT INTO lab_order_logs (lab_order_id, employee_id, action, details, created_at) 
                       VALUES (?, ?, 'status_auto_update', ?, NOW())";
            $logStmt = $conn->prepare($logSql);
            $action_details = json_encode([
                'old_status' => $labOrder['status'],
                'new_status' => $overall_status,
                'remarks' => $remarks,
                'auto_updated' => true
            ]);
            $logStmt->bind_param("iis", $lab_order_id, $_SESSION['employee_id'], $action_details);
            $logStmt->execute(); // Don't fail if logging fails
        }
        
        // If using LabOrderStatusManager utility, try to use it
        if (file_exists($root_path . '/utils/LabOrderStatusManager.php')) {
            require_once $root_path . '/utils/LabOrderStatusManager.php';
            
            try {
                $statusManager = new LabOrderStatusManager($conn);
                $statusManager->updateLabOrderStatus($lab_order_id, $overall_status, $remarks);
            } catch (Exception $e) {
                // Log but don't fail - we already updated the main status
                error_log("LabOrderStatusManager error: " . $e->getMessage());
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Lab order status updated successfully',
            'lab_order_id' => $lab_order_id,
            'new_status' => $overall_status,
            'auto_update' => $auto_update
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Lab order status update error: " . $e->getMessage());
    
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