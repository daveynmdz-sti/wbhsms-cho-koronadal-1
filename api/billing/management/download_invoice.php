<?php
/**
 * Download Invoice API (Management)
 * Generate downloadable invoice for management access
 */

// Root path for includes
$root_path = dirname(dirname(dirname(__DIR__)));

// Check if user is logged in as employee with proper role
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Authentication and authorization check
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit();
}

$employee_role = get_employee_session('role');
if (!in_array($employee_role, ['cashier', 'admin', 'nurse', 'doctor'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Insufficient permissions'
    ]);
    exit();
}

try {
    // Get parameters
    $billing_id = $_GET['billing_id'] ?? null;
    $format = $_GET['format'] ?? 'html'; // html, json, pdf
    
    if (!$billing_id) {
        throw new Exception('billing_id is required');
    }
    
    $billing_id = intval($billing_id);
    
    // Get invoice data using the same structure as print_invoice API
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
        http_response_code(404);
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
            pm.amount_paid,
            pm.payment_method,
            pm.paid_at as payment_date,
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
    
    // Format invoice data
    $invoice_data = [
        'invoice' => [
            'id' => $invoice['billing_id'],
            'number' => 'INV-' . str_pad($invoice['billing_id'], 8, '0', STR_PAD_LEFT),
            'date' => $invoice['billing_date'],
            'status' => strtoupper($invoice['payment_status']),
            'visit_id' => $invoice['visit_id'],
            'visit_date' => $invoice['visit_date'],
            'visit_purpose' => $invoice['visit_purpose'],
            'notes' => $invoice['notes']
        ],
        'patient' => [
            'id' => $invoice['patient_id'],
            'number' => $invoice['patient_number'],
            'name' => $invoice['first_name'] . ' ' . $invoice['last_name'],
            'first_name' => $invoice['first_name'],
            'last_name' => $invoice['last_name'],
            'age' => intval($invoice['age']),
            'sex' => $invoice['sex'],
            'date_of_birth' => $invoice['date_of_birth'],
            'contact' => $invoice['contact_number'],
            'email' => $invoice['email'],
            'address' => $invoice['barangay_name'] . ', ' . $invoice['city'] . ', ' . $invoice['province'] . ' ' . $invoice['zip_code'],
            'barangay' => $invoice['barangay_name']
        ],
        'items' => array_map(function($item) {
            return [
                'id' => $item['billing_item_id'],
                'service_id' => $item['service_item_id'],
                'name' => $item['item_name'],
                'description' => $item['item_description'],
                'category' => $item['category_name'],
                'unit' => $item['unit_of_measure'],
                'price' => floatval($item['item_price']),
                'quantity' => intval($item['quantity']),
                'subtotal' => floatval($item['subtotal'])
            ];
        }, $items),
        'financial' => [
            'subtotal' => array_sum(array_column($items, 'subtotal')),
            'discount_type' => $invoice['discount_type'],
            'discount_amount' => floatval($invoice['discount_amount']),
            'net_amount' => floatval($invoice['net_amount']),
            'paid_amount' => floatval($invoice['paid_amount']),
            'outstanding_balance' => floatval($invoice['net_amount'] - $invoice['paid_amount'])
        ],
        'payments' => array_map(function($payment) {
            return [
                'id' => $payment['payment_id'],
                'amount' => floatval($payment['amount_paid']),
                'method' => ucfirst($payment['payment_method']),
                'date' => $payment['payment_date'],
                'receipt_number' => $payment['receipt_number'],
                'notes' => $payment['notes'],
                'cashier' => $payment['cashier_first_name'] ? 
                    $payment['cashier_first_name'] . ' ' . $payment['cashier_last_name'] : 'System'
            ];
        }, $payments),
        'staff' => [
            'created_by' => $invoice['created_by_first_name'] ? 
                $invoice['created_by_first_name'] . ' ' . $invoice['created_by_last_name'] : 'System',
            'employee_number' => $invoice['employee_number']
        ]
    ];
    
    // Handle different output formats
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $invoice_data
        ]);
        
    } else if ($format === 'html') {
        // Set download headers for HTML
        $filename = 'invoice_' . $invoice_data['invoice']['number'] . '.html';
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Generate HTML invoice
        include $root_path . '/api/billing/shared/receipt_generator.php';
        generatePrintableInvoice($invoice_data);
        
    } else if ($format === 'pdf') {
        // Generate PDF invoice
        require_once $root_path . '/vendor/autoload.php';
        
        // Configure PDF options
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        
        // Initialize dompdf
        $dompdf = new \Dompdf\Dompdf($options);
        
        // Generate HTML content for PDF
        ob_start();
        include $root_path . '/api/billing/shared/receipt_generator.php';
        generatePrintableInvoice($invoice_data);
        $html = ob_get_clean();
        
        // Load HTML content
        $dompdf->loadHtml($html);
        
        // Set paper size and orientation
        $dompdf->setPaper('A4', 'portrait');
        
        // Render the HTML as PDF
        $dompdf->render();
        
        // Set headers for PDF download
        $filename = 'invoice_' . $invoice_data['invoice']['number'] . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output the PDF
        echo $dompdf->output();
        
    } else {
        throw new Exception('Invalid format requested. Use "html", "json", or "pdf"');
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    error_log("Download invoice API error: " . $e->getMessage());
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>