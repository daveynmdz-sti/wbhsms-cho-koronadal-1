<?php
// Complete fix for lab order system
$root_path = dirname(__DIR__);
include $root_path . '/config/db.php';

echo "<h2>Lab Order System - Complete Fix</h2>";

try {
    echo "<h3>Step 1: Check and Fix Database Schema</h3>";
    
    // Check if overall_status column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM lab_orders LIKE 'overall_status'");
    
    if ($checkColumn->num_rows == 0) {
        echo "<p>Adding missing 'overall_status' column...</p>";
        $addColumn = "ALTER TABLE `lab_orders` 
                     ADD COLUMN `overall_status` enum('pending','in_progress','completed','cancelled') 
                     DEFAULT 'pending' AFTER `status`";
        
        if ($conn->query($addColumn)) {
            echo "<p style='color: green;'>✓ Added overall_status column successfully</p>";
            
            // Update existing records to match status
            $updateStatus = "UPDATE lab_orders SET overall_status = status WHERE overall_status IS NULL";
            if ($conn->query($updateStatus)) {
                echo "<p style='color: green;'>✓ Updated existing records</p>";
            }
        } else {
            echo "<p style='color: red;'>✗ Error adding column: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: green;'>✓ overall_status column already exists</p>";
    }
    
    echo "<h3>Step 2: Test Lab Order Creation</h3>";
    
    if (isset($_POST['create_test_order'])) {
        // Test creating a lab order without appointment
        $conn->begin_transaction();
        
        try {
            $patient_id = 7; // David Diaz
            $employee_id = 1; // Alice Smith (Admin)
            $remarks = 'Test direct lab order - ' . date('Y-m-d H:i:s');
            
            // Insert lab order
            $sql = "INSERT INTO lab_orders (patient_id, appointment_id, visit_id, ordered_by_employee_id, remarks, status, overall_status) 
                    VALUES (?, NULL, NULL, ?, ?, 'pending', 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iis", $patient_id, $employee_id, $remarks);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create lab order: " . $stmt->error);
            }
            
            $lab_order_id = $conn->insert_id;
            
            // Insert lab order items
            $tests = ['Complete Blood Count (CBC)', 'Urinalysis', 'Blood Typing'];
            $itemSql = "INSERT INTO lab_order_items (lab_order_id, test_type, status) VALUES (?, ?, 'pending')";
            $itemStmt = $conn->prepare($itemSql);
            
            foreach ($tests as $test) {
                $itemStmt->bind_param("is", $lab_order_id, $test);
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to add test item: " . $itemStmt->error);
                }
            }
            
            $conn->commit();
            
            echo "<div style='color: green; padding: 15px; background: #e8f5e8; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4>✅ SUCCESS: Lab Order Created!</h4>";
            echo "<p><strong>Lab Order ID:</strong> {$lab_order_id}</p>";
            echo "<p><strong>Patient:</strong> David Diaz (P000007)</p>";
            echo "<p><strong>Tests:</strong> " . implode(', ', $tests) . "</p>";
            echo "<p><strong>Status:</strong> Pending</p>";
            echo "<p><strong>Appointment ID:</strong> NULL (Direct Order)</p>";
            echo "</div>";
            
        } catch (Exception $e) {
            $conn->rollback();
            echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h3>Step 3: Current Lab Orders Statistics</h3>";
    
    // Get current statistics
    $statsQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN overall_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN overall_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN overall_status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN overall_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                   FROM lab_orders 
                   WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $statsResult = $conn->query($statsQuery);
    
    if ($statsResult) {
        $stats = $statsResult->fetch_assoc();
        echo "<div style='display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin: 15px 0;'>";
        echo "<div style='text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;'>";
        echo "<div style='font-size: 2em; font-weight: bold; color: #0077b6;'>{$stats['total']}</div>";
        echo "<div style='font-size: 0.9em; color: #666;'>TOTAL</div></div>";
        
        echo "<div style='text-align: center; padding: 15px; background: #fff3cd; border-radius: 8px;'>";
        echo "<div style='font-size: 2em; font-weight: bold; color: #856404;'>{$stats['pending']}</div>";
        echo "<div style='font-size: 0.9em; color: #666;'>PENDING</div></div>";
        
        echo "<div style='text-align: center; padding: 15px; background: #d1ecf1; border-radius: 8px;'>";
        echo "<div style='font-size: 2em; font-weight: bold; color: #0c5460;'>{$stats['in_progress']}</div>";
        echo "<div style='font-size: 0.9em; color: #666;'>IN PROGRESS</div></div>";
        
        echo "<div style='text-align: center; padding: 15px; background: #d4edda; border-radius: 8px;'>";
        echo "<div style='font-size: 2em; font-weight: bold; color: #155724;'>{$stats['completed']}</div>";
        echo "<div style='font-size: 0.9em; color: #666;'>COMPLETED</div></div>";
        
        echo "<div style='text-align: center; padding: 15px; background: #f8d7da; border-radius: 8px;'>";
        echo "<div style='font-size: 2em; font-weight: bold; color: #721c24;'>{$stats['cancelled']}</div>";
        echo "<div style='font-size: 0.9em; color: #666;'>CANCELLED</div></div>";
        echo "</div>";
    }
    
    echo "<h3>Step 4: Recent Lab Orders</h3>";
    
    $recentQuery = "SELECT lo.lab_order_id, lo.patient_id, lo.appointment_id, lo.order_date, lo.overall_status,
                          p.first_name, p.last_name, p.username,
                          e.first_name as emp_first, e.last_name as emp_last,
                          COUNT(loi.item_id) as test_count
                   FROM lab_orders lo
                   LEFT JOIN patients p ON lo.patient_id = p.patient_id
                   LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
                   LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
                   GROUP BY lo.lab_order_id
                   ORDER BY lo.order_date DESC 
                   LIMIT 10";
    
    $recentResult = $conn->query($recentQuery);
    
    if ($recentResult && $recentResult->num_rows > 0) {
        echo "<table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Order ID</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Patient</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Appointment</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Date</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Tests</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Status</th>";
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>Ordered By</th>";
        echo "</tr>";
        
        while ($row = $recentResult->fetch_assoc()) {
            $appointmentDisplay = $row['appointment_id'] ? "#{$row['appointment_id']}" : "<em>Direct</em>";
            $statusColor = [
                'pending' => '#ffc107',
                'in_progress' => '#17a2b8', 
                'completed' => '#28a745',
                'cancelled' => '#dc3545'
            ];
            
            echo "<tr>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>#{$row['lab_order_id']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$row['first_name']} {$row['last_name']}<br><small>{$row['username']}</small></td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$appointmentDisplay}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>" . date('M d, Y H:i', strtotime($row['order_date'])) . "</td>";
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center;'>{$row['test_count']}</td>";
            
            $statusStyle = "color: white; padding: 3px 8px; border-radius: 3px; font-size: 0.8em; background: " . ($statusColor[$row['overall_status']] ?? '#6c757d');
            echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center;'>";
            echo "<span style='{$statusStyle}'>" . ucfirst(str_replace('_', ' ', $row['overall_status'])) . "</span>";
            echo "</td>";
            
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>{$row['emp_first']} {$row['emp_last']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p><em>No lab orders found. Create a test order to verify the system is working.</em></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>System Error:</strong> " . $e->getMessage() . "</p>";
}

?>

<div style="margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;">
    <h3>Test Actions</h3>
    
    <form method="POST" style="display: inline-block; margin: 10px;">
        <button type="submit" name="create_test_order" 
                style="background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
            🧪 Create Test Lab Order
        </button>
    </form>
    
    <div style="margin-top: 20px;">
        <h4>Navigation Links:</h4>
        <p>
            <a href="../pages/laboratory-management/lab_management.php" 
               style="color: #007bff; text-decoration: none; margin-right: 20px;">
               📊 Lab Management Dashboard
            </a>
            
            <a href="../pages/laboratory-management/create_lab_order.php" 
               style="color: #007bff; text-decoration: none; margin-right: 20px;">
               ➕ Create Lab Order
            </a>
            
            <a href="../pages/laboratory-management/debug_lab_orders.php" 
               style="color: #007bff; text-decoration: none;">
               🔧 Debug Lab Orders
            </a>
        </p>
    </div>
</div>