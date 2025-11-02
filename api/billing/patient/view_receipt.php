<?php
/**
 * View Individual Receipt API
 * Displays individual receipt from receipts table (patient access only)
 */

header('Content-Type: text/html; charset=UTF-8');

// Root path for includes
$root_path = dirname(dirname(dirname(__DIR__)));

// Check if user is logged in as patient
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

// Authentication check
if (!is_patient_logged_in()) {
    http_response_code(401);
    echo '<h3>Authentication required</h3>';
    exit();
}

try {
    // Get patient ID from session
    $patient_id = get_patient_session('patient_id');
    
    // Get receipt ID from URL parameter
    $receipt_id = $_GET['receipt_id'] ?? null;
    $format = $_GET['format'] ?? 'html'; // html, pdf
    
    if (!$receipt_id) {
        throw new Exception('Receipt ID is required');
    }
    
    // Get receipt data with security check (patient can only see their own receipts)
    $receipt_sql = "
        SELECT 
            r.receipt_id,
            r.receipt_number,
            r.billing_id,
            r.amount_paid,
            r.change_amount,
            r.payment_method,
            r.payment_date,
            r.notes as receipt_notes,
            b.total_amount,
            b.discount_amount,
            b.discount_type,
            b.net_amount,
            b.paid_amount as total_paid,
            b.billing_date,
            b.notes as billing_notes,
            p.first_name,
            p.last_name,
            p.username as patient_number,
            p.contact_number,
            p.email,
            e.first_name as cashier_first_name,
            e.last_name as cashier_last_name,
            e.employee_number
        FROM receipts r
        JOIN billing b ON r.billing_id = b.billing_id
        JOIN patients p ON b.patient_id = p.patient_id
        LEFT JOIN employees e ON r.received_by_employee_id = e.employee_id
        WHERE r.receipt_id = ? AND b.patient_id = ?
    ";
    
    $stmt = $pdo->prepare($receipt_sql);
    $stmt->execute([$receipt_id, $patient_id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$receipt) {
        http_response_code(404);
        echo '<h3>Receipt not found or access denied</h3>';
        exit();
    }
    
    // Get billing items for this invoice
    $items_sql = "
        SELECT 
            bi.billing_item_id,
            bi.service_item_id,
            bi.quantity,
            bi.item_price,
            bi.subtotal,
            si.item_name,
            si.unit,
            s.name as service_category
        FROM billing_items bi
        LEFT JOIN service_items si ON bi.service_item_id = si.item_id
        LEFT JOIN services s ON si.service_id = s.service_id
        WHERE bi.billing_id = ?
        ORDER BY s.name, si.item_name
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$receipt['billing_id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate net payment (amount paid minus change)
    $net_payment = $receipt['amount_paid'] - ($receipt['change_amount'] ?? 0);
    
} catch (Exception $e) {
    http_response_code(500);
    echo '<h3>Error loading receipt: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?php echo htmlspecialchars($receipt['receipt_number']); ?> - CHO Koronadal</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .receipt-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .receipt-header h1 {
            margin: 0 0 10px 0;
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .receipt-header p {
            margin: 5px 0;
            opacity: 0.9;
        }
        
        .receipt-number {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 25px;
            display: inline-block;
            margin-top: 15px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .receipt-body {
            padding: 30px;
        }
        
        .info-section {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #0077b6;
        }
        
        .info-section h3 {
            margin: 0 0 15px 0;
            color: #0077b6;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .info-value {
            color: #212529;
        }
        
        .services-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .services-table thead {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
        }
        
        .services-table th,
        .services-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .services-table th {
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        
        .services-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .amount-summary {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #bbdefb;
        }
        
        .amount-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #90caf9;
        }
        
        .amount-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 1.1rem;
            color: #0077b6;
            border-top: 2px solid #0077b6;
            padding-top: 15px;
            margin-top: 10px;
        }
        
        .payment-info {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .payment-info h4 {
            margin: 0 0 15px 0;
            color: #155724;
            font-weight: 600;
        }
        
        .print-buttons {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        
        .btn {
            background: #0077b6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin: 0 10px;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: #023e8a;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .receipt-container {
                box-shadow: none;
                margin: 0;
            }
            
            .print-buttons {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .receipt-header {
                padding: 20px;
            }
            
            .receipt-body {
                padding: 15px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .services-table {
                font-size: 0.9rem;
            }
            
            .services-table th,
            .services-table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h1>CITY HEALTH OFFICE KORONADAL</h1>
            <p>Zone III, Koronadal City, South Cotabato</p>
            <p>Phone: (083) 228-8331 | Email: cho.koronadal@gmail.com</p>
            <div class="receipt-number">
                RECEIPT #<?php echo htmlspecialchars($receipt['receipt_number']); ?>
            </div>
        </div>
        
        <div class="receipt-body">
            <!-- Patient Information -->
            <div class="info-section">
                <h3><i class="fas fa-user"></i> Patient Information</h3>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">Name:</span>
                            <span class="info-value"><?php echo htmlspecialchars($receipt['first_name'] . ' ' . $receipt['last_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Patient ID:</span>
                            <span class="info-value"><?php echo htmlspecialchars($receipt['patient_number']); ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">Contact:</span>
                            <span class="info-value"><?php echo htmlspecialchars($receipt['contact_number'] ?: 'Not provided'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($receipt['email'] ?: 'Not provided'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Receipt Information -->
            <div class="info-section">
                <h3><i class="fas fa-receipt"></i> Receipt Details</h3>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">Receipt Date:</span>
                            <span class="info-value"><?php echo date('M d, Y g:i A', strtotime($receipt['payment_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Invoice #:</span>
                            <span class="info-value">INV-<?php echo str_pad($receipt['billing_id'], 8, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">Payment Method:</span>
                            <span class="info-value"><?php echo htmlspecialchars(ucfirst($receipt['payment_method'])); ?></span>
                        </div>
                        <?php if ($receipt['cashier_first_name']): ?>
                        <div class="info-item">
                            <span class="info-label">Cashier:</span>
                            <span class="info-value"><?php echo htmlspecialchars($receipt['cashier_first_name'] . ' ' . $receipt['cashier_last_name']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Services Provided -->
            <div class="info-section">
                <h3><i class="fas fa-list"></i> Services Provided</h3>
                <table class="services-table">
                    <thead>
                        <tr>
                            <th>Service/Item</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['item_name'] ?: 'Service'); ?></strong>
                                <?php if ($item['service_category']): ?>
                                <br><small style="color: #6c757d;"><?php echo htmlspecialchars($item['service_category']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($item['quantity'], 0); ?> <?php echo htmlspecialchars($item['unit'] ?: ''); ?></td>
                            <td>₱<?php echo number_format($item['item_price'], 2); ?></td>
                            <td>₱<?php echo number_format($item['subtotal'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Amount Summary -->
            <div class="amount-summary">
                <div class="amount-row">
                    <span>Subtotal:</span>
                    <span>₱<?php echo number_format($receipt['total_amount'], 2); ?></span>
                </div>
                <?php if ($receipt['discount_amount'] > 0): ?>
                <div class="amount-row">
                    <span>Discount (<?php echo htmlspecialchars(ucfirst($receipt['discount_type'])); ?>):</span>
                    <span>-₱<?php echo number_format($receipt['discount_amount'], 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="amount-row">
                    <span>Net Amount:</span>
                    <span>₱<?php echo number_format($receipt['net_amount'], 2); ?></span>
                </div>
                <div class="amount-row">
                    <span><strong>This Payment:</strong></span>
                    <span><strong>₱<?php echo number_format($net_payment, 2); ?></strong></span>
                </div>
            </div>
            
            <!-- Payment Information -->
            <div class="payment-info">
                <h4><i class="fas fa-credit-card"></i> Payment Details</h4>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">Amount Tendered:</span>
                            <span class="info-value">₱<?php echo number_format($receipt['amount_paid'], 2); ?></span>
                        </div>
                        <?php if ($receipt['change_amount'] > 0): ?>
                        <div class="info-item">
                            <span class="info-label">Change:</span>
                            <span class="info-value">₱<?php echo number_format($receipt['change_amount'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">Net Payment:</span>
                            <span class="info-value"><strong>₱<?php echo number_format($net_payment, 2); ?></strong></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Remaining Balance:</span>
                            <span class="info-value">₱<?php echo number_format($receipt['net_amount'] - $receipt['total_paid'], 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <?php if ($receipt['receipt_notes']): ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #c3e6cb;">
                    <strong>Notes:</strong> <?php echo htmlspecialchars($receipt['receipt_notes']); ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d; font-size: 0.9rem;">
                <p>City Health Office Koronadal</p>
                <p>For inquiries, please visit our office or call (083) 228-8331</p>
                <p><em>This is a computer-generated receipt. Please keep this for your records.</em></p>
            </div>
        </div>
        
        <div class="print-buttons">
            <button onclick="window.print()" class="btn">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
    
    <script>
        // Auto-focus for better user experience
        window.addEventListener('load', function() {
            document.body.focus();
        });
        
        // Handle Escape key to close window
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.close();
            }
        });
    </script>
</body>
</html>