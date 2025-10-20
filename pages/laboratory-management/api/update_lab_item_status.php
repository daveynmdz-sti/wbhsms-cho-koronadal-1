<?php
// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Server-side role enforcement
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role'], ['admin', 'laboratory_tech'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
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

$lab_order_item_id = $input['lab_order_item_id'] ?? null;
$status = $input['status'] ?? null;
$remarks = $input['remarks'] ?? '';

if (!$lab_order_item_id || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lab order item ID and status are required']);
    exit();
}

// Validate status values
$validStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

try {
    $conn->begin_transaction();

    // Get current status and order info for timing logic
    $currentSql = "SELECT loi.status, loi.lab_order_id, loi.started_at, lo.order_date 
                   FROM lab_order_items loi
                   LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                   WHERE loi.item_id = ?";
    $currentStmt = $conn->prepare($currentSql);
    $currentStmt->bind_param("i", $lab_order_item_id);
    $currentStmt->execute();
    $currentResult = $currentStmt->get_result();
    $currentData = $currentResult->fetch_assoc();
    
    if (!$currentData) {
        throw new Exception('Lab order item not found');
    }

    $currentStatus = $currentData['status'];
    $orderDate = $currentData['order_date'];
    $startedAt = $currentData['started_at'];

    // Prepare timing updates based on status transitions
    $timingUpdates = [];
    $timingParams = [];
    $timingTypes = "";

    // Check if this is a timing-relevant transition for lab technicians
    // Support both role name and role_id checking
    $isLabTechnician = ($_SESSION['role'] === 'laboratory_tech') || 
                       (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 9);
    
    if ($isLabTechnician && $currentStatus === 'pending' && $status === 'in_progress') {
        // Transition: pending → in_progress
        // Set started_at and calculate waiting_time
        $timingUpdates[] = "started_at = NOW()";
        $timingUpdates[] = "waiting_time = TIMESTAMPDIFF(MINUTE, ?, NOW())";
        $timingParams[] = $orderDate;
        $timingTypes .= "s";
    } elseif ($isLabTechnician && $currentStatus === 'in_progress' && $status === 'completed' && $startedAt) {
        // Transition: in_progress → completed
        // Set completed_at and calculate turnaround_time
        $timingUpdates[] = "completed_at = NOW()";
        $timingUpdates[] = "turnaround_time = TIMESTAMPDIFF(MINUTE, started_at, NOW())";
    }

    // Build the update SQL
    $updateSql = "UPDATE lab_order_items 
                  SET status = ?, remarks = ?, updated_at = NOW()";
    
    if (!empty($timingUpdates)) {
        $updateSql .= ", " . implode(", ", $timingUpdates);
    }
    
    $updateSql .= " WHERE item_id = ?";
    
    // Prepare parameters
    $params = [$status, $remarks];
    $types = "ss";
    
    // Add timing parameters if any
    if (!empty($timingParams)) {
        $params = array_merge($params, $timingParams);
        $types .= $timingTypes;
    }
    
    // Add item_id parameter
    $params[] = $lab_order_item_id;
    $types .= "i";

    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param($types, ...$params);
    $updateStmt->execute();

    if ($updateStmt->affected_rows === 0) {
        throw new Exception('Lab order item not found or no changes made');
    }

    // Update overall order status using utility function and calculate average turnaround time
    $lab_order_id = $currentData['lab_order_id'];
    
    require_once '../../../utils/LabOrderStatusManager.php';
    $statusUpdated = updateLabOrderStatus($lab_order_id, $conn);
    
    if (!$statusUpdated) {
        error_log("Warning: Could not update lab order status for order ID: $lab_order_id");
    }
    
    // Update average turnaround time separately
    $avgTatSql = "SELECT AVG(CASE WHEN turnaround_time IS NOT NULL THEN turnaround_time END) as avg_turnaround
                  FROM lab_order_items 
                  WHERE lab_order_id = ?";
    
    $avgStmt = $conn->prepare($avgTatSql);
    $avgStmt->bind_param("i", $lab_order_id);
    $avgStmt->execute();
    $avgResult = $avgStmt->get_result()->fetch_assoc();
    
    // Update average turnaround time if column exists
    $checkTatColumn = $conn->query("SHOW COLUMNS FROM lab_orders LIKE 'average_tat'");
    if ($checkTatColumn->num_rows > 0 && $avgResult['avg_turnaround'] !== null) {
        $updateTatSql = "UPDATE lab_orders SET average_tat = ?, updated_at = NOW() WHERE lab_order_id = ?";
        $updateTatStmt = $conn->prepare($updateTatSql);
        $updateTatStmt->bind_param("di", $avgResult['avg_turnaround'], $lab_order_id);
        $updateTatStmt->execute();
    }

    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Lab test status updated successfully'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}