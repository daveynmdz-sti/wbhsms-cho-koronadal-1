<?php
/**
 * API Endpoint: Get Service Items
 * Purpose: Retrieve available service items for billing
 */

header('Content-Type: application/json');

// Include necessary files
$root_path = dirname(dirname(dirname(dirname(__FILE__))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

try {
    // Get query parameters
    $search = $_GET['search'] ?? '';
    $service_id = intval($_GET['service_id'] ?? 0);

    // Build query conditions
    $conditions = ['si.is_active = 1'];
    $params = [];

    if (!empty($search)) {
        $conditions[] = "si.item_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if ($service_id > 0) {
        $conditions[] = "si.service_id = ?";
        $params[] = $service_id;
    }

    $where_clause = 'WHERE ' . implode(' AND ', $conditions);

    // Query to get service items
    $query = "SELECT si.item_id, si.service_id, si.item_name, si.price_php, si.unit,
                     s.service_name, s.service_type
              FROM service_items si
              JOIN services s ON si.service_id = s.service_id
              $where_clause
              ORDER BY s.service_name, si.item_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $service_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by service for better organization
    $grouped_items = [];
    foreach ($service_items as $item) {
        $service_name = $item['service_name'];
        if (!isset($grouped_items[$service_name])) {
            $grouped_items[$service_name] = [
                'service_id' => $item['service_id'],
                'service_name' => $service_name,
                'service_type' => $item['service_type'],
                'items' => []
            ];
        }
        
        $grouped_items[$service_name]['items'][] = [
            'item_id' => $item['item_id'],
            'item_name' => $item['item_name'],
            'price_php' => floatval($item['price_php']),
            'unit' => $item['unit']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'service_items' => array_values($grouped_items),
            'total_items' => count($service_items)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error retrieving service items: ' . $e->getMessage()]);
}
?>