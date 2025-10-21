<?php
/**
 * API Endpoint: Process Payment
 * Purpose: Records payment for an invoice and generates receipt
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
    $billing_id = intval($_POST['billing_id'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $notes = trim($_POST['notes'] ?? '');

    // Validate required fields
    if (!$billing_id || $amount_paid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid payment details']);
        exit();
    }

    // Start transaction
    $pdo->beginTransaction();

    // Get billing details
    $stmt = $pdo->prepare("SELECT * FROM billing WHERE billing_id = ? AND payment_status IN ('unpaid', 'partial')");
    $stmt->execute([$billing_id]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$billing) {
        throw new Exception('Invoice not found or already paid');
    }

    // Validate payment amount
    $remaining_amount = $billing['net_amount'] - $billing['paid_amount'];
    if ($amount_paid > $remaining_amount + 0.01) { // Allow small rounding differences
        throw new Exception('Payment amount exceeds remaining balance');
    }

    // Calculate new totals
    $new_paid_amount = $billing['paid_amount'] + $amount_paid;
    $change_amount = $amount_paid - $remaining_amount;
    if ($change_amount < 0) $change_amount = 0;

    // Determine new payment status
    $new_status = 'paid';
    if ($new_paid_amount < $billing['net_amount'] - 0.01) {
        $new_status = 'partial';
    }

    // Generate receipt number
    $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($billing_id, 6, '0', STR_PAD_LEFT);

    // Insert payment record
    $stmt = $pdo->prepare("INSERT INTO payments (billing_id, amount_paid, payment_method, cashier_id, receipt_number, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$billing_id, $amount_paid, $payment_method, $employee_id, $receipt_number, $notes]);
    
    $payment_id = $pdo->lastInsertId();

    // Update billing record
    $stmt = $pdo->prepare("UPDATE billing SET paid_amount = ?, payment_status = ? WHERE billing_id = ?");
    $stmt->execute([$new_paid_amount, $new_status, $billing_id]);

    // Get patient details for receipt
    $stmt = $pdo->prepare("SELECT p.patient_id, p.first_name, p.last_name, p.username FROM patients p JOIN billing b ON p.patient_id = b.patient_id WHERE b.billing_id = ?");
    $stmt->execute([$billing_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    // Commit transaction
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
            'payment_status' => $new_status,
            'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
            'patient_id' => $patient['username'],
            'net_amount' => $billing['net_amount'],
            'total_paid' => $new_paid_amount
        ]
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    echo json_encode(['success' => false, 'message' => 'Error processing payment: ' . $e->getMessage()]);
}
?>