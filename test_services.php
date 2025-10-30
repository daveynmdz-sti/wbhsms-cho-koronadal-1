<?php
require_once 'config/db.php';

echo "Testing database connection and service_items table...\n";

try {
    // Check if service_items table exists
    $stmt = $pdo->query('SHOW TABLES LIKE "service_items"');
    if ($stmt->rowCount() > 0) {
        echo "✓ service_items table exists\n";
        
        // Check table structure
        $stmt = $pdo->query('DESCRIBE service_items');
        echo "\nTable structure:\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
        
        // Check for data
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM service_items WHERE is_active = 1');
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "\nActive service items: " . $count . "\n";
        
        if ($count > 0) {
            echo "\nSample data:\n";
            $stmt = $pdo->query('SELECT item_id, item_name, price_php, unit FROM service_items WHERE is_active = 1 LIMIT 5');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "  ID: " . $row['item_id'] . ", Name: " . $row['item_name'] . ", Price: ₱" . $row['price_php'] . ", Unit: " . $row['unit'] . "\n";
            }
        } else {
            echo "\nNo active service items found. Let's add some test data:\n";
            
            // Insert some test service items
            $test_services = [
                ['Consultation Fee', 50.00, 'service'],
                ['Blood Pressure Check', 20.00, 'service'],
                ['Laboratory Test - CBC', 150.00, 'test'],
                ['Prescription Medication', 25.00, 'medicine'],
                ['X-Ray Service', 200.00, 'service']
            ];
            
            $insert_stmt = $pdo->prepare("INSERT INTO service_items (item_name, price_php, unit, is_active) VALUES (?, ?, ?, 1)");
            
            foreach ($test_services as $service) {
                $insert_stmt->execute($service);
                echo "  Added: " . $service[0] . " - ₱" . $service[1] . "\n";
            }
            
            echo "\nTest services added successfully!\n";
        }
    } else {
        echo "✗ service_items table does not exist\n";
        
        // Show available tables
        $stmt = $pdo->query('SHOW TABLES');
        echo "\nAvailable tables:\n";
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            echo "  " . $row[0] . "\n";
        }
    }
    
    // Test the API directly
    echo "\n" . str_repeat('-', 50) . "\n";
    echo "Testing API response...\n";
    
    $services_sql = "
        SELECT 
            si.item_id as service_item_id,
            si.item_name as service_name,
            si.price_php as price,
            si.unit,
            COALESCE(s.name, 'General') as category
        FROM service_items si
        LEFT JOIN services s ON si.service_id = s.service_id
        WHERE si.is_active = 1
        ORDER BY COALESCE(s.name, 'General'), si.item_name
    ";
    
    $services_stmt = $pdo->prepare($services_sql);
    $services_stmt->execute();
    $service_items = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response for frontend compatibility
    $formatted_services = [];
    foreach ($service_items as $item) {
        $formatted_services[] = [
            'service_item_id' => $item['service_item_id'],
            'service_name' => $item['service_name'],
            'price' => floatval($item['price']),
            'formatted_price' => '₱' . number_format($item['price'], 2),
            'unit' => $item['unit'],
            'category' => $item['category'] ?? 'General'
        ];
    }
    
    $response = [
        'success' => true,
        'services' => $formatted_services,
        'summary' => [
            'total_items' => count($service_items),
        ]
    ];
    
    echo "API Response (JSON):\n";
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>