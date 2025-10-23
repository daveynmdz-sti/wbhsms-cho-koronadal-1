<?php
require_once 'config/db.php';

echo "<h2>Lab Order Items Table Structure</h2>\n";
echo "<pre>\n";

try {
    // Get table structure
    $stmt = $pdo->query('DESCRIBE lab_order_items');
    echo "Field                Type            Null     Key      Default         Extra\n";
    echo str_repeat('=', 80) . "\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-20s %-15s %-8s %-8s %-15s %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'], 
            $row['Default'] ?? 'NULL', 
            $row['Extra']
        );
    }
    
    echo "\n\nSample Lab Order Items Data:\n";
    echo str_repeat('=', 80) . "\n";
    $stmt = $pdo->query('SELECT * FROM lab_order_items ORDER BY id DESC LIMIT 3');
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        echo "Record $count:\n";
        foreach ($row as $key => $value) {
            echo "  $key: " . ($value ?? 'NULL') . "\n";
        }
        echo "\n";
    }
    
    echo "\n\nLab Orders Table Structure:\n";
    echo str_repeat('=', 80) . "\n";
    $stmt = $pdo->query('DESCRIBE lab_orders');
    echo "Field                Type            Null     Key      Default         Extra\n";
    echo str_repeat('-', 80) . "\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-20s %-15s %-8s %-8s %-15s %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'], 
            $row['Default'] ?? 'NULL', 
            $row['Extra']
        );
    }
    
    echo "\n\nSample Lab Orders Data:\n";
    echo str_repeat('=', 80) . "\n";
    $stmt = $pdo->query('SELECT * FROM lab_orders ORDER BY id DESC LIMIT 3');
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        echo "Record $count:\n";
        foreach ($row as $key => $value) {
            echo "  $key: " . ($value ?? 'NULL') . "\n";
        }
        echo "\n";
    }
    
    // Check if there are any lab_order_items with patient_id
    echo "\n\nChecking for lab_order_items with patient relationships:\n";
    echo str_repeat('=', 80) . "\n";
    $stmt = $pdo->query("
        SELECT 
            loi.*, 
            lo.patient_id 
        FROM lab_order_items loi 
        LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.id 
        WHERE lo.patient_id IS NOT NULL 
        ORDER BY loi.id DESC 
        LIMIT 5
    ");
    
    if ($stmt->rowCount() > 0) {
        echo "Found lab order items linked to patients:\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "Lab Order Item ID: {$row['id']}, Patient ID: {$row['patient_id']}, Test: {$row['test_name']}\n";
        }
    } else {
        echo "No lab order items found linked to patients.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
?>