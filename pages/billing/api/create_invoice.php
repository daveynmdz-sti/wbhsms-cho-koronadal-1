<?php
/**
 * API Endpoint: Create Invoice
 * Purpose: Creates a new billing invoice with selected services
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

$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    // Get POST data
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $visit_id = intval($_POST['visit_id'] ?? 0);
    $service_items = json_decode($_POST['service_items'] ?? '[]', true);
    $discount_type = $_POST['discount_type'] ?? 'none';
    $notes = trim($_POST['notes'] ?? '');

    // Validate required fields
    if (!$patient_id || !$visit_id || empty($service_items)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    // Start transaction
    $pdo->beginTransaction();

    // Calculate totals
    $total_amount = 0;
    $valid_items = [];

    // Validate service items and calculate totals
    foreach ($service_items as $item) {
        $service_item_id = intval($item['service_item_id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 1);
        
        if (!$service_item_id || $quantity < 1) continue;

        // Get service item details
        $stmt = $pdo->prepare("SELECT item_id, item_name, price_php FROM service_items WHERE item_id = ? AND is_active = 1");
        $stmt->execute([$service_item_id]);
        $service_item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service_item) continue;

        $subtotal = $service_item['price_php'] * $quantity;
        $total_amount += $subtotal;

        $valid_items[] = [
            'service_item_id' => $service_item_id,
            'item_name' => $service_item['item_name'],
            'item_price' => $service_item['price_php'],
            'quantity' => $quantity,
            'subtotal' => $subtotal
        ];
    }

    if (empty($valid_items)) {
        throw new Exception('No valid service items provided');
    }

    // Calculate discount
    $discount_amount = 0;
    if (in_array($discount_type, ['senior', 'pwd'])) {
        $discount_amount = $total_amount * 0.20; // 20% discount
    }

    $net_amount = $total_amount - $discount_amount;

    // Insert billing record
    $stmt = $pdo->prepare("INSERT INTO billing (visit_id, patient_id, total_amount, discount_amount, discount_type, net_amount, payment_status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, 'unpaid', ?, ?)");
    $stmt->execute([$visit_id, $patient_id, $total_amount, $discount_amount, $discount_type, $net_amount, $notes, $employee_id]);
    
    $billing_id = $pdo->lastInsertId();

    // Insert billing items
    $stmt = $pdo->prepare("INSERT INTO billing_items (billing_id, service_item_id, item_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($valid_items as $item) {
        $stmt->execute([
            $billing_id,
            $item['service_item_id'],
            $item['item_price'],
            $item['quantity'],
            $item['subtotal']
        ]);
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Invoice created successfully',
        'data' => [
            'billing_id' => $billing_id,
            'total_amount' => $total_amount,
            'discount_amount' => $discount_amount,
            'net_amount' => $net_amount,
            'items_count' => count($valid_items)
        ]
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    echo json_encode(['success' => false, 'message' => 'Error creating invoice: ' . $e->getMessage()]);
}
?>