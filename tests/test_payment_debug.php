<?php
/**
 * Payment Processing Debug Test
 * Check what happens when form is submitted
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$root_path = __DIR__;
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

echo "<h1>Payment Processing Debug</h1>";
echo "<p>Testing payment form submission and processing...</p>";

// Check session and authentication
echo "<h2>1. Session Check</h2>";
if (!is_employee_logged_in()) {
    echo "<p style='color: red;'>❌ Employee not logged in</p>";
    exit();
} else {
    echo "<p style='color: green;'>✅ Employee logged in</p>";
    echo "<p>Employee ID: " . get_employee_session('employee_id') . "</p>";
    echo "<p>Role: " . get_employee_session('role') . "</p>";
}

// Check database connection
echo "<h2>2. Database Connection</h2>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM billing WHERE payment_status IN ('unpaid', 'partial')");
    $stmt->execute();
    $unpaid_count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✅ Database connected</p>";
    echo "<p>Unpaid invoices: " . $unpaid_count['total'] . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}

// Check if POST data was received
echo "<h2>3. POST Data Check</h2>";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<p style='color: green;'>✅ POST request received</p>";
    echo "<h3>Form Data:</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    $action = $_POST['action'] ?? '';
    $billing_id = intval($_POST['billing_id'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    
    echo "<h3>Parsed Values:</h3>";
    echo "<p>Action: '$action'</p>";
    echo "<p>Billing ID: $billing_id</p>";
    echo "<p>Amount Paid: $amount_paid</p>";
    echo "<p>Payment Method: '$payment_method'</p>";
    echo "<p>Notes: '$notes'</p>";
    
    if ($action === 'process_payment') {
        echo "<p style='color: green;'>✅ Correct action parameter</p>";
        
        // Check billing record
        echo "<h3>Billing Record Check:</h3>";
        try {
            $stmt = $pdo->prepare("SELECT * FROM billing WHERE billing_id = ?");
            $stmt->execute([$billing_id]);
            $billing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($billing) {
                echo "<p style='color: green;'>✅ Billing record found</p>";
                echo "<p>Net Amount: ₱" . number_format($billing['net_amount'], 2) . "</p>";
                echo "<p>Paid Amount: ₱" . number_format($billing['paid_amount'], 2) . "</p>";
                echo "<p>Status: " . $billing['payment_status'] . "</p>";
                echo "<p>Outstanding: ₱" . number_format($billing['net_amount'] - $billing['paid_amount'], 2) . "</p>";
                
                // Validation checks
                echo "<h3>Validation Checks:</h3>";
                if (!$billing_id) {
                    echo "<p style='color: red;'>❌ Invalid billing ID</p>";
                } else {
                    echo "<p style='color: green;'>✅ Valid billing ID</p>";
                }
                
                if ($amount_paid <= 0) {
                    echo "<p style='color: red;'>❌ Invalid payment amount</p>";
                } else {
                    echo "<p style='color: green;'>✅ Valid payment amount</p>";
                }
                
                if (!in_array($billing['payment_status'], ['unpaid', 'partial'])) {
                    echo "<p style='color: red;'>❌ Invoice already paid</p>";
                } else {
                    echo "<p style='color: green;'>✅ Invoice can accept payment</p>";
                }
                
                $remaining_amount = $billing['net_amount'] - $billing['paid_amount'];
                if ($amount_paid > $remaining_amount + 0.01) {
                    echo "<p style='color: red;'>❌ Payment exceeds remaining balance</p>";
                } else {
                    echo "<p style='color: green;'>✅ Payment amount acceptable</p>";
                }
                
                // Test payment insertion (without committing)
                echo "<h3>Payment Processing Test:</h3>";
                try {
                    $pdo->beginTransaction();
                    
                    $employee_id = get_employee_session('employee_id');
                    $receipt_number = 'TEST-' . date('Ymd') . '-' . str_pad($billing_id, 6, '0', STR_PAD_LEFT);
                    
                    echo "<p>Receipt Number: $receipt_number</p>";
                    echo "<p>Cashier ID: $employee_id</p>";
                    
                    // Test insert payment
                    $stmt = $pdo->prepare("INSERT INTO payments (billing_id, amount_paid, payment_method, cashier_id, receipt_number, notes) VALUES (?, ?, ?, ?, ?, ?)");
                    $result = $stmt->execute([$billing_id, $amount_paid, $payment_method, $employee_id, $receipt_number, $notes]);
                    
                    if ($result) {
                        echo "<p style='color: green;'>✅ Payment record would be inserted successfully</p>";
                        echo "<p>Payment ID would be: " . $pdo->lastInsertId() . "</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Failed to insert payment record</p>";
                    }
                    
                    // Test update billing
                    $new_paid_amount = $billing['paid_amount'] + $amount_paid;
                    $new_status = ($new_paid_amount >= $billing['net_amount'] - 0.01) ? 'paid' : 'partial';
                    
                    $stmt = $pdo->prepare("UPDATE billing SET paid_amount = ?, payment_status = ? WHERE billing_id = ?");
                    $result = $stmt->execute([$new_paid_amount, $new_status, $billing_id]);
                    
                    if ($result) {
                        echo "<p style='color: green;'>✅ Billing record would be updated successfully</p>";
                        echo "<p>New paid amount: ₱" . number_format($new_paid_amount, 2) . "</p>";
                        echo "<p>New status: $new_status</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Failed to update billing record</p>";
                    }
                    
                    $pdo->rollback();
                    echo "<p style='color: blue;'>ℹ️ Transaction rolled back (test mode)</p>";
                    
                } catch (Exception $e) {
                    $pdo->rollback();
                    echo "<p style='color: red;'>❌ Processing error: " . $e->getMessage() . "</p>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ Billing record not found</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Wrong action parameter</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ️ No POST data - showing test form</p>";
}

// Check payments table structure
echo "<h2>4. Payments Table Structure</h2>";
try {
    $stmt = $pdo->prepare("DESCRIBE payments");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . $col['Field'] . "</td>";
        echo "<td>" . $col['Type'] . "</td>";
        echo "<td>" . $col['Null'] . "</td>";
        echo "<td>" . $col['Key'] . "</td>";
        echo "<td>" . $col['Default'] . "</td>";
        echo "<td>" . $col['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error checking table structure: " . $e->getMessage() . "</p>";
}

// Show sample billing records
echo "<h2>5. Sample Billing Records</h2>";
try {
    $stmt = $pdo->prepare("SELECT b.billing_id, b.patient_id, b.net_amount, b.paid_amount, b.payment_status, 
                                  p.first_name, p.last_name 
                           FROM billing b 
                           JOIN patients p ON b.patient_id = p.patient_id 
                           WHERE b.payment_status IN ('unpaid', 'partial') 
                           ORDER BY b.billing_date DESC LIMIT 5");
    $stmt->execute();
    $billings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($billings) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Patient</th><th>Net Amount</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Test Form</th></tr>";
        foreach ($billings as $b) {
            $outstanding = $b['net_amount'] - $b['paid_amount'];
            echo "<tr>";
            echo "<td>" . $b['billing_id'] . "</td>";
            echo "<td>" . $b['first_name'] . " " . $b['last_name'] . "</td>";
            echo "<td>₱" . number_format($b['net_amount'], 2) . "</td>";
            echo "<td>₱" . number_format($b['paid_amount'], 2) . "</td>";
            echo "<td>₱" . number_format($outstanding, 2) . "</td>";
            echo "<td>" . $b['payment_status'] . "</td>";
            echo "<td><a href='#test-form-{$b['billing_id']}'>Test</a></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test form for first record
        if (count($billings) > 0) {
            $test_billing = $billings[0];
            $test_outstanding = $test_billing['net_amount'] - $test_billing['paid_amount'];
            echo "<h3 id='test-form-{$test_billing['billing_id']}'>Test Payment Form (Billing ID: {$test_billing['billing_id']})</h3>";
            echo "<form method='POST'>";
            echo "<input type='hidden' name='action' value='process_payment'>";
            echo "<input type='hidden' name='billing_id' value='{$test_billing['billing_id']}'>";
            echo "<input type='hidden' name='payment_method' value='cash'>";
            echo "<p><label>Amount to Pay: <input type='number' name='amount_paid' value='" . number_format($test_outstanding, 2) . "' step='0.01'></label></p>";
            echo "<p><label>Notes: <input type='text' name='notes' value='Test payment'></label></p>";
            echo "<p><input type='submit' value='Test Process Payment'></p>";
            echo "</form>";
        }
    } else {
        echo "<p>No unpaid billing records found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error fetching billing records: " . $e->getMessage() . "</p>";
}

?>
<style>
    body { font-family: Arial; margin: 20px; }
    h1, h2, h3 { color: #0077b6; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 4px; }
    table { border-collapse: collapse; margin: 10px 0; }
    th { background: #f8f9fa; }
    p { margin: 5px 0; }
</style>