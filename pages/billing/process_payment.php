<?php
/**
 * Process Payment Page
 * Purpose: Cashier interface for receiving payments on invoices
 * UI Pattern: Topbar only (form page), follows create_referrals.php structure
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
    header('Location: ../management/' . strtolower($employee_role) . '/dashboard.php');
    exit();
}

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// Get billing_id from URL parameter
$billing_id = intval($_GET['billing_id'] ?? 0);
$invoice_data = null;
$invoice_items = [];

if ($billing_id) {
    try {
        // Get invoice details
        $stmt = $pdo->prepare("SELECT b.*, p.first_name, p.last_name, p.username as patient_username, 
                                      v.visit_date, e.first_name as created_by_first_name, e.last_name as created_by_last_name
                               FROM billing b
                               JOIN patients p ON b.patient_id = p.patient_id  
                               JOIN employees e ON b.created_by = e.employee_id
                               LEFT JOIN visits v ON b.visit_id = v.visit_id
                               WHERE b.billing_id = ? AND b.payment_status IN ('unpaid', 'partial')");
        $stmt->execute([$billing_id]);
        $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice_data) {
            // Get invoice items
            $stmt = $pdo->prepare("SELECT bi.*, si.item_name 
                                   FROM billing_items bi
                                   JOIN service_items si ON bi.service_item_id = si.item_id
                                   WHERE bi.billing_id = ?
                                   ORDER BY si.item_name");
            $stmt->execute([$billing_id]);
            $invoice_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = 'Error retrieving invoice details: ' . $e->getMessage();
    }
}

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'process_payment') {
        try {
            $pdo->beginTransaction();
            
            // Get form data
            $billing_id = intval($_POST['billing_id'] ?? 0);
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['notes'] ?? '');
            
            // Validation
            if (!$billing_id) {
                throw new Exception('Invalid invoice ID.');
            }
            if ($amount_paid <= 0) {
                throw new Exception('Payment amount must be greater than zero.');
            }

            // Get billing details for validation
            $stmt = $pdo->prepare("SELECT * FROM billing WHERE billing_id = ? AND payment_status IN ('unpaid', 'partial')");
            $stmt->execute([$billing_id]);
            $billing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$billing) {
                throw new Exception('Invoice not found or already paid.');
            }

            // Validate payment amount
            $remaining_amount = $billing['net_amount'] - $billing['paid_amount'];
            if ($amount_paid > $remaining_amount + 0.01) { // Allow small rounding differences
                throw new Exception('Payment amount exceeds remaining balance of ₱' . number_format($remaining_amount, 2));
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

            $pdo->commit();
            
            // Set success data for receipt display
            $_SESSION['payment_success'] = [
                'payment_id' => $payment_id,
                'billing_id' => $billing_id,
                'receipt_number' => $receipt_number,
                'amount_paid' => $amount_paid,
                'change_amount' => $change_amount,
                'payment_status' => $new_status,
                'patient_name' => $billing['first_name'] . ' ' . $billing['last_name'],
                'patient_id' => $billing['patient_username'],
                'net_amount' => $billing['net_amount'],
                'total_paid' => $new_paid_amount
            ];
            
            $success_message = 'Payment processed successfully!';
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            $error_message = 'Error processing payment: ' . $e->getMessage();
        }
    }
}

// Check for payment success from session
$payment_success_data = $_SESSION['payment_success'] ?? null;
if ($payment_success_data) {
    unset($_SESSION['payment_success']); // Clear after use
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payment - CHO Koronadal</title>
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/topbar.css">
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/profile-edit.css">
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 1rem 0;
            padding: 1.5rem;
        }
        
        .invoice-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .invoice-info h4 {
            color: #2196F3;
            margin-bottom: 0.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 0.25rem 0;
        }
        
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .items-table th,
        .items-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .items-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .payment-summary {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5rem 0;
        }
        
        .summary-row.total {
            font-size: 1.2em;
            font-weight: 600;
            border-top: 2px solid #ddd;
            padding-top: 0.5rem;
        }
        
        .summary-row.outstanding {
            color: #dc3545;
            font-weight: 600;
        }
        
        .payment-form {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .payment-input {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            align-items: end;
        }
        
        .amount-input {
            font-size: 1.2em;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 4px;
            text-align: right;
        }
        
        .amount-input:focus {
            border-color: #2196F3;
            outline: none;
        }
        
        .change-display {
            background: #e8f5e8;
            padding: 1rem;
            border-radius: 4px;
            text-align: center;
            font-size: 1.1em;
            font-weight: 600;
            margin: 1rem 0;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        
        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .btn-close {
            float: right;
            background: none;
            border: none;
            font-size: 1.2em;
            cursor: pointer;
            opacity: 0.7;
        }
        
        .btn-close:hover {
            opacity: 1;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
        }
        
        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .receipt-content {
            background: white;
            padding: 2rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }
        
        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin: 0.25rem 0;
        }
        
        .receipt-total {
            border-top: 1px solid #000;
            padding-top: 0.5rem;
            font-weight: bold;
        }

        .status-paid {
            color: #28a745;
            font-weight: bold;
        }

        .status-partial {
            color: #ffc107;
            font-weight: bold;
        }

        .status-unpaid {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php renderTopbar([
            'title' => 'Process Payment',
            'back_url' => 'billing_management.php',
            'user_type' => 'employee'
        ]); ?>

        <section class="homepage">
            <div class="main-content">
                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!$billing_id): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> No invoice selected. Please select an invoice from the billing management page.
                    </div>
                <?php elseif (!$invoice_data): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> Invoice not found or already fully paid.
                    </div>
                <?php else: ?>

                <!-- Invoice Details Section -->
                <div class="form-section">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Invoice Details</h3>
                    
                    <div class="invoice-header">
                        <div class="invoice-info">
                            <h4>Invoice Information</h4>
                            <div class="info-row">
                                <span class="info-label">Invoice ID:</span>
                                <span><?= $billing_id ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Date:</span>
                                <span><?= date('M d, Y', strtotime($invoice_data['billing_date'])) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created by:</span>
                                <span><?= htmlspecialchars($invoice_data['created_by_first_name'] . ' ' . $invoice_data['created_by_last_name']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span class="status-<?= $invoice_data['payment_status'] ?>">
                                    <?= ucfirst($invoice_data['payment_status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="patient-info">
                            <h4>Patient Information</h4>
                            <div class="info-row">
                                <span class="info-label">Patient ID:</span>
                                <span><?= htmlspecialchars($invoice_data['patient_username']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Name:</span>
                                <span><?= htmlspecialchars($invoice_data['first_name'] . ' ' . $invoice_data['last_name']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Visit Date:</span>
                                <span><?= $invoice_data['visit_date'] ? date('M d, Y', strtotime($invoice_data['visit_date'])) : 'N/A' ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Items -->
                    <h4>Services Billed</h4>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Service/Item</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoice_items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['item_name']) ?></td>
                                <td class="text-right">₱<?= number_format($item['item_price'], 2) ?></td>
                                <td class="text-right"><?= $item['quantity'] ?></td>
                                <td class="text-right">₱<?= number_format($item['subtotal'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Payment Summary -->
                    <div class="payment-summary">
                        <div class="summary-row">
                            <span>Total Amount:</span>
                            <span>₱<?= number_format($invoice_data['total_amount'], 2) ?></span>
                        </div>
                        <?php if ($invoice_data['discount_amount'] > 0): ?>
                        <div class="summary-row">
                            <span>Discount (<?= ucfirst($invoice_data['discount_type']) ?>):</span>
                            <span>-₱<?= number_format($invoice_data['discount_amount'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-row total">
                            <span>Net Amount:</span>
                            <span>₱<?= number_format($invoice_data['net_amount'], 2) ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Amount Paid:</span>
                            <span>₱<?= number_format($invoice_data['paid_amount'], 2) ?></span>
                        </div>
                        <div class="summary-row outstanding">
                            <span>Outstanding Balance:</span>
                            <span>₱<?= number_format($invoice_data['net_amount'] - $invoice_data['paid_amount'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment Form Section -->
                <div class="form-section">
                    <h3><i class="fas fa-credit-card"></i> Process Payment</h3>
                    
                    <form method="POST" id="payment-form">
                        <input type="hidden" name="action" value="process_payment">
                        <input type="hidden" name="billing_id" value="<?= $billing_id ?>">
                        
                        <div class="payment-input">
                            <div>
                                <label for="amount_paid">Amount Given by Patient *</label>
                                <input type="number" 
                                       class="amount-input" 
                                       id="amount_paid" 
                                       name="amount_paid" 
                                       step="0.01" 
                                       min="0" 
                                       max="<?= $invoice_data['net_amount'] - $invoice_data['paid_amount'] + 1000 ?>"
                                       placeholder="₱0.00"
                                       required
                                       oninput="calculateChange()">
                            </div>
                            
                            <div>
                                <label for="payment_method">Payment Method</label>
                                <select id="payment_method" name="payment_method" required>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="check">Check</option>
                                </select>
                            </div>
                        </div>

                        <div class="change-display" id="change-display" style="display: none;">
                            Change: <span id="change-amount">₱0.00</span>
                        </div>

                        <div>
                            <label for="notes">Payment Notes (Optional)</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="Additional notes about this payment..."></textarea>
                        </div>

                        <div style="margin-top: 1.5rem;">
                            <button type="button" class="btn btn-success" id="confirm-payment-btn" onclick="confirmPayment()" disabled>
                                <i class="fas fa-check"></i> Confirm Payment
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.history.back();">
                                <i class="fas fa-arrow-left"></i> Back to Billing
                            </button>
                        </div>
                    </form>
                </div>

                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Payment Confirmation Modal -->
    <div id="payment-confirmation-modal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-question-circle"></i> Confirm Payment</h3>
            <p>Please confirm the payment details:</p>
            <div id="payment-confirmation-details"></div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closePaymentConfirmation()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitPayment()">
                    <i class="fas fa-check"></i> Process Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loading-modal" class="modal">
        <div class="modal-content">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p>Processing payment, please wait...</p>
        </div>
    </div>

    <!-- Receipt Modal -->
    <?php if ($payment_success_data): ?>
    <div id="receipt-modal" class="modal" style="display: block;">
        <div class="modal-content">
            <h3><i class="fas fa-receipt"></i> Payment Receipt</h3>
            
            <div class="receipt-content">
                <div class="receipt-header">
                    <h3>CHO KORONADAL</h3>
                    <p>Official Receipt</p>
                    <p>Receipt #: <?= $payment_success_data['receipt_number'] ?></p>
                    <p>Date: <?= date('M d, Y g:i A') ?></p>
                </div>

                <div class="receipt-row">
                    <span>Patient:</span>
                    <span><?= htmlspecialchars($payment_success_data['patient_name']) ?></span>
                </div>
                <div class="receipt-row">
                    <span>Patient ID:</span>
                    <span><?= htmlspecialchars($payment_success_data['patient_id']) ?></span>
                </div>
                <div class="receipt-row">
                    <span>Invoice ID:</span>
                    <span><?= $payment_success_data['billing_id'] ?></span>
                </div>

                <br>

                <div class="receipt-row">
                    <span>Net Amount:</span>
                    <span>₱<?= number_format($payment_success_data['net_amount'], 2) ?></span>
                </div>
                <div class="receipt-row">
                    <span>Amount Paid:</span>
                    <span>₱<?= number_format($payment_success_data['amount_paid'], 2) ?></span>
                </div>
                <?php if ($payment_success_data['change_amount'] > 0): ?>
                <div class="receipt-row">
                    <span>Change:</span>
                    <span>₱<?= number_format($payment_success_data['change_amount'], 2) ?></span>
                </div>
                <?php endif; ?>

                <br>

                <div class="receipt-row receipt-total">
                    <span>Payment Status:</span>
                    <span><?= strtoupper($payment_success_data['payment_status']) ?></span>
                </div>
                
                <div style="text-align: center; margin-top: 1rem; font-size: 0.9em;">
                    <p>Thank you for your payment!</p>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="goToDashboard()">
                    <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                </button>
                <button type="button" class="btn btn-primary" onclick="printReceipt()">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const outstandingBalance = <?= $invoice_data ? ($invoice_data['net_amount'] - $invoice_data['paid_amount']) : 0 ?>;

        // Calculate change amount
        function calculateChange() {
            const amountPaid = parseFloat(document.getElementById('amount_paid').value) || 0;
            const change = amountPaid - outstandingBalance;
            const confirmBtn = document.getElementById('confirm-payment-btn');
            const changeDisplay = document.getElementById('change-display');
            const changeAmount = document.getElementById('change-amount');

            if (amountPaid >= outstandingBalance) {
                changeAmount.textContent = '₱' + Math.max(0, change).toFixed(2);
                changeDisplay.style.display = 'block';
                confirmBtn.disabled = false;
            } else {
                changeDisplay.style.display = 'none';
                confirmBtn.disabled = true;
            }
        }

        // Confirm payment details
        function confirmPayment() {
            const amountPaid = parseFloat(document.getElementById('amount_paid').value) || 0;
            const paymentMethod = document.getElementById('payment_method').value;
            const change = Math.max(0, amountPaid - outstandingBalance);

            if (amountPaid < outstandingBalance) {
                alert('Payment amount must be at least ₱' + outstandingBalance.toFixed(2));
                return;
            }

            let detailsHTML = `
                <div class="payment-summary">
                    <div class="summary-row">
                        <span>Outstanding Balance:</span>
                        <span>₱${outstandingBalance.toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Amount Given:</span>
                        <span>₱${amountPaid.toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Payment Method:</span>
                        <span>${paymentMethod.toUpperCase()}</span>
                    </div>
                    ${change > 0 ? `<div class="summary-row">
                        <span>Change:</span>
                        <span>₱${change.toFixed(2)}</span>
                    </div>` : ''}
                </div>
            `;

            document.getElementById('payment-confirmation-details').innerHTML = detailsHTML;
            document.getElementById('payment-confirmation-modal').style.display = 'block';
        }

        // Close payment confirmation modal
        function closePaymentConfirmation() {
            document.getElementById('payment-confirmation-modal').style.display = 'none';
        }

        // Submit payment form
        function submitPayment() {
            document.getElementById('payment-confirmation-modal').style.display = 'none';
            document.getElementById('loading-modal').style.display = 'block';
            document.getElementById('payment-form').submit();
        }

        // Go to dashboard
        function goToDashboard() {
            window.location.href = 'billing_management.php';
        }

        // Print receipt
        function printReceipt() {
            const receiptContent = document.querySelector('.receipt-content').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Receipt - ${<?= json_encode($payment_success_data['receipt_number'] ?? '') ?>}</title>
                    <style>
                        body { font-family: 'Courier New', monospace; padding: 20px; }
                        .receipt-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 1rem; margin-bottom: 1rem; }
                        .receipt-row { display: flex; justify-content: space-between; margin: 0.25rem 0; }
                        .receipt-total { border-top: 1px solid #000; padding-top: 0.5rem; font-weight: bold; }
                    </style>
                </head>
                <body>${receiptContent}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Auto-focus on amount input
        <?php if ($invoice_data): ?>
        document.getElementById('amount_paid').focus();
        <?php endif; ?>
    </script>
</body>
</html>