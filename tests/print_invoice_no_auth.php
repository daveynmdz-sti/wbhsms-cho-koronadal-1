<?php
/**
 * Print Invoice API - NO AUTHENTICATION VERSION (TESTING ONLY)
 * Generate printable invoice - bypasses authentication for testing
 * 
 * WARNING: This is for testing only! Remove in production.
 */

// Root path for includes
$root_path = __DIR__ . '/../../..';

require_once $root_path . '/config/db.php';

// Set content type header
header('Content-Type: text/html; charset=UTF-8');

try {
    // Get parameters
    $billing_id = $_GET['billing_id'] ?? null;
    $format = $_GET['format'] ?? 'html'; // html, json
    
    if (!$billing_id) {
        throw new Exception('billing_id is required');
    }
    
    $billing_id = intval($billing_id);
    
    // Get invoice data (exact same query as original)
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
    
    // Get invoice items (exact same query as original)
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
    
    // Get payment history (fixed payment_date column)
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
    
    // Format invoice data (exact same as original)
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
            'data' => $invoice_data,
            'debug_info' => 'Authentication bypassed for testing'
        ]);
        
    } else if ($format === 'html') {
        // Generate simple HTML invoice for printing
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Invoice #<?php echo htmlspecialchars($invoice_data['invoice']['number']); ?></title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 20px;
                    background: #f5f5f5;
                }
                
                .invoice-container {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                    margin-bottom: 20px;
                }
                
                .invoice-header {
                    text-align: center;
                    border-bottom: 2px solid #28a745;
                    padding-bottom: 20px;
                    margin-bottom: 30px;
                }
                
                .facility-name {
                    font-size: 24px;
                    font-weight: bold;
                    color: #28a745;
                    margin-bottom: 5px;
                }
                
                .facility-details {
                    color: #666;
                    font-size: 14px;
                    margin-bottom: 10px;
                }
                
                .invoice-info {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 30px;
                    margin-bottom: 30px;
                }
                
                .info-section h3 {
                    color: #28a745;
                    border-bottom: 1px solid #28a745;
                    padding-bottom: 5px;
                    margin-bottom: 15px;
                }
                
                .info-item {
                    margin-bottom: 8px;
                }
                
                .info-label {
                    font-weight: bold;
                    color: #333;
                    display: inline-block;
                    min-width: 120px;
                }
                
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                
                .items-table th,
                .items-table td {
                    border: 1px solid #ddd;
                    padding: 12px;
                    text-align: left;
                }
                
                .items-table th {
                    background-color: #28a745;
                    color: white;
                    font-weight: bold;
                }
                
                .items-table tr:nth-child(even) {
                    background-color: #f8f9fa;
                }
                
                .financial-summary {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin-top: 20px;
                }
                
                .financial-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 10px;
                }
                
                .financial-total {
                    font-size: 18px;
                    font-weight: bold;
                    color: #28a745;
                    border-top: 2px solid #28a745;
                    padding-top: 10px;
                }
                
                .status-badge {
                    display: inline-block;
                    padding: 4px 12px;
                    border-radius: 15px;
                    font-size: 12px;
                    font-weight: bold;
                    text-transform: uppercase;
                }
                
                .status-unpaid {
                    background: #dc3545;
                    color: white;
                }
                
                .status-paid {
                    background: #28a745;
                    color: white;
                }
                
                .status-partial {
                    background: #ffc107;
                    color: #212529;
                }
                
                @media print {
                    body {
                        background: white;
                    }
                    .invoice-container {
                        box-shadow: none;
                        padding: 0;
                    }
                }
            </style>
        </head>
        <body>
            <div class="invoice-container">
                <div class="invoice-header">
                    <div class="facility-name">City Health Office - Koronadal</div>
                    <div class="facility-details">Koronadal City, South Cotabato 9506</div>
                    <div class="facility-details">📞 (083) 228-8045 | ✉️ cho.koronadal@gmail.com</div>
                </div>
                
                <div class="invoice-info">
                    <div class="info-section">
                        <h3>Invoice Information</h3>
                        <div class="info-item">
                            <span class="info-label">Invoice #:</span>
                            <?php echo htmlspecialchars($invoice_data['invoice']['number']); ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date:</span>
                            <?php echo date('F j, Y', strtotime($invoice_data['invoice']['date'])); ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="status-badge status-<?php echo strtolower($invoice_data['invoice']['status']); ?>">
                                <?php echo $invoice_data['invoice']['status']; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Visit Date:</span>
                            <?php echo $invoice_data['invoice']['visit_date'] ? date('F j, Y', strtotime($invoice_data['invoice']['visit_date'])) : 'N/A'; ?>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h3>Patient Information</h3>
                        <div class="info-item">
                            <span class="info-label">Name:</span>
                            <?php echo htmlspecialchars($invoice_data['patient']['name']); ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Patient #:</span>
                            <?php echo htmlspecialchars($invoice_data['patient']['number']); ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Age/Sex:</span>
                            <?php echo $invoice_data['patient']['age']; ?> years old, <?php echo $invoice_data['patient']['sex']; ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Contact:</span>
                            <?php echo htmlspecialchars($invoice_data['patient']['contact']); ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Address:</span>
                            <?php echo htmlspecialchars($invoice_data['patient']['address']); ?>
                        </div>
                    </div>
                </div>
                
                <h3 style="color: #28a745; border-bottom: 1px solid #28a745; padding-bottom: 5px;">Services & Items</h3>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Service/Item</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoice_data['items'] as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                <?php if ($item['description']): ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars($item['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['category'] ?? 'General'); ?></td>
                            <td><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>
                            <td>₱<?php echo number_format($item['price'], 2); ?></td>
                            <td>₱<?php echo number_format($item['subtotal'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="financial-summary">
                    <div class="financial-row">
                        <span>Subtotal:</span>
                        <span>₱<?php echo number_format($invoice_data['financial']['subtotal'], 2); ?></span>
                    </div>
                    <?php if ($invoice_data['financial']['discount_amount'] > 0): ?>
                    <div class="financial-row">
                        <span>Discount (<?php echo ucfirst($invoice_data['financial']['discount_type']); ?>):</span>
                        <span>-₱<?php echo number_format($invoice_data['financial']['discount_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="financial-row financial-total">
                        <span>Total Amount:</span>
                        <span>₱<?php echo number_format($invoice_data['financial']['net_amount'], 2); ?></span>
                    </div>
                    <div class="financial-row">
                        <span>Amount Paid:</span>
                        <span>₱<?php echo number_format($invoice_data['financial']['paid_amount'], 2); ?></span>
                    </div>
                    <div class="financial-row" style="color: #dc3545; font-weight: bold;">
                        <span>Outstanding Balance:</span>
                        <span>₱<?php echo number_format($invoice_data['financial']['outstanding_balance'], 2); ?></span>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                    <p><strong>Generated by:</strong> <?php echo htmlspecialchars($invoice_data['staff']['created_by']); ?> (<?php echo htmlspecialchars($invoice_data['staff']['employee_number']); ?>)</p>
                    <p><strong>Generated on:</strong> <?php echo date('F j, Y g:i A'); ?></p>
                    <p style="background: #fff3cd; padding: 10px; border-radius: 5px; color: #856404;">
                        <strong>⚠️ TESTING VERSION:</strong> Authentication bypassed for testing purposes
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        
    } else {
        throw new Exception('Invalid format requested. Use "html" or "json"');
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error_details' => $e->getMessage(),
        'sql_state' => $e->errorInfo[0] ?? 'Unknown'
    ]);
    error_log("Print invoice API (no-auth) - PDO error: " . $e->getMessage());
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => 'General Exception'
    ]);
    error_log("Print invoice API (no-auth) - General error: " . $e->getMessage());
}
?>