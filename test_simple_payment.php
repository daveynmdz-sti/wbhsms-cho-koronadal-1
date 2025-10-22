<?php
/**
 * Simple Payment Test - Direct Form Submission
 */
$root_path = __DIR__;
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Must be logged in
if (!is_employee_logged_in()) {
    die('Please log in as an employee first. <a href="pages/management/auth/employee_login.php">Login</a>');
}

$employee_id = get_employee_session('employee_id');
$success_message = '';
$error_message = '';

// Process payment if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    try {
        $pdo->beginTransaction();
        
        $billing_id = intval($_POST['billing_id']);
        $amount_paid = floatval($_POST['amount_paid']);
        
        // Get billing record
        $stmt = $pdo->prepare("SELECT * FROM billing WHERE billing_id = ?");
        $stmt->execute([$billing_id]);
        $billing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$billing) {
            throw new Exception('Billing record not found');
        }
        
        // Calculate new amounts
        $new_paid_amount = $billing['paid_amount'] + $amount_paid;
        $new_status = ($new_paid_amount >= $billing['net_amount'] - 0.01) ? 'paid' : 'partial';
        $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($billing_id, 6, '0', STR_PAD_LEFT);
        
        // Insert payment record
        $stmt = $pdo->prepare("INSERT INTO payments (billing_id, amount_paid, payment_method, cashier_id, receipt_number, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$billing_id, $amount_paid, 'cash', $employee_id, $receipt_number, 'Test payment']);
        
        $payment_id = $pdo->lastInsertId();
        
        // Update billing record
        $stmt = $pdo->prepare("UPDATE billing SET paid_amount = ?, payment_status = ? WHERE billing_id = ?");
        $stmt->execute([$new_paid_amount, $new_status, $billing_id]);
        
        $pdo->commit();
        $success_message = "Payment processed successfully! Payment ID: $payment_id, Receipt: $receipt_number";
        
    } catch (Exception $e) {
        $pdo->rollback();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get unpaid billing records
$stmt = $pdo->prepare("
    SELECT b.billing_id, b.patient_id, b.net_amount, b.paid_amount, b.payment_status,
           p.first_name, p.last_name
    FROM billing b 
    JOIN patients p ON b.patient_id = p.patient_id 
    WHERE b.payment_status IN ('unpaid', 'partial') 
    ORDER BY b.billing_date DESC 
    LIMIT 10
");
$stmt->execute();
$billings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Simple Payment Test</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .success { color: green; background: #d4edda; padding: 10px; margin: 10px 0; border-radius: 4px; }
        .error { color: red; background: #f8d7da; padding: 10px; margin: 10px 0; border-radius: 4px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f8f9fa; }
        .form-section { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 4px; }
        input, button { padding: 5px 10px; margin: 5px; }
        button { background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #218838; }
    </style>
</head>
<body>
    <h1>Simple Payment Test</h1>
    <p>Employee ID: <?= $employee_id ?></p>
    
    <?php if ($success_message): ?>
        <div class="success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="error"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    
    <h2>Unpaid/Partial Billing Records</h2>
    <table>
        <tr>
            <th>Billing ID</th>
            <th>Patient</th>
            <th>Net Amount</th>
            <th>Paid Amount</th>
            <th>Outstanding</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php foreach ($billings as $b): ?>
            <?php $outstanding = $b['net_amount'] - $b['paid_amount']; ?>
            <tr>
                <td><?= $b['billing_id'] ?></td>
                <td><?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?></td>
                <td>₱<?= number_format($b['net_amount'], 2) ?></td>
                <td>₱<?= number_format($b['paid_amount'], 2) ?></td>
                <td>₱<?= number_format($outstanding, 2) ?></td>
                <td><?= $b['payment_status'] ?></td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="billing_id" value="<?= $b['billing_id'] ?>">
                        <input type="number" name="amount_paid" value="<?= number_format($outstanding, 2) ?>" step="0.01" style="width: 80px;">
                        <button type="submit" name="process_payment">Pay</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    
    <?php if (empty($billings)): ?>
        <p>No unpaid billing records found.</p>
    <?php endif; ?>
    
    <div class="form-section">
        <h3>Manual Payment Test</h3>
        <form method="POST">
            <label>Billing ID: <input type="number" name="billing_id" value="1" required></label><br>
            <label>Amount: <input type="number" name="amount_paid" value="100.00" step="0.01" required></label><br>
            <button type="submit" name="process_payment">Process Payment</button>
        </form>
    </div>
    
    <p><a href="pages/billing/process_payment.php">← Back to Process Payment</a></p>
</body>
</html>