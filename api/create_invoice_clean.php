<?php
// Create Invoice API - Uses existing billing table structure

// Root path for includes
$root_path = dirname(__DIR__);

// Include database connection
require_once $root_path . '/config/db.php';

// Set content type
header('Content-Type: application/json');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['patient_id', 'services'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$patient_id = intval($input['patient_id']);
$services = $input['services'];
$discount_type = $input['discount_type'] ?? 'none';
$discount_percentage = floatval($input['discount_percentage'] ?? 0);
$notes = $input['notes'] ?? '';
$cashier_id = $input['cashier_id'] ?? 1; // Default for testing

// Validate services array
if (!is_array($services) || empty($services)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Services array is required']);
    exit();
}
try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Verify patient exists
    $patient_check = $pdo->prepare("SELECT patient_id FROM patients WHERE patient_id = ? AND status = 'active'");
    $patient_check->execute([$patient_id]);
    if (!$patient_check->fetch()) {
        throw new Exception("Patient not found or inactive");
    }
    
    // Calculate totals
    $subtotal = 0;
    $valid_services = [];
    
    foreach ($services as $service) {
        $service_id = intval($service['service_item_id']);
        $quantity = intval($service['quantity']);
        
        // Get service details from service_items table
        $service_query = $pdo->prepare("SELECT item_id, item_name, price_php FROM service_items WHERE item_id = ? AND is_active = 1");
        $service_query->execute([$service_id]);
        $service_data = $service_query->fetch(PDO::FETCH_ASSOC);
        
        if (!$service_data) {
            throw new Exception("Service item not found: $service_id");
        }
        
        $unit_price = floatval($service_data['price_php']);
        $line_total = $unit_price * $quantity;
        $subtotal += $line_total;
        
        $valid_services[] = [
            'service_item_id' => $service_id,
            'service_name' => $service_data['item_name'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $line_total
        ];
    }
    
    // Calculate discount
    $discount_amount = ($subtotal * $discount_percentage) / 100;
    $net_amount = $subtotal - $discount_amount;
    
    // Insert into billing table (equivalent to invoices)
    $billing_sql = "
        INSERT INTO billing 
        (patient_id, billing_date, total_amount, discount_amount, discount_type, 
         net_amount, payment_status, notes, created_by, created_at)
        VALUES (?, NOW(), ?, ?, ?, ?, 'unpaid', ?, ?, NOW())
    ";
    
    $billing_stmt = $pdo->prepare($billing_sql);
    $billing_stmt->execute([
        $patient_id,
        $subtotal,
        $discount_amount,
        $discount_type,
        $net_amount,
        $notes,
        $cashier_id
    ]);
    
    $billing_id = $pdo->lastInsertId();
    
    // Insert billing items (equivalent to invoice_items)
    foreach ($valid_services as $service) {
        $item_sql = "
            INSERT INTO billing_items 
            (billing_id, service_item_id, item_price, quantity, subtotal, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ";
        
        $item_stmt = $pdo->prepare($item_sql);
        $item_stmt->execute([
            $billing_id,
            $service['service_item_id'],
            $service['unit_price'],
            $service['quantity'],
            $service['total_price']
        ]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Invoice created successfully',
        'data' => [
            'billing_id' => $billing_id, // Using billing_id instead of invoice_id
            'invoice_id' => $billing_id, // For frontend compatibility
            'patient_id' => $patient_id,
            'subtotal' => $subtotal,
            'discount_type' => $discount_type,
            'discount_percentage' => $discount_percentage,
            'discount_amount' => $discount_amount,
            'total_amount' => $net_amount,
            'services' => $valid_services,
            'status' => 'unpaid'
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Create Invoice Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to create invoice: ' . $e->getMessage()
    ]);
}
?>