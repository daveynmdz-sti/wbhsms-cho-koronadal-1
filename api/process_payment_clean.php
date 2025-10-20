<?php
ob_start(); // Start output buffering to prevent header issues
session_start();

$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';
require_once $root_path . '/config/session/employee_session.php';

header('Content-Type: application/json');

// Check if user is logged in and has appropriate role
if (!is_employee_logged_in() || (get_employee_session('role') !== 'Cashier' && get_employee_session('role') !== 'Admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    // Get POST data
    $invoice_id = $_POST['invoice_id'] ?? null;
    $payment_amount = $_POST['payment_amount'] ?? null;
    $payment_method = $_POST['payment_method'] ?? null;
    $notes = $_POST['notes'] ?? '';
    $cashier_id = $_POST['cashier_id'] ?? get_employee_session('employee_id');

    // Validate input
    if (!$invoice_id || !$payment_amount || !$payment_method) {
        throw new Exception('Invoice ID, payment amount, and payment method are required');
    }

    if (!is_numeric($payment_amount) || $payment_amount <= 0) {
        throw new Exception('Invalid payment amount');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Get invoice details
    $stmt = $pdo->prepare("
        SELECT pi.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
        FROM patient_invoices pi 
        JOIN patients p ON pi.patient_id = p.patient_id 
        WHERE pi.invoice_id = ? AND pi.status = 'pending'
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice not found or already paid');
    }

    // Check if payment amount is sufficient
    if ($payment_amount < $invoice['total_amount']) {
        throw new Exception('Payment amount is less than invoice total');
    }

    $change_amount = $payment_amount - $invoice['total_amount'];

    // Record payment
    $stmt = $pdo->prepare("
        INSERT INTO patient_payments (
            invoice_id, payment_amount, payment_method, change_amount, 
            cashier_id, notes, payment_date, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
    ");
    $stmt->execute([
        $invoice_id, 
        $payment_amount, 
        $payment_method, 
        $change_amount, 
        $cashier_id, 
        $notes
    ]);
    $payment_id = $pdo->lastInsertId();

    // Update invoice status
    $stmt = $pdo->prepare("UPDATE patient_invoices SET status = 'paid', updated_at = NOW() WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);

    // Log the payment
    $stmt = $pdo->prepare("
        INSERT INTO billing_logs (invoice_id, action, performed_by, notes, created_at) 
        VALUES (?, 'payment_processed', ?, ?, NOW())
    ");
    $stmt->execute([
        $invoice_id, 
        $cashier_id, 
        "Payment processed: ₱{$payment_amount} via {$payment_method}. Change: ₱{$change_amount}"
    ]);

    // Generate receipt number
    $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
    
    // Update payment with receipt number
    $stmt = $pdo->prepare("UPDATE patient_payments SET receipt_number = ? WHERE payment_id = ?");
    $stmt->execute([$receipt_number, $payment_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'payment_id' => $payment_id,
        'receipt_number' => $receipt_number,
        'change_amount' => $change_amount,
        'invoice_total' => $invoice['total_amount'],
        'payment_amount' => $payment_amount,
        'patient_name' => $invoice['patient_name'],
        'message' => 'Payment processed successfully'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

ob_end_flush(); // End output buffering
?>