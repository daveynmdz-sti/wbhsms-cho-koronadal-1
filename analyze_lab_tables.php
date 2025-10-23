<?php
require_once 'config/db.php';

echo "<h2>Complete Lab Tables Analysis</h2>\n";
echo "<pre>\n";

try {
    // Analyze lab_orders table structure
    echo "=== LAB_ORDERS TABLE STRUCTURE ===\n";
    $stmt = $pdo->query('DESCRIBE lab_orders');
    $lab_orders_fields = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lab_orders_fields[] = $row['Field'];
        printf("%-25s %-20s %-8s %-8s %-15s %-10s\n", 
            $row['Field'], $row['Type'], $row['Null'], $row['Key'], 
            $row['Default'] ?? 'NULL', $row['Extra']
        );
    }
    
    echo "\n=== LAB_ORDER_ITEMS TABLE STRUCTURE ===\n";
    $stmt = $pdo->query('DESCRIBE lab_order_items');
    $lab_items_fields = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lab_items_fields[] = $row['Field'];
        printf("%-25s %-20s %-8s %-8s %-15s %-10s\n", 
            $row['Field'], $row['Type'], $row['Null'], $row['Key'], 
            $row['Default'] ?? 'NULL', $row['Extra']
        );
    }
    
    echo "\n=== SAMPLE DATA FROM LAB_ORDERS ===\n";
    $stmt = $pdo->query('SELECT * FROM lab_orders ORDER BY id DESC LIMIT 3');
    $lab_orders_sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($lab_orders_sample) > 0) {
        foreach ($lab_orders_sample as $i => $order) {
            echo "Lab Order " . ($i + 1) . ":\n";
            foreach ($order as $key => $value) {
                echo "  $key: " . ($value ?? 'NULL') . "\n";
            }
            echo "\n";
        }
    } else {
        echo "No lab orders found in database\n";
    }
    
    echo "\n=== SAMPLE DATA FROM LAB_ORDER_ITEMS ===\n";
    $stmt = $pdo->query('SELECT * FROM lab_order_items ORDER BY id DESC LIMIT 3');
    $lab_items_sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($lab_items_sample) > 0) {
        foreach ($lab_items_sample as $i => $item) {
            echo "Lab Item " . ($i + 1) . ":\n";
            foreach ($item as $key => $value) {
                echo "  $key: " . ($value ?? 'NULL') . "\n";
            }
            echo "\n";
        }
    } else {
        echo "No lab order items found in database\n";
    }
    
    echo "\n=== TABLE RELATIONSHIP ANALYSIS ===\n";
    
    // Check foreign key relationships
    $stmt = $pdo->query("
        SELECT 
            COLUMN_NAME, 
            CONSTRAINT_NAME, 
            REFERENCED_TABLE_NAME, 
            REFERENCED_COLUMN_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'wbhsms_database' 
        AND (TABLE_NAME = 'lab_orders' OR TABLE_NAME = 'lab_order_items')
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    
    echo "Foreign Key Relationships:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$row['COLUMN_NAME']} -> {$row['REFERENCED_TABLE_NAME']}.{$row['REFERENCED_COLUMN_NAME']}\n";
    }
    
    echo "\n=== JOIN COMPATIBILITY TEST ===\n";
    
    // Test different possible JOIN scenarios
    $join_tests = [
        "lab_order_items.lab_order_id = lab_orders.id",
        "lab_order_items.lab_order_id = lab_orders.lab_order_id", 
        "lab_order_items.order_id = lab_orders.id",
        "lab_order_items.id = lab_orders.lab_order_item_id"
    ];
    
    foreach ($join_tests as $join_condition) {
        try {
            $test_query = "
                SELECT COUNT(*) as count 
                FROM lab_order_items 
                JOIN lab_orders ON $join_condition 
                LIMIT 1
            ";
            $stmt = $pdo->query($test_query);
            $result = $stmt->fetchColumn();
            echo "✓ JOIN condition '$join_condition' works - $result records\n";
        } catch (Exception $e) {
            echo "✗ JOIN condition '$join_condition' failed: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== PATIENT DATA AVAILABILITY ===\n";
    
    // Check which patients have lab data
    $stmt = $pdo->query("
        SELECT 
            p.patient_id,
            p.first_name,
            p.last_name,
            COUNT(DISTINCT lo.id) as lab_orders_count,
            COUNT(loi.id) as lab_items_count
        FROM patients p
        LEFT JOIN lab_orders lo ON p.patient_id = lo.patient_id
        LEFT JOIN lab_order_items loi ON lo.id = loi.lab_order_id
        GROUP BY p.patient_id, p.first_name, p.last_name
        HAVING lab_orders_count > 0 OR lab_items_count > 0
        ORDER BY lab_items_count DESC
        LIMIT 10
    ");
    
    echo "Patients with lab data:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  Patient {$row['patient_id']} ({$row['first_name']} {$row['last_name']}): {$row['lab_orders_count']} orders, {$row['lab_items_count']} items\n";
    }
    
    echo "\n=== RECOMMENDED QUERY STRUCTURE ===\n";
    
    // Find the working JOIN and create optimal query
    try {
        $stmt = $pdo->query("
            SELECT 
                loi.id as lab_order_item_id,
                loi.lab_order_id,
                lo.patient_id,
                loi.test_name,
                loi.test_type,
                loi.sample_type,
                loi.result_value,
                loi.result_unit,
                loi.result_status,
                loi.normal_range,
                loi.result_date,
                loi.created_at,
                lo.order_date,
                lo.status as order_status,
                CONCAT(e.first_name, ' ', e.last_name) as ordered_by_doctor
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
            LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
            ORDER BY COALESCE(loi.result_date, loi.created_at) DESC
            LIMIT 5
        ");
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "✓ Recommended query works! Found " . count($results) . " total lab items\n";
        
        if (count($results) > 0) {
            echo "\nSample results:\n";
            foreach (array_slice($results, 0, 2) as $i => $result) {
                echo "Result " . ($i + 1) . ":\n";
                echo "  Patient ID: {$result['patient_id']}\n";
                echo "  Test: {$result['test_name']}\n";
                echo "  Result: {$result['result_value']} {$result['result_unit']}\n";
                echo "  Status: {$result['result_status']}\n";
                echo "  Date: " . ($result['result_date'] ?? $result['created_at']) . "\n\n";
            }
        }
        
    } catch (Exception $e) {
        echo "✗ Recommended query failed: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>