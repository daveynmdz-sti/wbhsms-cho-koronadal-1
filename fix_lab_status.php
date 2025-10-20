<?php
require_once 'config/db.php';
require_once 'utils/LabOrderStatusManager.php';

echo "Checking for lab orders with status issues...\n";

$sql = "SELECT lo.lab_order_id, lo.status, lo.overall_status,
              COUNT(loi.item_id) as total_items,
              SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_items
       FROM lab_orders lo
       LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
       GROUP BY lo.lab_order_id
       HAVING total_items > 0 AND completed_items = total_items AND (lo.overall_status != 'completed' OR lo.status != 'completed')";

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " lab orders with status issues:\n";
    while ($row = $result->fetch_assoc()) {
        echo "Lab Order ID: {$row['lab_order_id']}, Status: {$row['status']}, Overall Status: {$row['overall_status']}, Items: {$row['completed_items']}/{$row['total_items']}\n";
        
        // Fix the status using the utility
        echo "Updating status for lab order {$row['lab_order_id']}...\n";
        $updated = updateLabOrderStatus($row['lab_order_id'], $conn);
        echo "Update result: " . ($updated ? 'Success' : 'Failed') . "\n";
    }
} else {
    echo "No lab orders found with status issues.\n";
}

echo "Done!\n";
?>