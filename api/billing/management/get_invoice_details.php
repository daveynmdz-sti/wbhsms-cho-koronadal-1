<?php
/**
 * Get Invoice Details API
 * Purpose: Fetch complete invoice details for modal preview
 * Access: Employee session required (cashier/admin)
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Root path and includes
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

try {
    // Check authentication
    if (!is_employee_logged_in()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]);
        exit();
    }

    // Check role authorization
    $employee_role = get_employee_session('role');
    $allowed_roles = ['cashier', 'admin'];
    if (!in_array(strtolower($employee_role), $allowed_roles)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Cashier or Admin role required.'
        ]);
        exit();
    }

    // Validate billing_id
    $billing_id = $_GET['billing_id'] ?? null;
    if (!$billing_id || !is_numeric($billing_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid billing ID'
        ]);
        exit();
    }

    // Get invoice details
    $invoice_sql = "
        SELECT 
            b.billing_id,
            b.total_amount,
            b.paid_amount,
            b.discount_amount,
            b.discount_type,
            b.net_amount,
            b.billing_date,
            b.payment_status,
            b.notes,
            b.visit_id,
            p.patient_id,
            p.first_name,
            p.last_name,
            p.username as patient_number,
            p.contact_number,
            p.date_of_birth,
            p.sex,
            p.email,
            bg.barangay_name,
            bg.city,
            bg.province,
            bg.zip_code,
            v.visit_date,
            v.remarks as visit_purpose,
            e.first_name as created_by_first_name,
            e.last_name as created_by_last_name,
            e.employee_number,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM billing b
        JOIN patients p ON b.patient_id = p.patient_id
        LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
        LEFT JOIN visits v ON b.visit_id = v.visit_id
        JOIN employees e ON b.created_by = e.employee_id
        WHERE b.billing_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        echo json_encode([
            'success' => false,
            'message' => 'Invoice not found'
        ]);
        exit();
    }

    // Get invoice items
    $items_sql = "
        SELECT 
            bi.billing_item_id,
            bi.service_item_id,
            bi.item_price,
            bi.quantity,
            bi.subtotal,
            si.item_name,
            si.price_php as item_description,
            si.unit as unit_of_measure,
            s.name as category_name
        FROM billing_items bi
        JOIN service_items si ON bi.service_item_id = si.item_id
        LEFT JOIN services s ON si.service_id = s.service_id
        WHERE bi.billing_id = ?
        ORDER BY s.name, si.item_name
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get payment history
    $payments_sql = "
        SELECT 
            pm.payment_id,
            pm.amount_paid as amount,
            pm.payment_method,
            pm.paid_at as date,
            pm.receipt_number,
            pm.notes,
            e.first_name as cashier_first_name,
            e.last_name as cashier_last_name
        FROM payments pm
        LEFT JOIN employees e ON pm.cashier_id = e.employee_id
        WHERE pm.billing_id = ?
        ORDER BY pm.paid_at ASC
    ";
    
    $stmt = $pdo->prepare($payments_sql);
    $stmt->execute([$billing_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $response = [
        'success' => true,
        'invoice' => array_merge($invoice, [
            'items' => $items,
            'payments' => $payments
        ])
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Get Invoice Details API Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error_details' => $e->getMessage()
    ]);
}
?>