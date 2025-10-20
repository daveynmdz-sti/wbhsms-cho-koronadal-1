<?php
ob_start(); // Start output buffering to prevent header issues

/**
 * Process Payment API
 * Process payments for invoices (management/cashier access only)
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
    $required_fields = ['invoice_id', 'payment_amount', 'payment_method'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $invoice_id = (int)$input['invoice_id'];
    $payment_amount = (float)$input['payment_amount'];
    $payment_method = trim($input['payment_method']);
    $notes = $input['notes'] ?? '';
    $cashier_id = get_employee_session('employee_id');
    
    // Validate payment amount
    if ($payment_amount <= 0) {
        throw new Exception('Payment amount must be greater than 0');
    }
    
    // Validate payment method
    $valid_payment_methods = ['cash', 'card', 'check', 'bank_transfer'];
    if (!in_array($payment_method, $valid_payment_methods)) {
        throw new Exception('Invalid payment method');
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Get invoice details and verify it exists and is pending
        $invoice_stmt = $pdo->prepare("
            SELECT pi.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   p.contact_number
            FROM patient_invoices pi
            JOIN patients p ON pi.patient_id = p.patient_id
            WHERE pi.invoice_id = ? AND pi.status = 'pending'
        ");
        
        $invoice_stmt->execute([$invoice_id]);
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            throw new Exception('Invoice not found or already processed');
        }
        
        // Check if payment amount covers the invoice
        if ($payment_amount < $invoice['total_amount']) {
            throw new Exception('Payment amount is less than invoice total');
        }
        
        $change_amount = $payment_amount - $invoice['total_amount'];
        
        // Create payment record
        $payment_stmt = $pdo->prepare("
            INSERT INTO patient_payments (
                invoice_id, payment_amount, payment_method, change_amount,
                cashier_id, notes, payment_date, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        
        $payment_stmt->execute([
            $invoice_id,
            $payment_amount,
            $payment_method,
            $change_amount,
            $cashier_id,
            $notes
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Generate receipt number
        $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
        
        // Update payment with receipt number
        $receipt_stmt = $pdo->prepare("UPDATE patient_payments SET receipt_number = ? WHERE payment_id = ?");
        $receipt_stmt->execute([$receipt_number, $payment_id]);
        
        // Update invoice status to paid
        $update_invoice_stmt = $pdo->prepare("UPDATE patient_invoices SET status = 'paid', updated_at = NOW() WHERE invoice_id = ?");
        $update_invoice_stmt->execute([$invoice_id]);
        
        // Log the payment
        $log_stmt = $pdo->prepare("
            INSERT INTO billing_logs (
                invoice_id, payment_id, action, performed_by, notes, created_at
            ) VALUES (?, ?, 'payment_processed', ?, ?, NOW())
        ");
        
        $log_message = "Payment processed for {$invoice['patient_name']} - Method: $payment_method, Amount: ₱" . number_format($payment_amount, 2) . ", Change: ₱" . number_format($change_amount, 2);
        $log_stmt->execute([$invoice_id, $payment_id, $cashier_id, $log_message]);
        
        // Commit transaction
        $pdo->commit();
        
        // Return success response with payment details
        echo json_encode([
            'success' => true,
            'message' => 'Payment processed successfully',
            'data' => [
                'payment_id' => $payment_id,
                'receipt_number' => $receipt_number,
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice['invoice_number'] ?? "INV-{$invoice_id}",
                'patient_name' => $invoice['patient_name'],
                'invoice_total' => $invoice['total_amount'],
                'payment_amount' => $payment_amount,
                'change_amount' => $change_amount,
                'payment_method' => $payment_method,
                'payment_date' => date('Y-m-d H:i:s'),
                'cashier_name' => get_employee_session('first_name') . ' ' . get_employee_session('last_name'),
                'receipt_url' => "print_receipt.php?payment_id=$payment_id"
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
    error_log("Process Payment API Database Error: " . $e->getMessage());
}

ob_end_flush(); // End output buffering
?>