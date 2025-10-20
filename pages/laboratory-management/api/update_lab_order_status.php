<?php
// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Server-side role enforcement using role_id
$authorizedRoleIds = [1, 2, 3, 9]; // admin, doctor, nurse, laboratory_tech
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

$lab_order_id = $input['lab_order_id'] ?? null;
$overall_status = $input['overall_status'] ?? null;
$remarks = $input['remarks'] ?? '';
$auto_update = $input['auto_update'] ?? false;

if (!$lab_order_id || !$overall_status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lab order ID and status are required']);
    exit();
}

// Validate status values
$validStatuses = ['pending', 'in_progress', 'completed', 'cancelled', 'partial'];
if (!in_array($overall_status, $validStatuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

try {
    // Include the LabOrderStatusManager utility
    require_once $root_path . '/utils/LabOrderStatusManager.php';
    
    $conn->begin_transaction();
    
    // Get current status for logging
    $currentSql = "SELECT overall_status FROM lab_orders WHERE lab_order_id = ?";
    $currentStmt = $conn->prepare($currentSql);
    $currentStmt->bind_param("i", $lab_order_id);
    $currentStmt->execute();
    $currentResult = $currentStmt->get_result();
    
    if ($currentResult->num_rows === 0) {
        throw new Exception('Lab order not found');
    }
    
    $currentOrder = $currentResult->fetch_assoc();
    $oldStatus = $currentOrder['overall_status'];
    
    // If auto_update is true, use the LabOrderStatusManager to determine the correct status
    if ($auto_update) {
        // Get item completion status for validation
        $itemsSql = "SELECT 
                        COUNT(*) as total_items,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_items,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_items
                     FROM lab_order_items 
                     WHERE lab_order_id = ?";
        
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->bind_param("i", $lab_order_id);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result()->fetch_assoc();
        
        // Determine the correct status based on item completion
        if ($itemsResult['total_items'] == 0) {
            $overall_status = 'pending'; // No items
        } elseif ($itemsResult['completed_items'] == $itemsResult['total_items']) {
            $overall_status = 'completed'; // All items completed
        } elseif ($itemsResult['completed_items'] > 0 || $itemsResult['in_progress_items'] > 0) {
            $overall_status = 'in_progress'; // Some items completed or in progress
        } elseif ($itemsResult['cancelled_items'] == $itemsResult['total_items']) {
            $overall_status = 'cancelled'; // All items cancelled
        } else {
            $overall_status = 'pending'; // Default
        }
    }

    // Update lab order overall status (update both status and overall_status for consistency)
    $updateSql = "UPDATE lab_orders 
                  SET overall_status = ?, status = ?, remarks = ?, updated_at = NOW()
                  WHERE lab_order_id = ?";
    
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("sssi", $overall_status, $overall_status, $remarks, $lab_order_id);
    $updateStmt->execute();

    if ($updateStmt->affected_rows === 0) {
        throw new Exception('Lab order not found or no changes made');
    }

    // If marking entire order as completed or cancelled, update all items accordingly
    if ($overall_status === 'completed' || $overall_status === 'cancelled') {
        $updateItemsSql = "UPDATE lab_order_items 
                          SET status = ?, updated_at = NOW() 
                          WHERE lab_order_id = ? AND status != ?";
        
        $updateItemsStmt = $conn->prepare($updateItemsSql);
        $updateItemsStmt->bind_param("sis", $overall_status, $lab_order_id, $overall_status);
        $updateItemsStmt->execute();
    }

    $conn->commit();
    
    // Get updated statistics for response
    $statsSql = "SELECT 
                    COUNT(loi.item_id) as total_items,
                    SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_items
                 FROM lab_order_items loi
                 WHERE loi.lab_order_id = ?";
    
    $statsStmt = $conn->prepare($statsSql);
    $statsStmt->bind_param("i", $lab_order_id);
    $statsStmt->execute();
    $statsResult = $statsStmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Lab order status updated successfully',
        'data' => [
            'lab_order_id' => $lab_order_id,
            'old_status' => $oldStatus,
            'new_status' => $overall_status,
            'total_items' => $statsResult['total_items'],
            'completed_items' => $statsResult['completed_items'],
            'auto_update' => $auto_update
        ]
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}