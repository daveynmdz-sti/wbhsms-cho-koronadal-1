<?php
/**
 * Get Invoice Details API
 * Returns detailed information about a specific invoice (patient access only)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Root path for includes
$root_path = dirname(dirname(dirname(__DIR__)));

// Check if user is logged in as patient
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

// Authentication check
if (!is_patient_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit();
}

try {
    // Get patient ID from session
    $patient_id = get_patient_session('patient_id');
    
    // Get billing ID from URL parameter
    $billing_id = $_GET['billing_id'] ?? null;
    
    if (!$billing_id) {
        throw new Exception('Billing ID is required');
    }
    
    // Get invoice details with security check (patient can only see their own invoices)
    $invoice_sql = "
        SELECT 
            b.billing_id,
            b.patient_id,
            b.visit_id,
            b.total_amount,
            b.paid_amount,
            b.discount_amount,
            b.discount_type,
            b.net_amount,
            b.payment_status,
            b.billing_date,
            b.notes,
            b.created_at,
            b.updated_at,
            p.first_name,
            p.last_name,
            p.username as patient_number,
            p.contact_number as phone_number,
            p.email
        FROM billing b
        LEFT JOIN patients p ON b.patient_id = p.patient_id
        WHERE b.billing_id = ? AND b.patient_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id, $patient_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Invoice not found or access denied'
        ]);
        exit();
    }
    
    // Get billing items (line items)
    $items_sql = "
        SELECT 
            bi.billing_item_id,
            bi.service_item_id,
            bi.quantity,
            bi.item_price as unit_price,
            bi.subtotal,
            si.item_name,
            si.unit,
            si.service_id
        FROM billing_items bi
        LEFT JOIN service_items si ON bi.service_item_id = si.item_id
        WHERE bi.billing_id = ?
        ORDER BY bi.billing_item_id
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format invoice data
    $formatted_invoice = [
        'billing_id' => intval($invoice['billing_id']),
        'visit_id' => $invoice['visit_id'] ? intval($invoice['visit_id']) : null,
        'patient_id' => intval($invoice['patient_id']),
        'patient_name' => trim($invoice['first_name'] . ' ' . $invoice['last_name']),
        'patient_number' => $invoice['patient_number'],
        'phone_number' => $invoice['phone_number'],
        'email' => $invoice['email'] ?: 'Not provided',
        'billing_date' => $invoice['billing_date'],
        'total_amount' => floatval($invoice['total_amount']),
        'discount_amount' => floatval($invoice['discount_amount']),
        'discount_type' => $invoice['discount_type'],
        'net_amount' => floatval($invoice['net_amount']),
        'paid_amount' => floatval($invoice['paid_amount']),
        'balance_due' => floatval($invoice['total_amount'] - $invoice['paid_amount']),
        'payment_status' => $invoice['payment_status'],
        'notes' => $invoice['notes'],
        'created_at' => $invoice['created_at'],
        'updated_at' => $invoice['updated_at'],
        'has_receipt' => $invoice['payment_status'] === 'paid',
        'payment_history' => [], // Will be populated if needed
        'items' => array_map(function($item) {
            return [
                'billing_item_id' => intval($item['billing_item_id']),
                'service_item_id' => intval($item['service_item_id']),
                'service_name' => $item['item_name'] ?: 'Service',
                'description' => $item['item_name'] . ' (' . $item['unit'] . ')',
                'quantity' => floatval($item['quantity']),
                'unit_price' => floatval($item['unit_price']),
                'amount' => floatval($item['subtotal'])
            ];
        }, $items)
    ];
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'data' => $formatted_invoice
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    error_log("Invoice details API error: " . $e->getMessage());
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>