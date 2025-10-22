<?php
// Invoice Details View - Administrative access to view invoice details
$root_path = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and has admin privileges
if (!is_employee_logged_in()) {
    header("Location: ../../auth/employee_login.php");
    exit();
}

$employee_role = get_employee_session('role');
if (!in_array($employee_role, ['admin', 'cashier'])) {
    echo "<script>alert('Access denied'); window.close();</script>";
    exit();
}

// Get billing ID
$billing_id = $_GET['billing_id'] ?? null;
if (!$billing_id || !is_numeric($billing_id)) {
    echo "<script>alert('Invalid billing ID'); window.close();</script>";
    exit();
}

$billing_id = intval($billing_id);

// Get invoice details
try {
    $invoice_sql = "
        SELECT 
            b.billing_id, 
            b.patient_id, 
            b.billing_date, 
            b.payment_status, 
            b.total_amount,
            b.paid_amount,
            b.net_amount,
            b.discount_amount,
            b.discount_type,
            b.notes,
            p.first_name, 
            p.last_name, 
            p.middle_name,
            p.username as patient_number,
            bg.barangay_name
        FROM billing b
        LEFT JOIN patients p ON b.patient_id = p.patient_id
        LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
        WHERE b.billing_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        echo "<script>alert('Invoice not found'); window.close();</script>";
        exit();
    }
    
    // Get billing items
    $items_sql = "
        SELECT 
            bi.item_name,
            bi.quantity,
            bi.item_price,
            bi.subtotal,
            bc.category_name
        FROM billing_items bi
        LEFT JOIN billing_categories bc ON bi.category_id = bc.category_id
        WHERE bi.billing_id = ?
        ORDER BY bi.item_name
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payment history
    $payments_sql = "
        SELECT 
            r.receipt_id,
            r.receipt_number,
            r.payment_date as date,
            r.amount_paid as amount,
            r.payment_method,
            CONCAT(e.first_name, ' ', e.last_name) as received_by
        FROM receipts r
        LEFT JOIN employees e ON r.received_by_employee_id = e.employee_id
        WHERE r.billing_id = ?
        ORDER BY r.payment_date DESC
    ";
    
    $stmt = $pdo->prepare($payments_sql);
    $stmt->execute([$billing_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Invoice details error: " . $e->getMessage());
    echo "<script>alert('Error loading invoice details'); window.close();</script>";
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= $invoice['billing_id'] ?> - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8f9fa;
            color: #333;
        }
        
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .invoice-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .invoice-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
        }
        
        .invoice-header p {
            margin: 0.25rem 0;
            opacity: 0.9;
        }
        
        .invoice-body {
            padding: 2rem;
        }
        
        .invoice-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .info-section h3 {
            margin: 0 0 1rem 0;
            color: #0077b6;
            font-size: 1.1rem;
        }
        
        .info-item {
            margin-bottom: 0.5rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 0.9rem;
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-unpaid {
            background: #fff5f5;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        
        .status-paid {
            background: #f0fff4;
            color: #28a745;
            border: 1px solid #c3e6cb;
        }
        
        .status-partial {
            background: #fff8e1;
            color: #ffc107;
            border: 1px solid #ffeeba;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 2rem 0;
        }
        
        .items-table th,
        .items-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .items-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .totals-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 2rem 0;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .total-row.main {
            font-weight: bold;
            font-size: 1.1rem;
            border-top: 2px solid #0077b6;
            padding-top: 0.5rem;
            margin-top: 1rem;
        }
        
        .payments-section {
            margin-top: 2rem;
        }
        
        .payments-section h3 {
            color: #0077b6;
            margin-bottom: 1rem;
        }
        
        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f8f9fa;
            margin-bottom: 0.5rem;
            border-radius: 4px;
        }
        
        .payment-info {
            flex: 1;
        }
        
        .payment-method {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        
        .payment-date {
            color: #666;
            font-size: 0.85rem;
        }
        
        .payment-amount {
            font-weight: bold;
            color: #28a745;
        }
        
        .action-buttons {
            text-align: center;
            padding: 2rem;
            border-top: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            margin: 0 0.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #1e7e34;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #545b62;
            transform: translateY(-2px);
        }
        
        @media print {
            .action-buttons { display: none; }
            body { background: white; }
            .invoice-container { box-shadow: none; }
        }
        
        @media (max-width: 768px) {
            .invoice-info {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .items-table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <h1>City Health Office</h1>
            <p>Koronadal City, South Cotabato</p>
            <p>Phone: (083) 228-xxxx</p>
            <p style="margin-top: 1rem; font-size: 1.2rem;">
                <strong>INVOICE #<?= $invoice['billing_id'] ?></strong>
            </p>
        </div>
        
        <div class="invoice-body">
            <div class="invoice-info">
                <div class="info-section">
                    <h3><i class="fas fa-user"></i> Patient Information</h3>
                    <div class="info-item">
                        <span class="info-label">Patient Name</span>
                        <?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Patient ID</span>
                        <?= htmlspecialchars($invoice['patient_number'] ?: 'N/A') ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Barangay</span>
                        <?= htmlspecialchars($invoice['barangay_name'] ?: 'N/A') ?>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3><i class="fas fa-file-invoice"></i> Invoice Information</h3>
                    <div class="info-item">
                        <span class="info-label">Invoice Date</span>
                        <?= date('F j, Y', strtotime($invoice['billing_date'])) ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Payment Status</span>
                        <span class="status-badge status-<?= $invoice['payment_status'] ?>">
                            <?= ucfirst($invoice['payment_status']) ?>
                        </span>
                    </div>
                    <?php if ($invoice['notes']): ?>
                    <div class="info-item">
                        <span class="info-label">Notes</span>
                        <?= htmlspecialchars($invoice['notes']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <h3><i class="fas fa-list"></i> Services & Items</h3>
            
            <?php if (empty($items)): ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p>No items found for this invoice</p>
                </div>
            <?php else: ?>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Service/Item</th>
                            <th>Category</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                            <td><?= htmlspecialchars($item['category_name'] ?: 'General') ?></td>
                            <td class="text-right"><?= $item['quantity'] ?></td>
                            <td class="text-right">₱<?= number_format($item['item_price'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($item['subtotal'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <div class="totals-section">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>₱<?= number_format($invoice['total_amount'], 2) ?></span>
                </div>
                <?php if ($invoice['discount_amount'] > 0): ?>
                <div class="total-row">
                    <span>Discount (<?= htmlspecialchars($invoice['discount_type'] ?: 'Standard') ?>):</span>
                    <span>-₱<?= number_format($invoice['discount_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row main">
                    <span>Total Amount:</span>
                    <span>₱<?= number_format($invoice['net_amount'], 2) ?></span>
                </div>
                <div class="total-row">
                    <span>Amount Paid:</span>
                    <span>₱<?= number_format($invoice['paid_amount'], 2) ?></span>
                </div>
                <div class="total-row" style="color: <?= ($invoice['net_amount'] - $invoice['paid_amount']) > 0 ? '#dc3545' : '#28a745' ?>;">
                    <span>Balance:</span>
                    <span>₱<?= number_format($invoice['net_amount'] - $invoice['paid_amount'], 2) ?></span>
                </div>
            </div>
            
            <div class="payments-section">
                <h3><i class="fas fa-credit-card"></i> Payment History</h3>
                
                <?php if (empty($payments)): ?>
                    <div style="text-align: center; padding: 2rem; color: #666;">
                        <i class="fas fa-credit-card" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                        <p>No payments recorded for this invoice</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-item">
                        <div class="payment-info">
                            <div class="payment-method"><?= htmlspecialchars($payment['payment_method']) ?></div>
                            <div class="payment-date">
                                <?= date('F j, Y g:i A', strtotime($payment['date'])) ?>
                                <?php if ($payment['received_by']): ?>
                                    • Received by: <?= htmlspecialchars($payment['received_by']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($payment['receipt_number']): ?>
                            <div class="payment-date">Receipt #<?= htmlspecialchars($payment['receipt_number']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="payment-amount">₱<?= number_format($payment['amount'], 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="action-buttons">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Invoice
            </button>
            <a href="../../../api/billing/management/print_invoice.php?billing_id=<?= $billing_id ?>&format=pdf" class="btn btn-success" target="_blank">
                <i class="fas fa-download"></i> Download PDF
            </a>
            <button class="btn btn-secondary" onclick="window.close()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>

    <script>
        // Auto-focus for better UX
        window.focus();
        
        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            // Escape to close
            if (e.key === 'Escape') {
                window.close();
            }
        });
    </script>
</body>
</html>