<?php
// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if user has permission to cancel lab orders
$canCancelOrders = in_array($_SESSION['role_id'], [1, 9]); // admin, laboratory_tech
if (!$canCancelOrders) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to cancel lab orders']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['lab_order_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$lab_order_id = intval($input['lab_order_id']);
$cancellation_reason = $input['cancellation_reason'] ?? 'Manual cancellation';
$cancelled_by = $_SESSION['employee_id'];

try {
    // Begin transaction
    $conn->begin_transaction();
    
    // Check if lab order exists and is not already completed or cancelled
    $checkSql = "SELECT lab_order_id, status, overall_status FROM lab_orders WHERE lab_order_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $lab_order_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Lab order not found');
    }
    
    $order = $result->fetch_assoc();
    
    // Check if order is already completed or cancelled
    $currentStatus = $order['overall_status'] ?? $order['status'];
    if ($currentStatus === 'completed') {
        throw new Exception('Cannot cancel a completed lab order');
    }
    
    if ($currentStatus === 'cancelled') {
        throw new Exception('Lab order is already cancelled');
    }
    
    // Update the lab order status
    $updateSql = "UPDATE lab_orders SET 
                    status = 'cancelled',
                    overall_status = 'cancelled',
                    remarks = CONCAT(COALESCE(remarks, ''), IF(COALESCE(remarks, '') = '', '', '; '), 'Cancelled by employee ID: ', ?, ' - Reason: ', ?),
                    updated_at = NOW()
                  WHERE lab_order_id = ?";
    
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("isi", $cancelled_by, $cancellation_reason, $lab_order_id);
    
    if (!$updateStmt->execute()) {
        throw new Exception('Failed to update lab order status');
    }
    
    // Update all associated lab order items to cancelled
    $updateItemsSql = "UPDATE lab_order_items SET 
                        status = 'cancelled',
                        updated_at = NOW()
                       WHERE lab_order_id = ? AND status != 'completed'";
    
    $updateItemsStmt = $conn->prepare($updateItemsSql);
    $updateItemsStmt->bind_param("i", $lab_order_id);
    $updateItemsStmt->execute();
    
    // Log the cancellation activity
    $logSql = "INSERT INTO lab_order_logs (lab_order_id, employee_id, action, details, created_at) 
               VALUES (?, ?, 'cancelled', ?, NOW())";
    
    $logStmt = $conn->prepare($logSql);
    $action_details = "Lab order manually cancelled by employee ID: " . $cancelled_by . ". Reason: " . $cancellation_reason;
    $logStmt->bind_param("iis", $lab_order_id, $cancelled_by, $action_details);
    $logStmt->execute();
    
    // Commit the transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Lab order cancelled successfully',
        'lab_order_id' => $lab_order_id,
        'cancelled_by' => $cancelled_by,
        'cancelled_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    // Rollback the transaction
    $conn->rollback();
    
    error_log("Lab order cancellation error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>