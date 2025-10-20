<?php
/**
 * Laboratory Order Status Management Utility
 * Provides functions to automatically manage lab order status based on item completion
 */

/**
 * Update lab order overall status based on item completion
 * @param int $lab_order_id The lab order ID to update
 * @param mysqli $conn Database connection
 * @return bool True if update was successful, false otherwise
 */
function updateLabOrderStatus($lab_order_id, $conn) {
    try {
        // Get item completion status for this lab order
        $checkSql = "SELECT 
                        COUNT(*) as total_items,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_items,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_items
                     FROM lab_order_items 
                     WHERE lab_order_id = ?";
        
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $lab_order_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result()->fetch_assoc();
        
        if (!$result || $result['total_items'] == 0) {
            return false; // No items found
        }
        
        // Determine the appropriate status
        $newStatus = 'pending'; // default
        
        if ($result['completed_items'] == $result['total_items']) {
            // All items completed
            $newStatus = 'completed';
        } elseif ($result['completed_items'] > 0 || $result['in_progress_items'] > 0) {
            // Some items completed or in progress
            $newStatus = 'in_progress';
        } elseif ($result['cancelled_items'] == $result['total_items']) {
            // All items cancelled
            $newStatus = 'cancelled';
        }
        // else remains 'pending'
        
        // Update the lab order status
        $updateSql = "UPDATE lab_orders SET overall_status = ? WHERE lab_order_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $newStatus, $lab_order_id);
        
        return $updateStmt->execute();
        
    } catch (Exception $e) {
        error_log("Error updating lab order status: " . $e->getMessage());
        return false;
    }
}

/**
 * Update lab order status after item status change
 * @param int $item_id The lab order item ID that was updated
 * @param mysqli $conn Database connection
 * @return bool True if update was successful, false otherwise
 */
function updateLabOrderStatusFromItem($item_id, $conn) {
    try {
        // Get the lab order ID for this item
        $getOrderSql = "SELECT lab_order_id FROM lab_order_items WHERE item_id = ?";
        $getOrderStmt = $conn->prepare($getOrderSql);
        $getOrderStmt->bind_param("i", $item_id);
        $getOrderStmt->execute();
        $orderResult = $getOrderStmt->get_result()->fetch_assoc();
        
        if (!$orderResult) {
            return false;
        }
        
        return updateLabOrderStatus($orderResult['lab_order_id'], $conn);
        
    } catch (Exception $e) {
        error_log("Error updating lab order status from item: " . $e->getMessage());
        return false;
    }
}

/**
 * Get lab order status summary
 * @param int $lab_order_id The lab order ID
 * @param mysqli $conn Database connection
 * @return array|false Array with status information or false on error
 */
function getLabOrderStatusSummary($lab_order_id, $conn) {
    try {
        $sql = "SELECT 
                    lo.overall_status,
                    COUNT(loi.item_id) as total_items,
                    SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                    SUM(CASE WHEN loi.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_items,
                    SUM(CASE WHEN loi.status = 'pending' THEN 1 ELSE 0 END) as pending_items,
                    SUM(CASE WHEN loi.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_items
                FROM lab_orders lo
                LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
                WHERE lo.lab_order_id = ?
                GROUP BY lo.lab_order_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $lab_order_id);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_assoc();
        
    } catch (Exception $e) {
        error_log("Error getting lab order status summary: " . $e->getMessage());
        return false;
    }
}
?>