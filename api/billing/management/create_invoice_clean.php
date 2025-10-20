<?php
ob_start(); // Start output buffering to prevent header issues

/**
 * Create Invoice API
 * Creates new invoices (management/cashier access only)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Root path for includes
$root_path = dirname(dirname(dirname(__DIR__)));

// Check if user is logged in as employee with proper role
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Authentication and authorization check
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Authorization check - Admin or Cashier only
$user_role = get_employee_session('role');
if (!in_array($user_role, ['Admin', 'Cashier'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin or Cashier role required.']);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Also check $_POST for form submissions
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    if (!$input) {
        throw new Exception('No input data received');
    }
    
    // Validate required fields
    $required_fields = ['patient_id', 'services'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $patient_id = (int)$input['patient_id'];
    $services = $input['services'];
    $notes = $input['notes'] ?? '';
    $cashier_id = get_employee_session('employee_id');
    
    // Validate patient exists
    $patient_stmt = $pdo->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE patient_id = ?");
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        throw new Exception('Patient not found');
    }
    
    // Validate services and calculate total
    $total_amount = 0;
    $valid_services = [];
    
    foreach ($services as $service) {
        if (!isset($service['service_id']) || !isset($service['quantity'])) {
            throw new Exception('Invalid service data');
        }
        
        $service_id = (int)$service['service_id'];
        $quantity = (int)$service['quantity'];
        
        if ($quantity <= 0) {
            throw new Exception('Service quantity must be greater than 0');
        }
        
        // Get service details
        $service_stmt = $pdo->prepare("SELECT service_id, service_name, price FROM services WHERE service_id = ? AND is_active = 1");
        $service_stmt->execute([$service_id]);
        $service_data = $service_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$service_data) {
            throw new Exception("Service not found or inactive: ID $service_id");
        }
        
        $service_total = $service_data['price'] * $quantity;
        $total_amount += $service_total;
        
        $valid_services[] = [
            'service_id' => $service_id,
            'service_name' => $service_data['service_name'],
            'unit_price' => $service_data['price'],
            'quantity' => $quantity,
            'total_price' => $service_total
        ];
    }
    
    if (empty($valid_services)) {
        throw new Exception('No valid services provided');
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Create invoice record
        $invoice_stmt = $pdo->prepare("
            INSERT INTO patient_invoices (
                patient_id, total_amount, status, notes, 
                created_by, created_at, updated_at
            ) VALUES (?, ?, 'pending', ?, ?, NOW(), NOW())
        ");
        
        $invoice_stmt->execute([
            $patient_id,
            $total_amount,
            $notes,
            $cashier_id
        ]);
        
        $invoice_id = $pdo->lastInsertId();
        
        // Create invoice items
        $item_stmt = $pdo->prepare("
            INSERT INTO invoice_items (
                invoice_id, service_id, service_name, 
                unit_price, quantity, total_price
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($valid_services as $service) {
            $item_stmt->execute([
                $invoice_id,
                $service['service_id'],
                $service['service_name'],
                $service['unit_price'],
                $service['quantity'],
                $service['total_price']
            ]);
        }
        
        // Log the action
        $log_stmt = $pdo->prepare("
            INSERT INTO billing_logs (
                invoice_id, action, performed_by, notes, created_at
            ) VALUES (?, 'invoice_created', ?, ?, NOW())
        ");
        
        $log_message = "Invoice created for patient: {$patient['first_name']} {$patient['last_name']} (ID: $patient_id) with " . count($valid_services) . " service(s)";
        $log_stmt->execute([$invoice_id, $cashier_id, $log_message]);
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($invoice_id, 6, '0', STR_PAD_LEFT);
        
        // Update invoice with invoice number
        $update_stmt = $pdo->prepare("UPDATE patient_invoices SET invoice_number = ? WHERE invoice_id = ?");
        $update_stmt->execute([$invoice_number, $invoice_id]);
        
        // Commit transaction
        $pdo->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Invoice created successfully',
            'data' => [
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice_number,
                'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
                'total_amount' => $total_amount,
                'service_count' => count($valid_services),
                'created_by' => get_employee_session('first_name') . ' ' . get_employee_session('last_name')
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    
    // Log database errors for debugging
    error_log("Create Invoice API Database Error: " . $e->getMessage());
}

ob_end_flush(); // End output buffering
?>