<?php
try {
    require_once 'config/db.php';
    
    echo "Checking actual lab_order_items table structure:\n\n";
    
    // First, let's see the actual table structure
    $result = $pdo->query('DESCRIBE lab_order_items');
    echo "lab_order_items columns:\n";
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    
    echo "\n\nSample data from lab_order_items:\n";
    $result = $pdo->query('SELECT * FROM lab_order_items LIMIT 3');
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "---\n";
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>