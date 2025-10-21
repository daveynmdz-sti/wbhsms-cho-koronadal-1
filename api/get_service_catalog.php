<?php
// Get Service Catalog API
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Root path for includes
$root_path = dirname(__DIR__);

// Include database connection
require_once $root_path . '/config/db.php';

// Set content type
header('Content-Type: application/json');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get service items using the correct table structure
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
    $services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get service items with pricing
    
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
    
    // Add summary statistics
    $total_items = count($service_items);
    $price_range = [
        'min' => 0,
        'max' => 0
    ];
    
    if (!empty($service_items)) {
        $prices = array_column($service_items, 'price');
        $price_range['min'] = min($prices);
        $price_range['max'] = max($prices);
    }
    
    echo json_encode([
        'success' => true,
        'services' => $formatted_services,
        'summary' => [
            'total_items' => $total_items,
            'price_range' => [
                'min' => floatval($price_range['min']),
                'max' => floatval($price_range['max']),
                'formatted_min' => '₱' . number_format($price_range['min'], 2),
                'formatted_max' => '₱' . number_format($price_range['max'], 2)
            ]
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Service Catalog Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred while retrieving service catalog'
    ]);
} catch (Exception $e) {
    error_log("Service Catalog Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred'
    ]);
}
?>