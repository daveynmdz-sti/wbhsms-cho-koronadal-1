<?php
// Test the billing API and database structure
try {
    require_once 'C:/xampp/htdocs/wbhsms-cho-koronadal-1/config/db.php';
    
    echo "Database connection: " . (isset($pdo) ? "OK" : "FAILED") . "\n";
    
    // Test billing table structure
    $result = $pdo->query('DESCRIBE billing');
    echo "Billing table columns:\n";
    while ($row = $result->fetch()) {
        echo "- " . $row['Field'] . "\n";
    }
    
    echo "\nTesting sample billing record:\n";
    $stmt = $pdo->query('SELECT * FROM billing LIMIT 1');
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($billing) {
        echo "Sample billing ID: " . $billing['billing_id'] . "\n";
        echo "Patient ID: " . $billing['patient_id'] . "\n";
        print_r($billing);
    } else {
        echo "No billing records found\n";
    }
    
    // Test patients table structure
    echo "\nPatients table columns:\n";
    $result = $pdo->query('DESCRIBE patients');
    while ($row = $result->fetch()) {
        echo "- " . $row['Field'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>