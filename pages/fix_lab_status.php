<?php
// Include employee session configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check if user is logged in and is admin - use session management functions
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Check admin role authorization
require_employee_role(['admin']);

require_once $root_path . '/utils/LabOrderStatusManager.php';

$message = '';
$messageType = '';

if ($_POST['action'] ?? '' === 'fix_status') {
    try {
        // Check for lab orders with mismatched status
        $sql = "SELECT lo.lab_order_id, lo.status, lo.overall_status,
                      COUNT(loi.item_id) as total_items,
                      SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_items
               FROM lab_orders lo
               LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
               GROUP BY lo.lab_order_id
               HAVING total_items > 0 AND completed_items = total_items AND (lo.overall_status != 'completed' OR lo.status != 'completed')";

        $result = $conn->query($sql);
        $fixedCount = 0;
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $updated = updateLabOrderStatus($row['lab_order_id'], $conn);
                if ($updated) {
                    $fixedCount++;
                }
            }
            $message = "Fixed status for $fixedCount lab orders with completed items.";
            $messageType = 'success';
        } else {
            $message = "No lab orders found with status issues.";
            $messageType = 'info';
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get current problematic lab orders for display
$problemsSql = "SELECT lo.lab_order_id, lo.status, lo.overall_status,
                      COUNT(loi.item_id) as total_items,
                      SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                      p.first_name, p.last_name, p.username as patient_id_display
               FROM lab_orders lo
               LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
               LEFT JOIN patients p ON lo.patient_id = p.patient_id
               GROUP BY lo.lab_order_id
               HAVING total_items > 0 AND completed_items = total_items AND (lo.overall_status != 'completed' OR lo.status != 'completed')
               ORDER BY lo.order_date DESC";

$problemsResult = $conn->query($problemsSql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Order Status Fix - WBHSMS</title>
    <link rel="stylesheet" href="../assets/css/sidebar.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .content-wrapper {
            margin-left: 300px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 10px;
            }
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            color: #0077b6;
            margin: 0;
            font-size: 1.8rem;
        }
        
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background-color: #0077b6;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #005577;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
        }
        
        .table th,
        .table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #03045e;
        }
        
        .table tr:hover {
            background-color: #f5f5f5;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }
        
        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Include Admin Sidebar -->
    <?php include $root_path . '/includes/sidebar_admin.php'; ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="management/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="laboratory-management/lab_management.php">Laboratory Management</a>
            <i class="fas fa-chevron-right"></i>
            <span>Status Fix</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-tools"></i> Lab Order Status Fix Utility</h1>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span><i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'exclamation-circle' : 'info-circle') ?>"></i> <?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Lab Orders with Status Issues</h3>
            <p>This utility finds lab orders where all items are completed but the parent order status hasn't been updated to "completed".</p>
            
            <?php if ($problemsResult && $problemsResult->num_rows > 0): ?>
                <p><strong>Found <?= $problemsResult->num_rows ?> lab orders with status issues:</strong></p>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th>Lab Order ID</th>
                            <th>Patient</th>
                            <th>Current Status</th>
                            <th>Overall Status</th>
                            <th>Completed Items</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $problemsResult->fetch_assoc()): ?>
                            <tr>
                                <td><?= $row['lab_order_id'] ?></td>
                                <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?><br>
                                    <small>ID: <?= $row['patient_id_display'] ?></small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $row['status'] ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $row['overall_status'] ?>">
                                        <?= ucfirst($row['overall_status']) ?>
                                    </span>
                                </td>
                                <td><?= $row['completed_items'] ?>/<?= $row['total_items'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <form method="POST" style="margin-top: 20px;">
                    <input type="hidden" name="action" value="fix_status">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Are you sure you want to fix the status for all these lab orders?')">
                        <i class="fas fa-wrench"></i> Fix All Status Issues
                    </button>
                </form>
            <?php else: ?>
                <div class="alert alert-success">
                    <span><i class="fas fa-check-circle"></i> No lab orders found with status issues. All lab order statuses are properly synchronized!</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>How This Works</h3>
            <p>This utility:</p>
            <ul>
                <li>Checks all lab orders to find cases where all individual test items are completed</li>
                <li>Identifies orders where the parent lab order status is still "pending" or "in_progress"</li>
                <li>Uses the LabOrderStatusManager utility to automatically update the parent order status to "completed"</li>
                <li>Ensures that the lab management interface shows the correct status for all orders</li>
            </ul>
        </div>
    </section>
</body>
</html>