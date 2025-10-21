<?php
// Process Payment API - Uses existing billing and payments table structure

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
$required_fields = ['billing_id', 'amount_paid'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$billing_id = intval($input['billing_id']);
$amount_paid = floatval($input['amount_paid']);
$payment_method = $input['payment_method'] ?? 'cash';
$notes = $input['notes'] ?? '';
$cashier_id = $input['cashier_id'] ?? 1; // Default for testing

try {
    // Validate input
    if ($amount_paid <= 0) {
        throw new Exception('Invalid payment amount');
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Get billing details using existing billing table
    $billing_stmt = $pdo->prepare("
        SELECT b.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
        FROM billing b 
        JOIN patients p ON b.patient_id = p.patient_id 
        WHERE b.billing_id = ? AND b.payment_status = 'unpaid'
    ");
    $billing_stmt->execute([$billing_id]);
    $billing = $billing_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$billing) {
        throw new Exception('Billing record not found or already paid');
    }

    // Check if payment amount is sufficient
    if ($amount_paid < $billing['net_amount']) {
        throw new Exception('Payment amount is less than invoice total');
    }

    $change_amount = $amount_paid - $billing['net_amount'];

    // Generate receipt number
    $receipt_number = 'RCP' . date('Ymd') . str_pad($billing_id, 6, '0', STR_PAD_LEFT);
    
    // Record payment using existing payments table
    $payment_stmt = $pdo->prepare("
        INSERT INTO payments (
            billing_id, amount_paid, payment_method, paid_at, 
            cashier_id, receipt_number, notes
        ) VALUES (?, ?, ?, NOW(), ?, ?, ?)
    ");
    $payment_stmt->execute([
        $billing_id, 
        $amount_paid, 
        $payment_method, 
        $cashier_id, 
        $receipt_number,
        $notes
    ]);
    $payment_id = $pdo->lastInsertId();

    // Update billing status to paid
    $update_stmt = $pdo->prepare("
        UPDATE billing 
        SET payment_status = 'paid', paid_amount = ?, updated_at = NOW() 
        WHERE billing_id = ?
    ");
    $update_stmt->execute([$amount_paid, $billing_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment processed successfully',
        'data' => [
            'payment_id' => $payment_id,
            'billing_id' => $billing_id,
            'receipt_number' => $receipt_number,
            'amount_paid' => $amount_paid,
            'change_amount' => $change_amount,
            'total_amount' => $billing['net_amount'],
            'patient_name' => $billing['patient_name'],
            'payment_method' => $payment_method,
            'payment_date' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Process Payment Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to process payment: ' . $e->getMessage()
    ]);
}
?>