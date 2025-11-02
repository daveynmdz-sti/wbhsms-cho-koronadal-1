<?php
/**
 * Get Receipt Details API
 * Purpose: Fetch individual receipt details for viewing
 * Method: GET
 * Parameters: receipt_number
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Include configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please log in.'
    ]);
    exit();
}

// Check if receipt_number is provided
if (!isset($_GET['receipt_number']) || empty($_GET['receipt_number'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Receipt number is required.'
    ]);
    exit();
}

$receipt_number = trim($_GET['receipt_number']);

try {
    // Query to get receipt details with related information
    $sql = "SELECT 
                r.receipt_id,
                r.billing_id,
                r.receipt_number,
                r.payment_date,
                r.amount_paid,
                r.change_amount,
                r.payment_method,
                r.notes,
                p.first_name,
                p.last_name,
                p.username as patient_id,
                e.first_name as cashier_first_name,
                e.last_name as cashier_last_name,
                b.net_amount,
                b.total_amount,
                b.discount_amount
            FROM receipts r
            LEFT JOIN billing b ON r.billing_id = b.billing_id
            LEFT JOIN patients p ON b.patient_id = p.patient_id
            LEFT JOIN employees e ON r.received_by_employee_id = e.employee_id
            WHERE r.receipt_number = ?
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$receipt_number]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Receipt not found.'
        ]);
        exit();
    }

    // Format the receipt data
    $receipt_data = [
        'receipt_id' => $receipt['receipt_id'],
        'receipt_number' => $receipt['receipt_number'],
        'billing_id' => $receipt['billing_id'],
        'payment_date' => $receipt['payment_date'],
        'amount_paid' => floatval($receipt['amount_paid']),
        'change_amount' => floatval($receipt['change_amount']),
        'payment_method' => $receipt['payment_method'],
        'notes' => $receipt['notes'],
        'patient_name' => trim(($receipt['first_name'] ?? '') . ' ' . ($receipt['last_name'] ?? '')),
        'patient_id' => $receipt['patient_id'],
        'cashier_name' => trim(($receipt['cashier_first_name'] ?? '') . ' ' . ($receipt['cashier_last_name'] ?? '')),
        'net_amount' => floatval($receipt['net_amount']),
        'total_amount' => floatval($receipt['total_amount']),
        'discount_amount' => floatval($receipt['discount_amount'])
    ];

    echo json_encode([
        'success' => true,
        'receipt' => $receipt_data,
        'message' => 'Receipt details retrieved successfully.'
    ]);

} catch (Exception $e) {
    error_log("Receipt Details API Error: " . $e->getMessage());
    error_log("Receipt Details API Trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error. Please try again later.'
    ]);
}
?>