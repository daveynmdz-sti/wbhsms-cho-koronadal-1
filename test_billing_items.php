<?php
try {
    require_once 'C:/xampp/htdocs/wbhsms-cho-koronadal-1/config/db.php';
    
    echo "Billing items table structure:\n";
    $result = $pdo->query('DESCRIBE billing_items');
    while ($row = $result->fetch()) {
        echo "- " . $row['Field'] . "\n";
    }
    
    echo "\nSample billing items:\n";
    $stmt = $pdo->query('SELECT * FROM billing_items LIMIT 3');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>