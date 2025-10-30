<?php
/**
 * Test Service Catalog API
 * Simplified version for debugging
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Root path for includes
$root_path = dirname(__DIR__);

// Set content type
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Include database connection
    require_once $root_path . '/config/db.php';
    
    // Check if PDO connection exists
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection not available');
    }
    
    // Test basic database connectivity
    $test_stmt = $pdo->query('SELECT 1 as test');
    $test_result = $test_stmt->fetch();
    
    if (!$test_result) {
        throw new Exception('Database connectivity test failed');
    }
    
    // Check if service_items table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'service_items'");
    if ($table_check->rowCount() === 0) {
        // Create some test data if table doesn't exist
        echo json_encode([
            'success' => false,
            'message' => 'service_items table does not exist',
            'debug' => [
                'available_tables' => 'Run SHOW TABLES to see available tables',
                'suggestion' => 'Create service_items table or check database setup'
            ]
        ]);
        exit();
    }
    
    // Get service items count first
    $count_stmt = $pdo->query('SELECT COUNT(*) as count FROM service_items WHERE is_active = 1');
    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $item_count = $count_result['count'];
    
    if ($item_count == 0) {
        // Insert some test data
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
        }
        
        // Re-count
        $count_stmt = $pdo->query('SELECT COUNT(*) as count FROM service_items WHERE is_active = 1');
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
        $item_count = $count_result['count'];
    }
    
    // Get actual service items
    $services_sql = "
        SELECT 
            si.item_id as service_item_id,
            si.item_name as service_name,
            si.price_php as price,
            si.unit
        FROM service_items si
        WHERE si.is_active = 1
        ORDER BY si.item_name
        LIMIT 50
    ";
    
    $services_stmt = $pdo->prepare($services_sql);
    $services_stmt->execute();
    $service_items = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $formatted_services = [];
    foreach ($service_items as $item) {
        $formatted_services[] = [
            'service_item_id' => (int)$item['service_item_id'],
            'service_name' => $item['service_name'],
            'price' => (float)$item['price'],
            'formatted_price' => '₱' . number_format($item['price'], 2),
            'unit' => $item['unit'] ?: 'service',
            'category' => 'General'
        ];
    }
    
    $response = [
        'success' => true,
        'services' => $formatted_services,
        'summary' => [
            'total_items' => count($formatted_services),
            'database_connection' => 'OK',
            'table_exists' => 'OK',
            'items_in_db' => $item_count
        ],
        'debug' => [
            'query_executed' => $services_sql,
            'timestamp' => date('Y-m-d H:i:s'),
            'server_info' => [
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
            ]
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'debug' => [
            'error_code' => $e->getCode(),
            'error_info' => $e->errorInfo ?? 'Not available',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'General error: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_PRETTY_PRINT);
}
?>