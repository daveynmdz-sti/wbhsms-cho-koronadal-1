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

// Check if user is logged in (this API can be called by any authenticated user)
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    // Get current time and check if it's after 5 PM
    $current_time = date('H:i:s');
    $current_date = date('Y-m-d');
    
    // Only run auto-cancellation after 5 PM (17:00)
    if ($current_time < '17:00:00') {
        echo json_encode([
            'success' => true,
            'message' => 'Auto-cancellation only runs after 5 PM',
            'cancelled_count' => 0
        ]);
        exit();
    }
    
    // Find lab orders that meet cancellation criteria:
    // 1. Order date is today
    // 2. Status is 'pending' or 'in_progress' 
    // 3. No lab order items have been completed
    // 4. Current time is after 5 PM
    
    $findExpiredSql = "
        SELECT DISTINCT lo.lab_order_id, lo.patient_id, lo.order_date
        FROM lab_orders lo
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE DATE(lo.order_date) = CURDATE()
        AND (lo.overall_status IN ('pending', 'in_progress') OR (lo.overall_status IS NULL AND lo.status IN ('pending', 'in_progress')))
        AND lo.lab_order_id NOT IN (
            SELECT DISTINCT loi2.lab_order_id 
            FROM lab_order_items loi2 
            WHERE loi2.lab_order_id = lo.lab_order_id 
            AND loi2.status = 'completed'
        )
        AND TIME(NOW()) >= '17:00:00'
    ";
    
    $findExpiredStmt = $conn->prepare($findExpiredSql);
    $findExpiredStmt->execute();
    $expiredOrders = $findExpiredStmt->get_result();
    
    $cancelled_count = 0;
    $cancelled_orders = [];
    
    if ($expiredOrders->num_rows > 0) {
        // Begin transaction for batch cancellation
        $conn->begin_transaction();
        
        while ($order = $expiredOrders->fetch_assoc()) {
            $lab_order_id = $order['lab_order_id'];
            
            try {
                // Update the lab order status to cancelled
                $updateSql = "UPDATE lab_orders SET 
                                status = 'cancelled',
                                overall_status = 'cancelled',
                                remarks = CONCAT(COALESCE(remarks, ''), IF(COALESCE(remarks, '') = '', '', '; '), 'Automatically cancelled at ', NOW(), ' - not fulfilled by 5 PM deadline'),
                                updated_at = NOW()
                              WHERE lab_order_id = ?";
                
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("i", $lab_order_id);
                
                if ($updateStmt->execute()) {
                    // Update all associated lab order items to cancelled
                    $updateItemsSql = "UPDATE lab_order_items SET 
                                        status = 'cancelled',
                                        updated_at = NOW()
                                       WHERE lab_order_id = ? AND status != 'completed'";
                    
                    $updateItemsStmt = $conn->prepare($updateItemsSql);
                    $updateItemsStmt->bind_param("i", $lab_order_id);
                    $updateItemsStmt->execute();
                    
                    // Log the automatic cancellation
                    $logSql = "INSERT INTO lab_order_logs (lab_order_id, employee_id, action, details, created_at) 
                               VALUES (?, 0, 'auto_cancelled', ?, NOW())";
                    
                    $logStmt = $conn->prepare($logSql);
                    $action_details = "Lab order automatically cancelled at " . date('Y-m-d H:i:s') . " - not fulfilled by 5 PM deadline";
                    $logStmt->bind_param("is", $lab_order_id, $action_details);
                    $logStmt->execute();
                    
                    $cancelled_count++;
                    $cancelled_orders[] = $lab_order_id;
                }
                
            } catch (Exception $e) {
                error_log("Error cancelling lab order {$lab_order_id}: " . $e->getMessage());
                continue; // Continue with other orders
            }
        }
        
        // Commit the transaction
        $conn->commit();
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Auto-cancellation completed",
        'cancelled_count' => $cancelled_count,
        'cancelled_orders' => $cancelled_orders,
        'check_time' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    // Rollback the transaction if it was started
    if (isset($conn)) {
        $conn->rollback();
    }
    
    error_log("Auto-cancellation error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during auto-cancellation: ' . $e->getMessage(),
        'cancelled_count' => 0
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>